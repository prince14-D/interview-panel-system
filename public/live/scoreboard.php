<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Database;

$token = trim((string)($_GET['token'] ?? ''));
$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT lb.*, s.title, s.job_title, s.session_code, s.interview_mode, s.interview_date, s.start_time, s.end_time,
            d.name AS department_name, v.venue_name, v.room_number
     FROM live_scoreboards lb
     INNER JOIN interview_sessions s ON s.id = lb.session_id
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     WHERE lb.access_token = :tok AND lb.is_enabled = 1
     LIMIT 1'
);
$stmt->execute(['tok' => $token]);
$board = $stmt->fetch();

if (!$board) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Live Scoreboard Unavailable</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light d-flex align-items-center justify-content-center min-vh-100"><div class="card shadow-sm p-4 text-center" style="max-width:460px;"><h4 class="text-danger mb-2">Live Scoreboard Unavailable</h4><p class="text-muted mb-0">This live scoreboard link is either invalid or has been disabled by the Ministry Administrator.</p></div></body></html>';
    exit;
}

$refreshSec = max(5, (int)$board['auto_refresh_seconds']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Scoreboard — <?= e($board['title']) ?> | Ministry of Youth &amp; Sports, Liberia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
    <style>
        .live-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #22C55E;
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: livePulse 1.6s infinite;
        }
        @keyframes livePulse {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }
        .rank-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 28px;
            border-radius: 6px;
            font-weight: 700;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar mys-navbar">
    <div class="container-fluid px-3 px-lg-4 d-flex justify-content-between align-items-center">
        <div class="navbar-brand d-flex align-items-center gap-2 mb-0">
            <img src="../assets/img/mys-emblem.svg" alt="MYS Emblem" width="36" height="36">
            <div class="lh-1">
                <div style="font-size:0.95rem;">Ministry of Youth &amp; Sports, Republic of Liberia</div>
                <small style="font-size:0.7rem; color:#F2D16B; letter-spacing:0.6px;">OFFICIAL LIVE INTERVIEW SCOREBOARD (READ-ONLY)</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3 text-white small">
            <span class="badge bg-dark border border-success d-inline-flex align-items-center gap-2 px-3 py-2">
                <span class="live-dot"></span>
                <span>LIVE &middot; Auto-refresh <?= $refreshSec ?>s</span>
            </span>
            <span class="d-none d-md-inline text-white-50">Updated: <strong class="text-white" id="lastUpdated">--:--:--</strong></span>
        </div>
    </div>
</nav>

<main class="flex-grow-1 py-4">
    <div class="container-fluid px-3 px-lg-4">
        <!-- Session Info Banner -->
        <div class="mys-card p-3 mb-4" style="border-left:5px solid #0B2545;">
            <div class="row g-3 align-items-center">
                <div class="col-lg-8">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge <?= $board['interview_mode'] === 'physical' ? 'badge-mode-physical' : 'badge-mode-virtual' ?>">
                            <i class="bi bi-<?= $board['interview_mode'] === 'physical' ? 'building' : 'camera-video' ?> me-1"></i>
                            <?= strtoupper($board['interview_mode']) ?> INTERVIEW
                        </span>
                        <span class="small fw-semibold text-muted"><?= e($board['session_code']) ?></span>
                        <span id="releaseBadge" class="badge bg-secondary">Loading...</span>
                    </div>
                    <h1 class="h4 fw-bold text-dark mb-1"><?= e($board['title']) ?> — <?= e($board['job_title']) ?></h1>
                    <div class="small text-muted">
                        <?= e($board['department_name']) ?> &middot; Date: <strong><?= e($board['interview_date']) ?></strong>
                        <?php if ($board['interview_mode'] === 'physical' && $board['venue_name']): ?>
                            &middot; Venue: <strong><?= e($board['venue_name']) ?> (<?= e($board['room_number'] ?: '') ?>)</strong>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="border rounded p-2 bg-light">
                                <div class="small text-muted">Scored</div>
                                <div class="fw-bold fs-5 text-success" id="statSubmitted">0</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 bg-light">
                                <div class="small text-muted">Pending</div>
                                <div class="fw-bold fs-5 text-danger" id="statPending">0</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 bg-light">
                                <div class="small text-muted">Progress</div>
                                <div class="fw-bold fs-5 text-primary" id="statPct">0%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Score Table -->
        <div class="mys-card">
            <div class="mys-card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Live Candidate Scoreboard</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="fetchBoard()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh Now
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="liveScoreTable">
                    <thead id="liveTableHead">
                        <tr>
                            <th>Rank</th>
                            <th>App #</th>
                            <th>Candidate Name</th>
                            <th>Status</th>
                            <th>Evaluations</th>
                            <th>Average Score</th>
                            <th>Percentage</th>
                            <th>Recommendation</th>
                        </tr>
                    </thead>
                    <tbody id="liveTableBody">
                        <tr><td colspan="8" class="text-center py-5 text-muted">Loading live scores...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<footer class="mys-footer">
    <div class="container-fluid px-3 px-lg-4 d-flex justify-content-between small">
        <span><strong>Republic of Liberia — Ministry of Youth and Sports</strong> &middot; Live Read-Only Scoreboard</span>
        <span>No account required &middot; Authorized live view</span>
    </div>
