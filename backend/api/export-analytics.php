<?php
// =====================================================
// Export Analytics API - REVISED FOR NORMALIZED DB
// File: backend/api/export-analytics.php
// Updated: 2026-03-04
// Revised to work with normalized database schema
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $format = $_GET['format'] ?? 'csv';
    $sy = isset($_GET['sy']) && $_GET['sy'] !== 'all' ? $_GET['sy'] : null;
    $grade = isset($_GET['grade']) && $_GET['grade'] !== 'all' ? intval($_GET['grade']) : null;
    $section = $_GET['section'] ?? 'all';
    
    // Get analytics data
    $analyticsData = getAnalyticsData($conn, $sy, $grade);
    
    if ($format === 'csv') {
        exportCSV($analyticsData, $sy, $grade, $section);
    } else {
        exportExcel($analyticsData, $sy, $grade, $section);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

function getAnalyticsData($conn, $sy, $grade) {
    $data = [];
    
    // Build WHERE clause
    $conditions = ["e.Status IN ('Confirmed', 'Pending')"];
    $params = [];
    
    if ($sy) {
        $conditions[] = "ay.YearLabel = :schoolYear";
        $params[':schoolYear'] = $sy;
    }
    
    if ($grade) {
        $conditions[] = "e.GradeLevelID = :gradeLevel";
        $params[':gradeLevel'] = $grade;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 1. Total Students
    $query = "SELECT COUNT(DISTINCT s.StudentID) as total 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE $whereClause";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['totalStudents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 2. Gender Distribution
    $query = "SELECT s.Gender, COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE $whereClause
              GROUP BY s.Gender";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $genderData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data['genderDistribution'] = [
        'Male' => 0,
        'Female' => 0
    ];
    foreach ($genderData as $row) {
        $data['genderDistribution'][$row['Gender']] = $row['count'];
    }
    
    // 3. Grade Level Distribution
    $query = "SELECT gl.GradeLevelName, COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
              WHERE $whereClause
              GROUP BY gl.GradeLevelNumber, gl.GradeLevelName
              ORDER BY gl.GradeLevelNumber";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['gradeLevelDistribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Enrollment Type Distribution (was LearnerType)
    $query = "SELECT e.EnrollmentType, COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE $whereClause
              GROUP BY e.EnrollmentType";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['learnerTypeDistribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. PWD Count
    $query = "SELECT COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE $whereClause AND s.IsPWD = 1";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['pwdCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 6. Balik-Aral Count
    $query = "SELECT COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE $whereClause AND e.EnrollmentType = 'Balik_Aral'";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['balikAralCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 7. IP Community Count
    $query = "SELECT COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE $whereClause AND s.IsIPCommunity = 1";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['ipCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 8. Strand Distribution (Grade 11 & 12 only)
    // Note: GradeLevelNumber 11 and 12 (not 5 and 6)
    $strandConditions = ["e.Status IN ('Confirmed', 'Pending')", "gl.GradeLevelNumber IN (11, 12)"];
    $strandParams = [];
    
    if ($sy) {
        $strandConditions[] = "ay.YearLabel = :schoolYear";
        $strandParams[':schoolYear'] = $sy;
    }
    
    $strandWhereClause = implode(' AND ', $strandConditions);
    
    $query = "SELECT st.StrandCode, st.StrandName, COUNT(DISTINCT s.StudentID) as count 
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
              INNER JOIN strand st ON e.StrandID = st.StrandID
              WHERE $strandWhereClause
              GROUP BY st.StrandID, st.StrandCode, st.StrandName
              ORDER BY count DESC";
    $stmt = $conn->prepare($query);
    foreach ($strandParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['strandDistribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 9. Detailed Statistics by Grade
    $query = "SELECT 
                gl.GradeLevelNumber,
                gl.GradeLevelName,
                COUNT(DISTINCT s.StudentID) as Total,
                SUM(CASE WHEN s.Gender = 'Male' THEN 1 ELSE 0 END) as Male,
                SUM(CASE WHEN s.Gender = 'Female' THEN 1 ELSE 0 END) as Female,
                SUM(CASE WHEN s.IsPWD = 1 THEN 1 ELSE 0 END) as PWD,
                SUM(CASE WHEN e.EnrollmentType = 'Balik_Aral' THEN 1 ELSE 0 END) as BalikAral
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
              WHERE $whereClause
              GROUP BY gl.GradeLevelNumber, gl.GradeLevelName
              ORDER BY gl.GradeLevelNumber";
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data['detailedStats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 10. Enrollment Trends (Year-over-year)
    $query = "SELECT ay.YearLabel, COUNT(DISTINCT s.StudentID) as count
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
              WHERE e.Status IN ('Confirmed', 'Pending')
              GROUP BY ay.YearLabel, ay.StartYear
              ORDER BY ay.StartYear";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $trendsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data['enrollmentTrends'] = [];
    foreach ($trendsData as $row) {
        $data['enrollmentTrends'][] = [
            'AcademicYear' => $row['YearLabel'],
            'count' => $row['count']
        ];
    }
    
    return $data;
}

function exportCSV($data, $sy, $grade, $section) {
    $sectionNames = [
        'overview' => 'Overview Summary',
        'trends' => 'Enrollment Trends',
        'demographics' => 'Demographics',
        'distribution' => 'Distribution Analysis',
        'strands' => 'Senior High Strands',
        'detailed' => 'Detailed Report',
        'all' => 'Complete Report'
    ];
    
    $sectionName = $sectionNames[$section] ?? 'Analytics';
    $filename = "DJIHS_" . str_replace(' ', '_', $sectionName) . "_" . date('Y-m-d_His') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Header information
    fputcsv($output, ['DON JOSE INTEGRATED HIGH SCHOOL']);
    fputcsv($output, ['School ID: 301239 | Division: Santa Rosa City | Region: IV-A']);
    fputcsv($output, [$sectionName]);
    fputcsv($output, ['']);
    fputcsv($output, ['School Year:', ($sy ? 'S.Y. ' . $sy : 'All Years')]);
    fputcsv($output, ['Grade Level:', ($grade ? 'Grade ' . (6 + $grade) : 'All Grades')]);
    fputcsv($output, ['Generated by:', 'DJIHS Enrollment System']);
    fputcsv($output, ['Date & Time:', date('F d, Y') . ' | ' . date('h:i:s A')]);
    fputcsv($output, ['']);
    
    // Export based on section
    if ($section === 'all' || $section === 'overview') {
        exportOverviewCSV($output, $data);
    }
    
    if ($section === 'all' || $section === 'trends') {
        exportTrendsCSV($output, $data);
    }
    
    if ($section === 'all' || $section === 'demographics') {
        exportDemographicsCSV($output, $data);
    }
    
    if ($section === 'all' || $section === 'distribution') {
        exportDistributionCSV($output, $data);
    }
    
    if ($section === 'all' || $section === 'strands') {
        if (!empty($data['strandDistribution'])) {
            exportStrandsCSV($output, $data);
        }
    }
    
    if ($section === 'all' || $section === 'detailed') {
        exportDetailedCSV($output, $data);
    }
    
    fclose($output);
    exit;
}

function exportOverviewCSV($output, $data) {
    fputcsv($output, ['OVERVIEW SUMMARY']);
    fputcsv($output, ['Total Enrolled Students', $data['totalStudents']]);
    fputcsv($output, ['PWD Students', $data['pwdCount'], number_format(($data['pwdCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%']);
    fputcsv($output, ['Balik-Aral Students', $data['balikAralCount'], number_format(($data['balikAralCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%']);
    fputcsv($output, ['IP Community Students', $data['ipCount'], number_format(($data['ipCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%']);
    fputcsv($output, []);
}

function exportTrendsCSV($output, $data) {
    fputcsv($output, ['ENROLLMENT TRENDS (YEAR-OVER-YEAR)']);
    fputcsv($output, ['Academic Year', 'Total Students']);
    foreach ($data['enrollmentTrends'] as $row) {
        fputcsv($output, ['S.Y. ' . $row['AcademicYear'], $row['count']]);
    }
    fputcsv($output, []);
}

function exportDemographicsCSV($output, $data) {
    fputcsv($output, ['DEMOGRAPHICS - GENDER DISTRIBUTION']);
    fputcsv($output, ['Gender', 'Count', 'Percentage']);
    foreach ($data['genderDistribution'] as $gender => $count) {
        $percentage = number_format(($count / max($data['totalStudents'], 1)) * 100, 2) . '%';
        fputcsv($output, [$gender, $count, $percentage]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['SPECIAL CATEGORIES']);
    fputcsv($output, ['Category', 'Count', 'Percentage']);
    fputcsv($output, ['PWD Students', $data['pwdCount'], number_format(($data['pwdCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%']);
    fputcsv($output, ['Balik-Aral Students', $data['balikAralCount'], number_format(($data['balikAralCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%']);
    fputcsv($output, ['IP Community', $data['ipCount'], number_format(($data['ipCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%']);
    fputcsv($output, []);
}

function exportDistributionCSV($output, $data) {
    fputcsv($output, ['GRADE LEVEL DISTRIBUTION']);
    fputcsv($output, ['Grade Level', 'Student Count']);
    foreach ($data['gradeLevelDistribution'] as $row) {
        fputcsv($output, [$row['GradeLevelName'], $row['count']]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['ENROLLMENT TYPE DISTRIBUTION']);
    fputcsv($output, ['Enrollment Type', 'Count']);
    foreach ($data['learnerTypeDistribution'] as $row) {
        fputcsv($output, [str_replace('_', ' ', $row['EnrollmentType']), $row['count']]);
    }
    fputcsv($output, []);
}

function exportStrandsCSV($output, $data) {
    fputcsv($output, ['SENIOR HIGH SCHOOL STRAND DISTRIBUTION']);
    fputcsv($output, ['Strand Code', 'Strand Name', 'Student Count']);
    foreach ($data['strandDistribution'] as $row) {
        fputcsv($output, [$row['StrandCode'], $row['StrandName'], $row['count']]);
    }
    fputcsv($output, []);
}

function exportDetailedCSV($output, $data) {
    fputcsv($output, ['DETAILED STATISTICS BY GRADE LEVEL']);
    fputcsv($output, ['Grade Level', 'Total', 'Male', 'Female', 'PWD', 'Balik-Aral']);
    foreach ($data['detailedStats'] as $row) {
        fputcsv($output, [
            $row['GradeLevelName'],
            $row['Total'],
            $row['Male'],
            $row['Female'],
            $row['PWD'],
            $row['BalikAral']
        ]);
    }
    fputcsv($output, []);
}

function exportExcel($data, $sy, $grade, $section) {
    $sectionNames = [
        'overview' => 'Overview Summary',
        'trends' => 'Enrollment Trends',
        'demographics' => 'Demographics',
        'distribution' => 'Distribution Analysis',
        'strands' => 'Senior High Strands',
        'detailed' => 'Detailed Report',
        'all' => 'Complete Report'
    ];
    
    $sectionName = $sectionNames[$section] ?? 'Analytics';
    $filename = "DJIHS_" . str_replace(' ', '_', $sectionName) . "_" . date('Y-m-d_His') . ".xls";
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="utf-8"><style>
        .header { font-size: 18px; font-weight: bold; text-align: center; }
        .subheader { font-size: 14px; text-align: center; }
        .section-title { font-weight: bold; background-color: #085019; color: white; padding: 5px; }
        .data-table { border-collapse: collapse; }
        .data-table th { background-color: #085019; color: white; font-weight: bold; }
    </style></head>';
    echo '<body>';
    
    // Header section
    echo '<table style="width:100%; margin-bottom: 12px;">';
    echo '<tr>
        <td style="width:80px; vertical-align:middle; padding-right:12px;">
            <img src="../../assets/images/DJIHS-Logo.png" style="height:70px; width:auto;" alt="DJIHS Logo"/>
        </td>
        <td style="vertical-align:middle;">
            <div style="font-size:18px; font-weight:bold;">DON JOSE INTEGRATED HIGH SCHOOL</div>
            <div style="font-size:12px;">School ID: 301239 | Division: Santa Rosa City | Region IV-A</div>
            <div style="font-size:14px; font-weight:bold; margin-top:4px;">' . $sectionName . '</div>
        </td>
    </tr>';
    echo '</table>';
    echo '<table style="width:100%; font-size:12px; margin-bottom:8px;">';
    echo '<tr><td><strong>School Year:</strong> ' . ($sy ? 'S.Y. ' . $sy : 'All Years') . '</td><td><strong>Grade Level:</strong> ' . ($grade ? "Grade " . (6 + $grade) : 'All Grades') . '</td></tr>';
    echo '<tr><td><strong>Date Generated:</strong> ' . date('F d, Y') . '</td><td><strong>Time:</strong> ' . date('h:i:s A') . '</td></tr>';
    echo '<tr><td><strong>Generated by:</strong> DJIHS Enrollment System</td><td></td></tr>';
    echo '</table>';
    echo '<hr style="border: 1px solid #085019; margin-bottom: 12px;"/>';
    
    // Export based on section
    if ($section === 'all' || $section === 'overview') {
        exportOverviewExcel($data);
    }
    
    if ($section === 'all' || $section === 'trends') {
        exportTrendsExcel($data);
    }
    
    if ($section === 'all' || $section === 'demographics') {
        exportDemographicsExcel($data);
    }
    
    if ($section === 'all' || $section === 'distribution') {
        exportDistributionExcel($data);
    }
    
    if ($section === 'all' || $section === 'strands') {
        if (!empty($data['strandDistribution'])) {
            exportStrandsExcel($data);
        }
    }
    
    if ($section === 'all' || $section === 'detailed') {
        exportDetailedExcel($data);
    }
    
    echo '<br><hr style="border: 1px solid #085019;"/>';
    echo '<table style="width:100%; font-size:11px; color:#666;">';
    echo '<tr>
        <td>Generated by DJIHS Enrollment System &mdash; ' . date('F d, Y h:i:s A') . '</td>
        <td style="text-align:right;">Page 1 of 1</td>
    </tr>';
    echo '</table>';
    echo '</html>';
    exit;
}

function exportOverviewExcel($data) {
    echo '<h3 class="section-title">OVERVIEW SUMMARY</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><td><strong>Total Enrolled Students</strong></td><td>' . $data['totalStudents'] . '</td><td></td></tr>';
    echo '<tr><td><strong>PWD Students</strong></td><td>' . $data['pwdCount'] . '</td><td>' . number_format(($data['pwdCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%</td></tr>';
    echo '<tr><td><strong>Balik-Aral Students</strong></td><td>' . $data['balikAralCount'] . '</td><td>' . number_format(($data['balikAralCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%</td></tr>';
    echo '<tr><td><strong>IP Community Students</strong></td><td>' . $data['ipCount'] . '</td><td>' . number_format(($data['ipCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%</td></tr>';
    echo '</table><br>';
}

function exportTrendsExcel($data) {
    echo '<h3 class="section-title">ENROLLMENT TRENDS (YEAR-OVER-YEAR)</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Academic Year</th><th>Total Students</th></tr>';
    foreach ($data['enrollmentTrends'] as $row) {
        echo '<tr><td>S.Y. ' . $row['AcademicYear'] . '</td><td>' . $row['count'] . '</td></tr>';
    }
    echo '</table><br>';
}

function exportDemographicsExcel($data) {
    echo '<h3 class="section-title">DEMOGRAPHICS - GENDER DISTRIBUTION</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Gender</th><th>Count</th><th>Percentage</th></tr>';
    foreach ($data['genderDistribution'] as $gender => $count) {
        $percentage = number_format(($count / max($data['totalStudents'], 1)) * 100, 2) . '%';
        echo '<tr><td>' . $gender . '</td><td>' . $count . '</td><td>' . $percentage . '</td></tr>';
    }
    echo '</table><br>';
    
    echo '<h3 class="section-title">SPECIAL CATEGORIES</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Category</th><th>Count</th><th>Percentage</th></tr>';
    echo '<tr><td>PWD Students</td><td>' . $data['pwdCount'] . '</td><td>' . number_format(($data['pwdCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%</td></tr>';
    echo '<tr><td>Balik-Aral Students</td><td>' . $data['balikAralCount'] . '</td><td>' . number_format(($data['balikAralCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%</td></tr>';
    echo '<tr><td>IP Community</td><td>' . $data['ipCount'] . '</td><td>' . number_format(($data['ipCount'] / max($data['totalStudents'], 1)) * 100, 2) . '%</td></tr>';
    echo '</table><br>';
}

function exportDistributionExcel($data) {
    echo '<h3 class="section-title">GRADE LEVEL DISTRIBUTION</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Grade Level</th><th>Student Count</th></tr>';
    foreach ($data['gradeLevelDistribution'] as $row) {
        echo '<tr><td>' . $row['GradeLevelName'] . '</td><td>' . $row['count'] . '</td></tr>';
    }
    echo '</table><br>';
    
    echo '<h3 class="section-title">ENROLLMENT TYPE DISTRIBUTION</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Enrollment Type</th><th>Count</th></tr>';
    foreach ($data['learnerTypeDistribution'] as $row) {
        echo '<tr><td>' . str_replace('_', ' ', $row['EnrollmentType']) . '</td><td>' . $row['count'] . '</td></tr>';
    }
    echo '</table><br>';
}

function exportStrandsExcel($data) {
    echo '<h3 class="section-title">SENIOR HIGH SCHOOL STRAND DISTRIBUTION</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Strand Code</th><th>Strand Name</th><th>Student Count</th></tr>';
    foreach ($data['strandDistribution'] as $row) {
        echo '<tr><td>' . $row['StrandCode'] . '</td><td>' . $row['StrandName'] . '</td><td>' . $row['count'] . '</td></tr>';
    }
    echo '</table><br>';
}

function exportDetailedExcel($data) {
    echo '<h3 class="section-title">DETAILED STATISTICS BY GRADE LEVEL</h3>';
    echo '<table border="1" class="data-table">';
    echo '<tr><th>Grade Level</th><th>Total</th><th>Male</th><th>Female</th><th>PWD</th><th>Balik-Aral</th></tr>';
    foreach ($data['detailedStats'] as $row) {
        echo '<tr>';
        echo '<td>' . $row['GradeLevelName'] . '</td>';
        echo '<td>' . $row['Total'] . '</td>';
        echo '<td>' . $row['Male'] . '</td>';
        echo '<td>' . $row['Female'] . '</td>';
        echo '<td>' . $row['PWD'] . '</td>';
        echo '<td>' . $row['BalikAral'] . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
?>