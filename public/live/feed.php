<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Database;
use App\Core\Response;
use App\Services\ScoringEngine;

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    Response::json(['ok' => false, 'error' => 'Missing scoreboard token'], 400);
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'SELECT lb.*, s.title, s.job_title, s.session_code, s.interview_mode, s.interview_date, s.status AS session_status,
            d.name AS department_name, v.venue_name, v.room_number
     FROM live_scoreboards lb
     INNER JOIN interview_sessions s ON s.id = lb.session_id
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     WHERE lb.access_token = :tok AND lb.is_enabled = 1
     LIMIT 1'
);
$stmt->execute(['tok' => $token]);
$board = $stmt->fetch();

if (!$board) {
    Response::json(['ok' => false, 'error' => 'Live scoreboard link is invalid or disabled.'], 404);
}

$sessionId = (int)$board['session_id'];
$scoring = new ScoringEngine($pdo);
$isReleased = $scoring->isSessionReleased($sessionId);
$showScores = ((int)$board['show_live_scores'] === 1) || $isReleased;
$progress = $scoring->getSessionSubmissionProgress($sessionId);
$criteria = $scoring->getSessionCriteria($sessionId);

$rows = [];
if ($showScores) {
    // Authorized read-only system view enabled explicitly by admin for this live board
    $rankings = $scoring->getSessionRankings($sessionId, ['id' => 0, 'role' => 'admin']);
    foreach ($rankings as $r) {
        $rows[] = [
            'rank' => $r['rank'],
            'application_number' => $r['application_number'],
            'full_name' => $r['full_name'],
            'attendance_status' => $r['attendance_status'],
            'interview_status' => $r['interview_status'],
            'evaluations_submitted' => $r['evaluations_submitted'],
            'evaluations_expected' => $r['evaluations_expected'],
            'criterion_averages' => $r['criterion_averages'],
            'average_score' => $r['average_score'],
            'max_possible_score' => $r['max_possible_score'],
            'average_percentage' => $r['average_percentage'],
            'recommendation' => $r['dominant_recommendation']
                ? (ScoringEngine::RECOMMENDATIONS[$r['dominant_recommendation']] ?? '')
                : 'Pending',
        ];
    }
} else {
    foreach ($progress['candidates'] as $pc) {
        $rows[] = [
            'rank' => null,
            'application_number' => $pc['application_number'],
            'full_name' => $pc['full_name'],
            'attendance_status' => '—',
            'interview_status' => $pc['interview_status'],
            'evaluations_submitted' => $pc['submitted_count'],
            'evaluations_expected' => $pc['expected_count'],
            'criterion_averages' => [],
            'average_score' => null,
            'max_possible_score' => null,
            'average_percentage' => null,
            'recommendation' => 'Awaiting Release',
        ];
    }
}

Response::json([
    'ok' => true,
    'updated_at' => date('H:i:s T'),
    'is_released' => $isReleased,
    'show_scores' => $showScores,
    'progress' => [
        'total_submitted' => $progress['total_submitted'],
        'total_expected' => $progress['total_expected'],
        'total_pending' => $progress['total_pending'],
        'completion_rate' => $progress['completion_rate'],
    ],
    'criteria' => array_map(static fn($c) => [
        'id' => (int)$c['id'],
        'name' => $c['criterion_name'],
        'max_marks' => (float)$c['max_marks'],
    ], $criteria),
    'rows' => $rows,
]);
