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

$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$candidateId = (int)($_GET['candidate_id'] ?? $_POST['candidate_id'] ?? 0);
$panelistId = (int)$user['id'];

if (!$scoringEngine->canPanelistScoreCandidate($sessionId, $candidateId, $panelistId)) {
    Response::flash('danger', 'You are not assigned to score this candidate.');
    Response::redirect(Auth::isAdmin() ? '../admin/dashboard.php' : 'dashboard.php');
}

$sStmt = $pdo->prepare(
    'SELECT s.*, d.name AS department_name, v.venue_name, v.room_number
     FROM interview_sessions s
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     WHERE s.id = :id LIMIT 1'
);
$sStmt->execute(['id' => $sessionId]);
$session = $sStmt->fetch();

$cStmt = $pdo->prepare('SELECT * FROM candidates WHERE id = :id LIMIT 1');
$cStmt->execute(['id' => $candidateId]);
$candidate = $cStmt->fetch();

if (!$session || !$candidate) {
    Response::flash('danger', 'Session or candidate not found.');
    Response::redirect('dashboard.php');
}

$criteria = $scoringEngine->getSessionCriteria($sessionId);
$isReleased = $scoringEngine->isSessionReleased($sessionId);
$existing = $scoringEngine->getOwnSubmission($sessionId, $candidateId, $panelistId);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $submitFinal = ($_POST['submit_mode'] ?? 'draft') === 'submitted';

    try {
        $scoringEngine->saveAssessment(
            $sessionId,
            $candidateId,
            $panelistId,
            $_POST['scores'] ?? [],
            $_POST['remarks'] ?? [],
            $_POST['recommendation'] ?: null,
            (string)($_POST['overall_comments'] ?? ''),
            (string)($_POST['strengths'] ?? ''),
            (string)($_POST['areas_for_improvement'] ?? ''),
            $submitFinal
        );
        Response::flash('success', $submitFinal
            ? 'Final assessment submitted securely. Scores remain confidential until official release.'
            : 'Draft assessment saved.');
        Response::redirect('score.php?session_id=' . $sessionId . '&candidate_id=' . $candidateId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$maxTotal = array_sum(array_map(static fn($c) => (float)$c['max_marks'], $criteria));
$pageTitle = 'Score Candidate — ' . $candidate['full_name'];
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge <?= $session['interview_mode'] === 'physical' ? 'badge-mode-physical' : 'badge-mode-virtual' ?>">
                    <?= strtoupper($session['interview_mode']) ?> INTERVIEW
                </span>
                <span class="badge bg-warning-subtle text-dark border"><i class="bi bi-shield-lock me-1"></i>Blind Independent Scoring</span>
            </div>
            <h1 class="page-title"><?= e($candidate['full_name']) ?> <span class="text-muted fs-6">(<?= e($candidate['application_number']) ?>)</span></h1>
            <p class="page-subtitle"><?= e($session['title']) ?> &middot; <?= e($session['job_title']) ?> &middot; <?= e($session['department_name']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($session['interview_mode'] === 'physical'): ?>
                <a href="../physical/dashboard.php?session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary">&larr; Physical Dashboard</a>
            <?php else: ?>
                <a href="../virtual/dashboard.php?session_id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary">&larr; Virtual Dashboard</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-sm btn-outline-primary">My Assignments</a>
        </div>
    </div>
</div>

<div class="container px-3 px-lg-4" style="max-width:960px;">
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($isReleased): ?>
        <div class="alert alert-info small">
            <i class="bi bi-lock-fill me-1"></i>Results for this session have been officially released. Your submitted assessment is now locked in read-only mode.
        </div>
    <?php else: ?>
        <div class="alert alert-light border small mb-3">
            <i class="bi bi-eye-slash text-primary me-1"></i>
            <strong>Confidentiality Notice:</strong> You are evaluating independently. Other panelists cannot see your scores, and you cannot see theirs until the Administrator officially releases the session results.
        </div>
    <?php endif; ?>

    <!-- Candidate Quick Profile -->
    <div class="mys-card p-3 mb-3">
        <div class="row g-2 small">
            <div class="col-sm-4"><strong>Qualification:</strong> <?= e($candidate['highest_qualification'] ?: 'Not specified') ?></div>
            <div class="col-sm-4"><strong>Experience:</strong> <?= number_format((float)$candidate['years_experience'], 1) ?> years</div>
            <div class="col-sm-4"><strong>County:</strong> <?= e($candidate['county'] ?: 'Liberia') ?></div>
        </div>
    </div>

    <form method="post" id="scoringForm">
        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
        <input type="hidden" name="candidate_id" value="<?= $candidateId ?>">

        <!-- Live Score Summary Sticky Bar -->
        <div class="mys-card p-3 mb-3 sticky-top" style="top:64px; z-index:100; border-left:5px solid #0B2545;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted text-uppercase fw-bold">Live Calculated Score</div>
                    <div class="fs-4 fw-bold text-primary">
                        <span id="liveTotal">0.00</span> / <?= number_format($maxTotal, 2) ?>
                        <span class="fs-6 text-muted">(<span id="livePct">0.0</span>%)</span>
                    </div>
                </div>
                <div class="text-end">
                    <?php if ($existing): ?>
                        <span class="badge bg-<?= $existing['status'] === 'submitted' ? 'success' : 'warning text-dark' ?>">
                            Status: <?= e(ucfirst($existing['status'])) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary">New Evaluation</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Criteria Cards (Responsive for Laptops, Tablets, and Smartphones) -->
        <?php foreach ($criteria as $idx => $c): ?>
            <?php
                $cid = (int)$c['id'];
                $savedScore = $existing['scores'][$cid]['score'] ?? '';
                $savedRem = $existing['scores'][$cid]['remarks'] ?? '';
            ?>
            <div class="criterion-card">
                <div class="row g-3 align-items-center">
                    <div class="col-md-7">
                        <div class="fw-bold text-dark"><?= ($idx + 1) ?>. <?= e($c['criterion_name']) ?></div>
                        <?php if (!empty($c['description'])): ?>
                            <div class="small text-muted"><?= e($c['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-5 d-flex align-items-center justify-content-md-end gap-2">
                        <label class="small fw-semibold text-muted mb-0">Score:</label>
                        <input type="number"
                               name="scores[<?= $cid ?>]"
                               class="form-control score-input-lg score-field"
                               step="0.5"
                               min="0"
                               max="<?= e((string)$c['max_marks']) ?>"
                               data-max="<?= e((string)$c['max_marks']) ?>"
                               value="<?= e((string)$savedScore) ?>"
                               <?= $isReleased ? 'readonly' : '' ?>
                               required>
                        <span class="fw-bold text-muted">/ <?= number_format((float)$c['max_marks'], 0) ?></span>
                    </div>
                    <div class="col-12">
                        <input type="text" name="remarks[<?= $cid ?>]" class="form-control form-control-sm"
                               value="<?= e($savedRem) ?>"
                               placeholder="Brief justification or observation for <?= e($c['criterion_name']) ?> (optional)"
                               <?= $isReleased ? 'readonly' : '' ?>>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Overall Evaluation & Recommendation -->
        <div class="mys-card p-3 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-chat-square-text me-1"></i>Panelist Comments &amp; Final Recommendation</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Key Strengths</label>
                    <textarea name="strengths" class="form-control form-control-sm" rows="2" <?= $isReleased ? 'readonly' : '' ?>><?= e($existing['strengths'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Areas for Improvement</label>
                    <textarea name="areas_for_improvement" class="form-control form-control-sm" rows="2" <?= $isReleased ? 'readonly' : '' ?>><?= e($existing['areas_for_improvement'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Overall Panelist Comments</label>
                    <textarea name="overall_comments" class="form-control form-control-sm" rows="3" <?= $isReleased ? 'readonly' : '' ?>><?= e($existing['overall_comments'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Final Recommendation *</label>
                    <select name="recommendation" class="form-select" <?= $isReleased ? 'disabled' : '' ?>>
                        <option value="">-- Select Recommendation --</option>
                        <?php foreach (ScoringEngine::RECOMMENDATIONS as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= (($existing['recommendation'] ?? '') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php if (!$isReleased): ?>
        <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mb-5">
            <button type="submit" name="submit_mode" value="draft" class="btn btn-outline-secondary px-4">
                <i class="bi bi-save me-1"></i>Save as Draft
            </button>
            <button type="submit" name="submit_mode" value="submitted" class="btn btn-mys px-4 fw-semibold" onclick="return confirm('Submit your final independent evaluation for this candidate?');">
                <i class="bi bi-check2-circle me-1"></i>Submit Final Assessment
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const fields = document.querySelectorAll('.score-field');
    const maxTotal = <?= json_encode((float)$maxTotal) ?>;
    const totalEl = document.getElementById('liveTotal');
    const pctEl = document.getElementById('livePct');

    function recalc() {
        let sum = 0;
        fields.forEach(f => {
            const v = parseFloat(f.value);
            const max = parseFloat(f.dataset.max);
            if (!isNaN(v)) {
                if (v > max) f.classList.add('is-invalid');
                else f.classList.remove('is-invalid');
                sum += Math.max(0, Math.min(v, max));
            }
        });
        totalEl.textContent = sum.toFixed(2);
        const pct = maxTotal > 0 ? (sum / maxTotal) * 100 : 0;
        pctEl.textContent = pct.toFixed(1);
    }

    fields.forEach(f => f.addEventListener('input', recalc));
    recalc();
});
</script>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
