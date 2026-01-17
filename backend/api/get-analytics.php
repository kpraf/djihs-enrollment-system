<?php
// backend/api/get-analytics.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once '../config/database.php';
    
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Get filters
    $schoolYear = isset($_GET['sy']) && $_GET['sy'] !== 'all' ? $_GET['sy'] : null;
    $gradeLevel = isset($_GET['grade']) && $_GET['grade'] !== 'all' ? intval($_GET['grade']) : null;

    $response = [
        'success' => true,
        'data' => [
            'totalStudents' => 0,
            'dropoutRate' => 0,
            'pwdCount' => 0,
            'balikAralCount' => 0,
            'genderDistribution' => ['male' => 0, 'female' => 0],
            'enrollmentTrends' => [],
            'learnerTypeDistribution' => [],
            'gradeLevelDistribution' => [0, 0, 0, 0, 0, 0],
            'detailedStats' => []
        ]
    ];

    // Build WHERE clause for filters
    $whereConditions = ["e.Status IN ('Confirmed', 'Pending')"];
    $params = [];

    if ($schoolYear) {
        $whereConditions[] = "e.AcademicYear = :schoolYear";
        $params[':schoolYear'] = $schoolYear;
    }

    if ($gradeLevel) {
        $whereConditions[] = "e.GradeLevelID = :gradeLevel";
        $params[':gradeLevel'] = $gradeLevel;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // 1. TOTAL STUDENTS
    $query = "SELECT COUNT(DISTINCT e.StudentID) as total 
              FROM Enrollment e 
              WHERE $whereClause";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalStudents = intval($result['total']);
    $response['data']['totalStudents'] = $totalStudents;

    // Previous year total for comparison
    if ($schoolYear) {
        $prevYear = getPreviousSchoolYear($schoolYear);
        $prevQuery = "SELECT COUNT(DISTINCT e.StudentID) as total 
                      FROM Enrollment e 
                      WHERE e.Status IN ('Confirmed', 'Pending') 
                      AND e.AcademicYear = :prevYear";
        $prevStmt = $conn->prepare($prevQuery);
        $prevStmt->bindValue(':prevYear', $prevYear);
        $prevStmt->execute();
        $prevResult = $prevStmt->fetch(PDO::FETCH_ASSOC);
        $response['data']['previousYearTotal'] = intval($prevResult['total']);
    }

    // 2. DROPOUT RATE
    $droppedQuery = "SELECT COUNT(DISTINCT s.StudentID) as dropped 
                     FROM Student s
                     INNER JOIN Enrollment e ON s.StudentID = e.StudentID
                     WHERE s.EnrollmentStatus = 'Dropped' 
                     AND $whereClause";
    
    $stmt = $conn->prepare($droppedQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $droppedCount = intval($result['dropped']);
    
    $dropoutRate = ($totalStudents + $droppedCount) > 0 ? 
        round(($droppedCount / ($totalStudents + $droppedCount)) * 100, 2) : 0;
    $response['data']['dropoutRate'] = $dropoutRate;

    // Previous year dropout rate
    if ($schoolYear) {
        $prevDropQuery = "SELECT 
                            COUNT(CASE WHEN s.EnrollmentStatus = 'Dropped' THEN 1 END) as dropped,
                            COUNT(*) as total
                          FROM Student s
                          INNER JOIN Enrollment e ON s.StudentID = e.StudentID
                          WHERE e.AcademicYear = :prevYear";
        $prevStmt = $conn->prepare($prevDropQuery);
        $prevStmt->bindValue(':prevYear', $prevYear);
        $prevStmt->execute();
        $prevResult = $prevStmt->fetch(PDO::FETCH_ASSOC);
        $prevDropRate = $prevResult['total'] > 0 ? 
            round(($prevResult['dropped'] / $prevResult['total']) * 100, 2) : 0;
        $response['data']['previousDropoutRate'] = $prevDropRate;
    }

    // 3. PWD COUNT
    $pwdQuery = "SELECT COUNT(DISTINCT s.StudentID) as pwd_count 
                 FROM Student s
                 INNER JOIN Enrollment e ON s.StudentID = e.StudentID
                 WHERE s.IsPWD = TRUE 
                 AND $whereClause";
    
    $stmt = $conn->prepare($pwdQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['data']['pwdCount'] = intval($result['pwd_count']);

    // 4. BALIK-ARAL COUNT
    $balikAralQuery = "SELECT COUNT(DISTINCT e.StudentID) as balik_aral_count 
                       FROM Enrollment e 
                       WHERE (e.LearnerType = 'Regular_Balik_Aral' 
                       OR e.LearnerType = 'Irregular_Balik_Aral')
                       AND $whereClause";
    
    $stmt = $conn->prepare($balikAralQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['data']['balikAralCount'] = intval($result['balik_aral_count']);

    // 5. GENDER DISTRIBUTION
    $genderQuery = "SELECT 
                        s.Gender,
                        COUNT(DISTINCT s.StudentID) as count
                    FROM Student s
                    INNER JOIN Enrollment e ON s.StudentID = e.StudentID
                    WHERE $whereClause
                    GROUP BY s.Gender";
    
    $stmt = $conn->prepare($genderQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $genderDist = ['male' => 0, 'female' => 0];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $genderDist[strtolower($row['Gender'])] = intval($row['count']);
    }
    $response['data']['genderDistribution'] = $genderDist;

    // 6. ENROLLMENT TRENDS (last 5 years)
    $trendsQuery = "SELECT 
                        e.AcademicYear as year,
                        COUNT(DISTINCT e.StudentID) as count
                    FROM Enrollment e
                    WHERE e.Status IN ('Confirmed', 'Pending')";
    
    if ($gradeLevel) {
        $trendsQuery .= " AND e.GradeLevelID = :gradeLevel";
    }
    
    $trendsQuery .= " GROUP BY e.AcademicYear ORDER BY e.AcademicYear DESC LIMIT 5";
    
    $stmt = $conn->prepare($trendsQuery);
    if ($gradeLevel) {
        $stmt->bindValue(':gradeLevel', $gradeLevel);
    }
    $stmt->execute();
    
    $trends = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $trends[] = [
            'year' => $row['year'],
            'count' => intval($row['count'])
        ];
    }
    $response['data']['enrollmentTrends'] = array_reverse($trends);

    // 7. LEARNER TYPE DISTRIBUTION
    $learnerTypeQuery = "SELECT 
                            e.LearnerType,
                            COUNT(DISTINCT e.StudentID) as count
                         FROM Enrollment e
                         WHERE $whereClause
                         GROUP BY e.LearnerType";
    
    $stmt = $conn->prepare($learnerTypeQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $learnerTypes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $learnerTypes[$row['LearnerType']] = intval($row['count']);
    }
    $response['data']['learnerTypeDistribution'] = $learnerTypes;

    // 8. GRADE LEVEL DISTRIBUTION
    $gradeLevelQuery = "SELECT 
                            gl.GradeLevelNumber,
                            COUNT(DISTINCT e.StudentID) as count
                        FROM Enrollment e
                        INNER JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
                        WHERE e.Status IN ('Confirmed', 'Pending')";
    
    $glParams = [];
    if ($schoolYear) {
        $gradeLevelQuery .= " AND e.AcademicYear = :schoolYear";
        $glParams[':schoolYear'] = $schoolYear;
    }
    
    $gradeLevelQuery .= " GROUP BY gl.GradeLevelNumber ORDER BY gl.GradeLevelNumber";
    
    $stmt = $conn->prepare($gradeLevelQuery);
    foreach ($glParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $gradeDist = [0, 0, 0, 0, 0, 0];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $index = intval($row['GradeLevelNumber']) - 7;
        if ($index >= 0 && $index < 6) {
            $gradeDist[$index] = intval($row['count']);
        }
    }
    $response['data']['gradeLevelDistribution'] = $gradeDist;

    // 9. DETAILED STATS BY GRADE LEVEL
    $detailedQuery = "SELECT 
                        gl.GradeLevelNumber as grade,
                        COUNT(DISTINCT e.StudentID) as total,
                        SUM(CASE WHEN s.Gender = 'Male' THEN 1 ELSE 0 END) as male,
                        SUM(CASE WHEN s.Gender = 'Female' THEN 1 ELSE 0 END) as female,
                        SUM(CASE WHEN s.IsPWD = TRUE THEN 1 ELSE 0 END) as pwd,
                        SUM(CASE WHEN (e.LearnerType = 'Regular_Balik_Aral' OR e.LearnerType = 'Irregular_Balik_Aral') THEN 1 ELSE 0 END) as balikAral
                      FROM Enrollment e
                      INNER JOIN Student s ON e.StudentID = s.StudentID
                      INNER JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
                      WHERE $whereClause
                      GROUP BY gl.GradeLevelNumber
                      ORDER BY gl.GradeLevelNumber";
    
    $stmt = $conn->prepare($detailedQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $detailedStats = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $detailedStats[] = [
            'grade' => intval($row['grade']),
            'total' => intval($row['total']),
            'male' => intval($row['male']),
            'female' => intval($row['female']),
            'pwd' => intval($row['pwd']),
            'balikAral' => intval($row['balikAral'])
        ];
    }
    $response['data']['detailedStats'] = $detailedStats;

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

function getPreviousSchoolYear($currentSY) {
    $years = explode('-', $currentSY);
    if (count($years) == 2) {
        $year1 = intval($years[0]) - 1;
        $year2 = intval($years[1]) - 1;
        return "$year1-$year2";
    }
    return $currentSY;
}
?>