<?php
// backend/api/employees.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Get database connection
$database = new Database();
$conn = $database->getConnection();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Handle preflight requests
if ($method == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get action from query parameter
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $action);
            break;
        case 'POST':
            handlePost($conn, $action);
            break;
        case 'PUT':
            handlePut($conn, $action);
            break;
        case 'DELETE':
            handleDelete($conn, $action);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function handleGet($conn, $action) {
    switch ($action) {
        case 'all':
            getAllEmployees($conn);
            break;
        case 'by-id':
            getEmployeeById($conn);
            break;
        case 'teaching':
            getTeachingStaff($conn);
            break;
        case 'non-teaching':
            getNonTeachingStaff($conn);
            break;
        case 'by-department':
            getByDepartment($conn);
            break;
        case 'stats':
            getEmployeeStats($conn);
            break;
        default:
            getAllEmployees($conn);
    }
}

function getAllEmployees($conn) {
    $sql = "SELECT * FROM vw_employeecomplete ORDER BY LastName, FirstName";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $employees,
        'count' => count($employees)
    ]);
}

function getEmployeeById($conn) {
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
        return;
    }
    
    $sql = "SELECT * FROM vw_employeecomplete WHERE EmployeeID = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($employee) {
        echo json_encode(['success' => true, 'data' => $employee]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
    }
}

function getTeachingStaff($conn) {
    $sql = "SELECT * FROM vw_employeecomplete 
            WHERE EmploymentType = 'Teaching' AND IsActive = 1
            ORDER BY Department, Position, LastName";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $employees,
        'count' => count($employees)
    ]);
}

function getNonTeachingStaff($conn) {
    $sql = "SELECT * FROM vw_employeecomplete 
            WHERE EmploymentType = 'Non_Teaching' AND IsActive = 1
            ORDER BY Position, LastName";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $employees,
        'count' => count($employees)
    ]);
}

function getByDepartment($conn) {
    $department = isset($_GET['department']) ? $_GET['department'] : null;
    
    if (!$department) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Department is required']);
        return;
    }
    
    $sql = "SELECT * FROM vw_employeecomplete 
            WHERE Department = :department AND IsActive = 1
            ORDER BY Position, LastName";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':department', $department);
    $stmt->execute();
    
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $employees,
        'count' => count($employees)
    ]);
}

function getEmployeeStats($conn) {
    // Total employees
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN EmploymentType = 'Teaching' THEN 1 ELSE 0 END) as teaching,
                SUM(CASE WHEN EmploymentType = 'Non_Teaching' THEN 1 ELSE 0 END) as non_teaching,
                SUM(CASE WHEN Department = 'JHS' THEN 1 ELSE 0 END) as jhs,
                SUM(CASE WHEN Department = 'SHS' THEN 1 ELSE 0 END) as shs,
                SUM(CASE WHEN Department = 'Admin' THEN 1 ELSE 0 END) as admin,
                SUM(CASE WHEN HasSystemAccess = 1 THEN 1 ELSE 0 END) as with_account
            FROM vw_employeecomplete
            WHERE IsActive = 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);
}

function handlePost($conn, $action) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    switch ($action) {
        case 'create':
            createEmployee($conn, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function createEmployee($conn, $data) {
    // Validate required fields
    $required = ['LastName', 'FirstName', 'Gender', 'EmploymentType', 'Position'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            return;
        }
    }
    
    $sql = "INSERT INTO employee (
                EmployeeNumber, LastName, FirstName, MiddleName, Gender,
                DateOfBirth, ContactNumber, Email, HouseNumber, SitioStreet,
                Barangay, Municipality, Province, EmploymentType, Department,
                Position, DateHired, EmploymentStatus, CreatedBy
            ) VALUES (
                :EmployeeNumber, :LastName, :FirstName, :MiddleName, :Gender,
                :DateOfBirth, :ContactNumber, :Email, :HouseNumber, :SitioStreet,
                :Barangay, :Municipality, :Province, :EmploymentType, :Department,
                :Position, :DateHired, :EmploymentStatus, :CreatedBy
            )";
    
    try {
        $stmt = $conn->prepare($sql);
        
        // Bind parameters
        $stmt->bindParam(':EmployeeNumber', $data['EmployeeNumber']);
        $stmt->bindParam(':LastName', $data['LastName']);
        $stmt->bindParam(':FirstName', $data['FirstName']);
        $stmt->bindParam(':MiddleName', $data['MiddleName']);
        $stmt->bindParam(':Gender', $data['Gender']);
        $stmt->bindParam(':DateOfBirth', $data['DateOfBirth']);
        $stmt->bindParam(':ContactNumber', $data['ContactNumber']);
        $stmt->bindParam(':Email', $data['Email']);
        $stmt->bindParam(':HouseNumber', $data['HouseNumber']);
        $stmt->bindParam(':SitioStreet', $data['SitioStreet']);
        $stmt->bindParam(':Barangay', $data['Barangay']);
        $stmt->bindParam(':Municipality', $data['Municipality']);
        $stmt->bindParam(':Province', $data['Province']);
        $stmt->bindParam(':EmploymentType', $data['EmploymentType']);
        $stmt->bindParam(':Department', $data['Department']);
        $stmt->bindParam(':Position', $data['Position']);
        $stmt->bindParam(':DateHired', $data['DateHired']);
        
        $status = isset($data['EmploymentStatus']) ? $data['EmploymentStatus'] : 'Active';
        $stmt->bindParam(':EmploymentStatus', $status);
        $stmt->bindParam(':CreatedBy', $data['CreatedBy']);
        
        $stmt->execute();
        
        $employeeId = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employee created successfully',
            'employeeId' => $employeeId
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create employee: ' . $e->getMessage()
        ]);
    }
}

