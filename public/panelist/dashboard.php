<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\ScoringEngine;

$user = Auth::requireAuth('../login.php');
$pdo = Database::getConnection();
$scoringEngine = new ScoringEngine($pdo);
$panelistId = (int)$user['id'];

$stmt = $pdo->prepare(
    "SELECT s.*, d.name AS department_name, v.venue_name, v.room_number,
            sp.panel_role, COALESCE(rr.is_released, 0) AS is_released
     FROM session_panelists sp
     INNER JOIN interview_sessions s ON s.id = sp.session_id
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     LEFT JOIN result_releases rr ON rr.session_id = s.id
     WHERE sp.panelist_id = :pid
     ORDER BY s.interview_date DESC, s.start_time ASC"
);
$stmt->execute(['pid' => $panelistId]);
$sessions = $stmt->fetchAll();

$sessionCandidates = [];
foreach ($sessions as $s) {
    $sid = (int)$s['id'];
    $cStmt = $pdo->prepare(
        "SELECT sc.*, c.application_number, c.full_name,
                ps.status AS my_status, ps.total_score AS my_score, ps.max_possible_score AS my_max
         FROM session_candidates sc
         INNER JOIN candidates c ON c.id = sc.candidate_id
         LEFT JOIN panel_submissions ps
            ON ps.session_id = sc.session_id AND ps.candidate_id = sc.candidate_id AND ps.panelist_id = :pid
         WHERE sc.session_id = :sid
         ORDER BY sc.interview_order ASC, sc.scheduled_time ASC"
    );
    $cStmt->execute(['pid' => $panelistId, 'sid' => $sid]);
    $allCands = $cStmt->fetchAll();
    // Keep only candidates this panelist is authorized to assess
    $sessionCandidates[$sid] = array_values(array_filter(
        $allCands,
        static fn($c) => $scoringEngine->canPanelistScoreCandidate($sid, (int)$c['candidate_id'], $panelistId)
    ));
}

$pageTitle = 'My Panel Assignments';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4">
        <h1 class="page-title"><i class="bi bi-clipboard-check me-2"></i>My Interview Panel Assignments</h1>
        <p class="page-subtitle">Assess assigned candidates independently for both physical and virtual interviews. Your scores remain confidential until official release.</p>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
<?php if (empty($sessions)): ?>
    <div class="mys-card p-5 text-center">
        <i class="bi bi-calendar2-check fs-1 text-muted d-block mb-2"></i>
        <h5>No Interview Sessions Assigned Yet</h5>
        <p class="text-muted">When the Ministry Administrator assigns you to a physical or virtual recruitment panel, it will appear here.</p>
    </div>
<?php else: ?>
    <?php foreach ($sessions as $s): ?>
        <?php $sid = (int)$s['id']; $cands = $sessionCandidates[$sid] ?? []; ?>
        <div class="mys-card mb-4">
            <div class="mys-card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <span class="badge <?= $s['interview_mode'] === 'physical' ? 'badge-mode-physical' : 'badge-mode-virtual' ?> me-2">
                        <i class="bi bi-<?= $s['interview_mode'] === 'physical' ? 'building' : 'camera-video' ?> me-1"></i><?= strtoupper($s['interview_mode']) ?>
                    </span>
                    <span class="fw-bold"><?= e($s['title']) ?></span>
                    <span class="text-muted small ms-1">&middot; <?= e($s['job_title']) ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($s['interview_mode'] === 'physical'): ?>
                        <a href="../physical/dashboard.php?session_id=<?= $sid ?>" class="btn btn-sm btn-outline-primary">Physical Dashboard</a>
                    <?php else: ?>
                        <a href="../virtual/room.php?session_id=<?= $sid ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-camera-video me-1"></i>Join Virtual Room</a>
                    <?php endif; ?>
                    <?php if ((int)$s['is_released'] === 1): ?>
                        <a href="../results/index.php?session_id=<?= $sid ?>" class="btn btn-sm btn-success"><i class="bi bi-trophy me-1"></i>View Released Results</a>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-dark border align-self-center"><i class="bi bi-lock me-1"></i>Blind Scoring Active</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="px-3 py-2 bg-light border-bottom small text-muted">
                <?= e($s['department_name']) ?> &middot; Date: <strong><?= e($s['interview_date']) ?></strong> (<?= e(substr($s['start_time'], 0, 5)) ?> - <?= e(substr($s['end_time'], 0, 5)) ?>)
                <?php if ($s['interview_mode'] === 'physical'): ?>
                    &middot; Venue: <strong><?= e($s['venue_name'] ?: 'TBD') ?> <?= $s['room_number'] ? '(' . e($s['room_number']) . ')' : '' ?></strong>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Candidate</th>
                            <th>Interview Status</th>
                            <th>My Assessment (Private)</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($cands)): ?>
                        <tr><td colspan="5" class="text-center py-3 text-muted small">No candidates assigned to this session yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($cands as $c): ?>
                            <tr>
                                <td class="small"><?= e($c['scheduled_time'] ? substr($c['scheduled_time'], 0, 5) : 'TBD') ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($c['full_name']) ?></div>
                                    <div class="small text-muted"><?= e($c['application_number']) ?></div>
                                </td>
                                <td><span class="badge bg-secondary-subtle text-dark border"><?= e(ucwords(str_replace('_', ' ', $c['interview_status']))) ?></span></td>
                                <td class="small">
                                    <?php if ($c['my_status'] === 'submitted'): ?>
                                        <span class="badge bg-success">Submitted</span>
                                        <strong class="ms-1"><?= number_format((float)$c['my_score'], 2) ?> / <?= number_format((float)$c['my_max'], 0) ?></strong>
                                    <?php elseif ($c['my_status'] === 'draft'): ?>
                                        <span class="badge bg-warning text-dark">Draft Saved</span>
                                        <span class="ms-1"><?= number_format((float)$c['my_score'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="score.php?session_id=<?= $sid ?>&candidate_id=<?= (int)$c['candidate_id'] ?>" class="btn btn-sm <?= $c['my_status'] === 'submitted' ? 'btn-outline-secondary' : 'btn-mys' ?>">
                                        <i class="bi bi-pencil-square me-1"></i><?= $c['my_status'] === 'submitted' ? 'Review My Score' : 'Score Candidate' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
