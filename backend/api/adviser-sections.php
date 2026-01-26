<?php
// backend/api/adviser-sections.php
// API endpoint for advisers to get their assigned sections and students

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function handleGet($db) {
    // Check if getting sections by adviser or students by section
    if (isset($_GET['adviser_id'])) {
        getAdviserSections($db, $_GET['adviser_id']);
    } elseif (isset($_GET['section_id'])) {
        getSectionStudents($db, $_GET['section_id']);
    } elseif (isset($_GET['user_id'])) {
        // Get sections by user ID (alternative method)
        getAdviserSectionsByUser($db, $_GET['user_id']);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters'
        ]);
    }
}

function getAdviserSections($db, $adviserId) {
    try {
        $query = "SELECT 
                    s.SectionID,
                    s.SectionName,
                    s.Capacity,
                    s.CurrentEnrollment,
                    s.AcademicYear,
                    s.IsActive,
                    gl.GradeLevelName,
                    gl.GradeLevelNumber,
                    st.StrandName,
                    st.StrandCode,
                    CONCAT(e.LastName, ', ', e.FirstName, ' ', COALESCE(e.MiddleName, '')) as AdviserName,
                    e.Position as AdviserPosition
                FROM section s
                INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                LEFT JOIN strand st ON s.StrandID = st.StrandID
                LEFT JOIN employee e ON s.AdviserEmployeeID = e.EmployeeID
                WHERE s.AdviserID = :adviser_id 
                AND s.IsActive = 1
                ORDER BY s.AcademicYear DESC, gl.GradeLevelNumber";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':adviser_id', $adviserId, PDO::PARAM_INT);
        $stmt->execute();
        
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $sections,
            'count' => count($sections)
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

function getAdviserSectionsByUser($db, $userId) {
    try {
        // First get the user's employee ID if they have one
        $userQuery = "SELECT EmployeeID FROM user WHERE UserID = :user_id";
        $userStmt = $db->prepare($userQuery);
        $userStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        $query = "SELECT 
                    s.SectionID,
                    s.SectionName,
                    s.Capacity,
                    s.CurrentEnrollment,
                    s.AcademicYear,
                    s.IsActive,
                    gl.GradeLevelName,
                    gl.GradeLevelNumber,
                    st.StrandName,
                    st.StrandCode,
                    CONCAT(u.LastName, ', ', u.FirstName) as AdviserName
                FROM section s
                INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                LEFT JOIN strand st ON s.StrandID = st.StrandID
                LEFT JOIN user u ON s.AdviserID = u.UserID
                WHERE s.AdviserID = :user_id 
                AND s.IsActive = 1
                ORDER BY s.AcademicYear DESC, gl.GradeLevelNumber";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $sections,
            'count' => count($sections)
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

function getSectionStudents($db, $sectionId) {
    try {
        $query = "SELECT 
                    st.StudentID,
                    st.LRN,
                    CONCAT(st.LastName, ', ', st.FirstName, ' ', COALESCE(st.MiddleName, '')) as StudentName,
                    st.FirstName,
                    st.LastName,
                    st.MiddleName,
                    st.Gender,
                    st.DateOfBirth,
                    st.Age,
                    st.ContactNumber,
                    st.Religion,
                    st.Barangay,
                    st.Municipality,
                    st.Province,
                    CONCAT(COALESCE(st.HouseNumber, ''), ' ', 
                           COALESCE(st.SitioStreet, ''), ', ',
                           st.Barangay, ', ',
                           st.Municipality, ', ',
                           st.Province) as CompleteAddress,
                    CONCAT(COALESCE(st.FatherLastName, ''), ', ', 
                           COALESCE(st.FatherFirstName, ''), ' ',
                           COALESCE(st.FatherMiddleName, '')) as FatherName,
                    CONCAT(COALESCE(st.MotherLastName, ''), ', ', 
                           COALESCE(st.MotherFirstName, ''), ' ',
                           COALESCE(st.MotherMiddleName, '')) as MotherName,
                    CONCAT(COALESCE(st.GuardianLastName, ''), ', ', 
                           COALESCE(st.GuardianFirstName, ''), ' ',
                           COALESCE(st.GuardianMiddleName, '')) as GuardianName,
                    st.EnrollmentStatus,
                    sa.AssignmentDate,
                    sa.AssignmentMethod,
                    e.EnrollmentID,
                    e.LearnerType,
                    e.EnrollmentType,
                    e.AcademicYear,
                    gl.GradeLevelName,
                    s.StrandName
                FROM sectionassignment sa
                INNER JOIN student st ON sa.StudentID = st.StudentID
                INNER JOIN enrollment e ON sa.EnrollmentID = e.EnrollmentID
                INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                LEFT JOIN strand s ON e.StrandID = s.StrandID
                WHERE sa.SectionID = :section_id 
                AND sa.IsActive = 1
                AND e.Status = 'Confirmed'
                ORDER BY st.LastName, st.FirstName";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':section_id', $sectionId, PDO::PARAM_INT);
        $stmt->execute();
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get section info
        $sectionQuery = "SELECT 
                            s.SectionName,
                            s.AcademicYear,
                            gl.GradeLevelName,
                            st.StrandName
                        FROM section s
                        INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                        LEFT JOIN strand st ON s.StrandID = st.StrandID
                        WHERE s.SectionID = :section_id";
        
        $sectionStmt = $db->prepare($sectionQuery);
        $sectionStmt->bindParam(':section_id', $sectionId, PDO::PARAM_INT);
        $sectionStmt->execute();
        $sectionInfo = $sectionStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $students,
            'section' => $sectionInfo,
            'count' => count($students)
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
?>