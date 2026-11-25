<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;

$dbPath = __DIR__ . '/mys_live.sqlite';
$pdo = Database::getConnection(['driver' => 'sqlite', 'sqlite_path' => $dbPath]);

$schema = file_get_contents(__DIR__ . '/schema_sqlite.sql');
$pdo->exec($schema);

// Seed departments if empty
if ((int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() === 0) {
    $depts = [
        ['ADMIN-HR', 'Department of Administration & Human Resources', 'Oversees ministry administration, personnel recruitment, and civil service compliance.'],
        ['YOUTH-DEV', 'Department of Youth Development', 'Coordinates national youth empowerment, vocational programs, and county youth councils.'],
        ['SPORTS-DEV', 'Department of Sports Development', 'Manages national sports federations, athletic programs, and sports infrastructure.'],
        ['TVET-SKILLS', 'Department of Technical & Vocational Education (TVET)', 'Directs national youth vocational training centers and skills acquisition programs.'],
        ['PLAN-RES', 'Department of Planning, Research & Statistics', 'Leads policy formulation, monitoring & evaluation, and ICT/statistical systems.'],
        ['FIN-BUDG', 'Department of Finance & Budget', 'Manages public financial administration, procurement, and internal controls.'],
    ];
    $st = $pdo->prepare('INSERT INTO departments (code, name, description) VALUES (?, ?, ?)');
    foreach ($depts as $d) {
        $st->execute($d);
    }
}

// Seed physical venues if empty
if ((int)$pdo->query('SELECT COUNT(*) FROM interview_venues')->fetchColumn() === 0) {
    $venues = [
        ['MYS Main Conference Hall', 'Ministry Headquarters', 'Conference Room A (2nd Floor)', 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia', 25, 'Primary in-person recruitment panel venue with waiting area.'],
        ['HR Recruitment Boardroom', 'Administration Wing', 'Room 104', 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia', 12, 'Dedicated panel boardroom for technical and management interviews.'],
        ['Youth Development Briefing Room', 'Youth Affairs Annex', 'Room 202', 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia', 15, 'Suitable for multi-panel physical interviews.'],
    ];
    $st = $pdo->prepare('INSERT INTO interview_venues (venue_name, building, room_number, address, capacity, notes) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($venues as $v) {
        $st->execute($v);
    }
}

// Seed initial Admin account if empty
if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE email = 'admin@mys.gov.lr'")->fetchColumn() === 0) {
    $hash = password_hash('Admin@2026!', PASSWORD_BCRYPT);
    $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, job_title, department_id, phone, is_active)
         VALUES (?, ?, ?, ?, ?, 1, ?, 1)'
    )->execute([
        'System Administrator',
        'admin@mys.gov.lr',
        $hash,
        'admin',
        'Director of Human Resources & Recruitment',
        '+231-77-000-0001',
    ]);
}

echo "Local database initialized successfully at {$dbPath}\n";
