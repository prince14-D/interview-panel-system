<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AttendanceService;

$user = Auth::requireAuth('../login.php');
$pdo = Database::getConnection();
$attendanceService = new AttendanceService($pdo);

$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
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
    Response::redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_candidate_row') {
            $arrivalDate = $session['interview_date'];
            $arrivalTime = trim((string)($_POST['arrival_time_only'] ?? ''));
            $fullArrival = $arrivalTime !== '' ? ($arrivalDate . ' ' . $arrivalTime . ':00') : null;
            $attendanceService->recordCandidateAttendance(
                $sessionId,
                (int)$_POST['candidate_id'],
                (string)$_POST['attendance_status'],
                $fullArrival,
                $_POST['remarks'] ?? null,
                (int)$user['id']
            );
            Response::flash('success', 'Attendance register entry updated.');
        } elseif ($action === 'save_panelist_row') {
            $attendanceService->recordPanelistAttendance(
                $sessionId,
                (int)$_POST['panelist_id'],
                (string)$_POST['attendance_status'],
                $_POST['remarks'] ?? null,
                (int)$user['id']
            );
            Response::flash('success', 'Panelist attendance updated.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', $e->getMessage());
    }
    Response::redirect('attendance.php?session_id=' . $sessionId);
}

$candidates = $attendanceService->getCandidateAttendanceRegister($sessionId);
$panelists = $attendanceService->getPanelistAttendanceRegister($sessionId);
$panelistsPresentCount = count(array_filter($panelists, static fn($p) => in_array($p['attendance_status'], ['Present', 'Late'], true)));

$pageTitle = 'Official Attendance Register — ' . $session['title'];
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-clipboard2-check me-2"></i>Official Interview Attendance Register</h1>
            <p class="page-subtitle"><?= e($session['title']) ?> &middot; <?= e($session['venue_name'] ?: 'Physical Venue') ?> (<?= e($session['room_number'] ?: '') ?>) &middot; <?= e($session['interview_date']) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="../results/export.php?type=candidate_attendance&format=pdf&session_id=<?= $sessionId ?>" class="btn btn-sm btn-mys-crimson"><i class="bi bi-file-pdf me-1"></i>Candidate Register PDF</a>
            <a href="../results/export.php?type=candidate_attendance&format=excel&session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-excel me-1"></i>Candidate Excel</a>
            <a href="../results/export.php?type=panelist_attendance&format=pdf&session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-pdf me-1"></i>Panelist Register PDF</a>
            <a href="dashboard.php?session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary">&larr; Physical Dashboard</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <!-- Candidate Attendance Register -->
    <div class="mys-card mb-4">
        <div class="mys-card-header d-flex justify-content-between align-items-center">
            <span>Candidate Attendance Register (<?= count($candidates) ?> Registered)</span>
            <span class="small text-muted">Panelist Attendance: <?= $panelistsPresentCount ?> / <?= count($panelists) ?> Present</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>App #</th>
                        <th>Candidate Name</th>
                        <th>Scheduled Time</th>
                        <th>Actual Arrival</th>
                        <th>Attendance Status</th>
                        <th>Panelist Attendance</th>
                        <th>Remarks</th>
                        <th class="no-print text-end">Save</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($candidates as $c): ?>
                    <tr>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                            <input type="hidden" name="action" value="save_candidate_row">
                            <input type="hidden" name="candidate_id" value="<?= (int)$c['candidate_id'] ?>">
                            <td class="small fw-semibold"><?= e($c['application_number']) ?></td>
                            <td class="fw-semibold"><?= e($c['full_name']) ?></td>
                            <td class="small"><?= e($c['scheduled_time'] ? substr($c['scheduled_time'], 0, 5) : '—') ?></td>
                            <td style="width:130px;">
                                <input type="time" name="arrival_time_only" class="form-control form-control-sm" value="<?= e($c['actual_arrival_time'] ? substr($c['actual_arrival_time'], 11, 5) : '') ?>">
                            </td>
                            <td style="width:140px;">
                                <select name="attendance_status" class="form-select form-select-sm">
                                    <?php foreach (AttendanceService::VALID_STATUSES as $st): ?>
                                        <option value="<?= $st ?>" <?= $c['attendance_status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="small text-muted"><?= $panelistsPresentCount ?> / <?= count($panelists) ?> Present</td>
                            <td>
                                <input type="text" name="remarks" class="form-control form-control-sm" value="<?= e($c['remarks'] ?? '') ?>" placeholder="Notes...">
                            </td>
                            <td class="no-print text-end">
                                <button type="submit" class="btn btn-sm btn-mys">Save</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Panelist Attendance Register -->
    <div class="mys-card">
        <div class="mys-card-header">Panelist Attendance Register (<?= count($panelists) ?> Assigned)</div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>Panelist Name</th>
                        <th>Panel Role</th>
                        <th>Check-In Time</th>
                        <th>Attendance Status</th>
                        <th>Remarks</th>
                        <th class="no-print text-end">Save</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($panelists as $p): ?>
                    <tr>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                            <input type="hidden" name="action" value="save_panelist_row">
                            <input type="hidden" name="panelist_id" value="<?= (int)$p['panelist_id'] ?>">
                            <td class="fw-semibold"><?= e($p['full_name']) ?></td>
                            <td class="small"><?= e(ucwords(str_replace('_', ' ', $p['panel_role']))) ?></td>
                            <td class="small"><?= e($p['check_in_time'] ?: '—') ?></td>
                            <td style="width:140px;">
                                <select name="attendance_status" class="form-select form-select-sm">
                                    <?php foreach (AttendanceService::VALID_STATUSES as $st): ?>
                                        <option value="<?= $st ?>" <?= $p['attendance_status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="remarks" class="form-control form-control-sm" value="<?= e($p['remarks'] ?? '') ?>" placeholder="Notes...">
                            </td>
                            <td class="no-print text-end">
                                <button type="submit" class="btn btn-sm btn-mys">Save</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
