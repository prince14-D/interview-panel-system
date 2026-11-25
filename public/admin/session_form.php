<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\ScoringEngine;
use App\Services\VideoConferenceService;

$user = Auth::requireRole(['admin', 'hr_officer'], '../panelist/dashboard.php');
$pdo = Database::getConnection();
$scoringEngine = new ScoringEngine($pdo);
$videoService = new VideoConferenceService($pdo);

$sessionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = null;
$videoRoom = null;

if ($sessionId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM interview_sessions WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $editing = $stmt->fetch();
    if (!$editing) {
        Response::flash('danger', 'Interview session not found.');
        Response::redirect('sessions.php');
    }
    $videoRoom = $videoService->getRoomBySession($sessionId);
}

$departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$venues = $pdo->query('SELECT * FROM interview_venues WHERE is_active = 1 ORDER BY venue_name ASC')->fetchAll();

$defaultMode = $editing['interview_mode'] ?? (($_GET['mode'] ?? '') === 'virtual' ? 'virtual' : 'physical');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $title = trim((string)($_POST['title'] ?? ''));
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $jobTitle = trim((string)($_POST['job_title'] ?? ''));
    $jobDescription = trim((string)($_POST['job_description'] ?? ''));
    $interviewMode = in_array($_POST['interview_mode'] ?? '', ['physical', 'virtual'], true) ? $_POST['interview_mode'] : 'physical';
    $interviewDate = trim((string)($_POST['interview_date'] ?? ''));
    $startTime = trim((string)($_POST['start_time'] ?? ''));
    $endTime = trim((string)($_POST['end_time'] ?? ''));
    $venueId = ($interviewMode === 'physical' && !empty($_POST['venue_id'])) ? (int)$_POST['venue_id'] : null;
    $venueRoomOverride = $interviewMode === 'physical' ? trim((string)($_POST['venue_room_override'] ?? '')) : null;
    $instructions = trim((string)($_POST['instructions'] ?? ''));
    $status = in_array($_POST['status'] ?? '', ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled', 'archived'], true)
        ? $_POST['status']
        : 'scheduled';

    // Virtual configuration
    $videoProvider = in_array($_POST['video_provider'] ?? '', ['jitsi', 'livekit'], true) ? $_POST['video_provider'] : 'jitsi';
    $videoDomain = trim((string)($_POST['video_domain'] ?? ''));
    $waitingRoomEnabled = isset($_POST['waiting_room_enabled']);

    if ($title === '' || $departmentId <= 0 || $jobTitle === '' || $interviewDate === '' || $startTime === '' || $endTime === '') {
        $error = 'Please fill in all required session fields (Title, Department, Job Title, Date, Start & End Time).';
    } elseif ($interviewMode === 'physical' && $venueId === null) {
        $error = 'Please select a physical interview venue for a Physical Interview session.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($editing) {
                $upd = $pdo->prepare(
                    'UPDATE interview_sessions
                     SET title = :title, department_id = :dept, job_title = :job, job_description = :jdesc,
                         interview_mode = :mode, interview_date = :idate, start_time = :stime, end_time = :etime,
                         venue_id = :venue, venue_room_override = :vroom, instructions = :instr, status = :status,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $upd->execute([
                    'title' => $title,
                    'dept' => $departmentId,
                    'job' => $jobTitle,
                    'jdesc' => $jobDescription ?: null,
                    'mode' => $interviewMode,
                    'idate' => $interviewDate,
                    'stime' => $startTime,
                    'etime' => $endTime,
                    'venue' => $venueId,
                    'vroom' => $venueRoomOverride ?: null,
                    'instr' => $instructions ?: null,
                    'status' => $status,
                    'id' => $sessionId,
                ]);

                if ($interviewMode === 'virtual') {
                    $videoService->createOrUpdateRoom($sessionId, $videoProvider, $videoDomain, $waitingRoomEnabled, (int)$user['id']);
                }

                AuditLogger::log($pdo, (int)$user['id'], 'session_updated', 'interview_session', $sessionId, "Updated {$interviewMode} session: {$title}");
                $pdo->commit();
                Response::flash('success', 'Interview session updated successfully.');
                Response::redirect('session_view.php?id=' . $sessionId);
            } else {
                $sessionCode = 'MYS-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $ins = $pdo->prepare(
                    'INSERT INTO interview_sessions
                        (session_code, title, department_id, job_title, job_description, interview_mode,
                         interview_date, start_time, end_time, venue_id, venue_room_override, instructions, status, created_by)
                     VALUES
                        (:code, :title, :dept, :job, :jdesc, :mode,
                         :idate, :stime, :etime, :venue, :vroom, :instr, :status, :uid)'
                );
                $ins->execute([
                    'code' => $sessionCode,
                    'title' => $title,
                    'dept' => $departmentId,
                    'job' => $jobTitle,
                    'jdesc' => $jobDescription ?: null,
                    'mode' => $interviewMode,
                    'idate' => $interviewDate,
                    'stime' => $startTime,
                    'etime' => $endTime,
                    'venue' => $venueId,
                    'vroom' => $venueRoomOverride ?: null,
                    'instr' => $instructions ?: null,
                    'status' => $status,
                    'uid' => (int)$user['id'],
                ]);
                $newId = (int)$pdo->lastInsertId();

                // Seed the 6 standard Ministry criteria (100 marks total)
                $scoringEngine->seedDefaultCriteria($newId);

                // Initialize blind-scoring release record (unreleased by default)
                $pdo->prepare('INSERT INTO result_releases (session_id, is_released) VALUES (:sid, 0)')
                    ->execute(['sid' => $newId]);

                if ($interviewMode === 'virtual') {
                    $videoService->createOrUpdateRoom($newId, $videoProvider, $videoDomain, $waitingRoomEnabled, (int)$user['id']);
                }

                AuditLogger::log($pdo, (int)$user['id'], 'session_created', 'interview_session', $newId, "Created {$interviewMode} session: {$title}");
                $pdo->commit();
                Response::flash('success', 'Interview session created with default 100-mark Ministry scoring criteria. You can now assign panelists and candidates.');
                Response::redirect('session_view.php?id=' . $newId);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to save interview session: ' . $e->getMessage();
        }
    }
}

