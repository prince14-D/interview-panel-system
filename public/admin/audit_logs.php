<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

Auth::requireRole(['admin'], '../admin/dashboard.php');
$pdo = Database::getConnection();

$logs = $pdo->query(
    'SELECT al.*, u.full_name, u.email
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.id DESC LIMIT 200'
)->fetchAll();

$pageTitle = 'System Audit Logs';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4">
        <h1 class="page-title"><i class="bi bi-shield-check me-2"></i>System Audit Trail</h1>
        <p class="page-subtitle">Immutable compliance record of logins, attendance changes, assessment submissions, and official result releases.</p>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <div class="mys-card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No audit events recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="small text-nowrap"><?= e($log['created_at']) ?></td>
                            <td class="small"><?= e($log['full_name'] ?: 'System') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($log['action']) ?></span></td>
                            <td class="small"><?= e($log['entity_type']) ?> #<?= e((string)$log['entity_id']) ?></td>
                            <td class="small"><?= e($log['details'] ?? '') ?></td>
                            <td class="small text-muted"><?= e($log['ip_address'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
