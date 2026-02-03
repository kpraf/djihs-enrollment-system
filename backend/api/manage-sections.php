<?php
// ============================================
// FILE: backend/api/manage-sections.php
// Purpose: CRUD operations for sections + Subject Teacher Assignments
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                getSections($conn);
            } elseif ($action === 'advisers') {
                getAvailableAdvisers($conn);
            } elseif ($action === 'details') {
                getSectionDetails($conn);
            } elseif ($action === 'subject-teachers') {
                getSubjectTeachers($conn);
            } elseif ($action === 'teaching-employees') {
                getTeachingEmployees($conn);
            }
            break;
            
        case 'POST':
            if ($action === 'create') {
                createSection($conn);
            } elseif ($action === 'assign-subject-teacher') {
                assignSubjectTeacher($conn);
            }
            break;
            
        case 'PUT':
            if ($action === 'update') {
                updateSection($conn);
            }
            break;
            
        case 'DELETE':
            if ($action === 'delete') {
                deleteSection($conn);
            } elseif ($action === 'remove-subject-teacher') {
                removeSubjectTeacher($conn);
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getSections($conn) {
    $academicYear = isset($_GET['year']) ? $_GET['year'] : null;
    $gradeLevel = isset($_GET['grade']) ? $_GET['grade'] : null;
    $strand = isset($_GET['strand']) ? $_GET['strand'] : null;
    
    $query = "SELECT 
                s.SectionID,
                s.SectionName,
                s.Capacity,
                s.CurrentEnrollment,
                s.AcademicYear,
                s.IsActive,
                gl.GradeLevelName,
                gl.GradeLevelNumber,
                st.StrandCode,
                st.StrandName,
                s.StrandID,
                COALESCE(CONCAT(e.LastName, ', ', e.FirstName), CONCAT(u.LastName, ', ', u.FirstName)) as AdviserName,
                s.AdviserEmployeeID,
                s.AdviserID,
                (s.Capacity - s.CurrentEnrollment) as AvailableSlots,
                CASE 
                    WHEN s.CurrentEnrollment >= s.Capacity THEN 'Full'
                    WHEN s.CurrentEnrollment >= (s.Capacity * 0.9) THEN 'Nearing Full'
                    ELSE 'Open'
                END as Status
              FROM section s
              INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
              LEFT JOIN strand st ON s.StrandID = st.StrandID
              LEFT JOIN employee e ON s.AdviserEmployeeID = e.EmployeeID
              LEFT JOIN user u ON s.AdviserID = u.UserID
              WHERE s.IsActive = 1";
    
    $params = [];
    
    if ($academicYear) {
        $query .= " AND s.AcademicYear = :year";
        $params[':year'] = $academicYear;
    }
    
    if ($gradeLevel) {
        $query .= " AND gl.GradeLevelNumber = :grade";
        $params[':grade'] = $gradeLevel;
    }
    
    if ($strand) {
        $query .= " AND s.StrandID = :strand";
        $params[':strand'] = $strand;
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
        'sections' => $sections
    ]);
}

function getAvailableAdvisers($conn) {
    $query = "SELECT 
                e.EmployeeID,
                CONCAT(e.LastName, ', ', e.FirstName) as FullName,
                e.Department,
                u.UserID
              FROM employee e
              LEFT JOIN user u ON e.EmployeeID = u.EmployeeID
              WHERE e.EmploymentType = 'Teaching'
              AND e.EmploymentStatus = 'Active'
              AND e.IsActive = 1
              ORDER BY e.LastName, e.FirstName";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $advisers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $advisers[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'advisers' => $advisers
    ]);
}

function getTeachingEmployees($conn) {
    $query = "SELECT 
                e.EmployeeID,
                CONCAT(e.LastName, ', ', e.FirstName, ' ', IFNULL(CONCAT(LEFT(e.MiddleName, 1), '.'), '')) as FullName,
                e.Position,
                e.Department
              FROM employee e
              WHERE e.EmploymentType = 'Teaching'
              AND e.EmploymentStatus = 'Active'
              AND e.IsActive = 1
              ORDER BY e.LastName, e.FirstName";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $employees = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $employees[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'employees' => $employees
    ]);
}

