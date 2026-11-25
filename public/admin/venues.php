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
            $name = trim((string)($_POST['venue_name'] ?? ''));
            $building = trim((string)($_POST['building'] ?? '')) ?: null;
            $room = trim((string)($_POST['room_number'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $capacity = max(1, (int)($_POST['capacity'] ?? 15));
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;

            if ($name === '' || $room === '' || $address === '') {
                throw new RuntimeException('Venue name, room number, and address are required.');
            }
            $pdo->prepare(
                'INSERT INTO interview_venues (venue_name, building, room_number, address, capacity, notes)
                 VALUES (:name, :bldg, :room, :addr, :cap, :notes)'
            )->execute(['name' => $name, 'bldg' => $building, 'room' => $room, 'addr' => $address, 'cap' => $capacity, 'notes' => $notes]);
            AuditLogger::log($pdo, (int)$user['id'], 'venue_created', 'interview_venue', (int)$pdo->lastInsertId(), $name);
            Response::flash('success', 'Physical interview venue added.');
        } elseif ($action === 'toggle') {
            $vid = (int)($_POST['venue_id'] ?? 0);
            $pdo->prepare('UPDATE interview_venues SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id')
                ->execute(['id' => $vid]);
            Response::flash('info', 'Venue status updated.');
        }
    } catch (Throwable $e) {
        Response::flash('danger', $e->getMessage());
    }
    Response::redirect('venues.php');
}

$venues = $pdo->query('SELECT * FROM interview_venues ORDER BY is_active DESC, venue_name ASC')->fetchAll();

$pageTitle = 'Physical Interview Venues';
$basePublic = '..';
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="page-header">
    <div class="container-fluid px-3 px-lg-4">
        <h1 class="page-title"><i class="bi bi-geo-alt me-2"></i>Physical Interview Venues</h1>
        <p class="page-subtitle">Manage Ministry offices, conference rooms, and regional halls for in-person interviews.</p>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="mys-card p-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-building-add me-1"></i>Add Physical Venue</h6>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Venue Name *</label>
                        <input type="text" name="venue_name" class="form-control form-control-sm" required placeholder="e.g., MYS Annex Conference Hall">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Building</label>
                            <input type="text" name="building" class="form-control form-control-sm" placeholder="Headquarters">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Room Number *</label>
                            <input type="text" name="room_number" class="form-control form-control-sm" required placeholder="Room 201">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Address *</label>
                        <input type="text" name="address" class="form-control form-control-sm" required value="Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Seating Capacity</label>
                        <input type="number" name="capacity" class="form-control form-control-sm" min="1" value="15">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-mys btn-sm w-100">Save Venue</button>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="mys-card">
                <div class="mys-card-header">Configured Venues (<?= count($venues) ?>)</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Venue &amp; Building</th>
                                <th>Room</th>
                                <th>Address</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($venues as $v): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($v['venue_name']) ?></div>
                                    <div class="small text-muted"><?= e($v['building'] ?: '') ?></div>
                                </td>
                                <td class="small"><?= e($v['room_number']) ?></td>
                                <td class="small"><?= e($v['address']) ?></td>
                                <td class="small"><?= (int)$v['capacity'] ?></td>
                                <td>
                                    <span class="badge bg-<?= (int)$v['is_active'] === 1 ? 'success' : 'secondary' ?>">
                                        <?= (int)$v['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="venue_id" value="<?= (int)$v['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-2">Toggle</button>
                                    </form>
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
