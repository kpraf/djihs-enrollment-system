<?php
// backend/api/users-with-credentials.php
// SPECIAL ENDPOINT - Only for authorized ICT Coordinator use
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
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
        case 'all-with-initial-passwords':
            getAllUsersWithPasswords($conn);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function getAllUsersWithPasswords($conn) {
    // This endpoint should only be accessible to ICT Coordinator
    // In production, add proper authentication check here
    
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
                e.Email,
                'RESET_PASSWORD_REQUIRED' as InitialPassword
            FROM user u
            LEFT JOIN employee e ON u.EmployeeID = e.EmployeeID
            ORDER BY u.CreatedAt DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $users,
        'count' => count($users),
        'note' => 'Actual passwords are hashed and cannot be retrieved. Users must use password reset functionality.'
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
        case 'create-with-return':
            createUserWithPasswordReturn($conn, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function createUserWithPasswordReturn($conn, $data) {
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
    
    // Store the plain password temporarily (only for return in response)
    $plainPassword = $data['Password'];
    
    // Hash the password for database storage
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
        
        // Return the plain password ONLY in this response for ICT Coordinator record-keeping
        echo json_encode([
            'success' => true,
            'message' => 'User account created successfully',
            'userId' => $userId,
            'credentials' => [
                'username' => $data['Username'],
                'password' => $plainPassword,
                'warning' => 'This password will not be shown again. Please record it securely.'
            ]
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create user: ' . $e->getMessage()
        ]);
    }
}
?>