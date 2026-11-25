<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Response;

$user = Auth::user();
$flashes = Response::pullFlashes();
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$pageTitle = $pageTitle ?? 'MYS Interview Panel System';
$basePublic = $basePublic ?? '..';
$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title><?= e($pageTitle) ?> — Ministry of Youth &amp; Sports, Liberia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e($basePublic) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="mys-has-sidebar">

<?php if ($user): ?>
<!-- Left Sidebar (Fixed on Desktop, Offcanvas Drawer on Mobile/Tablet) -->
<aside class="mys-sidebar offcanvas-lg offcanvas-start no-print" tabindex="-1" id="mysSidebar" aria-labelledby="mysSidebarLabel">
    <div class="mys-sidebar-brand">
        <a href="<?= e($basePublic) ?>/index.php" class="d-flex align-items-center gap-2 text-decoration-none text-white">
            <img src="<?= e($basePublic) ?>/assets/img/mys-emblem.svg" alt="MYS Emblem" width="38" height="38">
            <div class="lh-1">
                <div class="fw-bold" id="mysSidebarLabel" style="font-size:0.92rem;">Ministry of Youth &amp; Sports</div>
                <small style="font-size:0.66rem; color:#F2D16B; letter-spacing:0.6px;">INTERVIEW PANEL SYSTEM</small>
            </div>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#mysSidebar" aria-label="Close"></button>
    </div>

    <div class="mys-sidebar-body">
        <?php if (Auth::isAdmin()): ?>
            <div class="mys-nav-section">Overview</div>
            <ul class="nav flex-column mys-sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/admin/dashboard.php') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/dashboard.php">
                        <i class="bi bi-speedometer2"></i><span>Admin Dashboard</span>
                    </a>
                </li>
            </ul>

            <div class="mys-nav-section">Interview Operations</div>
            <ul class="nav flex-column mys-sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/physical/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/physical/dashboard.php">
                        <i class="bi bi-building"></i><span>Physical Interviews</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/virtual/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/virtual/dashboard.php">
                        <i class="bi bi-camera-video"></i><span>Virtual Interviews</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/admin/session_form.php') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/session_form.php">
                        <i class="bi bi-plus-circle"></i><span>New Interview Session</span>
                    </a>
                </li>
            </ul>

            <div class="mys-nav-section">Recruitment Management</div>
            <ul class="nav flex-column mys-sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?= (str_contains($currentUri, '/admin/sessions.php') || str_contains($currentUri, '/admin/session_view.php') || str_contains($currentUri, '/admin/criteria.php')) ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/sessions.php">
                        <i class="bi bi-calendar-event"></i><span>Interview Sessions</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/admin/candidates.php') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/candidates.php">
                        <i class="bi bi-people"></i><span>Candidates Registry</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/admin/venues.php') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/venues.php">
                        <i class="bi bi-geo-alt"></i><span>Physical Venues</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/admin/users.php') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/users.php">
                        <i class="bi bi-person-badge"></i><span>Panelists &amp; Users</span>
                    </a>
                </li>
            </ul>

            <div class="mys-nav-section">Results &amp; Compliance</div>
            <ul class="nav flex-column mys-sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/results/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/results/index.php">
                        <i class="bi bi-trophy"></i><span>Results, Live Board &amp; Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/admin/audit_logs.php') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/admin/audit_logs.php">
                        <i class="bi bi-shield-check"></i><span>Audit Logs</span>
                    </a>
                </li>
            </ul>
        <?php else: ?>
            <div class="mys-nav-section">Panelist Workspace</div>
            <ul class="nav flex-column mys-sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/panelist/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/panelist/dashboard.php">
                        <i class="bi bi-clipboard-check"></i><span>My Panel Assignments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/physical/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/physical/dashboard.php">
                        <i class="bi bi-building"></i><span>Physical Sessions</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/virtual/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/virtual/dashboard.php">
                        <i class="bi bi-camera-video"></i><span>Virtual Sessions</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($currentUri, '/results/') ? 'active' : '' ?>" href="<?= e($basePublic) ?>/results/index.php">
                        <i class="bi bi-file-earmark-bar-graph"></i><span>Released Results</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>
    </div>

    <div class="mys-sidebar-footer">
        <div class="d-flex align-items-center gap-2 mb-2">
            <div class="mys-avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div>
            <div class="overflow-hidden lh-sm">
                <div class="fw-semibold text-white text-truncate" style="font-size:0.84rem;"><?= e($user['full_name']) ?></div>
                <div class="text-truncate" style="font-size:0.72rem; color:#94A3B8;"><?= e(ucwords(str_replace('_', ' ', $user['role']))) ?></div>
            </div>
        </div>
        <a href="<?= e($basePublic) ?>/logout.php" class="btn btn-sm btn-outline-light w-100">
            <i class="bi bi-box-arrow-right me-1"></i>Sign Out
        </a>
    </div>
</aside>
<?php endif; ?>

<div class="mys-main-shell d-flex flex-column min-vh-100">
    <!-- Slim Top Header Bar -->
    <header class="mys-topbar no-print">
        <div class="d-flex align-items-center gap-2">
            <?php if ($user): ?>
                <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mysSidebar" aria-controls="mysSidebar" aria-label="Toggle Menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
            <?php endif; ?>
            <span class="fw-semibold text-dark d-none d-sm-inline"><?= e($pageTitle) ?></span>
            <span class="badge bg-light text-muted border d-none d-md-inline-block">Republic of Liberia</span>
        </div>
        <?php if ($user): ?>
            <div class="d-flex align-items-center gap-2 small">
                <span class="text-muted d-none d-md-inline"><i class="bi bi-person-circle me-1"></i><?= e($user['full_name']) ?></span>
                <a href="<?= e($basePublic) ?>/logout.php" class="btn btn-sm btn-outline-danger py-1 px-2 d-lg-none">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </header>

    <main class="flex-grow-1">
        <?php if (!empty($flashes)): ?>
            <div class="container-fluid px-3 px-lg-4 pt-3 no-print">
                <?php foreach ($flashes as $flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
                        <?= e($flash['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
