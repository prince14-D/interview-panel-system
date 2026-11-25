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

// Fetch accessible sessions
if (Auth::isAdmin()) {
    $sessions = $pdo->query(
        'SELECT s.*, d.name AS department_name, COALESCE(rr.is_released, 0) AS is_released
         FROM interview_sessions s
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN result_releases rr ON rr.session_id = s.id
         ORDER BY s.interview_date DESC, s.id DESC'
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        'SELECT s.*, d.name AS department_name, COALESCE(rr.is_released, 0) AS is_released
         FROM session_panelists sp
         INNER JOIN interview_sessions s ON s.id = sp.session_id
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN result_releases rr ON rr.session_id = s.id
         WHERE sp.panelist_id = :pid
         ORDER BY s.interview_date DESC, s.id DESC'
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

// Handle Admin Result Release / Revoke POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentSession && Auth::isAdmin()) {
    Auth::requireCsrf();
    $sid = (int)$currentSession['id'];
    $action = $_POST['action'] ?? '';
    $notes = trim((string)($_POST['release_notes'] ?? ''));

    if ($action === 'release') {
        $scoringEngine->releaseResults($sid, (int)$user['id'], $notes);
        Response::flash('success', 'Official interview results have been released! Authorized panelists can now view all scores, rankings, and reports.');
    } elseif ($action === 'revoke') {
        $scoringEngine->revokeRelease($sid, (int)$user['id'], $notes);
        Response::flash('warning', 'Result release revoked. Blind scoring confidentiality is active again.');
    } elseif ($action === 'enable_live_board') {
        $showLive = isset($_POST['show_live_scores']) ? 1 : 0;
        $chk = $pdo->prepare('SELECT id FROM live_scoreboards WHERE session_id = :sid LIMIT 1');
        $chk->execute(['sid' => $sid]);
        if ($chk->fetchColumn()) {
            $pdo->prepare('UPDATE live_scoreboards SET is_enabled = 1, show_live_scores = :sl, updated_at = CURRENT_TIMESTAMP WHERE session_id = :sid')
                ->execute(['sl' => $showLive, 'sid' => $sid]);
        } else {
            $tok = bin2hex(random_bytes(16));
            $pdo->prepare('INSERT INTO live_scoreboards (session_id, access_token, is_enabled, show_live_scores, created_by) VALUES (:sid, :tok, 1, :sl, :uid)')
                ->execute(['sid' => $sid, 'tok' => $tok, 'sl' => $showLive, 'uid' => (int)$user['id']]);
        }
        Response::flash('success', 'Public Live Scoreboard link is active (no login required).');
    } elseif ($action === 'disable_live_board') {
        $pdo->prepare('UPDATE live_scoreboards SET is_enabled = 0, updated_at = CURRENT_TIMESTAMP WHERE session_id = :sid')
            ->execute(['sid' => $sid]);
        Response::flash('info', 'Public Live Scoreboard link has been disabled.');
    } elseif ($action === 'regenerate_live_board') {
        $tok = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE live_scoreboards SET access_token = :tok, is_enabled = 1, updated_at = CURRENT_TIMESTAMP WHERE session_id = :sid')
            ->execute(['tok' => $tok, 'sid' => $sid]);
        Response::flash('success', 'Live Scoreboard link regenerated. Old links will no longer work.');
    }
    Response::redirect('index.php?session_id=' . $sid);
}

$canView = false;
$isReleased = false;
$releaseInfo = null;
$rankings = [];
$progress = null;
$criteria = [];
$panelists = [];

if ($currentSession) {
    $sid = (int)$currentSession['id'];
    $isReleased = $scoringEngine->isSessionReleased($sid);
    $releaseInfo = $scoringEngine->getReleaseInfo($sid);
    $canView = $scoringEngine->canViewReleasedResults($user, $sid);
    $progress = $scoringEngine->getSessionSubmissionProgress($sid);
    $criteria = $scoringEngine->getSessionCriteria($sid);

    if ($canView) {
        $rankings = $scoringEngine->getSessionRankings($sid, $user);
    }

    $pStmt = $pdo->prepare(
        'SELECT u.id, u.full_name, sp.panel_role
         FROM session_panelists sp INNER JOIN users u ON u.id = sp.panelist_id
         WHERE sp.session_id = :sid ORDER BY u.full_name ASC'
    );
    $pStmt->execute(['sid' => $sid]);
    $panelists = $pStmt->fetchAll();

    $lbStmt = $pdo->prepare('SELECT * FROM live_scoreboards WHERE session_id = :sid LIMIT 1');
    $lbStmt->execute(['sid' => $sid]);
    $liveBoard = $lbStmt->fetch() ?: null;
}

$pageTitle = 'Results, Rankings & Official Reports';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-trophy me-2 text-warning"></i>Results, Rankings &amp; Official Reports</h1>
            <p class="page-subtitle">Unified results calculation engine for both Physical and Virtual recruitment interviews with blind-scoring enforcement.</p>
        </div>
        <?php if (!empty($sessions)): ?>
            <form method="get">
                <select name="session_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ($currentSession && (int)$currentSession['id'] === (int)$s['id']) ? 'selected' : '' ?>>
                            [<?= strtoupper($s['interview_mode']) ?>] <?= e($s['title']) ?> (<?= e($s['interview_date']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
<?php if (!$currentSession): ?>
    <div class="mys-card p-5 text-center text-muted">No interview sessions available.</div>
<?php else: ?>
    <?php $sid = (int)$currentSession['id']; ?>

    <!-- Release Control & Confidentiality Status Banner -->
    <div class="mys-card p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-lg-7">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge <?= $currentSession['interview_mode'] === 'physical' ? 'badge-mode-physical' : 'badge-mode-virtual' ?>">
                        <?= strtoupper($currentSession['interview_mode']) ?> INTERVIEW
                    </span>
                    <?php if ($isReleased): ?>
                        <span class="badge bg-success"><i class="bi bi-unlock me-1"></i>OFFICIALLY RELEASED</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill me-1"></i>BLIND SCORING ACTIVE (UNRELEASED)</span>
                    <?php endif; ?>
                </div>
                <h5 class="fw-bold mb-1"><?= e($currentSession['title']) ?> — <?= e($currentSession['job_title']) ?></h5>
                <div class="small text-muted">
                    Submission Progress: <strong><?= $progress['total_submitted'] ?> / <?= $progress['total_expected'] ?></strong> evaluations submitted (<?= $progress['completion_rate'] ?>%)
                    <?php if ($isReleased && $releaseInfo): ?>
                        &middot; Released by <strong><?= e($releaseInfo['released_by_name'] ?: 'Admin') ?></strong> on <?= e($releaseInfo['released_at'] ?? '') ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (Auth::isAdmin()): ?>
            <div class="col-lg-5 text-lg-end">
                <form method="post" class="d-inline-flex flex-wrap gap-2 justify-content-lg-end align-items-center">
                    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="text" name="release_notes" class="form-control form-control-sm" style="max-width:210px;" placeholder="Release authorization note...">
                    <?php if (!$isReleased): ?>
                        <button type="submit" name="action" value="release" class="btn btn-sm btn-success fw-semibold" onclick="return confirm('Officially release results for this session? This will lock scoring and allow assigned panelists to view all scores and rankings.');">
                            <i class="bi bi-check2-all me-1"></i>Officially Release Results
                        </button>
                    <?php else: ?>
                        <button type="submit" name="action" value="revoke" class="btn btn-sm btn-outline-danger" onclick="return confirm('Lock results and re-enable blind scoring?');">
                            <i class="bi bi-lock me-1"></i>Lock / Revoke Release
                        </button>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$canView): ?>
        <div class="mys-card p-5 text-center">
            <i class="bi bi-shield-lock-fill fs-1 text-warning d-block mb-3"></i>
            <h4>Confidentiality Protection Active</h4>
            <p class="text-muted mx-auto" style="max-width:560px;">
                Individual panelist scores, candidate averages, and final rankings for <strong><?= e($currentSession['title']) ?></strong> are hidden until the Ministry Administrator officially releases the results.
                You can review or update your own submitted evaluations from <a href="../panelist/dashboard.php">My Panel Assignments</a>.
            </p>
        </div>
    <?php else: ?>
        <!-- Official Export Center (All 7 Report Types in PDF & Excel) -->
        <?php if (Auth::isAdmin()): ?>
        <div class="mys-card p-3 mb-4" style="border-left:5px solid #15803D;">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <div class="fw-bold small text-uppercase text-success mb-1">
                        <i class="bi bi-broadcast me-1"></i>Live Public Scoreboard Link (No Account Required)
                    </div>
                    <div class="small text-muted">
                        Share a read-only, auto-refreshing live score table for executives, observers, or conference hall display screens without requiring a login.
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if (!empty($liveBoard) && (int)$liveBoard['is_enabled'] === 1): ?>
                        <?php $liveUrl = Response::baseUrl('live/scoreboard.php?token=' . urlencode((string)$liveBoard['access_token'])); ?>
                        <div class="input-group input-group-sm" style="max-width:340px;">
                            <input type="text" class="form-control form-control-sm" value="<?= e($liveUrl) ?>" readonly>
                            <button type="button" class="btn btn-outline-primary" onclick="MYS.copyText('<?= e($liveUrl) ?>', this)">
                                <i class="bi bi-clipboard"></i> Copy
                            </button>
                        </div>
                        <a href="<?= e($liveUrl) ?>" target="_blank" class="btn btn-sm btn-success">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open Live Table
                        </a>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                            <button type="submit" name="action" value="disable_live_board" class="btn btn-sm btn-outline-danger">Disable</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="d-flex flex-wrap align-items-center gap-2">
                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                            <div class="form-check form-switch mb-0 small">
                                <input class="form-check-input" type="checkbox" name="show_live_scores" id="showLiveScoresToggle" checked>
                                <label class="form-check-label" for="showLiveScoresToggle">Show live scores immediately</label>
                            </div>
                            <button type="submit" name="action" value="enable_live_board" class="btn btn-sm btn-success fw-semibold">
                                <i class="bi bi-broadcast me-1"></i>Generate Live Scoreboard Link
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mys-card p-3 mb-4">
            <div class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-file-earmark-arrow-down me-1"></i>Official Ministry Reports (PDF &amp; Excel)</div>
            <div class="d-flex flex-wrap gap-2">
                <a href="export.php?type=final_ranking&format=pdf&session_id=<?= $sid ?>" class="btn btn-sm btn-mys-crimson"><i class="bi bi-file-pdf me-1"></i>Final Ranking (PDF)</a>
                <a href="export.php?type=final_ranking&format=excel&session_id=<?= $sid ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-excel me-1"></i>Final Ranking (Excel)</a>
                <a href="export.php?type=final_recruitment&format=pdf&session_id=<?= $sid ?>" class="btn btn-sm btn-mys"><i class="bi bi-file-pdf me-1"></i>Final Recruitment Report (PDF)</a>
                <a href="export.php?type=session_summary&format=pdf&session_id=<?= $sid ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-pdf me-1"></i>Session Summary (PDF)</a>
                <a href="export.php?type=candidate_attendance&format=pdf&session_id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary">Candidate Attendance (PDF)</a>
                <a href="export.php?type=candidate_attendance&format=excel&session_id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary">Candidate Attendance (Excel)</a>
                <a href="export.php?type=panelist_attendance&format=pdf&session_id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary">Panelist Attendance (PDF)</a>
                <a href="export.php?type=panelist_attendance&format=excel&session_id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary">Panelist Attendance (Excel)</a>
            </div>
            <?php if (!empty($panelists)): ?>
                <div class="mt-2 pt-2 border-top d-flex flex-wrap align-items-center gap-2 small">
                    <span class="text-muted fw-semibold">Individual Panelist Reports:</span>
                    <?php foreach ($panelists as $p): ?>
                        <a href="export.php?type=panelist_assessment&format=pdf&session_id=<?= $sid ?>&panelist_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-light border py-0">
                            <?= e($p['full_name']) ?> (PDF)
                        </a>
                        <a href="export.php?type=panelist_assessment&format=excel&session_id=<?= $sid ?>&panelist_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-light border py-0">
                            (Excel)
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Candidate Rankings Table -->
        <div class="mys-card">
            <div class="mys-card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ol me-2"></i>Final Candidate Ranking (<?= count($rankings) ?> Candidates)</span>
                <span class="small text-muted">Sorted by average panelist percentage &amp; score</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>App #</th>
                            <th>Candidate Name</th>
                            <th>Attendance</th>
                            <th>Evaluations</th>
                            <th>Avg Score</th>
                            <th>Avg %</th>
                            <th>Recommendation</th>
                            <th class="text-end">Detailed Report</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rankings)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No candidates in this session.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rankings as $r): ?>
                            <tr>
                                <td>
                                    <?php if ($r['rank'] !== null): ?>
                                        <span class="badge bg-<?= $r['rank'] === 1 ? 'warning text-dark' : ($r['rank'] <= 3 ? 'primary' : 'secondary') ?> fs-6">#<?= (int)$r['rank'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small fw-semibold"><?= e($r['application_number']) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($r['full_name']) ?></div>
                                    <div class="small text-muted"><?= e($r['highest_qualification'] ?: '') ?></div>
                                </td>
                                <td class="small"><?= e($r['attendance_status']) ?></td>
                                <td class="small"><?= (int)$r['evaluations_submitted'] ?> / <?= (int)$r['evaluations_expected'] ?></td>
                                <td class="fw-bold"><?= number_format((float)$r['average_score'], 2) ?> <span class="text-muted small fw-normal">/ <?= number_format((float)$r['max_possible_score'], 0) ?></span></td>
                                <td class="fw-bold text-primary"><?= number_format((float)$r['average_percentage'], 1) ?>%</td>
                                <td class="small">
                                    <?php if ($r['dominant_recommendation']): ?>
                                        <span class="badge bg-light text-dark border"><?= e(ScoringEngine::RECOMMENDATIONS[$r['dominant_recommendation']] ?? '') ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="candidate_report.php?session_id=<?= $sid ?>&candidate_id=<?= (int)$r['candidate_id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    <a href="export.php?type=candidate_assessment&format=pdf&session_id=<?= $sid ?>&candidate_id=<?= (int)$r['candidate_id'] ?>" class="btn btn-sm btn-outline-danger">PDF</a>
                                    <a href="export.php?type=candidate_assessment&format=excel&session_id=<?= $sid ?>&candidate_id=<?= (int)$r['candidate_id'] ?>" class="btn btn-sm btn-outline-success">Excel</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
