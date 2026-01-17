<?php
// backend/api/users.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function handleGet($conn, $action) {
    switch ($action) {
        case 'all':
            getAllUsers($conn);
            break;
        case 'by-id':
            getUserById($conn);
            break;
        case 'employees-without-account':
            getEmployeesWithoutAccount($conn);
            break;
        case 'stats':
            getUserStats($conn);
            break;
        default:
            getAllUsers($conn);
    }
}

function getAllUsers($conn) {
    $sql = "SELECT 
                u.UserID,
                u.Username,
                u.FirstName,
                u.LastName,
                u.Role,
                u.IsActive,
                u.CreatedAt,
                u.EmployeeID,
                e.EmployeeNumber,
                e.Position,
                e.Department,
                e.ContactNumber,
                e.Email
            FROM user u
            LEFT JOIN employee e ON u.EmployeeID = e.EmployeeID
            ORDER BY u.CreatedAt DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $users,
        'count' => count($users)
    ]);
}

function getUserById($conn) {
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }
    
    $sql = "SELECT 
                u.*,
                e.EmployeeNumber,
                e.LastName as EmpLastName,
                e.FirstName as EmpFirstName,
                e.Position,
                e.Department,
                e.ContactNumber,
                e.Email
            FROM user u
            LEFT JOIN employee e ON u.EmployeeID = e.EmployeeID
            WHERE u.UserID = :id";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode(['success' => true, 'data' => $user]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
}

function getEmployeesWithoutAccount($conn) {
    $sql = "SELECT 
                e.EmployeeID,
                e.EmployeeNumber,
                e.LastName,
                e.FirstName,
                e.MiddleName,
                CONCAT(e.LastName, ', ', e.FirstName, ' ', IFNULL(e.MiddleName, '')) as FullName,
                e.Gender,
                e.EmploymentType,
                e.Department,
                e.Position,
                e.ContactNumber,
                e.Email
            FROM employee e
            LEFT JOIN user u ON e.EmployeeID = u.EmployeeID
            WHERE u.UserID IS NULL 
            AND e.IsActive = 1
            ORDER BY e.LastName, e.FirstName";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $employees,
        'count' => count($employees)
    ]);
}

function getUserStats($conn) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN IsActive = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN IsActive = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN Role = 'Admin' THEN 1 ELSE 0 END) as admin,
                SUM(CASE WHEN Role = 'Adviser' THEN 1 ELSE 0 END) as adviser,
                SUM(CASE WHEN Role = 'Key_Teacher' THEN 1 ELSE 0 END) as key_teacher,
                SUM(CASE WHEN Role = 'ICT_Coordinator' THEN 1 ELSE 0 END) as ict,
                SUM(CASE WHEN Role = 'Registrar' THEN 1 ELSE 0 END) as registrar,
                SUM(CASE WHEN Role = 'Subject_Teacher' THEN 1 ELSE 0 END) as subject_teacher
            FROM user";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $stats]);
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
            createUser($conn, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function createUser($conn, $data) {
    // Validate required fields
    $required = ['Username', 'Password', 'FirstName', 'LastName', 'Role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            return;
        }
    }
    
    // Check if username already exists
    $checkSql = "SELECT UserID FROM user WHERE Username = :username";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bindParam(':username', $data['Username']);
    $checkStmt->execute();
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        return;
    }
    
    // Hash the password
    $hashedPassword = password_hash($data['Password'], PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO user (
                EmployeeID, Username, Password, FirstName, LastName, Role, IsActive
            ) VALUES (
                :EmployeeID, :Username, :Password, :FirstName, :LastName, :Role, :IsActive
            )";
    
    try {
        $stmt = $conn->prepare($sql);
        
        $employeeId = isset($data['EmployeeID']) && $data['EmployeeID'] !== '' ? $data['EmployeeID'] : null;
        $isActive = isset($data['IsActive']) ? $data['IsActive'] : 1;
        
        $stmt->bindParam(':EmployeeID', $employeeId);
        $stmt->bindParam(':Username', $data['Username']);
        $stmt->bindParam(':Password', $hashedPassword);
        $stmt->bindParam(':FirstName', $data['FirstName']);
        $stmt->bindParam(':LastName', $data['LastName']);
        $stmt->bindParam(':Role', $data['Role']);
        $stmt->bindParam(':IsActive', $isActive);
        
        $stmt->execute();
        
        $userId = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'User account created successfully',
            'userId' => $userId
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create user: ' . $e->getMessage()
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
            updateUser($conn, $data);
            break;
        case 'toggle-status':
            toggleUserStatus($conn, $data);
            break;
        case 'reset-password':
            resetPassword($conn, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function updateUser($conn, $data) {
    if (empty($data['UserID'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'UserID is required']);
        return;
    }
    
    $sql = "UPDATE user SET
                Username = :Username,
                FirstName = :FirstName,
                LastName = :LastName,
                Role = :Role
            WHERE UserID = :UserID";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':UserID', $data['UserID']);
        $stmt->bindParam(':Username', $data['Username']);
        $stmt->bindParam(':FirstName', $data['FirstName']);
        $stmt->bindParam(':LastName', $data['LastName']);
        $stmt->bindParam(':Role', $data['Role']);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $e->getMessage()]);
    }
}

function toggleUserStatus($conn, $data) {
    if (empty($data['UserID'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'UserID is required']);
        return;
    }
    
    $sql = "UPDATE user SET IsActive = NOT IsActive WHERE UserID = :UserID";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':UserID', $data['UserID']);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'User status updated successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()]);
    }
}

function resetPassword($conn, $data) {
    if (empty($data['UserID']) || empty($data['NewPassword'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'UserID and NewPassword are required']);
        return;
    }
    
    $hashedPassword = password_hash($data['NewPassword'], PASSWORD_DEFAULT);
    
    $sql = "UPDATE user SET Password = :Password WHERE UserID = :UserID";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':Password', $hashedPassword);
        $stmt->bindParam(':UserID', $data['UserID']);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to reset password: ' . $e->getMessage()]);
    }
}

function handleDelete($conn, $action) {
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }
    
    // Soft delete - just deactivate
    $sql = "UPDATE user SET IsActive = 0 WHERE UserID = :id";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'User deactivated successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete user: ' . $e->getMessage()]);
    }
}
?>