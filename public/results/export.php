<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AttendanceService;
use App\Services\ReportExportService;
use App\Services\ScoringEngine;

$user = Auth::requireAuth('../login.php');
$pdo = Database::getConnection();
$scoringEngine = new ScoringEngine($pdo);
$attendanceService = new AttendanceService($pdo);
$exporter = new ReportExportService();

$type = (string)($_GET['type'] ?? 'final_ranking');
$format = (string)($_GET['format'] ?? 'pdf');
$sessionId = (int)($_GET['session_id'] ?? 0);
$candidateId = (int)($_GET['candidate_id'] ?? 0);
$panelistId = (int)($_GET['panelist_id'] ?? 0);

$sStmt = $pdo->prepare(
    'SELECT s.*, d.name AS department_name, v.venue_name, v.room_number, v.address AS venue_address,
            vr.provider AS video_provider, vr.room_name
     FROM interview_sessions s
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN interview_venues v ON v.id = s.venue_id
     LEFT JOIN video_interview_rooms vr ON vr.session_id = s.id
     WHERE s.id = :id LIMIT 1'
);
$sStmt->execute(['id' => $sessionId]);
$session = $sStmt->fetch();
if (!$session) {
    Response::flash('danger', 'Interview session not found.');
    Response::redirect('index.php');
}

// Enforce blind-scoring authorization for score/ranking reports
$scoreReports = ['final_ranking', 'candidate_assessment', 'panelist_assessment', 'session_summary', 'final_recruitment'];
if (in_array($type, $scoreReports, true) && !$scoringEngine->canViewReleasedResults($user, $sessionId)) {
    // Allow a panelist to export ONLY their own individual panelist report before release
    if (!($type === 'panelist_assessment' && $panelistId === (int)$user['id'])) {
        http_response_code(403);
        Response::flash('danger', 'Access denied: scores and rankings cannot be exported until results are officially released.');
        Response::redirect('index.php?session_id=' . $sessionId);
    }
}

$locationStr = $session['interview_mode'] === 'physical'
    ? trim(($session['venue_name'] ?: 'Physical Venue') . ' ' . ($session['room_number'] ? '(' . $session['room_number'] . ')' : ''))
    : 'Virtual WebRTC (' . strtoupper((string)($session['video_provider'] ?: 'Jitsi')) . ' — ' . ($session['room_name'] ?: '') . ')';

$baseMeta = [
    'Session Code' => $session['session_code'],
    'Interview Title' => $session['title'],
    'Department' => $session['department_name'],
    'Job Position' => $session['job_title'],
    'Interview Mode' => strtoupper($session['interview_mode']),
    'Date & Time' => $session['interview_date'] . ' (' . substr($session['start_time'], 0, 5) . ' - ' . substr($session['end_time'], 0, 5) . ')',
    'Venue / Platform' => $locationStr,
];

AuditLogger::log($pdo, (int)$user['id'], 'report_exported', 'interview_session', $sessionId, "Type: {$type}, Format: {$format}");

