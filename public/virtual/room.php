<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\ScoringEngine;
use App\Services\VideoConferenceService;

$user = Auth::requireAuth('../login.php');
$pdo = Database::getConnection();
$videoService = new VideoConferenceService($pdo);
$scoringEngine = new ScoringEngine($pdo);

$sessionId = (int)($_GET['session_id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT s.*, d.name AS department_name
     FROM interview_sessions s
     INNER JOIN departments d ON d.id = s.department_id
     WHERE s.id = :id AND s.interview_mode = 'virtual' LIMIT 1"
);
$stmt->execute(['id' => $sessionId]);
$session = $stmt->fetch();

if (!$session) {
    Response::flash('danger', 'Virtual interview session not found.');
    Response::redirect('dashboard.php');
}

if (!Auth::isAdmin() && !$scoringEngine->isPanelistAssignedToSession((int)$user['id'], $sessionId)) {
    Response::flash('danger', 'You are not assigned to this virtual interview panel.');
    Response::redirect('../panelist/dashboard.php');
}

$room = $videoService->getRoomBySession($sessionId)
    ?? $videoService->createOrUpdateRoom($sessionId, 'jitsi', null, true, (int)$user['id']);

$candidates = $pdo->prepare(
    'SELECT sc.*, c.application_number, c.full_name
     FROM session_candidates sc
     INNER JOIN candidates c ON c.id = sc.candidate_id
     WHERE sc.session_id = :sid ORDER BY sc.interview_order ASC'
);
$candidates->execute(['sid' => $sessionId]);
$candidates = $candidates->fetchAll();

$notes = $pdo->prepare(
    'SELECT n.*, u.full_name AS author_name
     FROM interview_notes n
     INNER JOIN users u ON u.id = n.author_id
     WHERE n.session_id = :sid ORDER BY n.created_at DESC LIMIT 25'
);
$notes->execute(['sid' => $sessionId]);
$notes = $notes->fetchAll();

$livekitToken = null;
if ($room['provider'] === 'livekit') {
    $livekitToken = $videoService->generateLiveKitToken(
        (string)$room['room_name'],
        'panelist-' . $user['id'],
        (string)$user['full_name'],
        true
    );
}

$pageTitle = 'Virtual Interview Room — ' . $session['title'];
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header py-2">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <span class="badge badge-mode-virtual me-2"><?= strtoupper($room['provider']) ?> WEBRTC ROOM</span>
            <strong class="text-dark"><?= e($session['title']) ?></strong>
            <span class="text-muted small ms-2">(<?= e($session['job_title']) ?>)</span>
        </div>
        <div class="d-flex align-items-center gap-3 small">
            <span id="connStatus" class="badge bg-warning text-dark"><i class="bi bi-wifi me-1"></i>Connecting...</span>
            <span class="fw-bold font-monospace bg-light border rounded px-2 py-1"><i class="bi bi-stopwatch me-1"></i><span id="meetingTimer">00:00:00</span></span>
            <span class="badge bg-<?= (int)$room['recording_enabled'] === 1 ? 'warning text-dark' : 'secondary' ?>">
                <i class="bi bi-record-circle me-1"></i><?= (int)$room['recording_enabled'] === 1 ? 'Recording Authorized' : 'Recording Disabled' ?>
            </span>
            <a href="dashboard.php?session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary">Exit Room</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <div class="row g-3">
        <!-- Main WebRTC Stage -->
        <div class="col-lg-8">
            <div class="video-stage mb-2" id="webrtcContainer">
                <div class="d-flex flex-column justify-content-center align-items-center h-100 p-4 text-center" style="min-height:480px;" id="videoPlaceholder">
                    <div class="spinner-border text-light mb-3" role="status"></div>
                    <h5>Initializing Secure <?= e(strtoupper($room['provider'])) ?> WebRTC Room...</h5>
                    <p class="small text-white-50">Room ID: <?= e($room['room_name']) ?></p>
                </div>
            </div>

            <!-- Unified Control Bar -->
            <div class="mys-card p-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-dark" id="btnToggleMic"><i class="bi bi-mic-fill me-1"></i>Mute / Unmute</button>
                    <button type="button" class="btn btn-sm btn-outline-dark" id="btnToggleCam"><i class="bi bi-camera-video-fill me-1"></i>Camera On / Off</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnShareScreen"><i class="bi bi-display me-1"></i>Share Screen</button>
                </div>
                <div class="small text-muted">
                    <i class="bi bi-shield-lock me-1"></i>Encrypted WebRTC &middot; Recording disabled unless authorized &amp; consented
                </div>
            </div>
        </div>

        <!-- Right Sidebar: Candidate Queue + Scoring Shortcut + Live Notes -->
        <div class="col-lg-4">
            <div class="mys-card mb-3">
                <div class="mys-card-header"><i class="bi bi-people me-2"></i>Participants &amp; Quick Assessment</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($candidates as $c): ?>
                        <?php $cid = (int)$c['candidate_id']; ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="small">
                                <div class="fw-semibold"><?= e($c['full_name']) ?></div>
                                <div class="text-muted"><?= e($c['application_number']) ?> &middot; <?= e(ucwords(str_replace('_', ' ', $c['interview_status']))) ?></div>
                            </div>
                            <div class="d-flex gap-1">
                                <?php if ((int)$c['waiting_room_admitted'] === 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success py-0" onclick="admitCandidate(<?= $cid ?>, this)">Admit</button>
                                <?php endif; ?>
                                <?php if ($scoringEngine->canPanelistScoreCandidate($sessionId, $cid, (int)$user['id'])): ?>
                                    <a href="../panelist/score.php?session_id=<?= $sessionId ?>&candidate_id=<?= $cid ?>" target="_blank" class="btn btn-sm btn-mys py-0">
                                        <i class="bi bi-pencil-square me-1"></i>Score
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mys-card">
                <div class="mys-card-header"><i class="bi bi-journal-text me-2"></i>Live Interview Notes</div>
                <div class="p-3">
                    <div class="mb-2">
                        <select id="noteCandidateId" class="form-select form-select-sm mb-2">
                            <option value="">General Session Note</option>
                            <?php foreach ($candidates as $c): ?>
                                <option value="<?= (int)$c['candidate_id'] ?>"><?= e($c['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea id="noteContent" class="form-control form-control-sm mb-2" rows="2" placeholder="Write private/session interview notes..."></textarea>
                        <button type="button" class="btn btn-sm btn-mys w-100" onclick="saveLiveNote()">Save Note</button>
                    </div>
                    <div id="notesFeed" style="max-height:220px; overflow-y:auto;">
                        <?php foreach ($notes as $n): ?>
                            <div class="border-bottom pb-2 mb-2 small">
                                <div class="fw-semibold"><?= e($n['author_name']) ?></div>
                                <div><?= nl2br(e($n['content'])) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= e($n['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($room['provider'] === 'jitsi'): ?>
<script src="https://<?= e($room['server_domain'] ?: 'meet.jit.si') ?>/external_api.js"></script>
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.5.4/dist/livekit-client.umd.min.js"></script>
<?php endif; ?>

<script>
const ROOM_CONFIG = {
    sessionId: <?= $sessionId ?>,
    provider: <?= json_encode($room['provider']) ?>,
    domain: <?= json_encode($room['server_domain'] ?: 'meet.jit.si') ?>,
    roomName: <?= json_encode($room['room_name']) ?>,
    displayName: <?= json_encode($user['full_name'] . ' (Panel)') ?>,
    email: <?= json_encode($user['email']) ?>,
    recordingEnabled: <?= (int)$room['recording_enabled'] === 1 ? 'true' : 'false' ?>,
    livekitToken: <?= json_encode($livekitToken) ?>
};

// Meeting Timer
let seconds = 0;
setInterval(() => {
    seconds++;
    const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
    const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const s = String(seconds % 60).padStart(2, '0');
    document.getElementById('meetingTimer').textContent = `${h}:${m}:${s}`;
}, 1000);

function setConnStatus(label, cls) {
    const el = document.getElementById('connStatus');
    el.className = 'badge ' + cls;
    el.innerHTML = '<i class="bi bi-wifi me-1"></i>' + label;
}

let jitsiApi = null;
let lkRoom = null;

async function initRoom() {
    const container = document.getElementById('webrtcContainer');
    const placeholder = document.getElementById('videoPlaceholder');

    if (ROOM_CONFIG.provider === 'jitsi' && typeof JitsiMeetExternalAPI !== 'undefined') {
        placeholder.remove();
        jitsiApi = new JitsiMeetExternalAPI(ROOM_CONFIG.domain, {
            roomName: ROOM_CONFIG.roomName,
            width: '100%',
            height: 500,
            parentNode: container,
            userInfo: { displayName: ROOM_CONFIG.displayName, email: ROOM_CONFIG.email },
            configOverwrite: {
                prejoinPageEnabled: true,
                disableDeepLinking: true,
                fileRecordingsEnabled: ROOM_CONFIG.recordingEnabled,
                localRecording: { enabled: ROOM_CONFIG.recordingEnabled }
            }
        });
        jitsiApi.addListener('videoConferenceJoined', () => setConnStatus('Connected (Jitsi)', 'bg-success'));
        jitsiApi.addListener('videoConferenceLeft', () => setConnStatus('Disconnected', 'bg-secondary'));

        document.getElementById('btnToggleMic').onclick = () => jitsiApi.executeCommand('toggleAudio');
        document.getElementById('btnToggleCam').onclick = () => jitsiApi.executeCommand('toggleVideo');
        document.getElementById('btnShareScreen').onclick = () => jitsiApi.executeCommand('toggleShareScreen');
    } else if (ROOM_CONFIG.provider === 'livekit' && typeof LivekitClient !== 'undefined' && ROOM_CONFIG.livekitToken) {
        try {
            lkRoom = new LivekitClient.Room();
            await lkRoom.connect(ROOM_CONFIG.domain, ROOM_CONFIG.livekitToken);
            await lkRoom.localParticipant.enableCameraAndMicrophone();
            placeholder.innerHTML = '<div id="lkGrid" class="d-flex flex-wrap gap-2 justify-content-center p-2"></div>';
            setConnStatus('Connected (LiveKit)', 'bg-success');

            const attachTrack = (track) => {
                const el = track.attach();
                el.style.maxWidth = '360px';
                el.style.borderRadius = '8px';
                document.getElementById('lkGrid').appendChild(el);
            };
            lkRoom.localParticipant.videoTrackPublications.forEach(p => p.track && attachTrack(p.track));
            lkRoom.on(LivekitClient.RoomEvent.TrackSubscribed, (track) => attachTrack(track));

            document.getElementById('btnToggleMic').onclick = () => lkRoom.localParticipant.setMicrophoneEnabled(!lkRoom.localParticipant.isMicrophoneEnabled);
            document.getElementById('btnToggleCam').onclick = () => lkRoom.localParticipant.setCameraEnabled(!lkRoom.localParticipant.isCameraEnabled);
            document.getElementById('btnShareScreen').onclick = () => lkRoom.localParticipant.setScreenShareEnabled(!lkRoom.localParticipant.isScreenShareEnabled);
        } catch (err) {
            setConnStatus('Connection Failed', 'bg-danger');
            placeholder.innerHTML = `<div class="p-4 text-warning">LiveKit connection error: ${err.message}</div>`;
        }
    } else {
        setConnStatus('Provider Config Needed', 'bg-danger');
        placeholder.innerHTML = '<div class="p-4 text-warning">Unable to load WebRTC SDK. Check internet connectivity or configure LIVEKIT_API_KEY / LIVEKIT_API_SECRET in .env.</div>';
    }
}

async function admitCandidate(candidateId, btn) {
    try {
        await MYS.postJson('../api/virtual.php', {
            action: 'admit_candidate',
            session_id: ROOM_CONFIG.sessionId,
            candidate_id: candidateId
        });
        btn.replaceWith(Object.assign(document.createElement('span'), { className: 'badge bg-success', textContent: 'Admitted' }));
    } catch (e) {
        alert(e.message);
    }
}

async function saveLiveNote() {
    const content = document.getElementById('noteContent').value.trim();
    const candidateId = document.getElementById('noteCandidateId').value;
    if (!content) return;
    try {
        const res = await MYS.postJson('../api/notes.php', {
            session_id: ROOM_CONFIG.sessionId,
            candidate_id: candidateId || null,
            content
        });
        document.getElementById('noteContent').value = '';
        const div = document.createElement('div');
        div.className = 'border-bottom pb-2 mb-2 small';
        div.innerHTML = `<div class="fw-semibold">${res.note.author_name}</div><div>${res.note.content}</div><div class="text-muted" style="font-size:0.72rem;">${res.note.created_at}</div>`;
        document.getElementById('notesFeed').prepend(div);
    } catch (e) {
        alert(e.message);
    }
}

document.addEventListener('DOMContentLoaded', initRoom);
</script>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
