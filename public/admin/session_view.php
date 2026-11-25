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

$sessionId = (int)($_GET['id'] ?? $_POST['session_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT s.*, d.name AS department_name, v.venue_name, v.room_number, v.address AS venue_address
     FROM interview_sessions s
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     WHERE s.id = :id LIMIT 1'
);
$stmt->execute(['id' => $sessionId]);
$session = $stmt->fetch();

if (!$session) {
    Response::flash('danger', 'Interview session not found.');
    Response::redirect('sessions.php');
}

// Handle POST assignments (add/remove panelists, add/remove candidates, candidate-specific panelist assignment)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'assign_panelist') {
            $panelistId = (int)($_POST['panelist_id'] ?? 0);
            $panelRole = in_array($_POST['panel_role'] ?? '', ['chairperson', 'member', 'technical_specialist', 'hr_representative'], true)
                ? $_POST['panel_role']
                : 'member';
            if ($panelistId > 0) {
                $chk = $pdo->prepare('SELECT 1 FROM session_panelists WHERE session_id = :sid AND panelist_id = :pid LIMIT 1');
                $chk->execute(['sid' => $sessionId, 'pid' => $panelistId]);
                if (!$chk->fetchColumn()) {
                    $ins = $pdo->prepare(
                        'INSERT INTO session_panelists (session_id, panelist_id, panel_role) VALUES (:sid, :pid, :role)'
                    );
                    $ins->execute(['sid' => $sessionId, 'pid' => $panelistId, 'role' => $panelRole]);
                }
                AuditLogger::log($pdo, (int)$user['id'], 'panelist_assigned', 'interview_session', $sessionId, "Assigned panelist #{$panelistId} as {$panelRole}");
                Response::flash('success', 'Panelist assigned to session.');
            }
        } elseif ($action === 'remove_panelist') {
            $panelistId = (int)($_POST['panelist_id'] ?? 0);
            $pdo->prepare('DELETE FROM session_panelists WHERE session_id = :sid AND panelist_id = :pid')
                ->execute(['sid' => $sessionId, 'pid' => $panelistId]);
            $pdo->prepare('DELETE FROM candidate_panelist_assignments WHERE session_id = :sid AND panelist_id = :pid')
                ->execute(['sid' => $sessionId, 'pid' => $panelistId]);
            Response::flash('info', 'Panelist removed from session.');
        } elseif ($action === 'assign_candidate') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            $scheduledTime = trim((string)($_POST['scheduled_time'] ?? '')) ?: null;
            $order = max(1, (int)($_POST['interview_order'] ?? 1));
            if ($candidateId > 0) {
                $chkSc = $pdo->prepare('SELECT 1 FROM session_candidates WHERE session_id = :sid AND candidate_id = :cid LIMIT 1');
                $chkSc->execute(['sid' => $sessionId, 'cid' => $candidateId]);
                if (!$chkSc->fetchColumn()) {
                    $token = $session['interview_mode'] === 'virtual' ? bin2hex(random_bytes(20)) : null;
                    $ins = $pdo->prepare(
                        'INSERT INTO session_candidates (session_id, candidate_id, scheduled_time, interview_order, virtual_invite_token)
                         VALUES (:sid, :cid, :stime, :ord, :tok)'
                    );
                    $ins->execute([
                        'sid' => $sessionId,
                        'cid' => $candidateId,
                        'stime' => $scheduledTime,
                        'ord' => $order,
                        'tok' => $token,
                    ]);
                }
                // Initialize attendance record as Absent until checked in
                $chkCa = $pdo->prepare('SELECT 1 FROM candidate_attendance WHERE session_id = :sid AND candidate_id = :cid LIMIT 1');
                $chkCa->execute(['sid' => $sessionId, 'cid' => $candidateId]);
                if (!$chkCa->fetchColumn()) {
                    $pdo->prepare(
                        "INSERT INTO candidate_attendance (session_id, candidate_id, scheduled_time, attendance_status)
                         VALUES (:sid, :cid, :stime, 'Absent')"
                    )->execute(['sid' => $sessionId, 'cid' => $candidateId, 'stime' => $scheduledTime]);
                }

                AuditLogger::log($pdo, (int)$user['id'], 'candidate_assigned', 'interview_session', $sessionId, "Assigned candidate #{$candidateId}");
                Response::flash('success', 'Candidate registered for this interview session.');
            }
        } elseif ($action === 'remove_candidate') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            $pdo->prepare('DELETE FROM session_candidates WHERE session_id = :sid AND candidate_id = :cid')
                ->execute(['sid' => $sessionId, 'cid' => $candidateId]);
            Response::flash('info', 'Candidate removed from session.');
        } elseif ($action === 'assign_candidate_panelists') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            $panelistIds = array_map('intval', $_POST['panelist_ids'] ?? []);
            $pdo->prepare('DELETE FROM candidate_panelist_assignments WHERE session_id = :sid AND candidate_id = :cid')
                ->execute(['sid' => $sessionId, 'cid' => $candidateId]);
            $ins = $pdo->prepare(
                'INSERT INTO candidate_panelist_assignments (session_id, candidate_id, panelist_id) VALUES (:sid, :cid, :pid)'
            );
            foreach (array_unique($panelistIds) as $pid) {
                if ($pid > 0) {
                    $ins->execute(['sid' => $sessionId, 'cid' => $candidateId, 'pid' => $pid]);
                }
            }
            Response::flash('success', 'Candidate-specific panelist assignment saved.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', 'Operation failed: ' . $e->getMessage());
    }
    Response::redirect('session_view.php?id=' . $sessionId);
}

