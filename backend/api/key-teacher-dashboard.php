<?php
// ============================================
// FILE: backend/api/key-teacher-dashboard.php
// Purpose: Get dashboard statistics and data for Key Teacher
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : 'overview';

try {
    switch ($action) {
        case 'overview':
            getDashboardOverview($conn);
            break;
        case 'sections':
            getSectionOverview($conn);
            break;
        case 'alerts':
            getActionAlerts($conn);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getDashboardOverview($conn) {
    $academicYear = isset($_GET['year']) ? $_GET['year'] : null;
    $gradeLevel = isset($_GET['grade']) ? $_GET['grade'] : null;
    
    // If no year specified, get current/latest year
    if (!$academicYear) {
        $yearQuery = "SELECT DISTINCT AcademicYear FROM enrollment 
                      WHERE Status IN ('Confirmed', 'Pending') 
                      ORDER BY AcademicYear DESC LIMIT 1";
        $stmt = $conn->prepare($yearQuery);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $academicYear = $result ? $result['AcademicYear'] : date('Y') . '-' . (date('Y') + 1);
    }
    
    // Total enrolled students (confirmed + pending)
    $enrolledQuery = "SELECT COUNT(DISTINCT e.StudentID) as total
                      FROM enrollment e
                      INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                      WHERE e.AcademicYear = :year
                      AND e.Status IN ('Confirmed', 'Pending')";
    
    if ($gradeLevel) {
        $enrolledQuery .= " AND gl.GradeLevelNumber = :grade";
    }
    
    $stmt = $conn->prepare($enrolledQuery);
    $stmt->bindValue(':year', $academicYear);
    if ($gradeLevel) {
        $stmt->bindValue(':grade', $gradeLevel);
    }
    $stmt->execute();
    $enrolled = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Total sections
    $sectionsQuery = "SELECT COUNT(*) as total
                      FROM section s
                      INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                      WHERE s.AcademicYear = :year
                      AND s.IsActive = 1";
    
    if ($gradeLevel) {
        $sectionsQuery .= " AND gl.GradeLevelNumber = :grade";
    }
    
    $stmt = $conn->prepare($sectionsQuery);
    $stmt->bindValue(':year', $academicYear);
    if ($gradeLevel) {
        $stmt->bindValue(':grade', $gradeLevel);
    }
    $stmt->execute();
    $sections = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Average fill rate
    $fillRateQuery = "SELECT 
                        SUM(CurrentEnrollment) as totalEnrolled,
                        SUM(Capacity) as totalCapacity
                      FROM section s
                      INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                      WHERE s.AcademicYear = :year
                      AND s.IsActive = 1";
    
    if ($gradeLevel) {
        $fillRateQuery .= " AND gl.GradeLevelNumber = :grade";
    }
    
    $stmt = $conn->prepare($fillRateQuery);
    $stmt->bindValue(':year', $academicYear);
    if ($gradeLevel) {
        $stmt->bindValue(':grade', $gradeLevel);
    }
    $stmt->execute();
    $fillRate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $avgFillRate = 0;
    if ($fillRate['totalCapacity'] > 0) {
        $avgFillRate = round(($fillRate['totalEnrolled'] / $fillRate['totalCapacity']) * 100);
    }
    
    // Students assigned to sections
    $assignedQuery = "SELECT COUNT(DISTINCT sa.StudentID) as total
                      FROM sectionassignment sa
                      INNER JOIN section s ON sa.SectionID = s.SectionID
                      INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                      WHERE s.AcademicYear = :year
                      AND sa.IsActive = 1";
    
    if ($gradeLevel) {
        $assignedQuery .= " AND gl.GradeLevelNumber = :grade";
    }
    
    $stmt = $conn->prepare($assignedQuery);
    $stmt->bindValue(':year', $academicYear);
    if ($gradeLevel) {
        $stmt->bindValue(':grade', $gradeLevel);
    }
    $stmt->execute();
    $assigned = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $unassigned = $enrolled['total'] - $assigned['total'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'totalEnrolled' => (int)$enrolled['total'],
            'totalSections' => (int)$sections['total'],
            'avgFillRate' => $avgFillRate,
            'assignedStudents' => (int)$assigned['total'],
            'unassignedStudents' => $unassigned,
            'academicYear' => $academicYear
        ]
    ]);
}

function getSectionOverview($conn) {
    $academicYear = isset($_GET['year']) ? $_GET['year'] : null;
    $gradeLevel = isset($_GET['grade']) ? $_GET['grade'] : null;
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    
    // If no year specified, get current/latest year
    if (!$academicYear) {
        $yearQuery = "SELECT DISTINCT AcademicYear FROM section 
                      WHERE IsActive = 1 
                      ORDER BY AcademicYear DESC LIMIT 1";
        $stmt = $conn->prepare($yearQuery);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $academicYear = $result ? $result['AcademicYear'] : date('Y') . '-' . (date('Y') + 1);
    }
    
    $query = "SELECT 
                s.SectionID,
                s.SectionName,
                s.Capacity,
                s.CurrentEnrollment,
                gl.GradeLevelName,
                st.StrandCode,
                COALESCE(CONCAT(e.LastName, ', ', e.FirstName), CONCAT(u.LastName, ', ', u.FirstName)) as AdviserName,
                CASE 
                    WHEN s.CurrentEnrollment >= s.Capacity THEN 'Full'
                    WHEN s.CurrentEnrollment >= (s.Capacity * 0.9) THEN 'Nearing Capacity'
                    ELSE 'Open'
                END as Status,
                ROUND((s.CurrentEnrollment / s.Capacity) * 100) as FillPercentage
              FROM section s
              INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
              LEFT JOIN strand st ON s.StrandID = st.StrandID
              LEFT JOIN employee e ON s.AdviserEmployeeID = e.EmployeeID
              LEFT JOIN user u ON s.AdviserID = u.UserID
              WHERE s.AcademicYear = :year
              AND s.IsActive = 1";
    
    $params = [':year' => $academicYear];
    
    if ($gradeLevel) {
        $query .= " AND gl.GradeLevelNumber = :grade";
        $params[':grade'] = $gradeLevel;
    }
    
    if ($search) {
        $query .= " AND (s.SectionName LIKE :search 
                    OR CONCAT(e.LastName, ', ', e.FirstName) LIKE :search
                    OR CONCAT(u.LastName, ', ', u.FirstName) LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $query .= " ORDER BY gl.GradeLevelNumber, s.SectionName";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $sections = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sections[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'sections' => $sections,
        'count' => count($sections)
    ]);
}

