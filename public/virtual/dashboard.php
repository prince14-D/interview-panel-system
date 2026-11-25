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

// Fetch virtual sessions only
if (Auth::isAdmin()) {
    $sessions = $pdo->query(
        "SELECT s.*, d.name AS department_name, vr.provider, vr.server_domain, vr.room_name,
                vr.waiting_room_enabled, vr.recording_enabled, vr.meeting_status, vr.started_at, vr.ended_at
         FROM interview_sessions s
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN video_interview_rooms vr ON vr.session_id = s.id
         WHERE s.interview_mode = 'virtual'
         ORDER BY s.interview_date DESC, s.start_time ASC"
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT s.*, d.name AS department_name, vr.provider, vr.server_domain, vr.room_name,
                vr.waiting_room_enabled, vr.recording_enabled, vr.meeting_status, vr.started_at, vr.ended_at
         FROM interview_sessions s
         INNER JOIN session_panelists sp ON sp.session_id = s.id AND sp.panelist_id = :pid
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN video_interview_rooms vr ON vr.session_id = s.id
         WHERE s.interview_mode = 'virtual'
         ORDER BY s.interview_date DESC, s.start_time ASC"
    );
    $stmt->execute(['pid' => (int)$user['id']]);
    $sessions = $stmt->fetchAll();
}

$requestedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$currentSession = null;
foreach ($sessions as $s) {
    if ($requestedId > 0 && (int)$s['id'] === $requestedId) {
        $currentSession = $s;
        break;
    }
}
if (!$currentSession && !empty($sessions)) {
    $currentSession = $sessions[0];
}

// Handle POST virtual room actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentSession) {
    Auth::requireCsrf();
    $sid = (int)$currentSession['id'];
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'meeting_status') {
            $videoService->updateMeetingStatus($sid, (string)$_POST['meeting_status'], (int)$user['id']);
            Response::flash('success', 'Virtual meeting status updated.');
        } elseif ($action === 'admit_candidate') {
            $videoService->admitCandidateFromWaitingRoom($sid, (int)$_POST['candidate_id'], (int)$user['id']);
            Response::flash('success', 'Candidate admitted from waiting room.');
        } elseif ($action === 'toggle_recording' && Auth::isAdmin()) {
            $enable = ($_POST['enable'] ?? '0') === '1';
            $videoService->setRecordingAuthorization($sid, $enable, (int)$user['id']);
            Response::flash($enable ? 'warning' : 'info', $enable
                ? 'Recording authorized by Administrator. Candidate consent is still strictly required before recording.'
                : 'Video recording disabled.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', $e->getMessage());
    }
    Response::redirect('dashboard.php?session_id=' . $sid);
}

$candidates = [];
$panelists = [];
$progress = null;
if ($currentSession) {
    $sid = (int)$currentSession['id'];
    // Ensure room exists
    if (empty($currentSession['room_name'])) {
        $room = $videoService->createOrUpdateRoom($sid, 'jitsi', null, true, (int)$user['id']);
        $currentSession = array_merge($currentSession, $room);
    }

    $cStmt = $pdo->prepare(
        'SELECT sc.*, c.application_number, c.full_name, c.email, c.phone
         FROM session_candidates sc
         INNER JOIN candidates c ON c.id = sc.candidate_id
         WHERE sc.session_id = :sid
         ORDER BY sc.interview_order ASC, sc.scheduled_time ASC'
    );
    $cStmt->execute(['sid' => $sid]);
    $candidates = $cStmt->fetchAll();

    foreach ($candidates as &$cand) {
        if (empty($cand['virtual_invite_token'])) {
            $cand['virtual_invite_token'] = $videoService->ensureCandidateInviteToken($sid, (int)$cand['candidate_id']);
        }
    }
    unset($cand);

    $pStmt = $pdo->prepare(
        'SELECT sp.*, u.full_name, u.email, u.job_title
         FROM session_panelists sp
         INNER JOIN users u ON u.id = sp.panelist_id
         WHERE sp.session_id = :sid ORDER BY u.full_name ASC'
    );
    $pStmt->execute(['sid' => $sid]);
    $panelists = $pStmt->fetchAll();

    $progress = $scoringEngine->getSessionSubmissionProgress($sid);
}

