<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AttendanceService;
use App\Services\ScoringEngine;

$user = Auth::requireAuth('../login.php');
$pdo = Database::getConnection();
$attendanceService = new AttendanceService($pdo);
$scoringEngine = new ScoringEngine($pdo);

// Fetch physical sessions (all for admin/HR, assigned only for panelist)
if (Auth::isAdmin()) {
    $sessions = $pdo->query(
        "SELECT s.*, d.name AS department_name, v.venue_name, v.room_number, v.address AS venue_address
         FROM interview_sessions s
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN interview_venues v ON v.id = s.venue_id
         WHERE s.interview_mode = 'physical'
         ORDER BY s.interview_date DESC, s.start_time ASC"
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT s.*, d.name AS department_name, v.venue_name, v.room_number, v.address AS venue_address
         FROM interview_sessions s
         INNER JOIN session_panelists sp ON sp.session_id = s.id AND sp.panelist_id = :pid
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN interview_venues v ON v.id = s.venue_id
         WHERE s.interview_mode = 'physical'
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

// Handle POST quick actions on the Physical Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentSession) {
    Auth::requireCsrf();
    $sid = (int)$currentSession['id'];
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'begin_interview') {
            $attendanceService->beginCandidateInterview($sid, (int)$_POST['candidate_id'], (int)$user['id']);
            Response::flash('success', 'Candidate marked Present and interview started.');
        } elseif ($action === 'complete_interview') {
            $attendanceService->completeCandidateInterview($sid, (int)$_POST['candidate_id'], (int)$user['id']);
            Response::flash('success', 'Interview marked Completed. Panelists can now finalize scores.');
        } elseif ($action === 'update_candidate_attendance') {
            $attendanceService->recordCandidateAttendance(
                $sid,
                (int)$_POST['candidate_id'],
                (string)$_POST['attendance_status'],
                $_POST['actual_arrival_time'] ?: null,
                $_POST['remarks'] ?? null,
                (int)$user['id']
            );
            Response::flash('success', 'Candidate attendance updated.');
        } elseif ($action === 'update_panelist_attendance') {
            $targetPid = Auth::isAdmin() ? (int)$_POST['panelist_id'] : (int)$user['id'];
            $attendanceService->recordPanelistAttendance(
                $sid,
                $targetPid,
                (string)$_POST['attendance_status'],
                $_POST['remarks'] ?? null,
                (int)$user['id']
            );
            Response::flash('success', 'Panelist attendance recorded.');
        } elseif ($action === 'add_note') {
            $content = trim((string)($_POST['content'] ?? ''));
            $candId = !empty($_POST['candidate_id']) ? (int)$_POST['candidate_id'] : null;
            if ($content !== '') {
                $pdo->prepare(
                    "INSERT INTO interview_notes (session_id, candidate_id, author_id, note_type, content)
                     VALUES (:sid, :cid, :uid, :ntype, :content)"
                )->execute([
                    'sid' => $sid,
                    'cid' => $candId,
                    'uid' => (int)$user['id'],
                    'ntype' => $candId ? 'candidate' : 'general',
                    'content' => $content,
                ]);
                Response::flash('success', 'Interview note saved.');
            }
        }
    } catch (Throwable $e) {
        Response::flash('danger', $e->getMessage());
    }
    Response::redirect('dashboard.php?session_id=' . $sid);
}

