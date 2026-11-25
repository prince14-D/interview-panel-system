<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\ScoringEngine;

$user = Auth::requireRole(['admin', 'hr_officer'], '../panelist/dashboard.php');
$pdo = Database::getConnection();
$scoringEngine = new ScoringEngine($pdo);

// Filters
$filterMode = $_GET['mode'] ?? '';
$filterDept = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int)$_GET['department_id'] : null;
$filterJob = trim((string)($_GET['job_title'] ?? ''));
$filterDate = trim((string)($_GET['interview_date'] ?? ''));
$filterStatus = $_GET['status'] ?? '';

$departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$jobPositions = $pdo->query('SELECT DISTINCT job_title FROM interview_sessions ORDER BY job_title ASC')->fetchAll(PDO::FETCH_COLUMN);

// Global real-time statistics (zero fake data)
$totalCandidates = (int)$pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
$totalSessions = (int)$pdo->query('SELECT COUNT(*) FROM interview_sessions')->fetchColumn();
$physicalSessions = (int)$pdo->query("SELECT COUNT(*) FROM interview_sessions WHERE interview_mode = 'physical'")->fetchColumn();
$virtualSessions = (int)$pdo->query("SELECT COUNT(*) FROM interview_sessions WHERE interview_mode = 'virtual'")->fetchColumn();
$assignedPanelists = (int)$pdo->query('SELECT COUNT(DISTINCT panelist_id) FROM session_panelists')->fetchColumn();
$completedAssessments = (int)$pdo->query("SELECT COUNT(*) FROM panel_submissions WHERE status = 'submitted'")->fetchColumn();

// Compute total expected assessments across active/scheduled/completed sessions to derive real pending assessments
$allSessionIds = $pdo->query("SELECT id FROM interview_sessions WHERE status NOT IN ('cancelled')")->fetchAll(PDO::FETCH_COLUMN);
$totalPendingAssessments = 0;
foreach ($allSessionIds as $sid) {
    $prog = $scoringEngine->getSessionSubmissionProgress((int)$sid);
    $totalPendingAssessments += $prog['total_pending'];
}

$awaitingRelease = (int)$pdo->query(
    "SELECT COUNT(DISTINCT s.id)
     FROM interview_sessions s
     INNER JOIN panel_submissions ps ON ps.session_id = s.id AND ps.status = 'submitted'
     LEFT JOIN result_releases rr ON rr.session_id = s.id
     WHERE COALESCE(rr.is_released, 0) = 0"
)->fetchColumn();

// Filtered sessions query
$where = [];
$params = [];

