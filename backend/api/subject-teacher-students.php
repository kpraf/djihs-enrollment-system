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

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'getSections':
            $query = "SELECT DISTINCT
                        s.SectionID,
                        s.SectionName,
                        gl.GradeLevelName,
                        st.StrandCode,
                        st.StrandName,
                        s.AcademicYear,
                        ta.SubjectCode,
                        ta.SubjectName,
                        (SELECT COUNT(*) 
                         FROM sectionassignment sa 
                         WHERE sa.SectionID = s.SectionID 
                         AND sa.IsActive = 1) as StudentCount
                    FROM teacherassignment ta
                    INNER JOIN section s ON ta.SectionID = s.SectionID
                    INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                    LEFT JOIN strand st ON s.StrandID = st.StrandID
                    WHERE ta.EmployeeID = :employeeID
                    AND ta.AssignmentType = 'Subject_Teacher'
                    AND ta.IsActive = 1
                    AND s.IsActive = 1
                    ORDER BY gl.GradeLevelNumber, s.SectionName";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':employeeID', $employeeID);
            $stmt->execute();
            
            $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'sections' => $sections
            ]);
            break;
            
        case 'getStudents':
            $sectionID = $_GET['sectionID'] ?? null;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            if (!$sectionID) {
                throw new Exception('Section ID is required');
            }
            
            $verifyQuery = "SELECT COUNT(*) as count
                          FROM teacherassignment ta
                          WHERE ta.EmployeeID = :employeeID
                          AND ta.SectionID = :sectionID
                          AND ta.AssignmentType = 'Subject_Teacher'
                          AND ta.IsActive = 1";
            
            $verifyStmt = $db->prepare($verifyQuery);
            $verifyStmt->bindParam(':employeeID', $employeeID);
            $verifyStmt->bindParam(':sectionID', $sectionID);
            $verifyStmt->execute();
            $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($verify['count'] == 0) {
                throw new Exception('You do not have access to this section');
            }
            
            $countQuery = "SELECT COUNT(*) as total
                         FROM student s
                         INNER JOIN sectionassignment sa ON s.StudentID = sa.StudentID
                         WHERE sa.SectionID = :sectionID
                         AND sa.IsActive = 1";
            
            $countStmt = $db->prepare($countQuery);
            $countStmt->bindParam(':sectionID', $sectionID);
            $countStmt->execute();
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $totalRecords = $countResult['total'];
            
            $query = "SELECT 
                        s.StudentID,
                        s.LRN,
                        s.LastName,
                        s.FirstName,
                        s.MiddleName,
                        s.ExtensionName,
                        CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(CONCAT(LEFT(s.MiddleName, 1), '.'), '')) AS StudentName,
                        s.Gender,
                        s.ContactNumber
                    FROM student s
                    INNER JOIN sectionassignment sa ON s.StudentID = sa.StudentID
                    WHERE sa.SectionID = :sectionID
                    AND sa.IsActive = 1
                    ORDER BY s.LastName, s.FirstName
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':sectionID', $sectionID);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'students' => $students,
                'pagination' => [
                    'total' => $totalRecords,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => ceil($totalRecords / $limit)
                ]
            ]);
            break;
            
        case 'getSectionDetails':
            $sectionID = $_GET['sectionID'] ?? null;
            
            if (!$sectionID) {
                throw new Exception('Section ID is required');
            }
            
            $query = "SELECT 
                        s.SectionID,
                        s.SectionName,
                        gl.GradeLevelName,
                        st.StrandCode,
                        st.StrandName,
                        s.AcademicYear,
                        ta.SubjectCode,
                        ta.SubjectName,
                        s.Capacity,
                        (SELECT COUNT(*) 
                         FROM sectionassignment sa 
                         WHERE sa.SectionID = s.SectionID 
                         AND sa.IsActive = 1) as CurrentEnrollment
                    FROM section s
                    INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                    LEFT JOIN strand st ON s.StrandID = st.StrandID
                    INNER JOIN teacherassignment ta ON s.SectionID = ta.SectionID
                    WHERE s.SectionID = :sectionID
                    AND ta.EmployeeID = :employeeID
                    AND ta.AssignmentType = 'Subject_Teacher'
                    AND ta.IsActive = 1
                    AND s.IsActive = 1";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':sectionID', $sectionID);
            $stmt->bindParam(':employeeID', $employeeID);
            $stmt->execute();
            
            $sectionDetails = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sectionDetails) {
                throw new Exception('Section not found or access denied');
            }
            
            echo json_encode([
                'success' => true,
                'section' => $sectionDetails
            ]);
            break;
            
        case 'exportCSV':
            $sectionID = $_GET['sectionID'] ?? null;
            
            if (!$sectionID) {
                throw new Exception('Section ID is required');
            }
            
            $verifyQuery = "SELECT COUNT(*) as count
                          FROM teacherassignment ta
                          WHERE ta.EmployeeID = :employeeID
                          AND ta.SectionID = :sectionID
                          AND ta.AssignmentType = 'Subject_Teacher'
                          AND ta.IsActive = 1";
            
            $verifyStmt = $db->prepare($verifyQuery);
            $verifyStmt->bindParam(':employeeID', $employeeID);
            $verifyStmt->bindParam(':sectionID', $sectionID);
            $verifyStmt->execute();
            $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($verify['count'] == 0) {
                throw new Exception('You do not have access to this section');
            }
            
            $query = "SELECT 
                        s.LRN,
                        CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS StudentName,
                        s.Gender,
                        s.ContactNumber,
                        s.Barangay,
                        s.Municipality,
                        s.Province
                    FROM student s
                    INNER JOIN sectionassignment sa ON s.StudentID = sa.StudentID
                    WHERE sa.SectionID = :sectionID
                    AND sa.IsActive = 1
                    ORDER BY s.LastName, s.FirstName";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':sectionID', $sectionID);
            $stmt->execute();
            
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'students' => $students
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>