$candidateRegister = [];
$panelistRegister = [];
$progress = null;
$notes = [];
$stats = ['scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'present' => 0, 'pending_assessments' => 0];

if ($currentSession) {
    $sid = (int)$currentSession['id'];
    $candidateRegister = $attendanceService->getCandidateAttendanceRegister($sid);
    $panelistRegister = $attendanceService->getPanelistAttendanceRegister($sid);
    $progress = $scoringEngine->getSessionSubmissionProgress($sid);

    $stats['scheduled'] = count($candidateRegister);
    foreach ($candidateRegister as $cr) {
        if ($cr['interview_status'] === 'in_progress') {
            $stats['in_progress']++;
        }
        if ($cr['interview_status'] === 'completed') {
            $stats['completed']++;
        }
        if (in_array($cr['attendance_status'], ['Present', 'Late'], true)) {
            $stats['present']++;
        }
    }
    $stats['pending_assessments'] = $progress['total_pending'];

    $noteStmt = $pdo->prepare(
        'SELECT n.*, u.full_name AS author_name, c.full_name AS candidate_name
         FROM interview_notes n
         INNER JOIN users u ON u.id = n.author_id
         LEFT JOIN candidates c ON c.id = n.candidate_id
         WHERE n.session_id = :sid
         ORDER BY n.created_at DESC LIMIT 20'
    );
    $noteStmt->execute(['sid' => $sid]);
    $notes = $noteStmt->fetchAll();
}

$pageTitle = 'Physical Interview Dashboard';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <span class="badge badge-mode-physical mb-1"><i class="bi bi-building me-1"></i>IN-PERSON / PHYSICAL INTERVIEW OPERATIONS</span>
            <h1 class="page-title">Physical Interview Dashboard</h1>
            <p class="page-subtitle">Manage venue check-in, candidate arrivals, panelist attendance, and immediate mobile/tablet/laptop scoring — no cameras or video required.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($sessions)): ?>
                <form method="get" class="d-flex align-items-center gap-2">
                    <select name="session_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ($currentSession && (int)$currentSession['id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= e($s['interview_date']) ?> — <?= e($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <?php if ($currentSession): ?>
                <a href="attendance.php?session_id=<?= (int)$currentSession['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-clipboard2-check me-1"></i>Attendance Register
                </a>
            <?php endif; ?>
            <?php if (Auth::isAdmin()): ?>
                <a href="../admin/session_form.php?mode=physical" class="btn btn-sm btn-mys"><i class="bi bi-plus-lg me-1"></i>New Physical Session</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