if (in_array($filterMode, ['physical', 'virtual'], true)) {
    $where[] = 's.interview_mode = :mode';
    $params['mode'] = $filterMode;
}
if ($filterDept !== null) {
    $where[] = 's.department_id = :dept_id';
    $params['dept_id'] = $filterDept;
}
if ($filterJob !== '') {
    $where[] = 's.job_title LIKE :job';
    $params['job'] = '%' . $filterJob . '%';
}
if ($filterDate !== '') {
    $where[] = 's.interview_date = :idate';
    $params['idate'] = $filterDate;
}
if (in_array($filterStatus, ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled', 'archived'], true)) {
    $where[] = 's.status = :status';
    $params['status'] = $filterStatus;
}

$sql = "SELECT s.*, d.name AS department_name, v.venue_name, v.room_number,
               vr.provider AS video_provider, vr.meeting_status,
               COALESCE(rr.is_released, 0) AS is_released,
               (SELECT COUNT(*) FROM session_candidates sc WHERE sc.session_id = s.id) AS candidate_count,
               (SELECT COUNT(*) FROM session_panelists sp WHERE sp.session_id = s.id) AS panelist_count,
               (SELECT COUNT(*) FROM panel_submissions ps WHERE ps.session_id = s.id AND ps.status = 'submitted') AS submitted_count
        FROM interview_sessions s
        INNER JOIN departments d ON d.id = s.department_id
        LEFT JOIN interview_venues v ON v.id = s.venue_id
        LEFT JOIN video_interview_rooms vr ON vr.session_id = s.id
        LEFT JOIN result_releases rr ON rr.session_id = s.id";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY s.interview_date DESC, s.start_time ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sessions = $stmt->fetchAll();

// Department breakdown for Chart.js
$deptStats = $pdo->query(
    'SELECT d.name, COUNT(s.id) AS total
     FROM departments d
     LEFT JOIN interview_sessions s ON s.department_id = d.id
     GROUP BY d.id, d.name
     HAVING COUNT(s.id) > 0
     ORDER BY total DESC'
)->fetchAll();

$pageTitle = 'Unified Administrator Dashboard';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="container-fluid px-3 px-lg-4 pt-3">
    <!-- Executive Hero Banner -->
    <div class="mys-hero mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-lg-7">
                <div class="mys-hero-kicker mb-1"><i class="bi bi-shield-check me-1"></i>Republic of Liberia &middot; Ministry of Youth and Sports</div>
                <h1 class="h3 fw-bold mb-1">Unified Recruitment Command Center</h1>
                <p class="mb-0 text-white-50 small">Manage Physical (In-Person) and Virtual (Online WebRTC) interview panels, monitor live assessment progress, and release official results.</p>
            </div>
            <div class="col-lg-5 d-flex flex-wrap justify-content-lg-end gap-2">
                <a href="session_form.php?mode=physical" class="btn btn-hero-gold btn-sm px-3 py-2">
                    <i class="bi bi-building-add me-1"></i>New Physical Session
                </a>
                <a href="session_form.php?mode=virtual" class="btn btn-light btn-sm px-3 py-2 text-dark">
                    <i class="bi bi-camera-video me-1"></i>New Virtual Session
                </a>
                <a href="candidates.php" class="btn btn-outline-light btn-sm px-3 py-2">
                    <i class="bi bi-person-plus me-1"></i>Add Candidate
                </a>
            </div>
        </div>
    </div>

    <!-- 8 Live Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Total Candidates</div>
                        <div class="stat-value"><?= $totalCandidates ?></div>
                        <small class="text-muted">Registered applicants</small>
                    </div>
                    <span class="stat-icon stat-icon-navy"><i class="bi bi-people-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Interview Sessions</div>
                        <div class="stat-value"><?= $totalSessions ?></div>
                        <small class="text-muted">All departments &amp; roles</small>
                    </div>
                    <span class="stat-icon stat-icon-navy"><i class="bi bi-calendar2-week-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Physical Interviews</div>
                        <div class="stat-value"><?= $physicalSessions ?></div>
                        <a href="../physical/dashboard.php" class="small text-decoration-none fw-semibold">Open Physical Hub &rarr;</a>
                    </div>
                    <span class="stat-icon stat-icon-physical"><i class="bi bi-building-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Virtual Interviews</div>
                        <div class="stat-value"><?= $virtualSessions ?></div>
                        <a href="../virtual/dashboard.php" class="small text-decoration-none fw-semibold" style="color:#6D28D9;">Open Virtual Hub &rarr;</a>
                    </div>
                    <span class="stat-icon stat-icon-virtual"><i class="bi bi-camera-video-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Assigned Panelists</div>
                        <div class="stat-value"><?= $assignedPanelists ?></div>
                        <small class="text-muted">Active evaluators</small>
                    </div>
                    <span class="stat-icon stat-icon-gold"><i class="bi bi-person-badge-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Completed Scores</div>
                        <div class="stat-value"><?= $completedAssessments ?></div>
                        <small class="text-muted">Submitted evaluations</small>
                    </div>
                    <span class="stat-icon stat-icon-success"><i class="bi bi-check-circle-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Pending Scores</div>
                        <div class="stat-value"><?= $totalPendingAssessments ?></div>
                        <small class="text-muted">Awaiting submission</small>
                    </div>
                    <span class="stat-icon stat-icon-crimson"><i class="bi bi-hourglass-split"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Awaiting Release</div>
                        <div class="stat-value"><?= $awaitingRelease ?></div>
                        <a href="../results/index.php" class="small text-decoration-none fw-semibold text-danger">Review &amp; Release &rarr;</a>
                    </div>
                    <span class="stat-icon stat-icon-crimson"><i class="bi bi-shield-lock-fill"></i></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="mys-card mb-4">
        <div class="mys-card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-funnel me-2"></i>Filter Recruitment Sessions</span>
            <?php if ($filterMode || $filterDept || $filterJob || $filterDate || $filterStatus): ?>
                <a href="dashboard.php" class="btn btn-sm btn-link text-decoration-none p-0">Clear Filters</a>
            <?php endif; ?>
        </div>
        <div class="p-3">
            <form method="get" action="dashboard.php" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Interview Mode</label>
                    <select name="mode" class="form-select form-select-sm">
                        <option value="">All Modes</option>
                        <option value="physical" <?= $filterMode === 'physical' ? 'selected' : '' ?>>Physical (In-Person)</option>
                        <option value="virtual" <?= $filterMode === 'virtual' ? 'selected' : '' ?>>Virtual (Online)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Job Position</label>
                    <input type="text" name="job_title" class="form-control form-control-sm" list="jobList" value="<?= e($filterJob) ?>" placeholder="Search position...">
                    <datalist id="jobList">
                        <?php foreach ($jobPositions as $jp): ?>
                            <option value="<?= e($jp) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Interview Date</label>
                    <input type="date" name="interview_date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Session Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach (['draft', 'scheduled', 'in_progress', 'completed', 'cancelled', 'archived'] as $st): ?>
                            <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $st))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-mys btn-sm w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Sessions Table -->
        <div class="col-lg-8">
            <div class="mys-card h-100">
                <div class="mys-card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar2-week me-2"></i>Interview Sessions (<?= count($sessions) ?>)</span>
                    <a href="sessions.php" class="btn btn-sm btn-outline-secondary">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Session / Position</th>
                                <th>Mode &amp; Location</th>
                                <th>Date &amp; Time</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
                                    No interview sessions match your current filter.
                                    <div class="mt-2">
                                        <a href="session_form.php" class="btn btn-sm btn-mys">Create First Interview Session</a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $s): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($s['title']) ?></div>
                                        <div class="small text-muted"><?= e($s['job_title']) ?> &middot; <?= e($s['department_name']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($s['interview_mode'] === 'physical'): ?>
                                            <span class="badge badge-mode-physical"><i class="bi bi-building me-1"></i>Physical</span>
                                            <div class="small text-muted mt-1"><?= e($s['venue_name'] ?: 'Venue TBD') ?> <?= $s['room_number'] ? '(' . e($s['room_number']) . ')' : '' ?></div>
                                        <?php else: ?>
                                            <span class="badge badge-mode-virtual"><i class="bi bi-camera-video me-1"></i>Virtual</span>
                                            <div class="small text-muted mt-1"><?= e(strtoupper((string)($s['video_provider'] ?: 'JITSI'))) ?> &middot; <?= e(ucfirst((string)($s['meeting_status'] ?: 'scheduled'))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <div class="fw-semibold"><?= e($s['interview_date']) ?></div>
                                        <div class="text-muted"><?= e(substr($s['start_time'], 0, 5)) ?> - <?= e(substr($s['end_time'], 0, 5)) ?></div>
                                    </td>
                                    <td class="small">
                                        <div><strong><?= (int)$s['candidate_count'] ?></strong> candidates</div>
                                        <div class="text-muted"><?= (int)$s['panelist_count'] ?> panelists &middot; <?= (int)$s['submitted_count'] ?> scored</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $s['status'] === 'in_progress' ? 'primary' : ($s['status'] === 'completed' ? 'success' : 'secondary') ?>">
                                            <?= e(ucwords(str_replace('_', ' ', $s['status']))) ?>
                                        </span>
                                        <?php if ((int)$s['is_released'] === 1): ?>
                                            <div class="mt-1"><span class="badge bg-success-subtle text-success border border-success-subtle">Released</span></div>
                                        <?php else: ?>
                                            <div class="mt-1"><span class="badge bg-warning-subtle text-dark border border-warning-subtle">Blind Active</span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($s['interview_mode'] === 'physical'): ?>
                                            <a href="../physical/dashboard.php?session_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Physical Dashboard">
                                                <i class="bi bi-building"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="../virtual/dashboard.php?session_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Virtual Dashboard">
                                                <i class="bi bi-camera-video"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="session_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Manage Session">
                                            <i class="bi bi-sliders"></i>
                                        </a>
                                        <a href="../results/index.php?session_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-success" title="Results &amp; Reports">
                                            <i class="bi bi-trophy"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Analytics Charts -->
        <div class="col-lg-4">
            <div class="mys-card mb-4">
                <div class="mys-card-header"><i class="bi bi-pie-chart me-2"></i>Interview Mode Distribution</div>
                <div class="p-3">
                    <canvas id="modeChart" height="190"></canvas>
                </div>
            </div>
            <div class="mys-card">
                <div class="mys-card-header"><i class="bi bi-bar-chart me-2"></i>Assessment Completion</div>
                <div class="p-3">
                    <canvas id="assessmentChart" height="190"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modeCtx = document.getElementById('modeChart');
    if (modeCtx && window.Chart) {
        new Chart(modeCtx, {
            type: 'doughnut',
            data: {
                labels: ['Physical Interviews', 'Virtual Interviews'],
                datasets: [{
                    data: [<?= $physicalSessions ?>, <?= $virtualSessions ?>],
                    backgroundColor: ['#0E5A8A', '#5B21B6'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const assessCtx = document.getElementById('assessmentChart');
    if (assessCtx && window.Chart) {
        new Chart(assessCtx, {
            type: 'bar',
            data: {
                labels: ['Completed', 'Pending'],
                datasets: [{
                    label: 'Evaluations',
                    data: [<?= $completedAssessments ?>, <?= $totalPendingAssessments ?>],
                    backgroundColor: ['#15803D', '#BF0A30'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
});
</script>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