function getSubjectTeachers($conn) {
    $sectionId = isset($_GET['sectionId']) ? $_GET['sectionId'] : null;
    
    if (!$sectionId) {
        throw new Exception('Section ID is required');
    }
    
    $query = "SELECT 
                ta.AssignmentID,
                ta.SubjectCode,
                ta.SubjectName,
                CONCAT(e.LastName, ', ', e.FirstName, ' ', IFNULL(CONCAT(LEFT(e.MiddleName, 1), '.'), '')) as TeacherName,
                e.EmployeeID,
                ta.AssignedDate
              FROM teacherassignment ta
              INNER JOIN employee e ON ta.EmployeeID = e.EmployeeID
              WHERE ta.SectionID = :sectionId
              AND ta.AssignmentType = 'Subject_Teacher'
              AND ta.IsActive = 1
              ORDER BY ta.SubjectCode";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':sectionId', $sectionId);
    $stmt->execute();
    
    $teachers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $teachers[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'teachers' => $teachers
    ]);
}

function assignSubjectTeacher($conn) {
    session_start();
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['sectionId']) || empty($data['employeeId']) || 
        empty($data['subjectCode']) || empty($data['subjectName'])) {
        throw new Exception('All fields are required');
    }
    
    // Get section details to extract grade level and academic year
    $sectionQuery = "SELECT GradeLevelID, AcademicYear FROM section WHERE SectionID = :sectionId";
    $stmt = $conn->prepare($sectionQuery);
    $stmt->bindValue(':sectionId', $data['sectionId']);
    $stmt->execute();
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section) {
        throw new Exception('Section not found');
    }
    
    // Check for duplicate assignment
    $checkQuery = "SELECT AssignmentID FROM teacherassignment 
                   WHERE EmployeeID = :employeeId 
                   AND SectionID = :sectionId 
                   AND SubjectCode = :subjectCode
                   AND AssignmentType = 'Subject_Teacher'
                   AND IsActive = 1";
    
    $stmt = $conn->prepare($checkQuery);
    $stmt->bindValue(':employeeId', $data['employeeId']);
    $stmt->bindValue(':sectionId', $data['sectionId']);
    $stmt->bindValue(':subjectCode', $data['subjectCode']);
    $stmt->execute();
    
    if ($stmt->fetch()) {
        throw new Exception('This teacher is already assigned to this subject in this section');
    }
    
    // Insert assignment
    $query = "INSERT INTO teacherassignment 
              (EmployeeID, AcademicYear, AssignmentType, GradeLevelID, SectionID, 
               SubjectCode, SubjectName, IsActive, AssignedBy, AssignedDate)
              VALUES 
              (:employeeId, :academicYear, 'Subject_Teacher', :gradeLevelId, :sectionId,
               :subjectCode, :subjectName, 1, :assignedBy, NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':employeeId', $data['employeeId']);
    $stmt->bindValue(':academicYear', $section['AcademicYear']);
    $stmt->bindValue(':gradeLevelId', $section['GradeLevelID']);
    $stmt->bindValue(':sectionId', $data['sectionId']);
    $stmt->bindValue(':subjectCode', $data['subjectCode']);
    $stmt->bindValue(':subjectName', $data['subjectName']);
    $stmt->bindValue(':assignedBy', $_SESSION['user']['UserID'] ?? null);
    $stmt->execute();
    
    $assignmentId = $conn->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Subject teacher assigned successfully',
        'assignmentId' => $assignmentId
    ]);
}

function removeSubjectTeacher($conn) {
    $assignmentId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$assignmentId) {
        throw new Exception('Assignment ID is required');
    }
    
    // Soft delete the assignment
    $query = "UPDATE teacherassignment 
              SET IsActive = 0 
              WHERE AssignmentID = :id 
              AND AssignmentType = 'Subject_Teacher'";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':id', $assignmentId);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Assignment not found or already removed');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Subject teacher removed successfully'
    ]);
}

