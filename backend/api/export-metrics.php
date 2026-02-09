<?php
// export-metrics.php - Export Key Performance Metrics Report

require_once '../config/database.php';

$sy = isset($_GET['sy']) ? $_GET['sy'] : 'all';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get metrics data (reuse logic from get-metrics.php)
    $syCondition = "";
    if ($sy !== 'all') {
        $syCondition = "AND e.AcademicYear = :sy";
    }
    
    // Get current academic year
    $currentSY = $sy;
    if ($sy === 'all') {
        $syQuery = "SELECT DISTINCT AcademicYear FROM enrollment ORDER BY AcademicYear DESC LIMIT 1";
        $syStmt = $conn->prepare($syQuery);
        $syStmt->execute();
        $syResult = $syStmt->fetch(PDO::FETCH_ASSOC);
        $currentSY = $syResult ? $syResult['AcademicYear'] : date('Y') . '-' . (date('Y') + 1);
    }
    
    // Fetch all metrics data
    $gerQuery = "SELECT COUNT(DISTINCT e.StudentID) as totalEnrollment FROM enrollment e WHERE e.Status IN ('Confirmed', 'Pending') $syCondition";
    $gerStmt = $conn->prepare($gerQuery);
    if ($sy !== 'all') $gerStmt->bindParam(':sy', $sy);
    $gerStmt->execute();
    $gerData = $gerStmt->fetch(PDO::FETCH_ASSOC);
    
    $estimatedPopulation = $gerData['totalEnrollment'] * 1.2;
    $ger = $estimatedPopulation > 0 ? ($gerData['totalEnrollment'] / $estimatedPopulation) * 100 : 0;
    
    // Get grade-level data
    $gradeLevelQuery = "
        SELECT 
            gl.GradeLevelNumber as gradeLevel,
            gl.GradeLevelName as gradeName,
            COUNT(DISTINCT e.StudentID) as enrollment,
            SUM(CASE WHEN e.Status = 'Confirmed' THEN 1 ELSE 0 END) as promoted,
            SUM(CASE WHEN e.Status = 'Dropped' THEN 1 ELSE 0 END) as dropped
        FROM enrollment e
        INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
        WHERE 1=1 $syCondition
        GROUP BY gl.GradeLevelNumber, gl.GradeLevelName
        ORDER BY gl.GradeLevelNumber
    ";
    $gradeLevelStmt = $conn->prepare($gradeLevelQuery);
    if ($sy !== 'all') $gradeLevelStmt->bindParam(':sy', $sy);
    $gradeLevelStmt->execute();
    
    // Generate CSV
    $filename = "DJIHS_Performance_Metrics_" . ($sy !== 'all' ? $sy : 'All_Years') . "_" . date('Y-m-d') . ".csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['DON JOSE INTEGRATED HIGH SCHOOL']);
    fputcsv($output, ['KEY PERFORMANCE METRICS REPORT']);
    fputcsv($output, ['School Year: ' . ($sy !== 'all' ? 'S.Y. ' . $sy : 'All School Years')]);
    fputcsv($output, ['Generated: ' . date('F d, Y g:i A')]);
    fputcsv($output, []);
    
    // Summary Metrics
    fputcsv($output, ['ENROLLMENT METRICS']);
    fputcsv($output, ['Metric', 'Value', 'Standard', 'Status']);
    fputcsv($output, ['Gross Enrollment Ratio (GER)', number_format($ger, 1) . '%', '≥85%', $ger >= 85 ? 'Good' : 'Needs Improvement']);
    fputcsv($output, []);
    
    // Grade Level Breakdown
    fputcsv($output, ['GRADE LEVEL PERFORMANCE']);
    fputcsv($output, ['Grade Level', 'Enrollment', 'Promotion Rate', 'Retention Rate', 'Dropout Rate', 'Status']);
    
    while ($row = $gradeLevelStmt->fetch(PDO::FETCH_ASSOC)) {
        $enrollment = $row['enrollment'];
        $promoted = $row['promoted'];
        $dropped = $row['dropped'];
        
        $promotionRate = $enrollment > 0 ? ($promoted / $enrollment) * 100 : 0;
        $retentionRate = $enrollment > 0 ? (($enrollment - $dropped) / $enrollment) * 100 : 0;
        $dropoutRate = $enrollment > 0 ? ($dropped / $enrollment) * 100 : 0;
        
        $avgPerformance = ($promotionRate + $retentionRate - $dropoutRate) / 2;
        $status = $avgPerformance >= 90 ? 'Excellent' : ($avgPerformance >= 80 ? 'Good' : 'At Risk');
        
        fputcsv($output, [
            $row['gradeName'],
            $enrollment,
            number_format($promotionRate, 1) . '%',
            number_format($retentionRate, 1) . '%',
            number_format($dropoutRate, 1) . '%',
            $status
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['DEPED PERFORMANCE INDICATORS']);
    fputcsv($output, ['Indicator', 'Description', 'Formula']);
    fputcsv($output, ['GER', 'Gross Enrollment Ratio', 'Total Enrollment / Population × 100']);
    fputcsv($output, ['NER', 'Net Enrollment Ratio', 'Age-appropriate Enrollment / Population × 100']);
    fputcsv($output, ['CSR', 'Cohort Survival Rate', 'G10 Enrollment / G7 Enrollment (3 years ago) × 100']);
    fputcsv($output, ['Transition Rate', 'JHS to SHS Transition', 'G11 Enrollment / G10 Enrollment (previous year) × 100']);
    fputcsv($output, ['Promotion Rate', 'Student Promotion', 'Promoted Students / Total Enrollment × 100']);
    fputcsv($output, ['Dropout Rate', 'Student Attrition', 'Dropped Students / Total Enrollment × 100']);
    
    fputcsv($output, []);
    fputcsv($output, ['Report generated by DJIHS Enrollment System']);
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    header('Content-Type: text/plain');
    echo "Error generating report: " . $e->getMessage();
}
?>