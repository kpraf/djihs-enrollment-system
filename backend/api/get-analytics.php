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

    $schoolYear = isset($_GET['sy']) && $_GET['sy'] !== 'all' ? $_GET['sy'] : null;
    $gradeLevel = isset($_GET['grade']) && $_GET['grade'] !== 'all' ? intval($_GET['grade']) : null;

    $response = [
        'success' => true,
        'data' => [
            'totalStudents'           => 0,
            'dropoutRate'             => 0,
            'pwdCount'                => 0,
            'balikAralCount'          => 0,
            'ipCount'                 => 0,
            'genderDistribution'      => ['male' => 0, 'female' => 0],
            'enrollmentTrends'        => [],
            'learnerTypeDistribution' => [],
            'gradeLevelDistribution'  => [0, 0, 0, 0, 0, 0],
            'strandDistribution'      => [],
            'detailedStats'           => []
        ]
    ];

    function buildWhereClause($baseConditions, $schoolYear, $gradeLevel) {
        $conditions = $baseConditions;
        $params = [];

        if ($schoolYear) {
            $conditions[] = "e.AcademicYear = :schoolYear";
            $params[':schoolYear'] = $schoolYear;
        }

        if ($gradeLevel) {
            $conditions[] = "e.GradeLevelID = :gradeLevel";
            $params[':gradeLevel'] = $gradeLevel;
        }

        return [
            'clause' => implode(' AND ', $conditions),
            'params' => $params
        ];
    }

    $baseConditions = ["e.Status IN ('Confirmed', 'Pending')"];
    $where = buildWhereClause($baseConditions, $schoolYear, $gradeLevel);
    $whereClause = $where['clause'];
    $params = $where['params'];

    // ==========================================
    // 1. TOTAL STUDENTS
    // ==========================================
    $query = "SELECT COUNT(DISTINCT e.StudentID) as total
              FROM enrollment e
              WHERE $whereClause";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalStudents = intval($result['total']);
    $response['data']['totalStudents'] = $totalStudents;

    // Previous year total for trend comparison
    if ($schoolYear) {
        $prevYear = getPreviousSchoolYear($schoolYear);
        $prevConditions = ["e.Status IN ('Confirmed', 'Pending')", "e.AcademicYear = :prevYear"];
        $prevParams = [':prevYear' => $prevYear];

        if ($gradeLevel) {
            $prevConditions[] = "e.GradeLevelID = :gradeLevel";
            $prevParams[':gradeLevel'] = $gradeLevel;
        }

        $prevQuery = "SELECT COUNT(DISTINCT e.StudentID) as total
                      FROM enrollment e
                      WHERE " . implode(' AND ', $prevConditions);

        $prevStmt = $conn->prepare($prevQuery);
        foreach ($prevParams as $key => $value) {
            $prevStmt->bindValue($key, $value);
        }
        $prevStmt->execute();
        $prevResult = $prevStmt->fetch(PDO::FETCH_ASSOC);
        $response['data']['previousYearTotal'] = intval($prevResult['total']);
    }

    // ==========================================
    // 2. DROPOUT RATE
    // ==========================================
    // FIX: Use enrollment.Status IN ('Dropped','Transferred_Out') as the source of truth
    // for dropped students within a year. The student.EnrollmentStatus field is a
    // historical/aggregate flag and should not be used for year-specific dropout calculations.
    $droppedConditions = ["e.Status IN ('Dropped', 'Transferred_Out')"];
    $droppedParams = [];

    if ($schoolYear) {
        $droppedConditions[] = "e.AcademicYear = :schoolYear";
        $droppedParams[':schoolYear'] = $schoolYear;
    }

    if ($gradeLevel) {
        $droppedConditions[] = "e.GradeLevelID = :gradeLevel";
        $droppedParams[':gradeLevel'] = $gradeLevel;
    }

    $droppedQuery = "SELECT COUNT(DISTINCT e.StudentID) as dropped
                     FROM enrollment e
                     WHERE " . implode(' AND ', $droppedConditions);

    $stmt = $conn->prepare($droppedQuery);
    foreach ($droppedParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $droppedCount = intval($result['dropped']);

    $totalForDropout = $totalStudents + $droppedCount;
    $dropoutRate = $totalForDropout > 0 ?
        round(($droppedCount / $totalForDropout) * 100, 2) : 0;
    $response['data']['dropoutRate'] = $dropoutRate;

    // Previous year dropout rate
    if ($schoolYear) {
        $prevYear = getPreviousSchoolYear($schoolYear);
        $prevDropConditions = [
            "e.Status IN ('Dropped', 'Transferred_Out')",
            "e.AcademicYear = :prevYear"
        ];
        $prevDropParams = [':prevYear' => $prevYear];

        if ($gradeLevel) {
            $prevDropConditions[] = "e.GradeLevelID = :gradeLevel";
            $prevDropParams[':gradeLevel'] = $gradeLevel;
        }

        // Also get prev year total enrolled
        $prevTotalConditions = ["e.Status IN ('Confirmed','Pending')", "e.AcademicYear = :prevYear"];
        $prevTotalParams = [':prevYear' => $prevYear];
        if ($gradeLevel) {
            $prevTotalConditions[] = "e.GradeLevelID = :gradeLevel";
            $prevTotalParams[':gradeLevel'] = $gradeLevel;
        }

        $prevDropQuery = "SELECT COUNT(DISTINCT e.StudentID) as dropped
                          FROM enrollment e
                          WHERE " . implode(' AND ', $prevDropConditions);
        $prevTotalQuery = "SELECT COUNT(DISTINCT e.StudentID) as total
                           FROM enrollment e
                           WHERE " . implode(' AND ', $prevTotalConditions);

        $prevDropStmt = $conn->prepare($prevDropQuery);
        foreach ($prevDropParams as $key => $value) {
            $prevDropStmt->bindValue($key, $value);
        }
        $prevDropStmt->execute();
        $prevDropResult = $prevDropStmt->fetch(PDO::FETCH_ASSOC);

        $prevTotalStmt = $conn->prepare($prevTotalQuery);
        foreach ($prevTotalParams as $key => $value) {
            $prevTotalStmt->bindValue($key, $value);
        }
        $prevTotalStmt->execute();
        $prevTotalResult = $prevTotalStmt->fetch(PDO::FETCH_ASSOC);

        $prevDropped = intval($prevDropResult['dropped']);
        $prevTotal   = intval($prevTotalResult['total']);
        $prevTotalWithDropped = $prevTotal + $prevDropped;

        $response['data']['previousDropoutRate'] = $prevTotalWithDropped > 0 ?
            round(($prevDropped / $prevTotalWithDropped) * 100, 2) : 0;
    }

    // ==========================================
    // 3. PWD COUNT
    // ==========================================
    $pwdQuery = "SELECT COUNT(DISTINCT s.StudentID) as pwd_count
                 FROM student s
                 INNER JOIN enrollment e ON s.StudentID = e.StudentID
                 WHERE s.IsPWD = 1
                 AND $whereClause";

    $stmt = $conn->prepare($pwdQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['data']['pwdCount'] = intval($result['pwd_count']);

    // ==========================================
    // 4. BALIK-ARAL COUNT
    // ==========================================
    $balikAralQuery = "SELECT COUNT(DISTINCT e.StudentID) as balik_aral_count
                       FROM enrollment e
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

    // ==========================================
    // 5. IP COMMUNITY COUNT
    // ==========================================
    $ipQuery = "SELECT COUNT(DISTINCT s.StudentID) as ip_count
                FROM student s
                INNER JOIN enrollment e ON s.StudentID = e.StudentID
                WHERE s.IsIPCommunity = 1
                AND $whereClause";

    $stmt = $conn->prepare($ipQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['data']['ipCount'] = intval($result['ip_count']);

    // ==========================================
    // 6. GENDER DISTRIBUTION
    // ==========================================
    $genderQuery = "SELECT
                        s.Gender,
                        COUNT(DISTINCT s.StudentID) as count
                    FROM student s
                    INNER JOIN enrollment e ON s.StudentID = e.StudentID
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

    // ==========================================
    // 7. ENROLLMENT TRENDS
    // ==========================================
    $trendsConditions = ["e.Status IN ('Confirmed', 'Pending')"];
    $trendsParams = [];

    if ($gradeLevel) {
        $trendsConditions[] = "e.GradeLevelID = :gradeLevel";
        $trendsParams[':gradeLevel'] = $gradeLevel;
    }

    $trendsQuery = "SELECT
                        e.AcademicYear as year,
                        COUNT(DISTINCT e.StudentID) as count
                    FROM enrollment e
                    WHERE " . implode(' AND ', $trendsConditions) . "
                    GROUP BY e.AcademicYear
                    ORDER BY e.AcademicYear DESC
                    LIMIT 5";

    $stmt = $conn->prepare($trendsQuery);
    foreach ($trendsParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $trends = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $trends[] = [
            'year'  => $row['year'],
            'count' => intval($row['count'])
        ];
    }
    $response['data']['enrollmentTrends'] = array_reverse($trends);

    // ==========================================
    // 8. LEARNER TYPE DISTRIBUTION
    // ==========================================
    $learnerTypeQuery = "SELECT
                            e.LearnerType,
                            COUNT(DISTINCT e.StudentID) as count
                         FROM enrollment e
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

    // ==========================================
    // 9. GRADE LEVEL DISTRIBUTION
    // ==========================================
    $gradeLevelConditions = ["e.Status IN ('Confirmed', 'Pending')"];
    $gradeLevelParams = [];

    if ($schoolYear) {
        $gradeLevelConditions[] = "e.AcademicYear = :schoolYear";
        $gradeLevelParams[':schoolYear'] = $schoolYear;
    }

    if ($gradeLevel) {
        $gradeLevelConditions[] = "e.GradeLevelID = :gradeLevel";
        $gradeLevelParams[':gradeLevel'] = $gradeLevel;
    }

    $gradeLevelQuery = "SELECT
                            gl.GradeLevelNumber,
                            COUNT(DISTINCT e.StudentID) as count
                        FROM enrollment e
                        INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                        WHERE " . implode(' AND ', $gradeLevelConditions) . "
                        GROUP BY gl.GradeLevelNumber
                        ORDER BY gl.GradeLevelNumber";

    $stmt = $conn->prepare($gradeLevelQuery);
    foreach ($gradeLevelParams as $key => $value) {
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

    // ==========================================
    // 10. STRAND DISTRIBUTION (Grade 11 & 12)
    // ==========================================
    $strandConditions = ["e.Status IN ('Confirmed', 'Pending')", "e.GradeLevelID IN (5, 6)"];
    $strandParams = [];

    if ($schoolYear) {
        $strandConditions[] = "e.AcademicYear = :schoolYear";
        $strandParams[':schoolYear'] = $schoolYear;
    }

    $strandQuery = "SELECT
                        str.StrandCode,
                        str.StrandName,
                        COUNT(DISTINCT e.StudentID) as count
                    FROM enrollment e
                    LEFT JOIN strand str ON e.StrandID = str.StrandID
                    WHERE " . implode(' AND ', $strandConditions) . "
                    GROUP BY str.StrandCode, str.StrandName
                    ORDER BY count DESC";

    $stmt = $conn->prepare($strandQuery);
    foreach ($strandParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $strands = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $strands[] = [
            'code'  => $row['StrandCode'] ?: 'Unknown',
            'name'  => $row['StrandName'] ?: 'Not Assigned',
            'count' => intval($row['count'])
        ];
    }
    $response['data']['strandDistribution'] = $strands;

    // ==========================================
    // 11. DETAILED STATS BY GRADE LEVEL
    // ==========================================
    $detailedQuery = "SELECT
                        gl.GradeLevelNumber as grade,
                        COUNT(DISTINCT e.StudentID) as total,
                        SUM(CASE WHEN s.Gender = 'Male'   THEN 1 ELSE 0 END) as male,
                        SUM(CASE WHEN s.Gender = 'Female' THEN 1 ELSE 0 END) as female,
                        SUM(CASE WHEN s.IsPWD = 1         THEN 1 ELSE 0 END) as pwd,
                        SUM(CASE WHEN e.LearnerType IN ('Regular_Balik_Aral','Irregular_Balik_Aral')
                                 THEN 1 ELSE 0 END) as balikAral
                      FROM enrollment e
                      INNER JOIN student s  ON e.StudentID  = s.StudentID
                      INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
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
            'grade'     => intval($row['grade']),
            'total'     => intval($row['total']),
            'male'      => intval($row['male']),
            'female'    => intval($row['female']),
            'pwd'       => intval($row['pwd']),
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
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString()
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