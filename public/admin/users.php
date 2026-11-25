<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

$user = Auth::requireRole(['admin'], '../admin/dashboard.php');
$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? 'create';

    try {
        if ($action === 'create') {
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $role = in_array($_POST['role'] ?? '', ['admin', 'hr_officer', 'panelist'], true) ? $_POST['role'] : 'panelist';
            $jobTitle = trim((string)($_POST['job_title'] ?? '')) ?: null;
            $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $phone = trim((string)($_POST['phone'] ?? '')) ?: null;

            if ($fullName === '' || $email === '' || strlen($password) < 8) {
                throw new RuntimeException('Full name, valid email, and a password of at least 8 characters are required.');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO users (full_name, email, password_hash, role, job_title, department_id, phone, is_active)
                 VALUES (:name, :email, :hash, :role, :title, :dept, :phone, 1)'
            )->execute([
                'name' => $fullName,
                'email' => $email,
                'hash' => $hash,
                'role' => $role,
                'title' => $jobTitle,
                'dept' => $deptId,
                'phone' => $phone,
            ]);
            AuditLogger::log($pdo, (int)$user['id'], 'user_created', 'user', (int)$pdo->lastInsertId(), "Created {$role}: {$email}");
            Response::flash('success', "Account created for {$fullName} ({$email}).");
        } elseif ($action === 'toggle') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === (int)$user['id']) {
                throw new RuntimeException('You cannot deactivate your own active administrator account.');
            }
            $pdo->prepare('UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id')
                ->execute(['id' => $uid]);
            Response::flash('info', 'User account status updated.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', $e->getMessage());
    }
    Response::redirect('users.php');
}

$users = $pdo->query(
    'SELECT u.*, d.name AS department_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     ORDER BY u.role ASC, u.full_name ASC'
)->fetchAll();
$departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

$pageTitle = 'Panelists & User Accounts';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4">
        <h1 class="page-title"><i class="bi bi-person-badge me-2"></i>Panelists &amp; User Accounts</h1>
        <p class="page-subtitle">Create individual login accounts for interview panelists, HR officers, and system administrators.</p>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="mys-card p-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-person-plus me-1"></i>Create Account</h6>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Full Name *</label>
                        <input type="text" name="full_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Official Email *</label>
                        <input type="email" name="email" class="form-control form-control-sm" required placeholder="panelist@mys.gov.lr">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Initial Password (min 8 chars) *</label>
                        <input type="password" name="password" class="form-control form-control-sm" required minlength="8">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">System Role</label>
                            <select name="role" class="form-select form-select-sm">
                                <option value="panelist">Panelist</option>
                                <option value="hr_officer">HR Officer</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Job Title</label>
                        <input type="text" name="job_title" class="form-control form-control-sm" placeholder="e.g., Deputy Director of Youth">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Department</label>
                        <select name="department_id" class="form-select form-select-sm">
                            <option value="">-- Optional --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-mys btn-sm w-100">Create User Account</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="mys-card">
                <div class="mys-card-header">System Users (<?= count($users) ?>)</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name &amp; Title</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($u['full_name']) ?></div>
                                    <div class="small text-muted"><?= e($u['job_title'] ?: '—') ?></div>
                                </td>
                                <td class="small"><?= e($u['email']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e(ucwords(str_replace('_', ' ', $u['role']))) ?></span></td>
                                <td class="small"><?= e($u['department_name'] ?: '—') ?></td>
                                <td>
                                    <span class="badge bg-<?= (int)$u['is_active'] === 1 ? 'success' : 'secondary' ?>">
                                        <?= (int)$u['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-2">Toggle</button>
                                    </form>
                                    <?php else: ?>
                                        <span class="small text-muted">Current</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