function getActionAlerts($conn) {
    $academicYear = isset($_GET['year']) ? $_GET['year'] : null;
    
    // If no year specified, get current/latest year
    if (!$academicYear) {
        $yearQuery = "SELECT DISTINCT AcademicYear FROM section 
                      WHERE IsActive = 1 
                      ORDER BY AcademicYear DESC LIMIT 1";
        $stmt = $conn->prepare($yearQuery);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $academicYear = $result ? $result['AcademicYear'] : date('Y') . '-' . (date('Y') + 1);
    }
    
    $alerts = [];
    
    // Check for sections nearing capacity (90-99% full)
    $nearingQuery = "SELECT 
                        s.SectionName,
                        s.CurrentEnrollment,
                        s.Capacity,
                        ROUND((s.CurrentEnrollment / s.Capacity) * 100) as FillPercentage
                     FROM section s
                     WHERE s.AcademicYear = :year
                     AND s.IsActive = 1
                     AND s.CurrentEnrollment >= (s.Capacity * 0.9)
                     AND s.CurrentEnrollment < s.Capacity
                     ORDER BY FillPercentage DESC";
    
    $stmt = $conn->prepare($nearingQuery);
    $stmt->bindValue(':year', $academicYear);
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = [
            'type' => 'warning',
            'icon' => 'priority_high',
            'title' => "{$row['SectionName']} is {$row['FillPercentage']}% full.",
            'description' => "Consider closing enrollment soon. ({$row['CurrentEnrollment']}/{$row['Capacity']} students)"
        ];
    }
    
    // Check for full sections
    $fullQuery = "SELECT s.SectionName, s.Capacity
                  FROM section s
                  WHERE s.AcademicYear = :year
                  AND s.IsActive = 1
                  AND s.CurrentEnrollment >= s.Capacity
                  ORDER BY s.SectionName";
    
    $stmt = $conn->prepare($fullQuery);
    $stmt->bindValue(':year', $academicYear);
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = [
            'type' => 'danger',
            'icon' => 'group',
            'title' => "{$row['SectionName']} is now full.",
            'description' => "No further enrollments are possible. ({$row['Capacity']}/{$row['Capacity']} students)"
        ];
    }
    
    // Check for pending enrollments
    $pendingQuery = "SELECT COUNT(*) as count
                     FROM enrollment e
                     INNER JOIN student s ON e.StudentID = s.StudentID
                     WHERE e.AcademicYear = :year
                     AND e.Status = 'Pending'
                     AND NOT EXISTS (
                         SELECT 1 FROM sectionassignment sa
                         WHERE sa.StudentID = s.StudentID
                         AND sa.EnrollmentID = e.EnrollmentID
                         AND sa.IsActive = 1
                     )";
    
    $stmt = $conn->prepare($pendingQuery);
    $stmt->bindValue(':year', $academicYear);
    $stmt->execute();
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pending['count'] > 0) {
        $alerts[] = [
            'type' => 'info',
            'icon' => 'person_add',
            'title' => "New enrollments pending.",
            'description' => "{$pending['count']} student" . ($pending['count'] > 1 ? 's are' : ' is') . " waiting for section assignment."
        ];
    }
    
    // Check for unassigned confirmed students
    $unassignedQuery = "SELECT COUNT(*) as count
                        FROM enrollment e
                        INNER JOIN student s ON e.StudentID = s.StudentID
                        WHERE e.AcademicYear = :year
                        AND e.Status = 'Confirmed'
                        AND NOT EXISTS (
                            SELECT 1 FROM sectionassignment sa
                            WHERE sa.StudentID = s.StudentID
                            AND sa.EnrollmentID = e.EnrollmentID
                            AND sa.IsActive = 1
                        )";
    
    $stmt = $conn->prepare($unassignedQuery);
    $stmt->bindValue(':year', $academicYear);
    $stmt->execute();
    $unassigned = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($unassigned['count'] > 0) {
        $alerts[] = [
            'type' => 'warning',
            'icon' => 'assignment_late',
            'title' => "Confirmed students not assigned.",
            'description' => "{$unassigned['count']} confirmed student" . ($unassigned['count'] > 1 ? 's need' : ' needs') . " section assignment."
        ];
    }
    
    // If no alerts, add a success message
    if (empty($alerts)) {
        $alerts[] = [
            'type' => 'success',
            'icon' => 'check_circle',
            'title' => "All systems running smoothly!",
            'description' => "No action items require your attention at this time."
        ];
    }
    
    echo json_encode([
        'success' => true,
        'alerts' => $alerts,
        'count' => count($alerts)
    ]);
}
?>