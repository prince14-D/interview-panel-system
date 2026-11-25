<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use PDO;
use RuntimeException;

/**
 * Attendance & Session Flow Service.
 * Powers physical interview arrival tracking, interview start/completion, and panelist attendance registers.
 */
final class AttendanceService
{
    public const VALID_STATUSES = ['Present', 'Absent', 'Late', 'Rescheduled'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordCandidateAttendance(
        int $sessionId,
        int $candidateId,
        string $status,
        ?string $arrivalTime,
        ?string $remarks,
        int $recordedBy
    ): void {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new RuntimeException('Invalid attendance status.');
        }

        $scStmt = $this->pdo->prepare(
            'SELECT scheduled_time FROM session_candidates WHERE session_id = :sid AND candidate_id = :cid LIMIT 1'
        );
        $scStmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        $sc = $scStmt->fetch();
        if (!$sc) {
            throw new RuntimeException('Candidate is not registered for this interview session.');
        }

        $effectiveArrival = $arrivalTime;
        if (($status === 'Present' || $status === 'Late') && ($effectiveArrival === null || $effectiveArrival === '')) {
            $effectiveArrival = date('Y-m-d H:i:s');
        } elseif ($status === 'Absent' || $status === 'Rescheduled') {
            $effectiveArrival = null;
        }

        $exists = $this->pdo->prepare(
            'SELECT id FROM candidate_attendance WHERE session_id = :sid AND candidate_id = :cid LIMIT 1'
        );
        $exists->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        $row = $exists->fetch();

        if ($row) {
            $upd = $this->pdo->prepare(
                'UPDATE candidate_attendance
                 SET attendance_status = :status,
                     actual_arrival_time = :arrival,
                     remarks = :remarks,
                     recorded_by = :rec_by,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute([
                'status' => $status,
                'arrival' => $effectiveArrival,
                'remarks' => $remarks !== null ? trim($remarks) : null,
                'rec_by' => $recordedBy,
                'id' => $row['id'],
            ]);
        } else {
            $ins = $this->pdo->prepare(
                'INSERT INTO candidate_attendance
                    (session_id, candidate_id, scheduled_time, actual_arrival_time, attendance_status, remarks, recorded_by)
                 VALUES (:sid, :cid, :sched, :arrival, :status, :remarks, :rec_by)'
            );
            $ins->execute([
                'sid' => $sessionId,
                'cid' => $candidateId,
                'sched' => $sc['scheduled_time'],
                'arrival' => $effectiveArrival,
                'status' => $status,
                'remarks' => $remarks !== null ? trim($remarks) : null,
                'rec_by' => $recordedBy,
            ]);
        }

        // Sync session_candidates interview_status when appropriate
        $newInterviewStatus = match ($status) {
            'Present', 'Late' => 'checked_in',
            'Absent' => 'absent',
            'Rescheduled' => 'rescheduled',
        };

        $sync = $this->pdo->prepare(
            "UPDATE session_candidates
             SET interview_status = CASE
                    WHEN interview_status IN ('in_progress', 'completed') AND :status_in IN ('Present', 'Late')
                    THEN interview_status
                    ELSE :new_status
                 END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE session_id = :sid AND candidate_id = :cid"
        );
        $sync->execute([
            'status_in' => $status,
            'new_status' => $newInterviewStatus,
            'sid' => $sessionId,
            'cid' => $candidateId,
        ]);

        AuditLogger::log(
            $this->pdo,
            $recordedBy,
            'candidate_attendance_updated',
            'candidate_attendance',
            $candidateId,
            "Session #{$sessionId}, Candidate #{$candidateId}: {$status}"
        );
    }

    /**
     * One-click physical interview action: Mark candidate present (if not already) and begin interview.
     */
    public function beginCandidateInterview(int $sessionId, int $candidateId, int $actorId): void
    {
        $attStmt = $this->pdo->prepare(
            'SELECT attendance_status, actual_arrival_time, remarks
             FROM candidate_attendance WHERE session_id = :sid AND candidate_id = :cid LIMIT 1'
        );
        $attStmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        $att = $attStmt->fetch();

        if (!$att || !in_array($att['attendance_status'], ['Present', 'Late'], true)) {
            $this->recordCandidateAttendance($sessionId, $candidateId, 'Present', date('Y-m-d H:i:s'), $att['remarks'] ?? null, $actorId);
        }

        $upd = $this->pdo->prepare(
            "UPDATE session_candidates
             SET interview_status = 'in_progress',
                 interview_started_at = COALESCE(interview_started_at, CURRENT_TIMESTAMP),
                 updated_at = CURRENT_TIMESTAMP
             WHERE session_id = :sid AND candidate_id = :cid"
        );
        $upd->execute(['sid' => $sessionId, 'cid' => $candidateId]);

        $this->pdo->prepare(
            "UPDATE interview_sessions SET status = 'in_progress' WHERE id = :sid AND status = 'scheduled'"
        )->execute(['sid' => $sessionId]);

        AuditLogger::log($this->pdo, $actorId, 'interview_started', 'session_candidate', $candidateId, "Session #{$sessionId} interview started");
    }

    public function completeCandidateInterview(int $sessionId, int $candidateId, int $actorId): void
    {
        $upd = $this->pdo->prepare(
            "UPDATE session_candidates
             SET interview_status = 'completed',
                 interview_ended_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE session_id = :sid AND candidate_id = :cid"
        );
        $upd->execute(['sid' => $sessionId, 'cid' => $candidateId]);

        AuditLogger::log($this->pdo, $actorId, 'interview_completed', 'session_candidate', $candidateId, "Session #{$sessionId} interview completed");
    }

    public function recordPanelistAttendance(
        int $sessionId,
        int $panelistId,
        string $status,
        ?string $remarks,
        int $recordedBy
    ): void {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new RuntimeException('Invalid panelist attendance status.');
        }

        $checkIn = in_array($status, ['Present', 'Late'], true) ? date('Y-m-d H:i:s') : null;

        $exists = $this->pdo->prepare(
            'SELECT id FROM panelist_attendance WHERE session_id = :sid AND panelist_id = :pid LIMIT 1'
        );
        $exists->execute(['sid' => $sessionId, 'pid' => $panelistId]);
        $row = $exists->fetch();

        if ($row) {
            $upd = $this->pdo->prepare(
                'UPDATE panelist_attendance
                 SET attendance_status = :status,
                     check_in_time = COALESCE(check_in_time, :checkin),
                     remarks = :remarks,
                     recorded_by = :rec_by,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute([
                'status' => $status,
                'checkin' => $checkIn,
                'remarks' => $remarks !== null ? trim($remarks) : null,
                'rec_by' => $recordedBy,
                'id' => $row['id'],
            ]);
        } else {
            $ins = $this->pdo->prepare(
                'INSERT INTO panelist_attendance (session_id, panelist_id, attendance_status, check_in_time, remarks, recorded_by)
                 VALUES (:sid, :pid, :status, :checkin, :remarks, :rec_by)'
            );
            $ins->execute([
                'sid' => $sessionId,
                'pid' => $panelistId,
                'status' => $status,
                'checkin' => $checkIn,
                'remarks' => $remarks !== null ? trim($remarks) : null,
                'rec_by' => $recordedBy,
            ]);
        }

        AuditLogger::log(
            $this->pdo,
            $recordedBy,
            'panelist_attendance_updated',
            'panelist_attendance',
            $panelistId,
            "Session #{$sessionId}, Panelist #{$panelistId}: {$status}"
        );
    }

    public function getCandidateAttendanceRegister(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sc.candidate_id, sc.scheduled_time, sc.interview_order, sc.interview_status,
                    sc.interview_started_at, sc.interview_ended_at,
                    c.application_number, c.full_name, c.phone, c.email,
                    COALESCE(ca.attendance_status, 'Absent') AS attendance_status,
                    ca.actual_arrival_time, ca.remarks,
                    u.full_name AS recorded_by_name
             FROM session_candidates sc
             INNER JOIN candidates c ON c.id = sc.candidate_id
             LEFT JOIN candidate_attendance ca ON ca.session_id = sc.session_id AND ca.candidate_id = sc.candidate_id
             LEFT JOIN users u ON u.id = ca.recorded_by
             WHERE sc.session_id = :sid
             ORDER BY sc.interview_order ASC, sc.scheduled_time ASC, c.full_name ASC"
        );
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
    }

    public function getPanelistAttendanceRegister(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sp.panelist_id, sp.panel_role, u.full_name, u.email, u.job_title, u.phone,
                    COALESCE(pa.attendance_status, 'Absent') AS attendance_status,
                    pa.check_in_time, pa.remarks
             FROM session_panelists sp
             INNER JOIN users u ON u.id = sp.panelist_id
             LEFT JOIN panelist_attendance pa ON pa.session_id = sp.session_id AND pa.panelist_id = sp.panelist_id
             WHERE sp.session_id = :sid
             ORDER BY CASE sp.panel_role WHEN 'chairperson' THEN 1 ELSE 2 END, u.full_name ASC"
        );
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
    }
}
