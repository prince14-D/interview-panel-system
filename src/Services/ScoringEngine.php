<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Unified Scoring, Ranking & Blind-Scoring Confidentiality Engine.
 * Used identically by BOTH Physical and Virtual interview sessions.
 */
final class ScoringEngine
{
    public const RECOMMENDATIONS = [
        'strongly_recommended' => 'Strongly Recommended',
        'recommended' => 'Recommended',
        'recommended_with_reservations' => 'Recommended with Reservations',
        'not_recommended' => 'Not Recommended',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function seedDefaultCriteria(int $sessionId): void
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $defaults = $config['default_criteria'] ?? [];

        $stmt = $this->pdo->prepare(
            'INSERT INTO scoring_criteria (session_id, criterion_name, description, max_marks, display_order)
             VALUES (:session_id, :name, :desc, :max_marks, :ord)'
        );

        foreach ($defaults as $c) {
            $stmt->execute([
                'session_id' => $sessionId,
                'name' => $c['criterion_name'],
                'desc' => $c['description'],
                'max_marks' => $c['max_marks'],
                'ord' => $c['display_order'],
            ]);
        }
    }

    public function getSessionCriteria(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scoring_criteria WHERE session_id = :sid ORDER BY display_order ASC, id ASC'
        );
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
    }

    public function isSessionReleased(int $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_released FROM result_releases WHERE session_id = :sid LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();
        return $row !== false && (int)$row['is_released'] === 1;
    }

    public function getReleaseInfo(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rr.*, u.full_name AS released_by_name
             FROM result_releases rr
             LEFT JOIN users u ON u.id = rr.released_by
             WHERE rr.session_id = :sid LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Backend authorization check for viewing peer scores, averages, and rankings.
     * - Before official release: Panelists can ONLY see their own scores.
     * - After official release: Authorized panelists (assigned to the session) and admins can view all results.
     */
    public function canViewReleasedResults(array $user, int $sessionId): bool
    {
        $role = $user['role'] ?? '';
        if (in_array($role, ['admin', 'hr_officer'], true)) {
            return true;
        }

        if ($role === 'panelist') {
            if (!$this->isSessionReleased($sessionId)) {
                return false;
            }
            return $this->isPanelistAssignedToSession((int)$user['id'], $sessionId);
        }

        return false;
    }

    public function isPanelistAssignedToSession(int $panelistId, int $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM session_panelists WHERE session_id = :sid AND panelist_id = :pid LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId, 'pid' => $panelistId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Checks whether a panelist is authorized to assess a specific candidate in a session.
     * Supports both session-wide panel assignment and candidate-specific panel assignment.
     */
    public function canPanelistScoreCandidate(int $sessionId, int $candidateId, int $panelistId): bool
    {
        if (!$this->isPanelistAssignedToSession($panelistId, $sessionId)) {
            return false;
        }

        // Check if candidate-specific panelist restrictions exist for this candidate
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM candidate_panelist_assignments WHERE session_id = :sid AND candidate_id = :cid'
        );
        $countStmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        if ((int)$countStmt->fetchColumn() === 0) {
            return true;
        }

        $specStmt = $this->pdo->prepare(
            'SELECT 1 FROM candidate_panelist_assignments
             WHERE session_id = :sid AND candidate_id = :cid AND panelist_id = :pid LIMIT 1'
        );
        $specStmt->execute(['sid' => $sessionId, 'cid' => $candidateId, 'pid' => $panelistId]);
        return (bool)$specStmt->fetchColumn();
    }

    /**
     * Returns expected panelists for a given candidate in a session.
     */
    public function getAssignedPanelistsForCandidate(int $sessionId, int $candidateId): array
    {
        $specStmt = $this->pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.job_title, sp.panel_role
             FROM candidate_panelist_assignments cpa
             INNER JOIN users u ON u.id = cpa.panelist_id
             INNER JOIN session_panelists sp ON sp.session_id = cpa.session_id AND sp.panelist_id = cpa.panelist_id
             WHERE cpa.session_id = :sid AND cpa.candidate_id = :cid
             ORDER BY u.full_name ASC'
        );
        $specStmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        $specific = $specStmt->fetchAll();

        if (!empty($specific)) {
            return $specific;
        }

        $allStmt = $this->pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.job_title, sp.panel_role
             FROM session_panelists sp
             INNER JOIN users u ON u.id = sp.panelist_id
             WHERE sp.session_id = :sid
             ORDER BY u.full_name ASC'
        );
        $allStmt->execute(['sid' => $sessionId]);
        return $allStmt->fetchAll();
    }

    /**
     * Save or submit an independent panelist evaluation.
     * Validates marks against configured criteria and computes total & percentage server-side.
     */
    public function saveAssessment(
        int $sessionId,
        int $candidateId,
        int $panelistId,
        array $criterionScores,
        array $criterionRemarks,
        ?string $recommendation,
        string $overallComments,
        string $strengths,
        string $areasForImprovement,
        bool $submitFinal
    ): int {
        if (!$this->canPanelistScoreCandidate($sessionId, $candidateId, $panelistId)) {
            throw new RuntimeException('You are not assigned to assess this candidate.');
        }

        if ($this->isSessionReleased($sessionId)) {
            throw new RuntimeException('Results for this session have already been officially released and locked.');
        }

        $criteria = $this->getSessionCriteria($sessionId);
        if (empty($criteria)) {
            throw new RuntimeException('No scoring criteria are configured for this interview session.');
        }

        if ($submitFinal && (!is_string($recommendation) || !array_key_exists($recommendation, self::RECOMMENDATIONS))) {
            throw new RuntimeException('Please select a valid final recommendation before submitting.');
        }

        $totalScore = 0.0;
        $maxPossible = 0.0;
        $validatedScores = [];

        foreach ($criteria as $criterion) {
            $cid = (int)$criterion['id'];
            $maxMarks = (float)$criterion['max_marks'];
            $maxPossible += $maxMarks;

            $raw = $criterionScores[$cid] ?? null;
            if ($raw === null || $raw === '') {
                if ($submitFinal) {
                    throw new RuntimeException("Score is required for criterion: {$criterion['criterion_name']}.");
                }
                $score = 0.0;
            } else {
                if (!is_numeric($raw)) {
                    throw new RuntimeException("Invalid numeric score for {$criterion['criterion_name']}.");
                }
                $score = round((float)$raw, 2);
                if ($score < 0 || $score > $maxMarks) {
                    throw new RuntimeException(
                        "Score for {$criterion['criterion_name']} must be between 0 and {$maxMarks}."
                    );
                }
            }

            $totalScore += $score;
            $validatedScores[$cid] = [
                'score' => $score,
                'remarks' => trim((string)($criterionRemarks[$cid] ?? '')),
            ];
        }

        $totalScore = round($totalScore, 2);
        $maxPossible = round($maxPossible, 2);
        $percentage = $maxPossible > 0 ? round(($totalScore / $maxPossible) * 100, 2) : 0.0;
        $status = $submitFinal ? 'submitted' : 'draft';

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT id, status FROM panel_submissions
                 WHERE session_id = :sid AND candidate_id = :cid AND panelist_id = :pid LIMIT 1'
            );
            $existingStmt->execute(['sid' => $sessionId, 'cid' => $candidateId, 'pid' => $panelistId]);
            $existing = $existingStmt->fetch();

            if ($existing) {
                $submissionId = (int)$existing['id'];
                $upd = $this->pdo->prepare(
                    'UPDATE panel_submissions
                     SET total_score = :total,
                         max_possible_score = :max_possible,
                         percentage_score = :pct,
                         recommendation = :rec,
                         overall_comments = :comments,
                         strengths = :strengths,
                         areas_for_improvement = :improve,
                         status = :status,
                         submitted_at = CASE WHEN :status_check = \'submitted\' THEN COALESCE(submitted_at, CURRENT_TIMESTAMP) ELSE submitted_at END,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $upd->execute([
                    'total' => $totalScore,
                    'max_possible' => $maxPossible,
                    'pct' => $percentage,
                    'rec' => $recommendation ?: null,
                    'comments' => trim($overallComments),
                    'strengths' => trim($strengths),
                    'improve' => trim($areasForImprovement),
                    'status' => $status,
                    'status_check' => $status,
                    'id' => $submissionId,
                ]);
            } else {
                $ins = $this->pdo->prepare(
                    'INSERT INTO panel_submissions
                        (session_id, candidate_id, panelist_id, total_score, max_possible_score, percentage_score,
                         recommendation, overall_comments, strengths, areas_for_improvement, status, submitted_at)
                     VALUES
                        (:sid, :cid, :pid, :total, :max_possible, :pct,
                         :rec, :comments, :strengths, :improve, :status,
                         CASE WHEN :status_check = \'submitted\' THEN CURRENT_TIMESTAMP ELSE NULL END)'
                );
                $ins->execute([
                    'sid' => $sessionId,
                    'cid' => $candidateId,
                    'pid' => $panelistId,
                    'total' => $totalScore,
                    'max_possible' => $maxPossible,
                    'pct' => $percentage,
                    'rec' => $recommendation ?: null,
                    'comments' => trim($overallComments),
                    'strengths' => trim($strengths),
                    'improve' => trim($areasForImprovement),
                    'status' => $status,
                    'status_check' => $status,
                ]);
                $submissionId = (int)$this->pdo->lastInsertId();
            }

            // Upsert criterion scores cleanly (compatible with MySQL & SQLite)
            $delScores = $this->pdo->prepare('DELETE FROM panel_scores WHERE submission_id = :sub_id');
            $delScores->execute(['sub_id' => $submissionId]);

            $insScore = $this->pdo->prepare(
                'INSERT INTO panel_scores (submission_id, criterion_id, score, remarks)
                 VALUES (:sub_id, :crit_id, :score, :remarks)'
            );
            foreach ($validatedScores as $critId => $item) {
                $insScore->execute([
                    'sub_id' => $submissionId,
                    'crit_id' => $critId,
                    'score' => $item['score'],
                    'remarks' => $item['remarks'] !== '' ? $item['remarks'] : null,
                ]);
            }

            AuditLogger::log(
                $this->pdo,
                $panelistId,
                $submitFinal ? 'assessment_submitted' : 'assessment_draft_saved',
                'panel_submission',
                $submissionId,
                "Session #{$sessionId}, Candidate #{$candidateId}, Score: {$totalScore}/{$maxPossible}"
            );

            $this->pdo->commit();
            return $submissionId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Fetch a panelist's OWN submission for a candidate (always allowed for the owner).
     */
    public function getOwnSubmission(int $sessionId, int $candidateId, int $panelistId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM panel_submissions
             WHERE session_id = :sid AND candidate_id = :cid AND panelist_id = :pid LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId, 'cid' => $candidateId, 'pid' => $panelistId]);
        $sub = $stmt->fetch();
        if (!$sub) {
            return null;
        }

        $scoresStmt = $this->pdo->prepare(
            'SELECT criterion_id, score, remarks FROM panel_scores WHERE submission_id = :sub_id'
        );
        $scoresStmt->execute(['sub_id' => $sub['id']]);
        $scoresByCrit = [];
        foreach ($scoresStmt->fetchAll() as $row) {
            $scoresByCrit[(int)$row['criterion_id']] = $row;
        }
        $sub['scores'] = $scoresByCrit;
        return $sub;
    }

    /**
     * Submission progress monitor (safe before release — exposes counts & completion status only, not scores).
     */
    public function getSessionSubmissionProgress(int $sessionId): array
    {
        $candStmt = $this->pdo->prepare(
            'SELECT sc.candidate_id, c.application_number, c.full_name, sc.scheduled_time, sc.interview_status
             FROM session_candidates sc
             INNER JOIN candidates c ON c.id = sc.candidate_id
             WHERE sc.session_id = :sid
             ORDER BY sc.interview_order ASC, sc.scheduled_time ASC'
        );
        $candStmt->execute(['sid' => $sessionId]);
        $candidates = $candStmt->fetchAll();

        $subStmt = $this->pdo->prepare(
            'SELECT candidate_id, panelist_id, status, submitted_at
             FROM panel_submissions WHERE session_id = :sid'
        );
        $subStmt->execute(['sid' => $sessionId]);
        $subMap = [];
        foreach ($subStmt->fetchAll() as $row) {
            $subMap[(int)$row['candidate_id']][(int)$row['panelist_id']] = $row;
        }

        $totalExpected = 0;
        $totalSubmitted = 0;
        $totalDraft = 0;
        $rows = [];

        foreach ($candidates as $cand) {
            $cid = (int)$cand['candidate_id'];
            $assigned = $this->getAssignedPanelistsForCandidate($sessionId, $cid);
            $expected = count($assigned);
            $submitted = 0;
            $draft = 0;
            $panelStatuses = [];

            foreach ($assigned as $p) {
                $pid = (int)$p['id'];
                $entry = $subMap[$cid][$pid] ?? null;
                $st = $entry['status'] ?? 'pending';
                if ($st === 'submitted') {
                    $submitted++;
                } elseif ($st === 'draft') {
                    $draft++;
                }
                $panelStatuses[] = [
                    'panelist_id' => $pid,
                    'full_name' => $p['full_name'],
                    'panel_role' => $p['panel_role'],
                    'status' => $st,
                    'submitted_at' => $entry['submitted_at'] ?? null,
                ];
            }

            $totalExpected += $expected;
            $totalSubmitted += $submitted;
            $totalDraft += $draft;

            $rows[] = [
                'candidate_id' => $cid,
                'application_number' => $cand['application_number'],
                'full_name' => $cand['full_name'],
                'scheduled_time' => $cand['scheduled_time'],
                'interview_status' => $cand['interview_status'],
                'expected_count' => $expected,
                'submitted_count' => $submitted,
                'draft_count' => $draft,
                'pending_count' => max(0, $expected - $submitted),
                'panelists' => $panelStatuses,
            ];
        }

        return [
            'total_expected' => $totalExpected,
            'total_submitted' => $totalSubmitted,
            'total_draft' => $totalDraft,
            'total_pending' => max(0, $totalExpected - $totalSubmitted),
            'completion_rate' => $totalExpected > 0 ? round(($totalSubmitted / $totalExpected) * 100, 1) : 0.0,
            'candidates' => $rows,
        ];
    }

    /**
     * Compute official candidate rankings for a session (identical engine for physical & virtual).
     * Strictly enforces blind-scoring authorization.
     */
    public function getSessionRankings(int $sessionId, array $viewer): array
    {
        if (!$this->canViewReleasedResults($viewer, $sessionId)) {
            throw new RuntimeException('Confidentiality protection: rankings and peer scores are hidden until results are officially released.');
        }

        $criteria = $this->getSessionCriteria($sessionId);
        $maxTotal = 0.0;
        foreach ($criteria as $c) {
            $maxTotal += (float)$c['max_marks'];
        }

        $candStmt = $this->pdo->prepare(
            'SELECT c.id, c.application_number, c.full_name, c.email, c.phone, c.highest_qualification,
                    sc.scheduled_time, sc.interview_status,
                    COALESCE(ca.attendance_status, \'Absent\') AS attendance_status
             FROM session_candidates sc
             INNER JOIN candidates c ON c.id = sc.candidate_id
             LEFT JOIN candidate_attendance ca ON ca.session_id = sc.session_id AND ca.candidate_id = sc.candidate_id
             WHERE sc.session_id = :sid
             ORDER BY sc.interview_order ASC, c.full_name ASC'
        );
        $candStmt->execute(['sid' => $sessionId]);
        $candidates = $candStmt->fetchAll();

        $subStmt = $this->pdo->prepare(
            'SELECT ps.*, u.full_name AS panelist_name, sp.panel_role
             FROM panel_submissions ps
             INNER JOIN users u ON u.id = ps.panelist_id
             LEFT JOIN session_panelists sp ON sp.session_id = ps.session_id AND sp.panelist_id = ps.panelist_id
             WHERE ps.session_id = :sid AND ps.status = \'submitted\'
             ORDER BY u.full_name ASC'
        );
        $subStmt->execute(['sid' => $sessionId]);
        $submissions = $subStmt->fetchAll();

        $subsByCandidate = [];
        foreach ($submissions as $s) {
            $subsByCandidate[(int)$s['candidate_id']][] = $s;
        }

        // Load all criterion scores for submitted evaluations
        $critAvgMap = [];
        $scoreStmt = $this->pdo->prepare(
            'SELECT ps.candidate_id, psc.criterion_id, AVG(psc.score) AS avg_score
             FROM panel_scores psc
             INNER JOIN panel_submissions ps ON ps.id = psc.submission_id
             WHERE ps.session_id = :sid AND ps.status = \'submitted\'
             GROUP BY ps.candidate_id, psc.criterion_id'
        );
        $scoreStmt->execute(['sid' => $sessionId]);
        foreach ($scoreStmt->fetchAll() as $row) {
            $critAvgMap[(int)$row['candidate_id']][(int)$row['criterion_id']] = round((float)$row['avg_score'], 2);
        }

        $rankings = [];
        foreach ($candidates as $cand) {
            $cid = (int)$cand['id'];
            $candSubs = $subsByCandidate[$cid] ?? [];
            $evalCount = count($candSubs);
            $assignedCount = count($this->getAssignedPanelistsForCandidate($sessionId, $cid));

            $avgScore = 0.0;
            $avgPct = 0.0;
            $minScore = null;
            $maxScore = null;
            $recCounts = [
                'strongly_recommended' => 0,
                'recommended' => 0,
                'recommended_with_reservations' => 0,
                'not_recommended' => 0,
            ];

            if ($evalCount > 0) {
                $scores = array_map(static fn($s) => (float)$s['total_score'], $candSubs);
                $pcts = array_map(static fn($s) => (float)$s['percentage_score'], $candSubs);
                $avgScore = round(array_sum($scores) / $evalCount, 2);
                $avgPct = round(array_sum($pcts) / $evalCount, 2);
                $minScore = round(min($scores), 2);
                $maxScore = round(max($scores), 2);

                foreach ($candSubs as $s) {
                    if (!empty($s['recommendation']) && isset($recCounts[$s['recommendation']])) {
                        $recCounts[$s['recommendation']]++;
                    }
                }
            }

            $dominantRec = null;
            if ($evalCount > 0) {
                arsort($recCounts);
                $topKey = array_key_first($recCounts);
                if ($recCounts[$topKey] > 0) {
                    $dominantRec = $topKey;
                }
            }

            $rankings[] = [
                'candidate_id' => $cid,
                'application_number' => $cand['application_number'],
                'full_name' => $cand['full_name'],
                'email' => $cand['email'],
                'phone' => $cand['phone'],
                'highest_qualification' => $cand['highest_qualification'],
                'attendance_status' => $cand['attendance_status'],
                'interview_status' => $cand['interview_status'],
                'evaluations_submitted' => $evalCount,
                'evaluations_expected' => $assignedCount,
                'average_score' => $avgScore,
                'max_possible_score' => round($maxTotal, 2),
                'average_percentage' => $avgPct,
                'min_score' => $minScore,
                'max_score' => $maxScore,
                'criterion_averages' => $critAvgMap[$cid] ?? [],
                'recommendations' => $recCounts,
                'dominant_recommendation' => $dominantRec,
                'submissions' => $candSubs,
                'rank' => null,
            ];
        }

        // Sort descending by average_percentage, then average_score, then evaluations_submitted
        usort($rankings, static function (array $a, array $b): int {
            if ($a['evaluations_submitted'] === 0 && $b['evaluations_submitted'] > 0) {
                return 1;
            }
            if ($b['evaluations_submitted'] === 0 && $a['evaluations_submitted'] > 0) {
                return -1;
            }
            $cmp = $b['average_percentage'] <=> $a['average_percentage'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp2 = $b['average_score'] <=> $a['average_score'];
            if ($cmp2 !== 0) {
                return $cmp2;
            }
            return strcmp($a['full_name'], $b['full_name']);
        });

        // Standard competition ranking (1, 2, 2, 4) for evaluated candidates
        $currentRank = 0;
        $position = 0;
        $prevScore = null;
        foreach ($rankings as &$item) {
            if ($item['evaluations_submitted'] === 0) {
                $item['rank'] = null;
                continue;
            }
            $position++;
            if ($prevScore === null || $item['average_score'] < $prevScore) {
                $currentRank = $position;
                $prevScore = $item['average_score'];
            }
            $item['rank'] = $currentRank;
        }
        unset($item);

        return $rankings;
    }

    /**
     * Detailed candidate assessment report with backend blind-scoring protection.
     */
    public function getCandidateDetailedReport(int $sessionId, int $candidateId, array $viewer): array
    {
        $canViewAll = $this->canViewReleasedResults($viewer, $sessionId);
        $isReleased = $this->isSessionReleased($sessionId);
        $criteria = $this->getSessionCriteria($sessionId);

        $candStmt = $this->pdo->prepare(
            'SELECT c.*, sc.scheduled_time, sc.interview_status, sc.interview_started_at, sc.interview_ended_at,
                    ca.attendance_status, ca.actual_arrival_time, ca.remarks AS attendance_remarks
             FROM session_candidates sc
             INNER JOIN candidates c ON c.id = sc.candidate_id
             LEFT JOIN candidate_attendance ca ON ca.session_id = sc.session_id AND ca.candidate_id = sc.candidate_id
             WHERE sc.session_id = :sid AND sc.candidate_id = :cid LIMIT 1'
        );
        $candStmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        $candidate = $candStmt->fetch();
        if (!$candidate) {
            throw new RuntimeException('Candidate not found in this interview session.');
        }

        if ($canViewAll) {
            $subStmt = $this->pdo->prepare(
                'SELECT ps.*, u.full_name AS panelist_name, u.job_title AS panelist_title, sp.panel_role
                 FROM panel_submissions ps
                 INNER JOIN users u ON u.id = ps.panelist_id
                 LEFT JOIN session_panelists sp ON sp.session_id = ps.session_id AND sp.panelist_id = ps.panelist_id
                 WHERE ps.session_id = :sid AND ps.candidate_id = :cid AND ps.status = \'submitted\'
                 ORDER BY u.full_name ASC'
            );
            $subStmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        } else {
            // Blind scoring strictly enforced: panelist sees ONLY their own submission
            $subStmt = $this->pdo->prepare(
                'SELECT ps.*, u.full_name AS panelist_name, u.job_title AS panelist_title, sp.panel_role
                 FROM panel_submissions ps
                 INNER JOIN users u ON u.id = ps.panelist_id
                 LEFT JOIN session_panelists sp ON sp.session_id = ps.session_id AND sp.panelist_id = ps.panelist_id
                 WHERE ps.session_id = :sid AND ps.candidate_id = :cid AND ps.panelist_id = :pid
                 ORDER BY u.full_name ASC'
            );
            $subStmt->execute([
                'sid' => $sessionId,
                'cid' => $candidateId,
                'pid' => (int)$viewer['id'],
            ]);
        }

        $submissions = $subStmt->fetchAll();
        $scoreStmt = $this->pdo->prepare(
            'SELECT criterion_id, score, remarks FROM panel_scores WHERE submission_id = :sub_id'
        );

        foreach ($submissions as &$sub) {
            $scoreStmt->execute(['sub_id' => $sub['id']]);
            $map = [];
            foreach ($scoreStmt->fetchAll() as $row) {
                $map[(int)$row['criterion_id']] = $row;
            }
            $sub['scores'] = $map;
        }
        unset($sub);

        $aggregate = null;
        if ($canViewAll && !empty($submissions)) {
            $count = count($submissions);
            $avgTotal = round(array_sum(array_column($submissions, 'total_score')) / $count, 2);
            $avgPct = round(array_sum(array_column($submissions, 'percentage_score')) / $count, 2);
            $critAverages = [];
            foreach ($criteria as $c) {
                $cid = (int)$c['id'];
                $vals = [];
                foreach ($submissions as $s) {
                    if (isset($s['scores'][$cid])) {
                        $vals[] = (float)$s['scores'][$cid]['score'];
                    }
                }
                $critAverages[$cid] = !empty($vals) ? round(array_sum($vals) / count($vals), 2) : 0.0;
            }
            $aggregate = [
                'panelist_count' => $count,
                'average_score' => $avgTotal,
                'average_percentage' => $avgPct,
                'criterion_averages' => $critAverages,
            ];
        }

        return [
            'candidate' => $candidate,
            'criteria' => $criteria,
            'submissions' => $submissions,
            'aggregate' => $aggregate,
            'is_released' => $isReleased,
            'can_view_all' => $canViewAll,
        ];
    }

    /**
     * Officially release results for a session, unlocking averages, rankings, and peer assessments.
     */
    public function releaseResults(int $sessionId, int $adminUserId, string $notes = ''): void
    {
        $exists = $this->pdo->prepare('SELECT id FROM result_releases WHERE session_id = :sid LIMIT 1');
        $exists->execute(['sid' => $sessionId]);
        $row = $exists->fetch();

        if ($row) {
            $stmt = $this->pdo->prepare(
                'UPDATE result_releases
                 SET is_released = 1, released_by = :uid, released_at = CURRENT_TIMESTAMP, release_notes = :notes, updated_at = CURRENT_TIMESTAMP
                 WHERE session_id = :sid'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO result_releases (session_id, is_released, released_by, released_at, release_notes)
                 VALUES (:sid, 1, :uid, CURRENT_TIMESTAMP, :notes)'
            );
        }

        $stmt->execute([
            'sid' => $sessionId,
            'uid' => $adminUserId,
            'notes' => trim($notes),
        ]);

        AuditLogger::log($this->pdo, $adminUserId, 'results_released', 'interview_session', $sessionId, trim($notes) ?: 'Official results released');
    }

    public function revokeRelease(int $sessionId, int $adminUserId, string $reason = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE result_releases SET is_released = 0, release_notes = :notes, updated_at = CURRENT_TIMESTAMP WHERE session_id = :sid'
        );
        $stmt->execute(['sid' => $sessionId, 'notes' => trim($reason)]);
        AuditLogger::log($this->pdo, $adminUserId, 'results_release_revoked', 'interview_session', $sessionId, trim($reason) ?: 'Results locked again');
    }
}
