<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

$user = Auth::requireRole(['admin', 'hr_officer'], '../panelist/dashboard.php');
$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? 'create';

    try {
        if ($action === 'create') {
            $appNo = strtoupper(trim((string)($_POST['application_number'] ?? '')));
            if ($appNo === '') {
                $appNo = 'MYS-APP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            }
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? '')) ?: null;
            $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
            $gender = $_POST['gender'] ?: null;
            $qual = trim((string)($_POST['highest_qualification'] ?? '')) ?: null;
            $exp = $_POST['years_experience'] !== '' ? (float)$_POST['years_experience'] : 0.0;
            $county = trim((string)($_POST['county'] ?? '')) ?: null;

            if ($fullName === '') {
                throw new RuntimeException('Candidate full name is required.');
            }

            $ins = $pdo->prepare(
                'INSERT INTO candidates (application_number, full_name, email, phone, gender, highest_qualification, years_experience, county)
                 VALUES (:app, :name, :email, :phone, :gender, :qual, :exp, :county)'
            );
            $ins->execute([
                'app' => $appNo,
                'name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'gender' => $gender,
                'qual' => $qual,
                'exp' => $exp,
                'county' => $county,
            ]);
            $newId = (int)$pdo->lastInsertId();
            AuditLogger::log($pdo, (int)$user['id'], 'candidate_registered', 'candidate', $newId, "Registered {$fullName} ({$appNo})");
            Response::flash('success', "Candidate {$fullName} ({$appNo}) registered successfully.");
        } elseif ($action === 'delete') {
            $cid = (int)($_POST['candidate_id'] ?? 0);
            $pdo->prepare('DELETE FROM candidates WHERE id = :id')->execute(['id' => $cid]);
            Response::flash('info', 'Candidate record removed.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', 'Error: ' . $e->getMessage());
    }
    Response::redirect('candidates.php');
}

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT c.*, (SELECT COUNT(*) FROM session_candidates sc WHERE sc.candidate_id = c.id) AS session_count
         FROM candidates c
         WHERE c.full_name LIKE :q OR c.application_number LIKE :q2 OR c.email LIKE :q3
         ORDER BY c.created_at DESC'
    );
    $stmt->execute(['q' => "%{$search}%", 'q2' => "%{$search}%", 'q3' => "%{$search}%"]);
    $candidates = $stmt->fetchAll();
} else {
    $candidates = $pdo->query(
        'SELECT c.*, (SELECT COUNT(*) FROM session_candidates sc WHERE sc.candidate_id = c.id) AS session_count
         FROM candidates c ORDER BY c.created_at DESC'
    )->fetchAll();
}

$counties = ['Montserrado', 'Bong', 'Nimba', 'Grand Bassa', 'Margibi', 'Lofa', 'Grand Cape Mount', 'Bomi', 'Grand Gedeh', 'Sinoe', 'Maryland', 'River Cess', 'Grand Kru', 'River Gee', 'Gbarpolu'];

$pageTitle = 'Candidates Registry';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4 d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><i class="bi bi-people me-2"></i>Candidates Registry</h1>
            <p class="page-subtitle">Register recruitment applicants and assign them to physical or virtual interview sessions.</p>
        </div>
        <form method="get" class="d-flex gap-2">
            <input type="text" name="q" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Search name or App #...">
            <button class="btn btn-sm btn-outline-primary">Search</button>
        </form>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="mys-card p-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-person-plus me-1"></i>Register New Candidate</h6>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Application Number</label>
                        <input type="text" name="application_number" class="form-control form-control-sm" placeholder="Auto-generated if left blank">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Full Name *</label>
                        <input type="text" name="full_name" class="form-control form-control-sm" required placeholder="e.g., Korpo M. Johnson">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control form-control-sm" placeholder="+231-77-...">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Gender</label>
                            <select name="gender" class="form-select form-select-sm">
                                <option value="">-- Select --</option>
                                <option value="Female">Female</option>
                                <option value="Male">Male</option>
                                <option value="Other">Other</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">County</label>
                            <select name="county" class="form-select form-select-sm">
                                <option value="">-- Select --</option>
                                <?php foreach ($counties as $ct): ?>
                                    <option value="<?= e($ct) ?>"><?= e($ct) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <label class="form-label small fw-semibold">Highest Qualification</label>
                            <input type="text" name="highest_qualification" class="form-control form-control-sm" placeholder="e.g., B.Sc Public Admin">
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold">Exp. (Yrs)</label>
                            <input type="number" step="0.5" min="0" name="years_experience" class="form-control form-control-sm" value="0">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-mys btn-sm w-100">Register Candidate</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="mys-card">
                <div class="mys-card-header">Registered Candidates (<?= count($candidates) ?>)</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>App #</th>
                                <th>Candidate Name</th>
                                <th>Qualification / Exp</th>
                                <th>Contact</th>
                                <th>Sessions</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($candidates)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No candidates registered yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($candidates as $c): ?>
                                <tr>
                                    <td class="small fw-semibold text-primary"><?= e($c['application_number']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= e($c['full_name']) ?></div>
                                        <div class="small text-muted"><?= e($c['county'] ?: 'Liberia') ?> <?= $c['gender'] ? '&middot; ' . e($c['gender']) : '' ?></div>
                                    </td>
                                    <td class="small">
                                        <div><?= e($c['highest_qualification'] ?: '—') ?></div>
                                        <div class="text-muted"><?= number_format((float)$c['years_experience'], 1) ?> yrs exp</div>
                                    </td>
                                    <td class="small">
                                        <div><?= e($c['email'] ?: '—') ?></div>
                                        <div class="text-muted"><?= e($c['phone'] ?: '') ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= (int)$c['session_count'] ?></span></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this candidate and all linked records?');">
                                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="candidate_id" value="<?= (int)$c['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
