<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\ScoringEngine;

$user = Auth::requireRole(['admin', 'hr_officer'], '../panelist/dashboard.php');
$pdo = Database::getConnection();
$scoringEngine = new ScoringEngine($pdo);

$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM interview_sessions WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $sessionId]);
$session = $stmt->fetch();
if (!$session) {
    Response::flash('danger', 'Interview session not found.');
    Response::redirect('sessions.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_criterion') {
            $name = trim((string)($_POST['criterion_name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $maxMarks = (float)($_POST['max_marks'] ?? 0);
            $order = max(1, (int)($_POST['display_order'] ?? 1));
            if ($name === '' || $maxMarks <= 0) {
                throw new RuntimeException('Criterion name and positive maximum marks are required.');
            }
            $pdo->prepare(
                'INSERT INTO scoring_criteria (session_id, criterion_name, description, max_marks, display_order)
                 VALUES (:sid, :name, :desc, :max, :ord)'
            )->execute(['sid' => $sessionId, 'name' => $name, 'desc' => $desc ?: null, 'max' => $maxMarks, 'ord' => $order]);
            AuditLogger::log($pdo, (int)$user['id'], 'criterion_added', 'scoring_criteria', $sessionId, "Added {$name} ({$maxMarks})");
            Response::flash('success', 'Scoring criterion added.');
        } elseif ($action === 'update_criterion') {
            $critId = (int)($_POST['criterion_id'] ?? 0);
            $name = trim((string)($_POST['criterion_name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $maxMarks = (float)($_POST['max_marks'] ?? 0);
            $order = max(1, (int)($_POST['display_order'] ?? 1));
            $pdo->prepare(
                'UPDATE scoring_criteria SET criterion_name = :name, description = :desc, max_marks = :max, display_order = :ord
                 WHERE id = :id AND session_id = :sid'
            )->execute(['name' => $name, 'desc' => $desc ?: null, 'max' => $maxMarks, 'ord' => $order, 'id' => $critId, 'sid' => $sessionId]);
            Response::flash('success', 'Scoring criterion updated.');
        } elseif ($action === 'delete_criterion') {
            $critId = (int)($_POST['criterion_id'] ?? 0);
            $pdo->prepare('DELETE FROM scoring_criteria WHERE id = :id AND session_id = :sid')
                ->execute(['id' => $critId, 'sid' => $sessionId]);
            Response::flash('info', 'Scoring criterion removed.');
        } elseif ($action === 'reset_defaults') {
            $pdo->prepare('DELETE FROM scoring_criteria WHERE session_id = :sid')->execute(['sid' => $sessionId]);
            $scoringEngine->seedDefaultCriteria($sessionId);
            Response::flash('success', 'Criteria reset to the official 100-mark Ministry of Youth and Sports standard.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', $e->getMessage());
    }
    Response::redirect('criteria.php?session_id=' . $sessionId);
}

$criteria = $scoringEngine->getSessionCriteria($sessionId);
$totalMarks = array_sum(array_map(static fn($c) => (float)$c['max_marks'], $criteria));

$pageTitle = 'Scoring Criteria — ' . $session['title'];
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><i class="bi bi-list-check me-2"></i>Configurable Scoring Criteria</h1>
            <p class="page-subtitle"><?= e($session['title']) ?> (<?= e($session['job_title']) ?>) &middot; Current Total: <strong><?= number_format($totalMarks, 2) ?> marks</strong></p>
        </div>
        <div class="d-flex gap-2">
            <form method="post" onsubmit="return confirm('Reset criteria to the standard 100-mark Ministry template? Existing criterion scores for this session will be removed.');">
                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <input type="hidden" name="action" value="reset_defaults">
                <button class="btn btn-sm btn-outline-warning">Reset to 100-Mark Default</button>
            </form>
            <a href="session_view.php?id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary">&larr; Back to Session</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <?php if (abs($totalMarks - 100.0) > 0.01): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Current total weight is <strong><?= number_format($totalMarks, 2) ?> marks</strong>. Standard Ministry scoring totals <strong>100 marks</strong> (percentage scores are still automatically normalized).
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="mys-card">
                <div class="mys-card-header">Active Criteria (<?= count($criteria) ?>)</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Criterion &amp; Description</th>
                                <th style="width:120px;">Max Marks</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($criteria as $c): ?>
                            <tr>
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                    <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                                    <input type="hidden" name="action" value="update_criterion">
                                    <input type="hidden" name="criterion_id" value="<?= (int)$c['id'] ?>">
                                    <td style="width:75px;">
                                        <input type="number" name="display_order" class="form-control form-control-sm" value="<?= (int)$c['display_order'] ?>" min="1">
                                    </td>
                                    <td>
                                        <input type="text" name="criterion_name" class="form-control form-control-sm fw-semibold mb-1" value="<?= e($c['criterion_name']) ?>" required>
                                        <input type="text" name="description" class="form-control form-control-sm text-muted" value="<?= e($c['description'] ?? '') ?>" placeholder="Guidance for panelists...">
                                    </td>
                                    <td>
                                        <input type="number" step="0.5" min="1" name="max_marks" class="form-control form-control-sm fw-bold" value="<?= e((string)$c['max_marks']) ?>" required>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="submit" class="btn btn-sm btn-mys">Save</button>
                                </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this criterion?');">
                                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                                            <input type="hidden" name="action" value="delete_criterion">
                                            <input type="hidden" name="criterion_id" value="<?= (int)$c['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="2" class="text-end">Total Maximum Marks:</td>
                                <td colspan="2"><?= number_format($totalMarks, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="mys-card p-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-1"></i>Add Custom Criterion</h6>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                    <input type="hidden" name="action" value="add_criterion">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Criterion Name *</label>
                        <input type="text" name="criterion_name" class="form-control form-control-sm" required placeholder="e.g., Public Policy Analysis">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Description / Rubric</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Max Marks *</label>
                            <input type="number" step="0.5" min="1" name="max_marks" class="form-control form-control-sm" required value="10">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Display Order</label>
                            <input type="number" min="1" name="display_order" class="form-control form-control-sm" value="<?= count($criteria) + 1 ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-mys btn-sm w-100">Add Criterion</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
