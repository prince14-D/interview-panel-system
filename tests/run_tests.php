<?php
declare(strict_types=1);

/**
 * Automated End-to-End Verification Suite
 * Tests:
 * 1. Physical Interview Workflow (independently, without video)
 * 2. Virtual Interview Workflow (WebRTC room, waiting room, recording disabled by default, LiveKit JWT)
 * 3. Blind Scoring Confidentiality (backend enforcement before & after official release)
 * 4. Shared Scoring & Ranking Engine parity across Physical & Virtual modes
 * 5. PDF & Excel Report Generation with Ministry of Youth and Sports branding
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;
use App\Services\AttendanceService;
use App\Services\ReportExportService;
use App\Services\ScoringEngine;
use App\Services\VideoConferenceService;

$passed = 0;
$failed = 0;

function assertTrue(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    assertTrue($expected == $actual, $label . " (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")");
}

echo "=== MYS Interview Panel & Scoring System — Automated Test Suite ===\n\n";

// Boot isolated SQLite in-memory database with schema mirror
$pdo = Database::getConnection(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
Database::setConnection($pdo);
$schemaSql = file_get_contents(dirname(__DIR__) . '/database/schema_sqlite.sql');
$pdo->exec($schemaSql);

// Seed department, venue, admin, 2 panelists, 2 candidates
$pdo->exec("INSERT INTO departments (code, name) VALUES ('YOUTH-DEV', 'Department of Youth Development')");
$deptId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO interview_venues (venue_name, building, room_number, address, capacity) VALUES ('MYS Main Conference Hall', 'HQ', 'Room A', 'SKD Sports Complex, Paynesville', 20)");
$venueId = (int)$pdo->lastInsertId();

$hash = password_hash('TestPass@2026!', PASSWORD_BCRYPT);
$stmtU = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, job_title, department_id) VALUES (?, ?, ?, ?, ?, ?)");
$stmtU->execute(['Admin Officer', 'admin@mys.gov.lr', $hash, 'admin', 'HR Director', $deptId]);
$adminId = (int)$pdo->lastInsertId();

$stmtU->execute(['Panelist One', 'p1@mys.gov.lr', $hash, 'panelist', 'Senior Policy Advisor', $deptId]);
$p1Id = (int)$pdo->lastInsertId();

$stmtU->execute(['Panelist Two', 'p2@mys.gov.lr', $hash, 'panelist', 'Technical Lead', $deptId]);
$p2Id = (int)$pdo->lastInsertId();

$stmtC = $pdo->prepare("INSERT INTO candidates (application_number, full_name, email, highest_qualification, years_experience) VALUES (?, ?, ?, ?, ?)");
$stmtC->execute(['MYS-2026-001', 'Korpo Johnson', 'korpo@example.lr', 'M.Sc Public Policy', 6.0]);
$c1Id = (int)$pdo->lastInsertId();

$stmtC->execute(['MYS-2026-002', 'Varney Kollie', 'varney@example.lr', 'B.Sc Sociology', 4.0]);
$c2Id = (int)$pdo->lastInsertId();

$scoring = new ScoringEngine($pdo);
$attendance = new AttendanceService($pdo);
$video = new VideoConferenceService($pdo);
$exporter = new ReportExportService();

$adminUser = ['id' => $adminId, 'role' => 'admin', 'full_name' => 'Admin Officer'];
$p1User = ['id' => $p1Id, 'role' => 'panelist', 'full_name' => 'Panelist One'];
$p2User = ['id' => $p2Id, 'role' => 'panelist', 'full_name' => 'Panelist Two'];

// -------------------------------------------------------------------------
// 1. PHYSICAL INTERVIEW WORKFLOW TEST (No Video Required)
// -------------------------------------------------------------------------
echo "--- 1. Physical Interview Workflow ---\n";

$pdo->prepare(
    "INSERT INTO interview_sessions (session_code, title, department_id, job_title, interview_mode, interview_date, start_time, end_time, venue_id, status, created_by)
     VALUES ('MYS-PHY-01', 'Physical Youth Officer Panel', ?, 'Youth Program Officer', 'physical', '2026-11-30', '09:00', '15:00', ?, 'scheduled', ?)"
)->execute([$deptId, $venueId, $adminId]);
$physSessionId = (int)$pdo->lastInsertId();
$scoring->seedDefaultCriteria($physSessionId);
$pdo->prepare("INSERT INTO result_releases (session_id, is_released) VALUES (?, 0)")->execute([$physSessionId]);

// Verify no video room exists for physical session
assertTrue($video->getRoomBySession($physSessionId) === null, 'Physical session does not require or create a video room');

// Verify 6 default criteria totaling 100 marks
$physCriteria = $scoring->getSessionCriteria($physSessionId);
assertEquals(6, count($physCriteria), 'Default criteria count is 6');
$sumMax = array_sum(array_map(static fn($c) => (float)$c['max_marks'], $physCriteria));
assertEquals(100.0, $sumMax, 'Default criteria total exactly 100 marks');

// Assign panelists & candidates
$pdo->prepare("INSERT INTO session_panelists (session_id, panelist_id, panel_role) VALUES (?, ?, 'chairperson'), (?, ?, 'member')")
    ->execute([$physSessionId, $p1Id, $physSessionId, $p2Id]);
$pdo->prepare("INSERT INTO session_candidates (session_id, candidate_id, scheduled_time, interview_order) VALUES (?, ?, '09:30', 1), (?, ?, '10:30', 2)")
    ->execute([$physSessionId, $c1Id, $physSessionId, $c2Id]);

// Record attendance & begin/complete physical interview
$attendance->recordPanelistAttendance($physSessionId, $p1Id, 'Present', 'Arrived on time', $adminId);
$attendance->recordPanelistAttendance($physSessionId, $p2Id, 'Late', 'Traffic delay 10 mins', $adminId);
$attendance->beginCandidateInterview($physSessionId, $c1Id, $adminId);
$attendance->completeCandidateInterview($physSessionId, $c1Id, $adminId);
$attendance->recordCandidateAttendance($physSessionId, $c2Id, 'Present', '2026-11-30 10:25:00', 'Checked in at reception', $adminId);

$candReg = $attendance->getCandidateAttendanceRegister($physSessionId);
assertEquals('Present', $candReg[0]['attendance_status'], 'Candidate 1 marked Present when interview began');
assertEquals('completed', $candReg[0]['interview_status'], 'Candidate 1 interview status transitioned to completed');

// Submit independent scores for both candidates from both panelists
$scoresP1C1 = [];
$scoresP2C1 = [];
$scoresP1C2 = [];
$scoresP2C2 = [];
foreach ($physCriteria as $c) {
    $cid = (int)$c['id'];
    $m = (float)$c['max_marks'];
    $scoresP1C1[$cid] = $m * 0.90; // 90 total
    $scoresP2C1[$cid] = $m * 0.80; // 80 total -> Avg 85
    $scoresP1C2[$cid] = $m * 0.70; // 70 total
    $scoresP2C2[$cid] = $m * 0.76; // 76 total -> Avg 73
}

$scoring->saveAssessment($physSessionId, $c1Id, $p1Id, $scoresP1C1, [], 'strongly_recommended', 'Excellent policy grasp', 'Leadership', 'None', true);
$scoring->saveAssessment($physSessionId, $c1Id, $p2Id, $scoresP2C1, [], 'recommended', 'Strong technical answers', 'Communication', 'Budgeting', true);
$scoring->saveAssessment($physSessionId, $c2Id, $p1Id, $scoresP1C2, [], 'recommended', 'Solid field experience', 'Teamwork', 'Policy depth', true);
$scoring->saveAssessment($physSessionId, $c2Id, $p2Id, $scoresP2C2, [], 'recommended_with_reservations', 'Adequate', 'Enthusiasm', 'Technical depth', true);

// -------------------------------------------------------------------------
// 2. BLIND SCORING & CONFIDENTIALITY ENFORCEMENT
// -------------------------------------------------------------------------
echo "\n--- 2. Blind Scoring Confidentiality ---\n";

assertTrue(!$scoring->canViewReleasedResults($p1User, $physSessionId), 'Panelist cannot view released results before admin release');

$blockedException = false;
try {
    $scoring->getSessionRankings($physSessionId, $p1User);
} catch (RuntimeException $e) {
    $blockedException = true;
}
assertTrue($blockedException, 'Backend blocks panelist from fetching session rankings before official release');

$blindDetail = $scoring->getCandidateDetailedReport($physSessionId, $c1Id, $p1User);
assertEquals(1, count($blindDetail['submissions']), 'Before release, panelist sees ONLY their own 1 submission');
assertEquals($p1Id, (int)$blindDetail['submissions'][0]['panelist_id'], 'Visible submission belongs strictly to the requesting panelist');
assertTrue($blindDetail['aggregate'] === null, 'Before release, panelist cannot see aggregate panel averages');

// Admin releases results officially
$scoring->releaseResults($physSessionId, $adminId, 'Approved by HR Director');
assertTrue($scoring->canViewReleasedResults($p1User, $physSessionId), 'After release, assigned panelist can view released results');

$physRankings = $scoring->getSessionRankings($physSessionId, $p1User);
assertEquals(1, $physRankings[0]['rank'], 'Top candidate ranked #1');
assertEquals('Korpo Johnson', $physRankings[0]['full_name'], 'Korpo Johnson ranks #1');
assertEquals(85.0, $physRankings[0]['average_score'], 'Korpo Johnson average score is 85.00');
assertEquals(2, $physRankings[1]['rank'], 'Second candidate ranked #2');
assertEquals(73.0, $physRankings[1]['average_score'], 'Varney Kollie average score is 73.00');

// -------------------------------------------------------------------------
// 3. VIRTUAL INTERVIEW WORKFLOW TEST (WebRTC + Shared Scoring Engine)
// -------------------------------------------------------------------------
echo "\n--- 3. Virtual Interview Workflow ---\n";

$pdo->prepare(
    "INSERT INTO interview_sessions (session_code, title, department_id, job_title, interview_mode, interview_date, start_time, end_time, status, created_by)
     VALUES ('MYS-VIR-01', 'Virtual ICT Specialist Panel', ?, 'ICT Systems Analyst', 'virtual', '2026-12-02', '10:00', '13:00', 'scheduled', ?)"
)->execute([$deptId, $adminId]);
$virtSessionId = (int)$pdo->lastInsertId();
$scoring->seedDefaultCriteria($virtSessionId);
$pdo->prepare("INSERT INTO result_releases (session_id, is_released) VALUES (?, 0)")->execute([$virtSessionId]);

$room = $video->createOrUpdateRoom($virtSessionId, 'livekit', 'wss://mys-test.livekit.cloud', true, $adminId);
assertTrue($room !== null && str_starts_with($room['room_name'], 'MYS-LR-'), 'Virtual session creates dedicated WebRTC room');
assertEquals(0, (int)$room['recording_enabled'], 'Video recording is DISABLED by default');
assertEquals(1, (int)$room['waiting_room_enabled'], 'Waiting room is enabled by default');

$pdo->prepare("INSERT INTO session_panelists (session_id, panelist_id, panel_role) VALUES (?, ?, 'chairperson'), (?, ?, 'technical_specialist')")
    ->execute([$virtSessionId, $p1Id, $virtSessionId, $p2Id]);
$pdo->prepare("INSERT INTO session_candidates (session_id, candidate_id, scheduled_time, interview_order) VALUES (?, ?, '10:00', 1)")
    ->execute([$virtSessionId, $c1Id]);

$inviteToken = $video->ensureCandidateInviteToken($virtSessionId, $c1Id);
assertTrue(strlen($inviteToken) >= 32, 'Secure candidate virtual invitation token generated');

$video->admitCandidateFromWaitingRoom($virtSessionId, $c1Id, $adminId);
$admittedStatus = $pdo->query("SELECT interview_status FROM session_candidates WHERE session_id = {$virtSessionId} AND candidate_id = {$c1Id}")->fetchColumn();
assertEquals('in_progress', $admittedStatus, 'Admitting candidate from waiting room transitions interview to in_progress');

// Verify LiveKit HS256 JWT generation when env credentials are provided
$_ENV['LIVEKIT_API_KEY'] = 'API_TEST_KEY';
$_ENV['LIVEKIT_API_SECRET'] = 'SUPER_SECRET_TEST_SIGNING_KEY_123456';
$jwt = $video->generateLiveKitToken($room['room_name'], 'panelist-1', 'Panelist One', true);
assertTrue(is_string($jwt) && count(explode('.', $jwt)) === 3, 'LiveKit HS256 JWT token generated with valid 3-segment structure');

// Verify Virtual uses the EXACT same ScoringEngine
$virtCriteria = $scoring->getSessionCriteria($virtSessionId);
$vScores1 = [];
$vScores2 = [];
foreach ($virtCriteria as $c) {
    $vScores1[(int)$c['id']] = (float)$c['max_marks'] * 0.88;
    $vScores2[(int)$c['id']] = (float)$c['max_marks'] * 0.92;
}
$scoring->saveAssessment($virtSessionId, $c1Id, $p1Id, $vScores1, [], 'strongly_recommended', 'Virtual evaluation 1', '', '', true);
$scoring->saveAssessment($virtSessionId, $c1Id, $p2Id, $vScores2, [], 'strongly_recommended', 'Virtual evaluation 2', '', '', true);
$scoring->releaseResults($virtSessionId, $adminId, 'Virtual results released');

$virtRankings = $scoring->getSessionRankings($virtSessionId, $adminUser);
assertEquals(90.0, $virtRankings[0]['average_score'], 'Virtual session computes exact same average score (90.00) via shared ScoringEngine');

// -------------------------------------------------------------------------
// 4. OFFICIAL PDF & EXCEL EXPORT GENERATION TEST
// -------------------------------------------------------------------------
echo "\n--- 4. PDF & Excel Official Reports ---\n";

$pdfOut = $exporter->outputPdf('Final Candidate Ranking Report', '<p>Test Table</p>', 'test_ranking', ['details' => ['Department' => 'Youth Development']], true);
assertTrue(is_string($pdfOut) && strlen($pdfOut) > 200, 'PDF report renderer produces non-empty output');
if (!class_exists(\Dompdf\Dompdf::class)) {
    assertTrue(str_contains($pdfOut, 'Ministry of Youth and Sports') && str_contains($pdfOut, 'Deputy Minister for Administration'), 'Official report includes Ministry branding and authorized signature blocks');
}

$xlsOut = $exporter->outputExcel('Final Candidate Ranking Report', ['Mode' => 'PHYSICAL'], ['Rank', 'Candidate', 'Score'], [[1, 'Korpo Johnson', 85.0]], 'test_ranking', true);
assertTrue(is_string($xlsOut) && strlen($xlsOut) > 100, 'Excel report exporter produces non-empty output');

echo "\n=== SUMMARY: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