</footer>

<script>
const TOKEN = <?= json_encode($token) ?>;
const REFRESH_MS = <?= $refreshSec * 1000 ?>;

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
}

async function fetchBoard() {
    try {
        const res = await fetch('feed.php?token=' + encodeURIComponent(TOKEN), { cache: 'no-store' });
        const data = await res.json();
        if (!data.ok) {
            document.getElementById('liveTableBody').innerHTML = `<tr><td colspan="12" class="text-center py-4 text-danger">${escapeHtml(data.error)}</td></tr>`;
            return;
        }

        document.getElementById('lastUpdated').textContent = data.updated_at;
        document.getElementById('statSubmitted').textContent = data.progress.total_submitted;
        document.getElementById('statPending').textContent = data.progress.total_pending;
        document.getElementById('statPct').textContent = data.progress.completion_rate + '%';

        const relBadge = document.getElementById('releaseBadge');
        if (data.is_released) {
            relBadge.className = 'badge bg-success';
            relBadge.textContent = 'OFFICIALLY RELEASED';
        } else if (data.show_scores) {
            relBadge.className = 'badge bg-primary';
            relBadge.textContent = 'LIVE SCORING IN PROGRESS';
        } else {
            relBadge.className = 'badge bg-warning text-dark';
            relBadge.textContent = 'BLIND SCORING — AWAITING RELEASE';
        }

        // Build dynamic header including criteria columns when scores are shown
        let headHtml = '<tr><th>Rank</th><th>App #</th><th>Candidate Name</th><th>Interview Status</th><th>Panelists</th>';
        if (data.show_scores) {
            data.criteria.forEach(c => {
                headHtml += `<th class="text-center small" title="${escapeHtml(c.name)}">${escapeHtml(c.name)}<br><span class="text-muted fw-normal">/${c.max_marks}</span></th>`;
            });
        }
        headHtml += '<th>Avg Score</th><th>Percentage</th><th>Recommendation</th></tr>';
        document.getElementById('liveTableHead').innerHTML = headHtml;

        if (!data.rows.length) {
            document.getElementById('liveTableBody').innerHTML = '<tr><td colspan="15" class="text-center py-4 text-muted">No candidates registered for this session yet.</td></tr>';
            return;
        }

        let bodyHtml = '';
        data.rows.forEach(r => {
            const rankHtml = r.rank !== null
                ? `<span class="rank-pill ${r.rank === 1 ? 'bg-warning text-dark' : (r.rank <= 3 ? 'bg-primary text-white' : 'bg-light text-dark border')}">#${r.rank}</span>`
                : '<span class="text-muted">—</span>';

            let critCells = '';
            if (data.show_scores) {
                data.criteria.forEach(c => {
                    const val = r.criterion_averages && r.criterion_averages[c.id] !== undefined
                        ? Number(r.criterion_averages[c.id]).toFixed(1)
                        : '—';
                    critCells += `<td class="text-center small">${val}</td>`;
                });
            }

            const scoreHtml = data.show_scores && r.average_score !== null
                ? `<strong>${Number(r.average_score).toFixed(2)}</strong> <span class="text-muted small">/ ${Number(r.max_possible_score).toFixed(0)}</span>`
                : '<span class="text-muted small">Hidden</span>';

            const pctVal = data.show_scores && r.average_percentage !== null ? Number(r.average_percentage) : null;
            const pctHtml = pctVal !== null
                ? `<div class="fw-bold text-primary">${pctVal.toFixed(1)}%</div>
                   <div class="progress mt-1" style="height:5px;min-width:70px;"><div class="progress-bar bg-success" style="width:${Math.min(100, pctVal)}%"></div></div>`
                : '<span class="text-muted small">—</span>';

            bodyHtml += `<tr>
                <td>${rankHtml}</td>
                <td class="small fw-semibold text-primary">${escapeHtml(r.application_number)}</td>
                <td class="fw-semibold">${escapeHtml(r.full_name)}</td>
                <td><span class="badge bg-secondary-subtle text-dark border">${escapeHtml(r.interview_status.replace(/_/g, ' '))}</span></td>
                <td class="small"><strong>${r.evaluations_submitted}</strong> / ${r.evaluations_expected}</td>
                ${critCells}
                <td>${scoreHtml}</td>
                <td>${pctHtml}</td>
                <td class="small"><span class="badge bg-light text-dark border">${escapeHtml(r.recommendation)}</span></td>
            </tr>`;
        });
        document.getElementById('liveTableBody').innerHTML = bodyHtml;
    } catch (e) {
        console.error(e);
    }
}

fetchBoard();
setInterval(fetchBoard, REFRESH_MS);
</script>
</body>
</html>
