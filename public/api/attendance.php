<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AttendanceService;

$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

Auth::requireCsrf();
$payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$pdo = Database::getConnection();
$service = new AttendanceService($pdo);

try {
    $action = (string)($payload['action'] ?? '');
    $sessionId = (int)($payload['session_id'] ?? 0);

    if ($action === 'candidate_attendance') {
        $service->recordCandidateAttendance(
            $sessionId,
            (int)$payload['candidate_id'],
            (string)$payload['status'],
            $payload['arrival_time'] ?? null,
            $payload['remarks'] ?? null,
            (int)$user['id']
        );
    } elseif ($action === 'begin_interview') {
        $service->beginCandidateInterview($sessionId, (int)$payload['candidate_id'], (int)$user['id']);
    } elseif ($action === 'complete_interview') {
        $service->completeCandidateInterview($sessionId, (int)$payload['candidate_id'], (int)$user['id']);
    } elseif ($action === 'panelist_attendance') {
        $pid = Auth::isAdmin() ? (int)($payload['panelist_id'] ?? $user['id']) : (int)$user['id'];
        $service->recordPanelistAttendance(
            $sessionId,
            $pid,
            (string)$payload['status'],
            $payload['remarks'] ?? null,
            (int)$user['id']
        );
    } else {
        Response::json(['ok' => false, 'error' => 'Unknown attendance action'], 400);
    }

    Response::json(['ok' => true]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
}
