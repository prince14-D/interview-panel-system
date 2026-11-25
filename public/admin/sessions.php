<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

$user = Auth::requireRole(['admin', 'hr_officer'], '../panelist/dashboard.php');
$pdo = Database::getConnection();

$mode = $_GET['mode'] ?? '';
$status = $_GET['status'] ?? '';

$where = [];
$params = [];
if (in_array($mode, ['physical', 'virtual'], true)) {
    $where[] = 's.interview_mode = :mode';
    $params['mode'] = $mode;
}
if (in_array($status, ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled', 'archived'], true)) {
    $where[] = 's.status = :status';
    $params['status'] = $status;
}

$sql = "SELECT s.*, d.name AS department_name, v.venue_name, v.room_number,
               vr.provider AS video_provider, vr.meeting_status,
               COALESCE(rr.is_released, 0) AS is_released,
               (SELECT COUNT(*) FROM session_candidates sc WHERE sc.session_id = s.id) AS candidate_count,
               (SELECT COUNT(*) FROM session_panelists sp WHERE sp.session_id = s.id) AS panelist_count
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

$pageTitle = 'Interview Sessions';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-calendar-event me-2"></i>Interview Sessions</h1>
            <p class="page-subtitle">Organize and manage physical and virtual recruitment exercises across all Ministry departments.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="session_form.php?mode=physical" class="btn btn-mys btn-sm"><i class="bi bi-building-add me-1"></i>New Physical Session</a>
            <a href="session_form.php?mode=virtual" class="btn btn-outline-primary btn-sm"><i class="bi bi-camera-video me-1"></i>New Virtual Session</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <div class="mb-3 d-flex flex-wrap gap-2">
        <a href="sessions.php" class="btn btn-sm <?= $mode === '' ? 'btn-mys' : 'btn-outline-secondary' ?>">All Sessions</a>
        <a href="sessions.php?mode=physical" class="btn btn-sm <?= $mode === 'physical' ? 'btn-mys' : 'btn-outline-secondary' ?>"><i class="bi bi-building me-1"></i>Physical Only</a>
        <a href="sessions.php?mode=virtual" class="btn btn-sm <?= $mode === 'virtual' ? 'btn-mys' : 'btn-outline-secondary' ?>"><i class="bi bi-camera-video me-1"></i>Virtual Only</a>
    </div>

    <div class="mys-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Interview Title &amp; Position</th>
                        <th>Department</th>
                        <th>Mode</th>
                        <th>Schedule</th>
                        <th>Venue / Room</th>
                        <th>Panel / Candidates</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">No interview sessions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td class="small fw-semibold text-muted"><?= e($s['session_code']) ?></td>
                            <td>
                                <a href="session_view.php?id=<?= (int)$s['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= e($s['title']) ?></a>
                                <div class="small text-muted"><?= e($s['job_title']) ?></div>
                            </td>
                            <td class="small"><?= e($s['department_name']) ?></td>
                            <td>
                                <?php if ($s['interview_mode'] === 'physical'): ?>
                                    <span class="badge badge-mode-physical"><i class="bi bi-building me-1"></i>Physical</span>
                                <?php else: ?>
                                    <span class="badge badge-mode-virtual"><i class="bi bi-camera-video me-1"></i>Virtual</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div><?= e($s['interview_date']) ?></div>
                                <div class="text-muted"><?= e(substr($s['start_time'], 0, 5)) ?> - <?= e(substr($s['end_time'], 0, 5)) ?></div>
                            </td>
                            <td class="small">
                                <?php if ($s['interview_mode'] === 'physical'): ?>
                                    <?= e($s['venue_name'] ?: 'Not set') ?>
                                    <div class="text-muted"><?= e($s['venue_room_override'] ?: ($s['room_number'] ?: '')) ?></div>
                                <?php else: ?>
                                    <?= e(strtoupper((string)($s['video_provider'] ?: 'Jitsi'))) ?> WebRTC
                                    <div class="text-muted"><?= e(ucfirst((string)($s['meeting_status'] ?: 'scheduled'))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div><?= (int)$s['panelist_count'] ?> panelists</div>
                                <div class="text-muted"><?= (int)$s['candidate_count'] ?> candidates</div>
                            </td>
                            <td>
                                <span class="badge bg-<?= $s['status'] === 'in_progress' ? 'primary' : ($s['status'] === 'completed' ? 'success' : 'secondary') ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $s['status']))) ?>
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="session_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-mys">Manage</a>
                                <a href="session_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
