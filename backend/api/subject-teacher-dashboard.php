<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-Data');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

// Get user data from custom header
$userDataHeader = $_SERVER['HTTP_X_USER_DATA'] ?? '';

if (!$userDataHeader) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Not authenticated. Please log in.'
    ]);
    exit;
}

// Decode user data from header
$userData = json_decode($userDataHeader, true);

if (!$userData || !isset($userData['UserID']) || !isset($userData['Role'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid authentication data.'
    ]);
    exit;
}

// Verify user has Subject_Teacher role
if ($userData['Role'] !== 'Subject_Teacher') {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Access denied. Subject Teacher role required.'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Get EmployeeID from user table using UserID
$employeeID = null;
$query = "SELECT EmployeeID FROM user WHERE UserID = :userID";
$stmt = $db->prepare($query);
$stmt->bindParam(':userID', $userData['UserID']);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result && $result['EmployeeID']) {
    $employeeID = $result['EmployeeID'];
} else {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'No employee record found for this user. Please contact administrator.'
    ]);
    exit;
}

try {
    // Get dashboard statistics
    
    // 1. Count assigned sections/classes
    $sectionsQuery = "SELECT COUNT(DISTINCT ta.SectionID) as total
                      FROM teacherassignment ta
                      WHERE ta.EmployeeID = :employeeID
                      AND ta.AssignmentType = 'Subject_Teacher'
                      AND ta.IsActive = 1";
    
    $stmt = $db->prepare($sectionsQuery);
    $stmt->bindParam(':employeeID', $employeeID);
    $stmt->execute();
    $sectionsCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 2. Count total students across all sections
    $studentsQuery = "SELECT COUNT(DISTINCT sa.StudentID) as total
                      FROM teacherassignment ta
                      INNER JOIN sectionassignment sa ON ta.SectionID = sa.SectionID
                      WHERE ta.EmployeeID = :employeeID
                      AND ta.AssignmentType = 'Subject_Teacher'
                      AND ta.IsActive = 1
                      AND sa.IsActive = 1";
    
    $stmt = $db->prepare($studentsQuery);
    $stmt->bindParam(':employeeID', $employeeID);
    $stmt->execute();
    $studentsCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. Get list of all classes with details
    $classesQuery = "SELECT DISTINCT
                        s.SectionID,
                        s.SectionName,
                        gl.GradeLevelName,
                        gl.GradeLevelNumber,
                        st.StrandCode,
                        st.StrandName,
                        s.AcademicYear,
                        ta.SubjectCode,
                        ta.SubjectName,
                        (SELECT COUNT(*) 
                         FROM sectionassignment sa 
                         WHERE sa.SectionID = s.SectionID 
                         AND sa.IsActive = 1) as StudentCount,
                        s.Capacity
                    FROM teacherassignment ta
                    INNER JOIN section s ON ta.SectionID = s.SectionID
                    INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                    LEFT JOIN strand st ON s.StrandID = st.StrandID
                    WHERE ta.EmployeeID = :employeeID
                    AND ta.AssignmentType = 'Subject_Teacher'
                    AND ta.IsActive = 1
                    AND s.IsActive = 1
                    ORDER BY gl.GradeLevelNumber, s.SectionName
                    LIMIT 5";
    
    $stmt = $db->prepare($classesQuery);
    $stmt->bindParam(':employeeID', $employeeID);
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Get subject distribution (how many sections per subject)
    $subjectsQuery = "SELECT 
                        ta.SubjectName,
                        ta.SubjectCode,
                        COUNT(DISTINCT ta.SectionID) as SectionCount,
                        SUM((SELECT COUNT(*) 
                             FROM sectionassignment sa 
                             WHERE sa.SectionID = ta.SectionID 
                             AND sa.IsActive = 1)) as TotalStudents
                      FROM teacherassignment ta
                      WHERE ta.EmployeeID = :employeeID
                      AND ta.AssignmentType = 'Subject_Teacher'
                      AND ta.IsActive = 1
                      GROUP BY ta.SubjectName, ta.SubjectCode
                      ORDER BY SectionCount DESC";
    
    $stmt = $db->prepare($subjectsQuery);
    $stmt->bindParam(':employeeID', $employeeID);
    $stmt->execute();
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Get grade level distribution
    $gradeLevelsQuery = "SELECT 
                            gl.GradeLevelName,
                            gl.GradeLevelNumber,
                            COUNT(DISTINCT ta.SectionID) as SectionCount,
                            SUM((SELECT COUNT(*) 
                                 FROM sectionassignment sa 
                                 WHERE sa.SectionID = ta.SectionID 
                                 AND sa.IsActive = 1)) as TotalStudents
                         FROM teacherassignment ta
                         INNER JOIN section s ON ta.SectionID = s.SectionID
                         INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                         WHERE ta.EmployeeID = :employeeID
                         AND ta.AssignmentType = 'Subject_Teacher'
                         AND ta.IsActive = 1
                         GROUP BY gl.GradeLevelName, gl.GradeLevelNumber
                         ORDER BY gl.GradeLevelNumber";
    
    $stmt = $db->prepare($gradeLevelsQuery);
    $stmt->bindParam(':employeeID', $employeeID);
    $stmt->execute();
    $gradeLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return all data
    echo json_encode([
        'success' => true,
        'statistics' => [
            'totalClasses' => $sectionsCount,
            'totalStudents' => $studentsCount,
            'totalSubjects' => count($subjects)
        ],
        'classes' => $classes,
        'subjects' => $subjects,
        'gradeLevels' => $gradeLevels
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>