switch ($type) {
    case 'candidate_attendance':
        $rowsData = $attendanceService->getCandidateAttendanceRegister($sessionId);
        $panelists = $attendanceService->getPanelistAttendanceRegister($sessionId);
        $pPresent = count(array_filter($panelists, static fn($p) => in_array($p['attendance_status'], ['Present', 'Late'], true)));
        $pSummary = "{$pPresent} / " . count($panelists) . " Present";
        $headers = ['Application #', 'Candidate Name', 'Scheduled Time', 'Actual Arrival', 'Attendance Status', 'Panelist Attendance', 'Remarks'];
        $rows = [];
        $html = '<table class="data-table"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . e($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rowsData as $r) {
            $row = [
                $r['application_number'],
                $r['full_name'],
                $r['scheduled_time'] ? substr($r['scheduled_time'], 0, 5) : '—',
                $r['actual_arrival_time'] ?: '—',
                $r['attendance_status'],
                $pSummary,
                $r['remarks'] ?: '',
            ];
            $rows[] = $row;
            $html .= '<tr>' . implode('', array_map(static fn($v) => '<td>' . e((string)$v) . '</td>', $row)) . '</tr>';
        }
        $html .= '</tbody></table>';
        $title = 'Interview Attendance Register (Candidates)';
        $file = 'MYS_Candidate_Attendance_' . $session['session_code'];
        if ($format === 'excel') {
            $exporter->outputExcel($title, $baseMeta, $headers, $rows, $file);
        }
        $exporter->outputPdf($title, $html, $file, ['details' => $baseMeta, 'orientation' => 'landscape']);
        break;

    case 'panelist_attendance':
        $rowsData = $attendanceService->getPanelistAttendanceRegister($sessionId);
        $headers = ['Panelist Name', 'Panel Role', 'Job Title', 'Attendance Status', 'Check-In Time', 'Remarks'];
        $rows = [];
        $html = '<table class="data-table"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . e($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rowsData as $r) {
            $row = [
                $r['full_name'],
                ucwords(str_replace('_', ' ', (string)$r['panel_role'])),
                $r['job_title'] ?: '—',
                $r['attendance_status'],
                $r['check_in_time'] ?: '—',
                $r['remarks'] ?: '',
            ];
            $rows[] = $row;
            $html .= '<tr>' . implode('', array_map(static fn($v) => '<td>' . e((string)$v) . '</td>', $row)) . '</tr>';
        }
        $html .= '</tbody></table>';
        $title = 'Interview Panelist Attendance Register';
        $file = 'MYS_Panelist_Attendance_' . $session['session_code'];
        if ($format === 'excel') {
            $exporter->outputExcel($title, $baseMeta, $headers, $rows, $file);
        }
        $exporter->outputPdf($title, $html, $file, ['details' => $baseMeta]);
        break;

    case 'candidate_assessment':
        $rep = $scoringEngine->getCandidateDetailedReport($sessionId, $candidateId, $user);
        $cand = $rep['candidate'];
        $criteria = $rep['criteria'];
        $subs = $rep['submissions'];
        $agg = $rep['aggregate'];
        $baseMeta['Candidate'] = $cand['full_name'] . ' (' . $cand['application_number'] . ')';
        $baseMeta['Final Average'] = $agg ? ($agg['average_score'] . ' (' . $agg['average_percentage'] . '%)') : 'N/A';

        $headers = ['Criterion', 'Max Marks'];
        foreach ($subs as $s) {
            $headers[] = $s['panelist_name'];
        }
        if ($agg) {
            $headers[] = 'Panel Average';
        }
        $rows = [];
        $html = '<table class="data-table"><thead><tr>' . implode('', array_map(static fn($h) => '<th>' . e($h) . '</th>', $headers)) . '</tr></thead><tbody>';
        foreach ($criteria as $c) {
            $cid = (int)$c['id'];
            $row = [$c['criterion_name'], (float)$c['max_marks']];
            foreach ($subs as $s) {
                $row[] = isset($s['scores'][$cid]) ? (float)$s['scores'][$cid]['score'] : 0.0;
            }
            if ($agg) {
                $row[] = (float)($agg['criterion_averages'][$cid] ?? 0.0);
            }
            $rows[] = $row;
            $html .= '<tr>' . implode('', array_map(static fn($v) => '<td>' . e((string)$v) . '</td>', $row)) . '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<div class="section-heading">Panelist Comments &amp; Recommendations</div>';
        foreach ($subs as $s) {
            $recLabel = ScoringEngine::RECOMMENDATIONS[$s['recommendation']] ?? 'N/A';
            $html .= '<p><strong>' . e($s['panelist_name']) . '</strong> (' . e($recLabel) . '): ' . e($s['overall_comments'] ?: 'No remarks') . '</p>';
        }
        $title = 'Candidate Assessment Report';
        $file = 'MYS_Candidate_Assessment_' . $cand['application_number'];
        if ($format === 'excel') {
            $exporter->outputExcel($title, $baseMeta, $headers, $rows, $file);
        }
        $exporter->outputPdf($title, $html, $file, ['details' => $baseMeta]);
        break;

    case 'panelist_assessment':
        $pStmt = $pdo->prepare('SELECT full_name, job_title, email FROM users WHERE id = :id LIMIT 1');
        $pStmt->execute(['id' => $panelistId]);
        $pInfo = $pStmt->fetch();
        $baseMeta['Panelist'] = $pInfo ? ($pInfo['full_name'] . ' (' . ($pInfo['job_title'] ?: $pInfo['email']) . ')') : 'Panelist #' . $panelistId;

        $subStmt = $pdo->prepare(
            "SELECT ps.*, c.application_number, c.full_name AS candidate_name
             FROM panel_submissions ps
             INNER JOIN candidates c ON c.id = ps.candidate_id
             WHERE ps.session_id = :sid AND ps.panelist_id = :pid AND ps.status = 'submitted'
             ORDER BY ps.total_score DESC"
        );
        $subStmt->execute(['sid' => $sessionId, 'pid' => $panelistId]);
        $subs = $subStmt->fetchAll();

        $headers = ['Application #', 'Candidate Name', 'Score', 'Max Score', 'Percentage', 'Recommendation', 'Comments'];
        $rows = [];
        $html = '<table class="data-table"><thead><tr>' . implode('', array_map(static fn($h) => '<th>' . e($h) . '</th>', $headers)) . '</tr></thead><tbody>';
        foreach ($subs as $s) {
            $row = [
                $s['application_number'],
                $s['candidate_name'],
                (float)$s['total_score'],
                (float)$s['max_possible_score'],
                (float)$s['percentage_score'] . '%',
                ScoringEngine::RECOMMENDATIONS[$s['recommendation']] ?? '—',
                $s['overall_comments'] ?: '',
            ];
            $rows[] = $row;
            $html .= '<tr>' . implode('', array_map(static fn($v) => '<td>' . e((string)$v) . '</td>', $row)) . '</tr>';
        }
        $html .= '</tbody></table>';
        $title = 'Individual Panelist Assessment Report';
        $file = 'MYS_Panelist_Report_' . $session['session_code'] . '_P' . $panelistId;
        if ($format === 'excel') {
            $exporter->outputExcel($title, $baseMeta, $headers, $rows, $file);
        }
        $exporter->outputPdf($title, $html, $file, ['details' => $baseMeta, 'orientation' => 'landscape']);
        break;

    case 'session_summary':
    case 'final_recruitment':
    case 'final_ranking':
    default:
        $rankings = $scoringEngine->getSessionRankings($sessionId, $user);
        $progress = $scoringEngine->getSessionSubmissionProgress($sessionId);
        $baseMeta['Completed Evaluations'] = $progress['total_submitted'] . ' / ' . $progress['total_expected'] . ' (' . $progress['completion_rate'] . '%)';

        $headers = ['Rank', 'Application #', 'Candidate Name', 'Attendance', 'Evaluations', 'Average Score', 'Percentage', 'Recommendation'];
        $rows = [];
        $html = '<div class="section-heading">Official Candidate Ranking &amp; Scores</div>';
        $html .= '<table class="data-table"><thead><tr>' . implode('', array_map(static fn($h) => '<th>' . e($h) . '</th>', $headers)) . '</tr></thead><tbody>';
        foreach ($rankings as $r) {
            $row = [
                $r['rank'] !== null ? '#' . $r['rank'] : 'N/A',
                $r['application_number'],
                $r['full_name'],
                $r['attendance_status'],
                $r['evaluations_submitted'] . '/' . $r['evaluations_expected'],
                (float)$r['average_score'],
                (float)$r['average_percentage'] . '%',
                $r['dominant_recommendation'] ? (ScoringEngine::RECOMMENDATIONS[$r['dominant_recommendation']] ?? '') : 'Pending',
            ];
            $rows[] = $row;
            $html .= '<tr>' . implode('', array_map(static fn($v) => '<td>' . e((string)$v) . '</td>', $row)) . '</tr>';
        }
        $html .= '</tbody></table>';

        if ($type === 'final_recruitment' || $type === 'session_summary') {
            $panelAtt = $attendanceService->getPanelistAttendanceRegister($sessionId);
            $html .= '<div class="section-heading">Interview Panel Composition &amp; Attendance</div>';
            $html .= '<table class="data-table"><thead><tr><th>Panelist</th><th>Role</th><th>Status</th><th>Check-In</th></tr></thead><tbody>';
            foreach ($panelAtt as $pa) {
                $html .= '<tr><td>' . e($pa['full_name']) . '</td><td>' . e(ucwords(str_replace('_', ' ', (string)$pa['panel_role']))) . '</td><td>' . e($pa['attendance_status']) . '</td><td>' . e($pa['check_in_time'] ?: '—') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $title = match ($type) {
            'final_recruitment' => 'Official Final Recruitment Report',
            'session_summary' => 'Interview Session Summary Report',
            default => 'Final Candidate Ranking Report',
        };
        $file = 'MYS_' . strtoupper($type) . '_' . $session['session_code'];
        if ($format === 'excel') {
            $exporter->outputExcel($title, $baseMeta, $headers, $rows, $file);
        }
        $exporter->outputPdf($title, $html, $file, ['details' => $baseMeta, 'orientation' => 'landscape']);
        break;
}
