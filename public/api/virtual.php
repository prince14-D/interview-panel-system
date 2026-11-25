<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\VideoConferenceService;

$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

Auth::requireCsrf();
$payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$pdo = Database::getConnection();
$videoService = new VideoConferenceService($pdo);

try {
    $action = (string)($payload['action'] ?? '');
    $sessionId = (int)($payload['session_id'] ?? 0);

    if ($action === 'admit_candidate') {
        $videoService->admitCandidateFromWaitingRoom($sessionId, (int)$payload['candidate_id'], (int)$user['id']);
    } elseif ($action === 'meeting_status') {
        $videoService->updateMeetingStatus($sessionId, (string)$payload['status'], (int)$user['id']);
    } elseif ($action === 'recording_auth') {
        if (!Auth::isAdmin()) {
            Response::json(['ok' => false, 'error' => 'Administrator authorization required.'], 403);
        }
        $videoService->setRecordingAuthorization($sessionId, !empty($payload['enable']), (int)$user['id']);
    } else {
        Response::json(['ok' => false, 'error' => 'Unknown virtual room action'], 400);
    }

    Response::json(['ok' => true]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
}
