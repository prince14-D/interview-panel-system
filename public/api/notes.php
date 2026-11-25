<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

Auth::requireCsrf();
$payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$sessionId = (int)($payload['session_id'] ?? 0);
$candidateId = !empty($payload['candidate_id']) ? (int)$payload['candidate_id'] : null;
$content = trim((string)($payload['content'] ?? ''));

if ($sessionId <= 0 || $content === '') {
    Response::json(['ok' => false, 'error' => 'Session and note content are required.'], 422);
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'INSERT INTO interview_notes (session_id, candidate_id, author_id, note_type, content)
     VALUES (:sid, :cid, :uid, :ntype, :content)'
);
$stmt->execute([
    'sid' => $sessionId,
    'cid' => $candidateId,
    'uid' => (int)$user['id'],
    'ntype' => $candidateId ? 'candidate' : 'general',
    'content' => $content,
]);

Response::json([
    'ok' => true,
    'note' => [
        'id' => (int)$pdo->lastInsertId(),
        'author_name' => $user['full_name'],
        'content' => $content,
        'created_at' => date('Y-m-d H:i:s'),
    ],
]);