$assignedPanelists = $pdo->prepare(
    'SELECT sp.*, u.full_name, u.email, u.job_title
     FROM session_panelists sp
     INNER JOIN users u ON u.id = sp.panelist_id
     WHERE sp.session_id = :sid ORDER BY u.full_name ASC'
);
$assignedPanelists->execute(['sid' => $sessionId]);
$assignedPanelists = $assignedPanelists->fetchAll();

$allPanelists = $pdo->query("SELECT id, full_name, email, job_title FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();

$assignedCandidates = $pdo->prepare(
    'SELECT sc.*, c.application_number, c.full_name, c.email, c.phone
     FROM session_candidates sc
     INNER JOIN candidates c ON c.id = sc.candidate_id
     WHERE sc.session_id = :sid
     ORDER BY sc.interview_order ASC, sc.scheduled_time ASC'
);
$assignedCandidates->execute(['sid' => $sessionId]);
$assignedCandidates = $assignedCandidates->fetchAll();

$allCandidates = $pdo->query('SELECT id, application_number, full_name FROM candidates ORDER BY created_at DESC, full_name ASC')->fetchAll();
$criteria = $scoringEngine->getSessionCriteria($sessionId);
$totalMaxMarks = array_sum(array_map(static fn($c) => (float)$c['max_marks'], $criteria));
$progress = $scoringEngine->getSessionSubmissionProgress($sessionId);
$isReleased = $scoringEngine->isSessionReleased($sessionId);
$videoRoom = $session['interview_mode'] === 'virtual' ? $videoService->getRoomBySession($sessionId) : null;

$pageTitle = $session['title'];
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge <?= $session['interview_mode'] === 'physical' ? 'badge-mode-physical' : 'badge-mode-virtual' ?>">
                    <i class="bi bi-<?= $session['interview_mode'] === 'physical' ? 'building' : 'camera-video' ?> me-1"></i>
                    <?= strtoupper($session['interview_mode']) ?> INTERVIEW
                </span>
                <span class="small text-muted fw-semibold"><?= e($session['session_code']) ?></span>
                <?php if ($isReleased): ?>
                    <span class="badge bg-success">Results Released</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Blind Scoring Active</span>
                <?php endif; ?>
            </div>
            <h1 class="page-title"><?= e($session['title']) ?></h1>
            <p class="page-subtitle"><?= e($session['job_title']) ?> &middot; <?= e($session['department_name']) ?> &middot; <?= e($session['interview_date']) ?> (<?= e(substr($session['start_time'], 0, 5)) ?> - <?= e(substr($session['end_time'], 0, 5)) ?>)</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($session['interview_mode'] === 'physical'): ?>
                <a href="../physical/dashboard.php?session_id=<?= $sessionId ?>" class="btn btn-mys btn-sm"><i class="bi bi-building me-1"></i>Physical Dashboard</a>
            <?php else: ?>
                <a href="../virtual/dashboard.php?session_id=<?= $sessionId ?>" class="btn btn-mys btn-sm"><i class="bi bi-camera-video me-1"></i>Virtual Dashboard</a>
            <?php endif; ?>
            <a href="criteria.php?session_id=<?= $sessionId ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-check me-1"></i>Criteria (<?= number_format($totalMaxMarks, 0) ?> pts)</a>
            <a href="../results/index.php?session_id=<?= $sessionId ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-trophy me-1"></i>Results &amp; Release</a>
            <a href="session_form.php?id=<?= $sessionId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <!-- Session Summary Strip -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="mys-card p-3 h-100">
                <div class="small text-uppercase fw-bold text-muted mb-1"><?= $session['interview_mode'] === 'physical' ? 'Physical Venue' : 'Virtual WebRTC Room' ?></div>
                <?php if ($session['interview_mode'] === 'physical'): ?>
                    <div class="fw-semibold"><?= e($session['venue_name'] ?: 'Not Assigned') ?></div>
                    <div class="small text-muted"><?= e($session['venue_room_override'] ?: ($session['room_number'] ?: '')) ?></div>
                    <div class="small text-muted"><?= e($session['venue_address'] ?: '') ?></div>
                <?php else: ?>
                    <div class="fw-semibold"><?= e(strtoupper((string)($videoRoom['provider'] ?? 'Jitsi'))) ?> &middot; <?= e($videoRoom['room_name'] ?? 'Pending') ?></div>
                    <div class="small text-muted">Waiting Room: <?= !empty($videoRoom['waiting_room_enabled']) ? 'Enabled' : 'Disabled' ?> &middot; Recording: <?= !empty($videoRoom['recording_enabled']) ? 'Authorized' : 'Disabled (Default)' ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mys-card p-3 h-100">
                <div class="small text-uppercase fw-bold text-muted mb-1">Assessment Progress</div>
                <div class="fw-bold fs-5 text-primary"><?= $progress['total_submitted'] ?> / <?= $progress['total_expected'] ?> Submitted (<?= $progress['completion_rate'] ?>%)</div>
                <div class="progress mt-2" style="height:8px;">
                    <div class="progress-bar bg-success" style="width:<?= $progress['completion_rate'] ?>%"></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mys-card p-3 h-100">
                <div class="small text-uppercase fw-bold text-muted mb-1">Scoring Criteria</div>
                <div class="fw-semibold"><?= count($criteria) ?> Criteria &middot; Total <?= number_format($totalMaxMarks, 2) ?> Marks</div>
                <a href="criteria.php?session_id=<?= $sessionId ?>" class="small text-decoration-none">Customize criteria &amp; weights &rarr;</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Assigned Panelists -->
        <div class="col-lg-5">
            <div class="mys-card mb-4">
                <div class="mys-card-header"><i class="bi bi-person-badge me-2"></i>Assigned Interview Panelists (<?= count($assignedPanelists) ?>)</div>
                <div class="p-3 border-bottom bg-light">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                        <input type="hidden" name="action" value="assign_panelist">
                        <div class="col-7">
                            <select name="panelist_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Panelist --</option>
                                <?php foreach ($allPanelists as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>"><?= e($p['full_name']) ?> (<?= e($p['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <select name="panel_role" class="form-select form-select-sm">
                                <option value="member">Member</option>
                                <option value="chairperson">Chairperson</option>
                                <option value="technical_specialist">Technical</option>
                                <option value="hr_representative">HR Rep</option>
                            </select>
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-mys btn-sm w-100">Add</button>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Panelist</th>
                                <th>Role</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($assignedPanelists)): ?>
                            <tr><td colspan="3" class="text-center py-3 text-muted small">No panelists assigned yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignedPanelists as $ap): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($ap['full_name']) ?></div>
                                        <div class="small text-muted"><?= e($ap['job_title'] ?: $ap['email']) ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= e(ucwords(str_replace('_', ' ', $ap['panel_role']))) ?></span></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Remove panelist from this session?');">
                                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                                            <input type="hidden" name="action" value="remove_panelist">
                                            <input type="hidden" name="panelist_id" value="<?= (int)$ap['panelist_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-x"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Registered Candidates -->
        <div class="col-lg-7">
            <div class="mys-card mb-4">
                <div class="mys-card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Registered Candidates (<?= count($assignedCandidates) ?>)</span>
                    <a href="candidates.php" class="btn btn-sm btn-outline-secondary">+ New Candidate</a>
                </div>
                <div class="p-3 border-bottom bg-light">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                        <input type="hidden" name="action" value="assign_candidate">
                        <div class="col-md-5">
                            <select name="candidate_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Candidate --</option>
                                <?php foreach ($allCandidates as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= e($c['application_number']) ?> — <?= e($c['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="time" name="scheduled_time" class="form-control form-control-sm" value="09:30" title="Scheduled Interview Time">
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="interview_order" class="form-control form-control-sm" min="1" value="<?= count($assignedCandidates) + 1 ?>" title="Order">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-mys btn-sm w-100">Assign</button>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Candidate</th>
                                <th>Scheduled</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($assignedCandidates)): ?>
                            <tr><td colspan="5" class="text-center py-3 text-muted small">No candidates registered for this session yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignedCandidates as $ac): ?>
                                <tr>
                                    <td class="small fw-semibold"><?= (int)$ac['interview_order'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= e($ac['full_name']) ?></div>
                                        <div class="small text-muted"><?= e($ac['application_number']) ?></div>
                                    </td>
                                    <td class="small"><?= e($ac['scheduled_time'] ? substr($ac['scheduled_time'], 0, 5) : 'TBD') ?></td>
                                    <td><span class="badge bg-secondary-subtle text-dark border"><?= e(ucwords(str_replace('_', ' ', $ac['interview_status']))) ?></span></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Remove candidate from session?');">
                                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                                            <input type="hidden" name="action" value="remove_candidate">
                                            <input type="hidden" name="candidate_id" value="<?= (int)$ac['candidate_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-x"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