function getSectionDetails($conn) {
    $sectionId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$sectionId) {
        throw new Exception('Section ID is required');
    }
    
    $query = "SELECT 
                s.*,
                gl.GradeLevelName,
                gl.GradeLevelNumber,
                st.StrandCode,
                st.StrandName
              FROM section s
              INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
              LEFT JOIN strand st ON s.StrandID = st.StrandID
              WHERE s.SectionID = :id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':id', $sectionId);
    $stmt->execute();
    
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section) {
        throw new Exception('Section not found');
    }
    
    echo json_encode([
        'success' => true,
        'section' => $section
    ]);
}

function createSection($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['sectionName', 'gradeLevelId', 'academicYear', 'capacity'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("Field $field is required");
        }
    }
    
    // Validate adviser - at least one must be provided
    if (empty($data['adviserUserId'])) {
        throw new Exception('Adviser is required. Please select an adviser from the dropdown.');
    }
    
    // Check if section name already exists for this academic year
    $checkQuery = "SELECT SectionID FROM section 
                   WHERE SectionName = :name 
                   AND AcademicYear = :year 
                   AND IsActive = 1";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bindValue(':name', $data['sectionName']);
    $stmt->bindValue(':year', $data['academicYear']);
    $stmt->execute();
    
    if ($stmt->fetch()) {
        throw new Exception('Section name already exists for this academic year');
    }
    
    // Insert section
    $query = "INSERT INTO section 
              (SectionName, GradeLevelID, StrandID, AdviserEmployeeID, AdviserID, 
               Capacity, CurrentEnrollment, AcademicYear, IsActive) 
              VALUES 
              (:name, :gradeLevel, :strand, :adviserEmp, :adviserUser, 
               :capacity, 0, :year, 1)";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':name', $data['sectionName']);
    $stmt->bindValue(':gradeLevel', $data['gradeLevelId']);
    $stmt->bindValue(':strand', $data['strandId'] ?? null);
    $stmt->bindValue(':adviserEmp', $data['adviserEmployeeId'] ?? null);
    $stmt->bindValue(':adviserUser', $data['adviserUserId']);
    $stmt->bindValue(':capacity', $data['capacity']);
    $stmt->bindValue(':year', $data['academicYear']);
    $stmt->execute();
    
    $sectionId = $conn->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Section created successfully',
        'sectionId' => $sectionId
    ]);
}

function updateSection($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['sectionId'])) {
        throw new Exception('Section ID is required');
    }
    
    // Validate adviser - at least one must be provided
    if (empty($data['adviserUserId'])) {
        throw new Exception('Adviser is required. Please select an adviser from the dropdown.');
    }
    
    $query = "UPDATE section SET 
              SectionName = :name,
              GradeLevelID = :gradeLevel,
              StrandID = :strand,
              AdviserEmployeeID = :adviserEmp,
              AdviserID = :adviserUser,
              Capacity = :capacity,
              AcademicYear = :year
              WHERE SectionID = :id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':name', $data['sectionName']);
    $stmt->bindValue(':gradeLevel', $data['gradeLevelId']);
    $stmt->bindValue(':strand', $data['strandId'] ?? null);
    $stmt->bindValue(':adviserEmp', $data['adviserEmployeeId'] ?? null);
    $stmt->bindValue(':adviserUser', $data['adviserUserId']);
    $stmt->bindValue(':capacity', $data['capacity']);
    $stmt->bindValue(':year', $data['academicYear']);
    $stmt->bindValue(':id', $data['sectionId']);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Section updated successfully'
    ]);
}

function deleteSection($conn) {
    $sectionId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$sectionId) {
        throw new Exception('Section ID is required');
    }
    
    // Check if section has students
    $checkQuery = "SELECT COUNT(*) as count FROM sectionassignment 
                   WHERE SectionID = :id AND IsActive = 1";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bindValue(':id', $sectionId);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        throw new Exception('Cannot delete section with assigned students');
    }
    
    // Soft delete
    $query = "UPDATE section SET IsActive = 0 WHERE SectionID = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':id', $sectionId);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Section deleted successfully'
    ]);
}
?>