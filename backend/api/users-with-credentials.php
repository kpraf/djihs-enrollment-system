<?php
// backend/api/users-with-credentials.php
// SPECIAL ENDPOINT — Only for ICT Coordinator use
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn     = $database->getConnection();
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(200); exit(); }

$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'POST': handlePost($conn, $action); break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function handlePost($conn, $action) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }

    switch ($action) {
        case 'create-with-return': createUserWithPasswordReturn($conn, $data); break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function createUserWithPasswordReturn($conn, $data) {
    $required = ['Username', 'Password', 'FirstName', 'LastName', 'Role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            return;
        }
    }

    // Only one Admin allowed
    if ($data['Role'] === 'Admin') {
        $chk = $conn->prepare("SELECT COUNT(*) as c FROM user WHERE Role = 'Admin'");
        $chk->execute();
        if ($chk->fetch(PDO::FETCH_ASSOC)['c'] > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cannot create Admin account. Only one Admin (Principal) is allowed.']);
            return;
        }
    }

    // Username uniqueness
    $chk = $conn->prepare("SELECT UserID FROM user WHERE Username = :username");
    $chk->bindParam(':username', $data['Username']);
    $chk->execute();
    if ($chk->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        return;
    }

    $plainPassword  = $data['Password'];
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

    // Insert — only columns that exist in the schema
    $sql = "INSERT INTO user (Username, Password, FirstName, LastName, Role, IsActive)
            VALUES (:Username, :Password, :FirstName, :LastName, :Role, :IsActive)";

    try {
        $stmt = $conn->prepare($sql);
        $isActive = $data['IsActive'] ?? 1;
        $stmt->bindParam(':Username',  $data['Username']);
        $stmt->bindParam(':Password',  $hashedPassword);
        $stmt->bindParam(':FirstName', $data['FirstName']);
        $stmt->bindParam(':LastName',  $data['LastName']);
        $stmt->bindParam(':Role',      $data['Role']);
        $stmt->bindParam(':IsActive',  $isActive);
        $stmt->execute();

        $userId = $conn->lastInsertId();

        // Return the plain password ONCE — this is the only time it will ever be visible
        echo json_encode([
            'success' => true,
            'message' => 'User account created successfully',
            'userId'  => $userId,
            'credentials' => [
                'username' => $data['Username'],
                'password' => $plainPassword,
                'warning'  => 'CRITICAL: This password will NEVER be shown again. Save it immediately, or the user will need to use the "Change Password" feature on the login page to reset it.'
            ]
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $e->getMessage()]);
    }
}
?>