function handlePut($conn, $action) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    switch ($action) {
        case 'update':
            updateEmployee($conn, $data);
            break;
        case 'deactivate':
            deactivateEmployee($conn, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function updateEmployee($conn, $data) {
    if (empty($data['EmployeeID'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'EmployeeID is required']);
        return;
    }
    
    $sql = "UPDATE employee SET
                EmployeeNumber = :EmployeeNumber,
                LastName = :LastName,
                FirstName = :FirstName,
                MiddleName = :MiddleName,
                Gender = :Gender,
                DateOfBirth = :DateOfBirth,
                ContactNumber = :ContactNumber,
                Email = :Email,
                HouseNumber = :HouseNumber,
                SitioStreet = :SitioStreet,
                Barangay = :Barangay,
                Municipality = :Municipality,
                Province = :Province,
                Department = :Department,
                Position = :Position,
                DateHired = :DateHired,
                EmploymentStatus = :EmploymentStatus,
                UpdatedBy = :UpdatedBy
            WHERE EmployeeID = :EmployeeID";
    
    try {
        $stmt = $conn->prepare($sql);
        
        $stmt->bindParam(':EmployeeID', $data['EmployeeID']);
        $stmt->bindParam(':EmployeeNumber', $data['EmployeeNumber']);
        $stmt->bindParam(':LastName', $data['LastName']);
        $stmt->bindParam(':FirstName', $data['FirstName']);
        $stmt->bindParam(':MiddleName', $data['MiddleName']);
        $stmt->bindParam(':Gender', $data['Gender']);
        $stmt->bindParam(':DateOfBirth', $data['DateOfBirth']);
        $stmt->bindParam(':ContactNumber', $data['ContactNumber']);
        $stmt->bindParam(':Email', $data['Email']);
        $stmt->bindParam(':HouseNumber', $data['HouseNumber']);
        $stmt->bindParam(':SitioStreet', $data['SitioStreet']);
        $stmt->bindParam(':Barangay', $data['Barangay']);
        $stmt->bindParam(':Municipality', $data['Municipality']);
        $stmt->bindParam(':Province', $data['Province']);
        $stmt->bindParam(':Department', $data['Department']);
        $stmt->bindParam(':Position', $data['Position']);
        $stmt->bindParam(':DateHired', $data['DateHired']);
        $stmt->bindParam(':EmploymentStatus', $data['EmploymentStatus']);
        $stmt->bindParam(':UpdatedBy', $data['UpdatedBy']);
        
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employee updated successfully'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update employee: ' . $e->getMessage()
        ]);
    }
}

function deactivateEmployee($conn, $data) {
    if (empty($data['EmployeeID'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'EmployeeID is required']);
        return;
    }
    
    $sql = "UPDATE employee SET 
                IsActive = 0,
                EmploymentStatus = 'Inactive',
                UpdatedBy = :UpdatedBy
            WHERE EmployeeID = :EmployeeID";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':EmployeeID', $data['EmployeeID']);
        $stmt->bindParam(':UpdatedBy', $data['UpdatedBy']);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employee deactivated successfully'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to deactivate employee: ' . $e->getMessage()
        ]);
    }
}

function handleDelete($conn, $action) {
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
        return;
    }
    
    // Soft delete (deactivate) instead of hard delete
    $sql = "UPDATE employee SET IsActive = 0, EmploymentStatus = 'Inactive' WHERE EmployeeID = :id";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employee deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete employee: ' . $e->getMessage()
        ]);
    }
}
?>