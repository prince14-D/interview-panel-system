<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\ScoringEngine;

$user = Auth::requireAuth('../login.php');
$pdo = Database::getConnection();
$scoringEngine = new ScoringEngine($pdo);

$sessionId = (int)($_GET['session_id'] ?? 0);
$candidateId = (int)($_GET['candidate_id'] ?? 0);

$sStmt = $pdo->prepare(
    'SELECT s.*, d.name AS department_name, v.venue_name, v.room_number
     FROM interview_sessions s
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     WHERE s.id = :id LIMIT 1'
);
$sStmt->execute(['id' => $sessionId]);
$session = $sStmt->fetch();
if (!$session) {
    Response::flash('danger', 'Interview session not found.');
    Response::redirect('index.php');
}

try {
    $report = $scoringEngine->getCandidateDetailedReport($sessionId, $candidateId, $user);
} catch (Throwable $e) {
    Response::flash('danger', $e->getMessage());
    Response::redirect('index.php?session_id=' . $sessionId);
}

$cand = $report['candidate'];
$criteria = $report['criteria'];
$submissions = $report['submissions'];
$agg = $report['aggregate'];

$pageTitle = 'Candidate Assessment Report — ' . $cand['full_name'];
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-file-earmark-person me-2"></i>Candidate Assessment Report</h1>
            <p class="page-subtitle"><?= e($cand['full_name']) ?> (<?= e($cand['application_number']) ?>) &middot; <?= e($session['title']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($report['can_view_all']): ?>
                <a href="export.php?type=candidate_assessment&format=pdf&session_id=<?= $sessionId ?>&candidate_id=<?= $candidateId ?>" class="btn btn-sm btn-mys-crimson"><i class="bi bi-file-pdf me-1"></i>Download PDF</a>
                <a href="export.php?type=candidate_assessment&format=excel&session_id=<?= $sessionId ?>&candidate_id=<?= $candidateId ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-excel me-1"></i>Download Excel</a>
            <?php endif; ?>
            <a href="index.php?session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary">&larr; Back to Results</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <?php if ($agg): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card stat-success">
                    <div class="stat-label">Final Average Score</div>
                    <div class="stat-value"><?= number_format((float)$agg['average_score'], 2) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label">Average Percentage</div>
                    <div class="stat-value"><?= number_format((float)$agg['average_percentage'], 1) ?>%</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card stat-gold">
                    <div class="stat-label">Submitted Evaluations</div>
                    <div class="stat-value"><?= (int)$agg['panelist_count'] ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Criterion Breakdown Table -->
    <div class="mys-card mb-4">
        <div class="mys-card-header">Assessment Breakdown by Criterion</div>
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead>
                    <tr>
                        <th>Criterion</th>
                        <th>Max Marks</th>
                        <?php foreach ($submissions as $s): ?>
                            <th><?= e($s['panelist_name']) ?></th>
                        <?php endforeach; ?>
                        <?php if ($agg): ?>
                            <th>Panel Average</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($criteria as $c): ?>
                    <?php $cid = (int)$c['id']; ?>
                    <tr>
                        <td class="fw-semibold"><?= e($c['criterion_name']) ?></td>
                        <td><?= number_format((float)$c['max_marks'], 2) ?></td>
                        <?php foreach ($submissions as $s): ?>
                            <td><?= isset($s['scores'][$cid]) ? number_format((float)$s['scores'][$cid]['score'], 2) : '—' ?></td>
                        <?php endforeach; ?>
                        <?php if ($agg): ?>
                            <td class="fw-bold text-primary"><?= number_format((float)($agg['criterion_averages'][$cid] ?? 0), 2) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td>Total</td>
                        <td><?= number_format(array_sum(array_map(static fn($c) => (float)$c['max_marks'], $criteria)), 2) ?></td>
                        <?php foreach ($submissions as $s): ?>
                            <td><?= number_format((float)$s['total_score'], 2) ?> (<?= number_format((float)$s['percentage_score'], 1) ?>%)</td>
                        <?php endforeach; ?>
                        <?php if ($agg): ?>
                            <td class="text-primary"><?= number_format((float)$agg['average_score'], 2) ?> (<?= number_format((float)$agg['average_percentage'], 1) ?>%)</td>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Panelist Comments & Recommendations -->
    <div class="mys-card">
        <div class="mys-card-header">Panelist Comments &amp; Recommendations</div>
        <div class="p-3">
            <?php if (empty($submissions)): ?>
                <div class="text-muted small">No submitted assessments available to display.</div>
            <?php else: ?>
                <?php foreach ($submissions as $s): ?>
                    <div class="border rounded p-3 mb-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong><?= e($s['panelist_name']) ?></strong>
                                <span class="text-muted small"> &middot; <?= e(ucwords(str_replace('_', ' ', (string)($s['panel_role'] ?? 'member')))) ?></span>
                            </div>
                            <span class="badge bg-primary"><?= e(ScoringEngine::RECOMMENDATIONS[$s['recommendation']] ?? 'Not specified') ?></span>
                        </div>
                        <?php if (!empty($s['strengths'])): ?>
                            <div class="small mb-1"><strong>Strengths:</strong> <?= e($s['strengths']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($s['areas_for_improvement'])): ?>
                            <div class="small mb-1"><strong>Areas for Improvement:</strong> <?= e($s['areas_for_improvement']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($s['overall_comments'])): ?>
                            <div class="small"><strong>Comments:</strong> <?= nl2br(e($s['overall_comments'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
