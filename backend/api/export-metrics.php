<?php
// ============================================================
// FILE: backend/api/export-metrics.php
// Purpose: Export Key Performance Metrics Report as CSV
// Updated: 2026-03-04 — Revised for normalized DB
//   - enrollment.AcademicYearID (FK int) replaces AcademicYear string
//   - Year resolution via academicyear table
// ============================================================

require_once '../config/database.php';

$syParam = isset($_GET['sy']) ? trim($_GET['sy']) : 'all';

try {
    $database = new Database();
    $conn = $database->getConnection();

    // ─── Resolve AcademicYearID from YearLabel ───────────────────────────
    if ($syParam !== 'all') {
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel FROM academicyear WHERE YearLabel = :yl LIMIT 1"
        );
        $stmt->bindValue(':yl', $syParam);
        $stmt->execute();
        $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ayRow) {
            throw new Exception("Academic year '$syParam' not found.");
        }
        $ayID      = (int) $ayRow['AcademicYearID'];
        $currentSY = $ayRow['YearLabel'];
    } else {
        // Default: active year, fallback to latest StartYear
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel FROM academicyear
             ORDER BY IsActive DESC, StartYear DESC LIMIT 1"
        );
        $stmt->execute();
        $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ayRow) {
            throw new Exception('No academic year records found.');
        }
        $ayID      = (int) $ayRow['AcademicYearID'];
        $currentSY = $ayRow['YearLabel'];
    }

    // ─── Total enrollment ────────────────────────────────────────────────
    if ($syParam !== 'all') {
        $totalStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS total FROM enrollment
             WHERE Status IN ('Confirmed','Pending') AND AcademicYearID = :ayID"
        );
        $totalStmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    } else {
        $totalStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS total FROM enrollment
             WHERE Status IN ('Confirmed','Pending')"
        );
    }
    $totalStmt->execute();
    $totalEnrollment = (int) $totalStmt->fetchColumn();

    $estimatedPopulation = $totalEnrollment > 0 ? $totalEnrollment * 1.2 : 1;
    $ger = round(($totalEnrollment / $estimatedPopulation) * 100, 1);

    // ─── Grade-level breakdown ───────────────────────────────────────────
    if ($syParam !== 'all') {
        $glStmt = $conn->prepare(
            "SELECT
                 gl.GradeLevelNumber AS gradeLevel,
                 gl.GradeLevelName   AS gradeName,
                 COUNT(DISTINCT e.StudentID) AS enrollment,
                 SUM(CASE WHEN e.Status = 'Confirmed'                    THEN 1 ELSE 0 END) AS promoted,
                 SUM(CASE WHEN e.Status IN ('Dropped','Transferred_Out') THEN 1 ELSE 0 END) AS dropped
             FROM enrollment e
             INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
             WHERE e.AcademicYearID = :ayID
             GROUP BY gl.GradeLevelID, gl.GradeLevelNumber, gl.GradeLevelName
             ORDER BY gl.GradeLevelNumber"
        );
        $glStmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    } else {
        $glStmt = $conn->prepare(
            "SELECT
                 gl.GradeLevelNumber AS gradeLevel,
                 gl.GradeLevelName   AS gradeName,
                 COUNT(DISTINCT e.StudentID) AS enrollment,
                 SUM(CASE WHEN e.Status = 'Confirmed'                    THEN 1 ELSE 0 END) AS promoted,
                 SUM(CASE WHEN e.Status IN ('Dropped','Transferred_Out') THEN 1 ELSE 0 END) AS dropped
             FROM enrollment e
             INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
             GROUP BY gl.GradeLevelID, gl.GradeLevelNumber, gl.GradeLevelName
             ORDER BY gl.GradeLevelNumber"
        );
    }
    $glStmt->execute();
    $gradeLevelRows = $glStmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Additional summary metrics ──────────────────────────────────────
    // Promotion rate
    if ($syParam !== 'all') {
        $promStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS confirmed FROM enrollment
             WHERE Status = 'Confirmed' AND AcademicYearID = :ayID"
        );
        $promStmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    } else {
        $promStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS confirmed FROM enrollment WHERE Status = 'Confirmed'"
        );
    }
    $promStmt->execute();
    $confirmed     = (int) $promStmt->fetchColumn();
    $promotionRate = $totalEnrollment > 0 ? round(($confirmed / $totalEnrollment) * 100, 1) : 0;

    // Dropout rate
    if ($syParam !== 'all') {
        $dropStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS dropped FROM enrollment
             WHERE Status IN ('Dropped','Transferred_Out') AND AcademicYearID = :ayID"
        );
        $dropStmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    } else {
        $dropStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS dropped FROM enrollment
             WHERE Status IN ('Dropped','Transferred_Out')"
        );
    }
    $dropStmt->execute();
    $dropped         = (int) $dropStmt->fetchColumn();
    $totalForDropout = $totalEnrollment + $dropped;
    $dropoutRate     = $totalForDropout > 0 ? round(($dropped / $totalForDropout) * 100, 1) : 0;

    // Student-teacher ratio (graceful fallback if employee table absent)
    $strValue = 'N/A';
    try {
        $tchStmt = $conn->prepare(
            "SELECT COUNT(*) FROM employee
             WHERE EmploymentType = 'Teaching' AND EmploymentStatus = 'Active' AND IsActive = 1"
        );
        $tchStmt->execute();
        $teachers = (int) $tchStmt->fetchColumn();
        $strValue = $teachers > 0 ? '1:' . round($totalEnrollment / $teachers, 0) : 'N/A';
    } catch (Exception $e) { /* employee table not yet available */ }

    // ─── Build CSV output ────────────────────────────────────────────────
    $filename = 'DJIHS_Performance_Metrics_'
        . ($syParam !== 'all' ? $syParam : 'All_Years')
        . '_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // ── Report header
    fputcsv($output, ['DON JOSE INTEGRATED HIGH SCHOOL']);
    fputcsv($output, ['School ID: 301239 | Division: Santa Rosa City | Region: IV-A']);
    fputcsv($output, ['KEY PERFORMANCE METRICS REPORT']);
    fputcsv($output, ['']);
    fputcsv($output, ['School Year:', ($syParam !== 'all' ? 'S.Y. ' . $currentSY : 'All School Years')]);
    fputcsv($output, ['Date Generated:', date('F d, Y')]);
    fputcsv($output, ['Time Generated:', date('h:i:s A')]);
    fputcsv($output, ['Generated by:', 'DJIHS Enrollment System']);
    fputcsv($output, ['']);

    // ── Summary metrics
    fputcsv($output, ['ENROLLMENT SUMMARY']);
    fputcsv($output, ['Metric', 'Value', 'Standard', 'Status', 'Note']);
    fputcsv($output, [
        'Total Enrollment',
        $totalEnrollment,
        '-',
        '-',
        ''
    ]);
    fputcsv($output, [
        'Gross Enrollment Ratio (GER)',
        number_format($ger, 1) . '%',
        '≥85%',
        $ger >= 85 ? 'Good' : 'Needs Improvement',
        'Estimated — requires PSA population data for accuracy'
    ]);
    fputcsv($output, [
        'Promotion Rate',
        number_format($promotionRate, 1) . '%',
        '≥95%',
        $promotionRate >= 95 ? 'Good' : ($promotionRate >= 85 ? 'Fair' : 'Needs Improvement'),
        ''
    ]);
    fputcsv($output, [
        'School Leaver Rate',
        number_format($dropoutRate, 1) . '%',
        '≤5%',
        $dropoutRate <= 5 ? 'Good' : ($dropoutRate <= 10 ? 'Fair' : 'Needs Improvement'),
        ''
    ]);
    fputcsv($output, [
        'Student-Teacher Ratio',
        $strValue,
        '≤1:35',
        '-',
        'Requires employee data'
    ]);
    fputcsv($output, []);

    // ── Grade-level breakdown
    fputcsv($output, ['GRADE LEVEL PERFORMANCE']);
    fputcsv($output, ['Grade Level', 'Enrollment', 'Promotion Rate', 'Retention Rate', 'Dropout Rate', 'Status']);

    foreach ($gradeLevelRows as $row) {
        $enr   = (int) $row['enrollment'];
        $prom  = (int) $row['promoted'];
        $drop  = (int) $row['dropped'];
        $total = $enr + $drop;

        $promRate = $enr   > 0 ? round(($prom / $enr)  * 100, 1) : 0;
        $retRate  = $total > 0 ? round(($enr  / $total) * 100, 1) : 0;
        $dropRate = $total > 0 ? round(($drop / $total) * 100, 1) : 0;

        $avg    = ($promRate + $retRate) / 2;
        $status = $avg >= 90 ? 'Excellent' : ($avg >= 80 ? 'Good' : 'At Risk');

        fputcsv($output, [
            $row['gradeName'],
            $enr,
            number_format($promRate, 1) . '%',
            number_format($retRate,  1) . '%',
            number_format($dropRate, 1) . '%',
            $status,
        ]);
    }

    fputcsv($output, []);

    // ── DepEd indicator reference
    fputcsv($output, ['DEPED PERFORMANCE INDICATORS REFERENCE']);
    fputcsv($output, ['Indicator', 'Description', 'Formula']);
    fputcsv($output, ['GER',             'Gross Enrollment Ratio',    'Total Enrollment / Population × 100']);
    fputcsv($output, ['NER',             'Net Enrollment Ratio',      'Age-appropriate Enrollment / Population × 100']);
    fputcsv($output, ['CSR',             'Cohort Survival Rate (JHS)','G10 Enrollment / G7 Enrollment (3 years ago) × 100']);
    fputcsv($output, ['Transition Rate', 'JHS to SHS Transition',     'G11 Enrollment / G10 Enrollment (previous year) × 100']);
    fputcsv($output, ['Promotion Rate',  'Student Promotion',         'Confirmed Enrollments / Total Enrollment × 100']);
    fputcsv($output, ['School Leaver',   'Student Attrition',         'Dropped + Transferred-Out / (Total + Dropped) × 100']);
    fputcsv($output, []);
    fputcsv($output, ['--- End of Report ---']);
    fputcsv($output, ['']);
    fputcsv($output, ['Generated by:', 'DJIHS Enrollment System']);
    fputcsv($output, ['Date & Time:', date('F d, Y') . ' | ' . date('h:i:s A')]);
    fputcsv($output, ['Note:', 'This is a system-generated report. Page 1 of 1.']);

    fclose($output);
    exit;

} catch (Exception $e) {
    header('Content-Type: text/plain');
    echo 'Error generating report: ' . $e->getMessage();
}
?>