<?php if (!$currentSession): ?>
    <div class="mys-card p-5 text-center">
        <i class="bi bi-building fs-1 text-muted d-block mb-2"></i>
        <h5>No Physical Interview Sessions Scheduled</h5>
        <p class="text-muted">Create a physical interview session to manage in-person venue attendance and panel scoring.</p>
        <?php if (Auth::isAdmin()): ?>
            <a href="../admin/session_form.php?mode=physical" class="btn btn-mys">Create Physical Interview Session</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Current Physical Session & Venue Banner -->
    <div class="mys-card p-3 mb-4" style="border-left:5px solid #0E5A8A;">
        <div class="row g-3 align-items-center">
            <div class="col-md-7">
                <div class="small text-uppercase fw-bold text-primary"><?= e($currentSession['department_name']) ?> &middot; <?= e($currentSession['session_code']) ?></div>
                <h5 class="fw-bold mb-1"><?= e($currentSession['title']) ?> — <?= e($currentSession['job_title']) ?></h5>
                <div class="small text-muted">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                    <strong>Venue:</strong> <?= e($currentSession['venue_name'] ?: 'TBD') ?>
                    (<?= e($currentSession['venue_room_override'] ?: ($currentSession['room_number'] ?: 'Room TBD')) ?>)
                    &middot; <?= e($currentSession['venue_address'] ?: '') ?>
                </div>
            </div>
            <div class="col-md-5 text-md-end small">
                <div><i class="bi bi-calendar3 me-1"></i><strong>Date:</strong> <?= e($currentSession['interview_date']) ?> &middot; <?= e(substr($currentSession['start_time'], 0, 5)) ?> - <?= e(substr($currentSession['end_time'], 0, 5)) ?></div>
                <div class="mt-1">
                    <a href="../results/export.php?type=candidate_attendance&format=pdf&session_id=<?= (int)$currentSession['id'] ?>" class="btn btn-sm btn-outline-secondary py-0">Export Attendance PDF</a>
                    <a href="../results/export.php?type=candidate_attendance&format=excel&session_id=<?= (int)$currentSession['id'] ?>" class="btn btn-sm btn-outline-success py-0">Export Excel</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Counters -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="stat-card stat-physical">
                <div class="stat-label">Scheduled</div>
                <div class="stat-value"><?= $stats['scheduled'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card stat-success">
                <div class="stat-label">Arrived / Present</div>
                <div class="stat-value"><?= $stats['present'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-gold">
                <div class="stat-label">Currently Interviewing</div>
                <div class="stat-value"><?= $stats['in_progress'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $stats['completed'] ?></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="stat-card stat-crimson">
                <div class="stat-label">Pending Assessments</div>
                <div class="stat-value"><?= $stats['pending_assessments'] ?></div>
                <small class="text-muted"><?= $progress['total_submitted'] ?>/<?= $progress['total_expected'] ?> submitted (<?= $progress['completion_rate'] ?>%)</small>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Candidate Queue, Arrival & Scoring Shortcuts -->
        <div class="col-lg-8">
            <div class="mys-card mb-4">
                <div class="mys-card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people-fill me-2"></i>Scheduled Candidates &amp; Interview Queue</span>
                    <span class="small text-muted">Mark Present &amp; Begin Interview or Score Immediately</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Candidate</th>
                                <th>Arrival Status</th>
                                <th>Interview State</th>
                                <th class="text-end">Physical Flow &amp; Scoring</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($candidateRegister)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No candidates assigned to this physical session yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($candidateRegister as $cr): ?>
                                <?php
                                    $cid = (int)$cr['candidate_id'];
                                    $canScore = $scoringEngine->canPanelistScoreCandidate((int)$currentSession['id'], $cid, (int)$user['id']);
                                ?>
                                <tr>
                                    <td class="small text-nowrap">
                                        <div class="fw-bold"><?= e($cr['scheduled_time'] ? substr($cr['scheduled_time'], 0, 5) : 'TBD') ?></div>
                                        <?php if ($cr['actual_arrival_time']): ?>
                                            <div class="text-success" style="font-size:0.75rem;">Arr: <?= e(substr($cr['actual_arrival_time'], 11, 5)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= e($cr['full_name']) ?></div>
                                        <div class="small text-muted"><?= e($cr['application_number']) ?></div>
                                    </td>
                                    <td>
                                        <form method="post" class="d-flex gap-1 align-items-center">
                                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_candidate_attendance">
                                            <input type="hidden" name="candidate_id" value="<?= $cid ?>">
                                            <input type="hidden" name="actual_arrival_time" value="<?= e($cr['actual_arrival_time'] ?? '') ?>">
                                            <select name="attendance_status" class="form-select form-select-sm" style="width:120px;" onchange="this.form.submit()">
                                                <?php foreach (AttendanceService::VALID_STATUSES as $st): ?>
                                                    <option value="<?= $st ?>" <?= $cr['attendance_status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <?php
                                            $badgeClass = match ($cr['interview_status']) {
                                                'in_progress' => 'bg-primary',
                                                'completed' => 'bg-success',
                                                'checked_in' => 'bg-info text-dark',
                                                'absent' => 'bg-danger',
                                                'rescheduled' => 'bg-warning text-dark',
                                                default => 'bg-secondary',
                                            };
                                        ?>
                                        <span class="badge <?= $badgeClass ?>"><?= e(ucwords(str_replace('_', ' ', $cr['interview_status']))) ?></span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($cr['interview_status'] !== 'in_progress' && $cr['interview_status'] !== 'completed'): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                                <input type="hidden" name="action" value="begin_interview">
                                                <input type="hidden" name="candidate_id" value="<?= $cid ?>">
                                                <button class="btn btn-sm btn-outline-primary" title="Mark Present & Begin Interview">
                                                    <i class="bi bi-play-fill"></i> Present &amp; Begin
                                                </button>
                                            </form>
                                        <?php elseif ($cr['interview_status'] === 'in_progress'): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                                <input type="hidden" name="action" value="complete_interview">
                                                <input type="hidden" name="candidate_id" value="<?= $cid ?>">
                                                <button class="btn btn-sm btn-outline-success" title="Mark Interview Completed">
                                                    <i class="bi bi-check2-circle"></i> Complete
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($canScore): ?>
                                            <a href="../panelist/score.php?session_id=<?= (int)$currentSession['id'] ?>&candidate_id=<?= $cid ?>" class="btn btn-sm btn-mys ms-1">
                                                <i class="bi bi-pencil-square me-1"></i>Score
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

            <!-- Panelist Submission Progress (Blind-Safe) -->
            <div class="mys-card">
                <div class="mys-card-header"><i class="bi bi-bar-chart-steps me-2"></i>Panelist Assessment Submission Progress</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Candidate</th>
                                <th>Submitted / Expected</th>
                                <th>Panelist Status (Blind Protected)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($progress['candidates'] as $pc): ?>
                            <tr>
                                <td class="small fw-semibold"><?= e($pc['full_name']) ?></td>
                                <td class="small"><strong><?= $pc['submitted_count'] ?></strong> / <?= $pc['expected_count'] ?></td>
                                <td class="small">
                                    <?php foreach ($pc['panelists'] as $p): ?>
                                        <span class="badge bg-<?= $p['status'] === 'submitted' ? 'success' : ($p['status'] === 'draft' ? 'warning text-dark' : 'light text-dark border') ?> me-1">
                                            <?= e($p['full_name']) ?>: <?= e(ucfirst($p['status'])) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column: Panelist Attendance & Session Notes -->
        <div class="col-lg-4">
            <div class="mys-card mb-4">
                <div class="mys-card-header"><i class="bi bi-person-check me-2"></i>Panelist Attendance</div>
                <div class="list-group list-group-flush">
                    <?php if (empty($panelistRegister)): ?>
                        <div class="p-3 text-muted small">No panelists assigned yet.</div>
                    <?php else: ?>
                        <?php foreach ($panelistRegister as $pr): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="fw-semibold small"><?= e($pr['full_name']) ?></div>
                                    <div class="text-muted" style="font-size:0.75rem;"><?= e(ucwords(str_replace('_', ' ', $pr['panel_role']))) ?>
                                        <?= $pr['check_in_time'] ? ' &middot; In: ' . e(substr($pr['check_in_time'], 11, 5)) : '' ?>
                                    </div>
                                </div>
                                <?php if (Auth::isAdmin() || (int)$pr['panelist_id'] === (int)$user['id']): ?>
                                    <form method="post">
                                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="update_panelist_attendance">
                                        <input type="hidden" name="panelist_id" value="<?= (int)$pr['panelist_id'] ?>">
                                        <select name="attendance_status" class="form-select form-select-sm" style="width:115px;" onchange="this.form.submit()">
                                            <?php foreach (AttendanceService::VALID_STATUSES as $st): ?>
                                                <option value="<?= $st ?>" <?= $pr['attendance_status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <span class="badge bg-<?= $pr['attendance_status'] === 'Present' ? 'success' : 'secondary' ?>"><?= e($pr['attendance_status']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Physical Interview Notes -->
            <div class="mys-card">
                <div class="mys-card-header"><i class="bi bi-journal-text me-2"></i>Interview Notes</div>
                <div class="p-3">
                    <form method="post" class="mb-3">
                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="add_note">
                        <select name="candidate_id" class="form-select form-select-sm mb-2">
                            <option value="">General Session Note</option>
                            <?php foreach ($candidateRegister as $cr): ?>
                                <option value="<?= (int)$cr['candidate_id'] ?>">Candidate: <?= e($cr['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="content" class="form-control form-control-sm mb-2" rows="2" placeholder="Record room logistics, candidate remarks, or panel observations..." required></textarea>
                        <button type="submit" class="btn btn-sm btn-mys w-100">Save Note</button>
                    </form>
                    <div style="max-height:240px; overflow-y:auto;">
                        <?php if (empty($notes)): ?>
                            <div class="small text-muted text-center py-2">No notes recorded yet.</div>
                        <?php else: ?>
                            <?php foreach ($notes as $n): ?>
                                <div class="border-bottom pb-2 mb-2 small">
                                    <div class="fw-semibold"><?= e($n['author_name']) ?>
                                        <?php if ($n['candidate_name']): ?>
                                            <span class="badge bg-light text-dark border"><?= e($n['candidate_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div><?= nl2br(e($n['content'])) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;"><?= e($n['created_at']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
