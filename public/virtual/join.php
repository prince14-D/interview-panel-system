<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Database;
use App\Services\VideoConferenceService;

$token = trim((string)($_GET['token'] ?? ''));
$pdo = Database::getConnection();
$videoService = new VideoConferenceService($pdo);

$stmt = $pdo->prepare(
    "SELECT sc.*, c.full_name, c.application_number,
            s.title AS session_title, s.job_title, s.interview_date, s.start_time, s.end_time,
            d.name AS department_name,
            vr.provider, vr.server_domain, vr.room_name, vr.waiting_room_enabled, vr.recording_enabled, vr.meeting_status
     FROM session_candidates sc
     INNER JOIN candidates c ON c.id = sc.candidate_id
     INNER JOIN interview_sessions s ON s.id = sc.session_id
     INNER JOIN departments d ON d.id = s.department_id
     INNER JOIN video_interview_rooms vr ON vr.session_id = s.id
     WHERE sc.virtual_invite_token = :tok AND s.interview_mode = 'virtual'
     LIMIT 1"
);
$stmt->execute(['tok' => $token]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:40px;text-align:center;"><h3>Invalid or Expired Interview Invitation Link</h3><p>Please contact the Ministry of Youth and Sports Human Resources Office.</p></body></html>';
    exit;
}

// Mark candidate as in waiting room if not yet admitted
if ($invite['interview_status'] === 'scheduled') {
    $pdo->prepare("UPDATE session_candidates SET interview_status = 'waiting_room' WHERE id = :id")
        ->execute(['id' => $invite['id']]);
    $invite['interview_status'] = 'waiting_room';
}

// Handle candidate consent submission when recording is authorized
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['give_consent'])) {
    $pdo->prepare('UPDATE session_candidates SET recording_consent_given = 1 WHERE id = :id')
        ->execute(['id' => $invite['id']]);
    $invite['recording_consent_given'] = 1;
}

$canEnter = ((int)$invite['waiting_room_enabled'] === 0 || (int)$invite['waiting_room_admitted'] === 1)
    && ((int)$invite['recording_enabled'] === 0 || (int)$invite['recording_consent_given'] === 1);

$livekitToken = null;
if ($canEnter && $invite['provider'] === 'livekit') {
    $livekitToken = $videoService->generateLiveKitToken(
        (string)$invite['room_name'],
        'candidate-' . $invite['candidate_id'],
        (string)$invite['full_name'] . ' (Candidate)',
        false
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Virtual Interview — Ministry of Youth &amp; Sports, Liberia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar mys-navbar">
    <div class="container">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <img src="../assets/img/mys-emblem.svg" alt="MYS" width="32" height="32">
            <span>Ministry of Youth &amp; Sports — Virtual Interview Portal</span>
        </span>
    </div>
</nav>

<div class="container py-4">
    <div class="mys-card p-3 mb-3">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="small text-uppercase fw-bold text-primary"><?= e($invite['department_name']) ?></div>
                <h5 class="fw-bold mb-1"><?= e($invite['session_title']) ?> — <?= e($invite['job_title']) ?></h5>
                <div class="small text-muted">
                    Candidate: <strong><?= e($invite['full_name']) ?></strong> (<?= e($invite['application_number']) ?>) &middot; Scheduled: <?= e($invite['interview_date']) ?> at <?= e($invite['scheduled_time'] ? substr($invite['scheduled_time'], 0, 5) : substr($invite['start_time'], 0, 5)) ?>
                </div>
            </div>
            <div class="col-md-4 text-md-end mt-2 mt-md-0">
                <span class="badge bg-<?= $canEnter ? 'success' : 'warning text-dark' ?>">
                    <?= $canEnter ? 'Admitted — Ready to Join' : 'Waiting Room Active' ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ((int)$invite['recording_enabled'] === 1 && (int)$invite['recording_consent_given'] === 0): ?>
        <div class="mys-card p-4 mb-3 border-warning">
            <h6 class="fw-bold text-danger"><i class="bi bi-record-circle me-1"></i>Recording Consent Required</h6>
            <p class="small">The Ministry Administrator has authorized recording for this virtual recruitment interview. Before joining the room, please confirm your consent.</p>
            <form method="post">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="consentCheck" required>
                    <label class="form-check-label small" for="consentCheck">I acknowledge and consent to audio/video recording of this recruitment interview for official Ministry evaluation purposes.</label>
                </div>
                <button type="submit" name="give_consent" value="1" class="btn btn-mys btn-sm">Confirm Consent &amp; Continue</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!$canEnter): ?>
        <div class="mys-card p-5 text-center">
            <div class="spinner-grow text-primary mb-3" role="status"></div>
            <h4>You Are in the Virtual Waiting Room</h4>
            <p class="text-muted">Please keep this page open. The Ministry Interview Panel will admit you shortly.</p>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="location.reload()">Refresh Status</button>
        </div>
        <script>setTimeout(() => location.reload(), 10000);</script>
    <?php else: ?>
        <div class="video-stage" id="candidateVideoStage" style="min-height:520px;"></div>
        <?php if ($invite['provider'] === 'jitsi'): ?>
            <script src="https://<?= e($invite['server_domain'] ?: 'meet.jit.si') ?>/external_api.js"></script>
            <script>
                new JitsiMeetExternalAPI(<?= json_encode($invite['server_domain'] ?: 'meet.jit.si') ?>, {
                    roomName: <?= json_encode($invite['room_name']) ?>,
                    width: '100%',
                    height: 520,
                    parentNode: document.getElementById('candidateVideoStage'),
                    userInfo: { displayName: <?= json_encode($invite['full_name'] . ' (Candidate)') ?> },
                    configOverwrite: {
                        prejoinPageEnabled: true,
                        fileRecordingsEnabled: <?= (int)$invite['recording_enabled'] === 1 ? 'true' : 'false' ?>
                    }
                });
            </script>
        <?php elseif ($invite['provider'] === 'livekit' && $livekitToken): ?>
            <script src="https://cdn.jsdelivr.net/npm/livekit-client@2.5.4/dist/livekit-client.umd.min.js"></script>
            <script>
                (async () => {
                    const room = new LivekitClient.Room();
                    await room.connect(<?= json_encode($invite['server_domain']) ?>, <?= json_encode($livekitToken) ?>);
                    await room.localParticipant.enableCameraAndMicrophone();
                    const stage = document.getElementById('candidateVideoStage');
                    const attach = (track) => { const el = track.attach(); el.style.maxWidth = '380px'; stage.appendChild(el); };
                    room.localParticipant.videoTrackPublications.forEach(p => p.track && attach(p.track));
                    room.on(LivekitClient.RoomEvent.TrackSubscribed, attach);
                })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
