<?php
// get-metrics.php - Calculate DepEd Key Performance Metrics

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // First, verify the enrollment table structure
    $tableCheck = $conn->query("SHOW COLUMNS FROM enrollment LIKE 'AcademicYear'");
    if ($tableCheck->rowCount() === 0) {
        throw new Exception("AcademicYear column not found in enrollment table. Please check your database schema.");
    }
    
    // Check if there's any data
    $dataCheck = $conn->query("SELECT COUNT(*) as count FROM enrollment");
    $dataCount = $dataCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($dataCount['count'] == 0) {
        // Return empty metrics with a helpful message
        echo json_encode([
            'success' => true,
            'message' => 'No enrollment data available. Please add enrollment records to see metrics.',
            'data' => generateEmptyMetrics()
        ]);
        exit;
    }
    
    $sy = isset($_GET['sy']) ? $_GET['sy'] : 'all';
    
    // Build WHERE clause for school year filter
    $syCondition = "";
    if ($sy !== 'all') {
        $syCondition = "AND e.AcademicYear = :sy";
    }
    
    // Get current academic year if 'all' is selected (for default calculations)
    $currentSY = $sy;
    if ($sy === 'all') {
        $syQuery = "SELECT DISTINCT AcademicYear FROM enrollment ORDER BY AcademicYear DESC LIMIT 1";
        $syStmt = $conn->prepare($syQuery);
        $syStmt->execute();
        $syResult = $syStmt->fetch(PDO::FETCH_ASSOC);
        $currentSY = $syResult ? $syResult['AcademicYear'] : date('Y') . '-' . (date('Y') + 1);
    }
    
    $metrics = [];
    
    // ==========================================
    // 1. GROSS ENROLLMENT RATIO (GER)
    // ==========================================
    // Formula: Total Enrollment / School-Age Population × 100
    // Note: Using total enrollment as proxy since we don't have population data
    $gerQuery = "
        SELECT COUNT(DISTINCT e.StudentID) as totalEnrollment
        FROM enrollment e
        WHERE e.Status IN ('Confirmed', 'Pending')
        $syCondition
    ";
    $gerStmt = $conn->prepare($gerQuery);
    if ($sy !== 'all') {
        $gerStmt->bindParam(':sy', $sy);
    }
    $gerStmt->execute();
    $gerData = $gerStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate estimated population (using 1.2x enrollment as estimate)
    $estimatedPopulation = $gerData['totalEnrollment'] * 1.2;
    $metrics['ger'] = $estimatedPopulation > 0 ? 
        ($gerData['totalEnrollment'] / $estimatedPopulation) * 100 : 0;
    
    // ==========================================
    // 2. NET ENROLLMENT RATIO (NER)
    // ==========================================
    // Formula: Age-appropriate Enrollment / Population × 100
    $nerQuery = "
        SELECT COUNT(DISTINCT e.StudentID) as ageAppropriateEnrollment
        FROM enrollment e
        INNER JOIN student s ON e.StudentID = s.StudentID
        INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
        WHERE e.Status IN ('Confirmed', 'Pending')
        AND (
            (gl.GradeLevelNumber = 7 AND s.Age BETWEEN 12 AND 13) OR
            (gl.GradeLevelNumber = 8 AND s.Age BETWEEN 13 AND 14) OR
            (gl.GradeLevelNumber = 9 AND s.Age BETWEEN 14 AND 15) OR
            (gl.GradeLevelNumber = 10 AND s.Age BETWEEN 15 AND 16) OR
            (gl.GradeLevelNumber = 11 AND s.Age BETWEEN 16 AND 17) OR
            (gl.GradeLevelNumber = 12 AND s.Age BETWEEN 17 AND 18)
        )
        $syCondition
    ";
    $nerStmt = $conn->prepare($nerQuery);
    if ($sy !== 'all') {
        $nerStmt->bindParam(':sy', $sy);
    }
    $nerStmt->execute();
    $nerData = $nerStmt->fetch(PDO::FETCH_ASSOC);
    
    $metrics['ner'] = $gerData['totalEnrollment'] > 0 ? 
        ($nerData['ageAppropriateEnrollment'] / $gerData['totalEnrollment']) * 100 : 0;
    
    // ==========================================
    // 3. TRANSITION RATE (Elementary to Secondary)
    // ==========================================
    // Formula: G11 Enrollment (current year) / G10 Enrollment (previous year) × 100
    if ($sy !== 'all') {
        list($startYear, $endYear) = explode('-', $sy);
        $previousSY = ($startYear - 1) . '-' . $startYear;
        
        $transitionQuery = "
            SELECT 
                (SELECT COUNT(*) FROM enrollment e
                 INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                 WHERE gl.GradeLevelNumber = 11 
                 AND e.AcademicYear = :currentSY
                 AND e.Status IN ('Confirmed', 'Pending')) as g11Current,
                (SELECT COUNT(*) FROM enrollment e
                 INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                 WHERE gl.GradeLevelNumber = 10
                 AND e.AcademicYear = :previousSY
                 AND e.Status IN ('Confirmed', 'Pending')) as g10Previous
        ";
        $transitionStmt = $conn->prepare($transitionQuery);
        $transitionStmt->bindParam(':currentSY', $sy);
        $transitionStmt->bindParam(':previousSY', $previousSY);
        $transitionStmt->execute();
        $transitionData = $transitionStmt->fetch(PDO::FETCH_ASSOC);
        
        $metrics['transitionRate'] = $transitionData['g10Previous'] > 0 ? 
            ($transitionData['g11Current'] / $transitionData['g10Previous']) * 100 : 0;
    } else {
        $metrics['transitionRate'] = 0;
    }
    
    // ==========================================
    // 4. COHORT SURVIVAL RATE (JHS)
    // ==========================================
    // Formula: G10 Enrollment (current SY) / G7 Enrollment (3 years ago) × 100
    if ($sy !== 'all') {
        list($startYear, $endYear) = explode('-', $sy);
        $threeYearsAgo = ($startYear - 3) . '-' . ($startYear - 2);
        
        $csrQuery = "
            SELECT 
                (SELECT COUNT(*) FROM enrollment e
                 INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                 WHERE gl.GradeLevelNumber = 10
                 AND e.AcademicYear = :currentSY
                 AND e.Status IN ('Confirmed', 'Pending')) as g10Current,
                (SELECT COUNT(*) FROM enrollment e
                 INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                 WHERE gl.GradeLevelNumber = 7
                 AND e.AcademicYear = :threeYearsAgo
                 AND e.Status IN ('Confirmed', 'Pending')) as g7Previous
        ";
        $csrStmt = $conn->prepare($csrQuery);
        $csrStmt->bindParam(':currentSY', $sy);
        $csrStmt->bindParam(':threeYearsAgo', $threeYearsAgo);
        $csrStmt->execute();
        $csrData = $csrStmt->fetch(PDO::FETCH_ASSOC);
        
        $metrics['cohortSurvivalRateJHS'] = $csrData['g7Previous'] > 0 ? 
            ($csrData['g10Current'] / $csrData['g7Previous']) * 100 : 0;
    } else {
        $metrics['cohortSurvivalRateJHS'] = 0;
    }
    
    // ==========================================
    // 5. PROMOTION RATE
    // ==========================================
    // Formula: Promotees (current year) / Enrollment (previous year) × 100
    // Using confirmed enrollments as proxy for promotions
    $promotionQuery = "
        SELECT COUNT(*) as confirmed
        FROM enrollment
        WHERE Status = 'Confirmed'
        $syCondition
    ";
    $promotionStmt = $conn->prepare($promotionQuery);
    if ($sy !== 'all') {
        $promotionStmt->bindParam(':sy', $sy);
    }
    $promotionStmt->execute();
    $promotionData = $promotionStmt->fetch(PDO::FETCH_ASSOC);
    
    $metrics['promotionRate'] = $gerData['totalEnrollment'] > 0 ? 
        ($promotionData['confirmed'] / $gerData['totalEnrollment']) * 100 : 0;
    
    // ==========================================
    // 6. RETENTION RATE
    // ==========================================
    // Formula: Continuing students / Previous enrollment × 100
    if ($sy !== 'all') {
        list($startYear, $endYear) = explode('-', $sy);
        $previousSY = ($startYear - 1) . '-' . $startYear;
        
        // Students who were enrolled last year and are enrolled this year
        $retentionQuery = "
            SELECT COUNT(DISTINCT s1.StudentID) as retained
            FROM enrollment s1
            INNER JOIN enrollment s2 ON s1.StudentID = s2.StudentID
            WHERE s1.AcademicYear = :currentSY
            AND s2.AcademicYear = :previousSY
            AND s1.Status IN ('Confirmed', 'Pending')
            AND s2.Status IN ('Confirmed', 'Pending')
        ";
        $retentionStmt = $conn->prepare($retentionQuery);
        $retentionStmt->bindParam(':currentSY', $sy);
        $retentionStmt->bindParam(':previousSY', $previousSY);
        $retentionStmt->execute();
        $retentionData = $retentionStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get previous year enrollment
        $prevEnrollQuery = "
            SELECT COUNT(*) as prevEnrollment
            FROM enrollment
            WHERE AcademicYear = :previousSY
            AND Status IN ('Confirmed', 'Pending')
        ";
        $prevEnrollStmt = $conn->prepare($prevEnrollQuery);
        $prevEnrollStmt->bindParam(':previousSY', $previousSY);
        $prevEnrollStmt->execute();
        $prevEnrollData = $prevEnrollStmt->fetch(PDO::FETCH_ASSOC);
        
        $metrics['retentionRate'] = $prevEnrollData['prevEnrollment'] > 0 ? 
            ($retentionData['retained'] / $prevEnrollData['prevEnrollment']) * 100 : 0;
    } else {
        $metrics['retentionRate'] = 0;
    }
    
    // ==========================================
    // 7. DROPOUT RATE
    // ==========================================
    // Formula: Dropped students / Total enrollment × 100
    $dropoutQuery = "
        SELECT COUNT(*) as dropped
        FROM enrollment
        WHERE Status IN ('Dropped', 'Transferred_Out')
        $syCondition
    ";
    $dropoutStmt = $conn->prepare($dropoutQuery);
    if ($sy !== 'all') {
        $dropoutStmt->bindParam(':sy', $sy);
    }
    $dropoutStmt->execute();
    $dropoutData = $dropoutStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalForDropout = $gerData['totalEnrollment'] + $dropoutData['dropped'];
    $metrics['dropoutRate'] = $totalForDropout > 0 ? 
        ($dropoutData['dropped'] / $totalForDropout) * 100 : 0;
    
    // ==========================================
    // 8. COEFFICIENT OF EFFICIENCY
    // ==========================================
    // Formula: (Ideal student-years / Actual student-years) × 100
    // Simplified calculation based on promotion rate
    $metrics['coefficientOfEfficiency'] = $metrics['promotionRate'];
    
    // ==========================================
    // 9. COMPLETION RATE
    // ==========================================
    // Similar to cohort survival rate but for graduates
    $metrics['completionRate'] = $metrics['cohortSurvivalRateJHS'];
    
    // ==========================================
    // 10. STUDENT-TEACHER RATIO
    // ==========================================
    $resourceQuery = "
        SELECT 
            COUNT(DISTINCT e.StudentID) as totalStudents,
            COUNT(DISTINCT emp.EmployeeID) as totalTeachers,
            COUNT(DISTINCT s.SectionID) as totalSections,
            SUM(s.Capacity) as totalCapacity
        FROM enrollment e
        LEFT JOIN employee emp ON emp.EmploymentType = 'Teaching' AND emp.IsActive = 1
        LEFT JOIN section s ON s.IsActive = 1
        WHERE e.Status IN ('Confirmed', 'Pending')
        $syCondition
    ";
    $resourceStmt = $conn->prepare($resourceQuery);
    if ($sy !== 'all') {
        $resourceStmt->bindParam(':sy', $sy);
    }
    $resourceStmt->execute();
    $resourceData = $resourceStmt->fetch(PDO::FETCH_ASSOC);
    
    $metrics['studentTeacherRatio'] = $resourceData['totalTeachers'] > 0 ? 
        $resourceData['totalStudents'] / $resourceData['totalTeachers'] : 0;
    
    $metrics['studentClassroomRatio'] = $resourceData['totalSections'] > 0 ? 
        $resourceData['totalStudents'] / $resourceData['totalSections'] : 0;
    
    $metrics['sectionUtilization'] = $resourceData['totalCapacity'] > 0 ? 
        ($resourceData['totalStudents'] / $resourceData['totalCapacity']) * 100 : 0;
    
    // ==========================================
    // GRADE LEVEL BREAKDOWN
    // ==========================================
    $gradeLevelQuery = "
        SELECT 
            gl.GradeLevelNumber as gradeLevel,
            COUNT(DISTINCT e.StudentID) as enrollment,
            SUM(CASE WHEN e.Status = 'Confirmed' THEN 1 ELSE 0 END) as promoted,
            SUM(CASE WHEN e.Status = 'Dropped' THEN 1 ELSE 0 END) as dropped
        FROM enrollment e
        INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
        WHERE 1=1 $syCondition
        GROUP BY gl.GradeLevelNumber
        ORDER BY gl.GradeLevelNumber
    ";
    $gradeLevelStmt = $conn->prepare($gradeLevelQuery);
    if ($sy !== 'all') {
        $gradeLevelStmt->bindParam(':sy', $sy);
    }
    $gradeLevelStmt->execute();
    
    $gradeLevelData = [];
    while ($row = $gradeLevelStmt->fetch(PDO::FETCH_ASSOC)) {
        $enrollment = $row['enrollment'];
        $promoted = $row['promoted'];
        $dropped = $row['dropped'];
        
        $gradeLevelData[] = [
            'gradeLevel' => $row['gradeLevel'],
            'enrollment' => $enrollment,
            'promotionRate' => $enrollment > 0 ? ($promoted / $enrollment) * 100 : 0,
            'retentionRate' => $enrollment > 0 ? (($enrollment - $dropped) / $enrollment) * 100 : 0,
            'dropoutRate' => $enrollment > 0 ? ($dropped / $enrollment) * 100 : 0
        ];
    }
    
    $metrics['gradeLevelData'] = $gradeLevelData;
    
    // ==========================================
    // TRENDS (Year-over-year comparison)
    // ==========================================
    $trendsQuery = "
        SELECT 
            e.AcademicYear as year,
            COUNT(DISTINCT e.StudentID) as enrollment,
            SUM(CASE WHEN e.Status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN e.Status = 'Dropped' THEN 1 ELSE 0 END) as dropped
        FROM enrollment e
        GROUP BY e.AcademicYear
        ORDER BY e.AcademicYear DESC
        LIMIT 5
    ";
    $trendsStmt = $conn->prepare($trendsQuery);
    $trendsStmt->execute();
    
    $trends = [];
    while ($row = $trendsStmt->fetch(PDO::FETCH_ASSOC)) {
        $enrollment = $row['enrollment'];
        $confirmed = $row['confirmed'];
        $dropped = $row['dropped'];
        
        // Estimate population for GER calculation
        $estPop = $enrollment * 1.2;
        
        $trends[] = [
            'year' => $row['year'],
            'enrollmentRate' => $estPop > 0 ? ($enrollment / $estPop) * 100 : 0,
            'promotionRate' => $enrollment > 0 ? ($confirmed / $enrollment) * 100 : 0,
            'retentionRate' => $enrollment > 0 ? (($enrollment - $dropped) / $enrollment) * 100 : 0,
            'dropoutRate' => $enrollment > 0 ? ($dropped / $enrollment) * 100 : 0
        ];
    }
    
    $metrics['trends'] = array_reverse($trends);
    
    echo json_encode([
        'success' => true,
        'data' => $metrics
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

// Helper function to generate empty metrics when no data exists
function generateEmptyMetrics() {
    return [
        'ger' => 0,
        'ner' => 0,
        'transitionRate' => 0,
        'cohortSurvivalRateJHS' => 0,
        'promotionRate' => 0,
        'retentionRate' => 0,
        'dropoutRate' => 0,
        'coefficientOfEfficiency' => 0,
        'completionRate' => 0,
        'studentTeacherRatio' => 0,
        'studentClassroomRatio' => 0,
        'sectionUtilization' => 0,
        'gradeLevelData' => [],
        'trends' => []
    ];
}
?>