$pageTitle = 'Virtual Interview Dashboard';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <span class="badge badge-mode-virtual mb-1"><i class="bi bi-camera-video me-1"></i>REMOTE / VIRTUAL WEBRTC OPERATIONS</span>
            <h1 class="page-title">Virtual Interview Dashboard</h1>
            <p class="page-subtitle">Manage secure Jitsi/LiveKit WebRTC rooms, candidate invitation links, waiting room admissions, and live panel scoring.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($sessions)): ?>
                <form method="get">
                    <select name="session_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ($currentSession && (int)$currentSession['id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= e($s['interview_date']) ?> — <?= e($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <?php if (Auth::isAdmin()): ?>
                <a href="../admin/session_form.php?mode=virtual" class="btn btn-sm btn-mys"><i class="bi bi-plus-lg me-1"></i>New Virtual Session</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
<?php if (!$currentSession): ?>
    <div class="mys-card p-5 text-center">
        <i class="bi bi-camera-video-off fs-1 text-muted d-block mb-2"></i>
        <h5>No Virtual Interview Sessions Scheduled</h5>
        <p class="text-muted">Create a virtual interview session to launch secure WebRTC rooms with Jitsi Meet or LiveKit.</p>
        <?php if (Auth::isAdmin()): ?>
            <a href="../admin/session_form.php?mode=virtual" class="btn btn-mys">Create Virtual Interview Session</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php
        $sid = (int)$currentSession['id'];
        $panelistRoomUrl = Response::baseUrl('virtual/room.php?session_id=' . $sid);
    ?>
    <!-- Virtual Room Control Banner -->
    <div class="mys-card p-3 mb-4" style="border-left:5px solid #5B21B6;">
        <div class="row g-3 align-items-center">
            <div class="col-lg-7">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <span class="badge bg-<?= $currentSession['meeting_status'] === 'live' ? 'success' : ($currentSession['meeting_status'] === 'ended' ? 'secondary' : 'primary') ?>">
                        Meeting: <?= e(strtoupper((string)$currentSession['meeting_status'])) ?>
                    </span>
                    <span class="badge bg-light text-dark border">Provider: <?= e(strtoupper((string)$currentSession['provider'])) ?> WebRTC</span>
                    <span class="badge bg-light text-dark border">Room: <?= e((string)$currentSession['room_name']) ?></span>
                    <span class="badge bg-<?= (int)$currentSession['recording_enabled'] === 1 ? 'warning text-dark' : 'secondary-subtle text-dark border' ?>">
                        Recording: <?= (int)$currentSession['recording_enabled'] === 1 ? 'Authorized (Consent Required)' : 'Disabled (Default)' ?>
                    </span>
                </div>
                <h5 class="fw-bold mb-1"><?= e($currentSession['title']) ?> — <?= e($currentSession['job_title']) ?></h5>
                <div class="small text-muted">
                    <?= e($currentSession['department_name']) ?> &middot; <?= e($currentSession['interview_date']) ?> (<?= e(substr($currentSession['start_time'], 0, 5)) ?> - <?= e(substr($currentSession['end_time'], 0, 5)) ?>)
                </div>
            </div>
            <div class="col-lg-5 text-lg-end d-flex flex-wrap justify-content-lg-end gap-2">
                <a href="room.php?session_id=<?= $sid ?>" class="btn btn-mys">
                    <i class="bi bi-camera-video-fill me-1"></i>Join Panelist Room
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="MYS.copyText('<?= e($panelistRoomUrl) ?>', this)">
                    <i class="bi bi-link-45deg me-1"></i>Copy Panelist Link
                </button>
                <?php if (Auth::isAdmin()): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="toggle_recording">
                        <input type="hidden" name="enable" value="<?= (int)$currentSession['recording_enabled'] === 1 ? '0' : '1' ?>">
                        <button class="btn btn-sm btn-outline-<?= (int)$currentSession['recording_enabled'] === 1 ? 'danger' : 'secondary' ?>" onclick="return confirm('Change recording authorization policy for this virtual session?');">
                            <i class="bi bi-record-circle me-1"></i><?= (int)$currentSession['recording_enabled'] === 1 ? 'Disable Recording' : 'Authorize Recording' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Candidate Links, Waiting Room & Assessment Shortcuts -->
        <div class="col-lg-8">
            <div class="mys-card mb-4">
                <div class="mys-card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Virtual Candidates &amp; Invitation Links</span>
                    <span class="small text-muted">Waiting Room: <?= (int)$currentSession['waiting_room_enabled'] === 1 ? 'Enabled' : 'Open' ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Candidate</th>
                                <th>Participant Status</th>
                                <th>Candidate Invitation Link</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($candidates)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No candidates registered for this virtual session.</td></tr>
                        <?php else: ?>
                            <?php foreach ($candidates as $c): ?>
                                <?php
                                    $cid = (int)$c['candidate_id'];
                                    $inviteUrl = Response::baseUrl('virtual/join.php?token=' . urlencode((string)$c['virtual_invite_token']));
                                    $canScore = $scoringEngine->canPanelistScoreCandidate($sid, $cid, (int)$user['id']);
                                ?>
                                <tr>
                                    <td class="small fw-semibold"><?= e($c['scheduled_time'] ? substr($c['scheduled_time'], 0, 5) : 'TBD') ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= e($c['full_name']) ?></div>
                                        <div class="small text-muted"><?= e($c['application_number']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $c['interview_status'] === 'in_progress' ? 'success' : ($c['interview_status'] === 'waiting_room' ? 'warning text-dark' : 'secondary') ?>">
                                            <?= e(ucwords(str_replace('_', ' ', $c['interview_status']))) ?>
                                        </span>
                                        <?php if ((int)$c['waiting_room_admitted'] === 1): ?>
                                            <div class="small text-success">Admitted</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm" style="max-width:240px;">
                                            <input type="text" class="form-control form-control-sm" value="<?= e($inviteUrl) ?>" readonly>
                                            <button type="button" class="btn btn-outline-secondary" onclick="MYS.copyText('<?= e($inviteUrl) ?>', this)">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ((int)$c['waiting_room_admitted'] === 0): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                                <input type="hidden" name="action" value="admit_candidate">
                                                <input type="hidden" name="candidate_id" value="<?= $cid ?>">
                                                <button class="btn btn-sm btn-outline-success"><i class="bi bi-door-open me-1"></i>Admit</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canScore): ?>
                                            <a href="../panelist/score.php?session_id=<?= $sid ?>&candidate_id=<?= $cid ?>" class="btn btn-sm btn-mys ms-1">
                                                <i class="bi bi-pencil-square me-1"></i>Assess
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column: Meeting Controls & Assigned Panelists -->
        <div class="col-lg-4">
            <div class="mys-card mb-4 p-3">
                <h6 class="fw-bold mb-2"><i class="bi bi-broadcast me-1"></i>Meeting Status &amp; Controls</h6>
                <p class="small text-muted mb-2">Update room state and verify your camera &amp; microphone before entering.</p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach (['waiting' => 'Open Waiting Room', 'live' => 'Start Live Meeting', 'ended' => 'End Meeting'] as $stKey => $stLabel): ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="action" value="meeting_status">
                            <input type="hidden" name="meeting_status" value="<?= $stKey ?>">
                            <button class="btn btn-sm <?= $currentSession['meeting_status'] === $stKey ? 'btn-mys' : 'btn-outline-secondary' ?>"><?= e($stLabel) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <div class="border rounded p-2 bg-light small">
                    <div class="fw-semibold mb-1"><i class="bi bi-mic me-1"></i>Pre-Join Device Check</div>
                    <div id="deviceStatus" class="text-muted">Click below to test browser camera &amp; microphone permissions.</div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="checkMediaDevices()">Test Camera &amp; Mic</button>
                </div>
            </div>

            <div class="mys-card">
                <div class="mys-card-header"><i class="bi bi-person-badge me-2"></i>Assigned Panelists (<?= count($panelists) ?>)</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($panelists as $p): ?>
                        <div class="list-group-item small d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold"><?= e($p['full_name']) ?></div>
                                <div class="text-muted"><?= e($p['email']) ?></div>
                            </div>
                            <span class="badge bg-light text-dark border"><?= e(ucwords(str_replace('_', ' ', $p['panel_role']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
async function checkMediaDevices() {
    const out = document.getElementById('deviceStatus');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        out.innerHTML = '<span class="text-danger">WebRTC media access requires HTTPS (or localhost).</span>';
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
        stream.getTracks().forEach(t => t.stop());
        out.innerHTML = '<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Camera & Microphone ready!</span>';
    } catch (err) {
        out.innerHTML = '<span class="text-danger">Permission denied or device unavailable: ' + err.message + '</span>';
    }
}
</script>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