$pageTitle = $editing ? 'Edit Interview Session' : 'Create Interview Session';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><?= $editing ? 'Edit Interview Session' : 'Configure New Interview Session' ?></h1>
            <p class="page-subtitle">Choose Physical (In-Person) or Virtual (Online WebRTC) interview mode and configure session details.</p>
        </div>
        <a href="sessions.php" class="btn btn-sm btn-outline-secondary">&larr; Back to Sessions</a>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="mys-card p-4">
        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">

        <!-- Mode Selector -->
        <div class="mb-4">
            <label class="form-label fw-bold text-uppercase small text-muted">1. Select Interview Mode</label>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="d-block border rounded-3 p-3 cursor-pointer h-100" id="cardPhysical" style="cursor:pointer;">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="interview_mode" id="modePhysical" value="physical" <?= $defaultMode === 'physical' ? 'checked' : '' ?>>
                            <span class="fw-bold text-primary ms-1"><i class="bi bi-building me-1"></i>Physical (In-Person) Interview</span>
                        </div>
                        <div class="small text-muted mt-1 ps-4">
                            Held at a Ministry conference room or physical venue. Includes arrival tracking, attendance register, and mobile/tablet/laptop panel scoring. No camera or video meeting required.
                        </div>
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="d-block border rounded-3 p-3 cursor-pointer h-100" id="cardVirtual" style="cursor:pointer;">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="interview_mode" id="modeVirtual" value="virtual" <?= $defaultMode === 'virtual' ? 'checked' : '' ?>>
                            <span class="fw-bold ms-1" style="color:#5B21B6;"><i class="bi bi-camera-video me-1"></i>Virtual (Online WebRTC) Interview</span>
                        </div>
                        <div class="small text-muted mt-1 ps-4">
                            Conducted remotely via Jitsi Meet or LiveKit WebRTC with waiting room, candidate invitation links, live audio/video controls, and integrated panel scoring. Recording disabled by default.
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <hr>

        <!-- Core Session Details -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Interview Session Title *</label>
                <input type="text" name="title" class="form-control" required value="<?= e($editing['title'] ?? '') ?>" placeholder="e.g., Q3 Recruitment — Senior Youth Policy Officer">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Ministry Department *</label>
                <select name="department_id" class="form-select" required>
                    <option value="">-- Select Department --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= ((int)($editing['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Job Title / Position *</label>
                <input type="text" name="job_title" class="form-control" required value="<?= e($editing['job_title'] ?? '') ?>" placeholder="e.g., Youth Program Coordinator">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Session Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['draft', 'scheduled', 'in_progress', 'completed', 'cancelled', 'archived'] as $st): ?>
                        <option value="<?= $st ?>" <?= (($editing['status'] ?? 'scheduled') === $st) ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $st))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Interview Date *</label>
                <input type="date" name="interview_date" class="form-control" required value="<?= e($editing['interview_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Start Time *</label>
                <input type="time" name="start_time" class="form-control" required value="<?= e(isset($editing['start_time']) ? substr($editing['start_time'], 0, 5) : '09:00') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold small">End Time *</label>
                <input type="time" name="end_time" class="form-control" required value="<?= e(isset($editing['end_time']) ? substr($editing['end_time'], 0, 5) : '16:00') ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold small">Job Description</label>
                <textarea name="job_description" class="form-control" rows="3" placeholder="Key duties, qualifications, and civil service grade requirements..."><?= e($editing['job_description'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold small">Interview Instructions for Panelists &amp; Logistics</label>
                <textarea name="instructions" class="form-control" rows="2" placeholder="Assessment guidelines, timing per candidate, document checks..."><?= e($editing['instructions'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Physical Venue Section -->
        <div id="physicalConfigSection" class="p-3 rounded-3 mb-4" style="background:#F0F7FC; border:1px solid #C5DFF5;">
            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-building me-1"></i>Physical Interview Venue Configuration</h6>
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label fw-semibold small">Ministry Venue / Conference Room *</label>
                    <select name="venue_id" id="venueIdInput" class="form-select">
                        <option value="">-- Select Physical Venue --</option>
                        <?php foreach ($venues as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= ((int)($editing['venue_id'] ?? 0) === (int)$v['id']) ? 'selected' : '' ?>>
                                <?= e($v['venue_name']) ?> — <?= e($v['room_number']) ?> (Cap: <?= (int)$v['capacity'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Need another location? <a href="venues.php" target="_blank">Manage Physical Venues</a>.</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">Specific Room / Desk Note (Optional)</label>
                    <input type="text" name="venue_room_override" class="form-control" value="<?= e($editing['venue_room_override'] ?? '') ?>" placeholder="e.g., Wing B, Panel Desk 2">
                </div>
            </div>
        </div>

        <!-- Virtual WebRTC Section -->
        <div id="virtualConfigSection" class="p-3 rounded-3 mb-4" style="background:#F6F2FE; border:1px solid #DACBFA;">
            <h6 class="fw-bold mb-3" style="color:#5B21B6;"><i class="bi bi-camera-video me-1"></i>Virtual Interview WebRTC Configuration</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Video Infrastructure Provider</label>
                    <select name="video_provider" class="form-select">
                        <option value="jitsi" <?= (($videoRoom['provider'] ?? 'jitsi') === 'jitsi') ? 'selected' : '' ?>>Jitsi Meet (WebRTC External API)</option>
                        <option value="livekit" <?= (($videoRoom['provider'] ?? '') === 'livekit') ? 'selected' : '' ?>>LiveKit (WebRTC SFU + Server JWT)</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">Server Domain / LiveKit WebSocket URL</label>
                    <input type="text" name="video_domain" class="form-control" value="<?= e($videoRoom['server_domain'] ?? '') ?>" placeholder="Leave blank to use .env default (meet.jit.si / LiveKit URL)">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="waiting_room_enabled" id="waitingRoomToggle" <?= (!isset($videoRoom) || (int)$videoRoom['waiting_room_enabled'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label small fw-semibold" for="waitingRoomToggle">Enable Waiting Room</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="alert alert-light border small mb-0">
                        <i class="bi bi-shield-exclamation text-danger me-1"></i>
                        <strong>Recording Policy:</strong> Video recording is <strong>disabled by default</strong> for all virtual interviews and can only be enabled from the Virtual Interview Dashboard with explicit Administrator authorization and candidate consent.
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="sessions.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-mys px-4">
                <i class="bi bi-check2-circle me-1"></i><?= $editing ? 'Save Changes' : 'Create Interview Session' ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rPhys = document.getElementById('modePhysical');
    const rVirt = document.getElementById('modeVirtual');
    const secPhys = document.getElementById('physicalConfigSection');
    const secVirt = document.getElementById('virtualConfigSection');
    const venueInput = document.getElementById('venueIdInput');
    const cardPhys = document.getElementById('cardPhysical');
    const cardVirt = document.getElementById('cardVirtual');

    function syncMode() {
        const isPhys = rPhys.checked;
        secPhys.style.display = isPhys ? 'block' : 'none';
        secVirt.style.display = isPhys ? 'none' : 'block';
        venueInput.required = isPhys;
        cardPhys.style.borderColor = isPhys ? '#0E5A8A' : '#DDE5EF';
        cardPhys.style.backgroundColor = isPhys ? '#F0F7FC' : '#FFFFFF';
        cardVirt.style.borderColor = !isPhys ? '#5B21B6' : '#DDE5EF';
        cardVirt.style.backgroundColor = !isPhys ? '#F6F2FE' : '#FFFFFF';
    }

    rPhys.addEventListener('change', syncMode);
    rVirt.addEventListener('change', syncMode);
    syncMode();
});
</script>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
