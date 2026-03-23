<?php
// backend/api/users.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../helpers/audit_logger.php';

$database = new Database();
$conn     = $database->getConnection();
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(200); exit(); }

$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------------------
// Get authenticated user from session (set during login in login.php)
// ---------------------------------------------------------------------------
function getCurrentUser() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return [
        'UserID' => $_SESSION['user_id'] ?? null,
        'Role'   => $_SESSION['role']    ?? null,
    ];
}

try {
    switch ($method) {
        case 'GET':    handleGet($conn, $action);    break;
        case 'POST':   handlePost($conn, $action);   break;
        case 'PUT':    handlePut($conn, $action);     break;
        case 'DELETE': handleDelete($conn, $action); break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ===========================================================================
// GET handlers
// ===========================================================================
function handleGet($conn, $action) {
    switch ($action) {
        case 'all':    getAllUsers($conn);   break;
        case 'by-id':  getUserById($conn);  break;
        case 'stats':  getUserStats($conn); break;
        default:       getAllUsers($conn);
    }
}

function getAllUsers($conn) {
    // Only columns that actually exist in the user table
    $sql = "SELECT UserID, Username, FirstName, LastName, Role, IsActive
            FROM user
            ORDER BY LastName, FirstName";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $users, 'count' => count($users)]);
}

function getUserById($conn) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }

    $sql  = "SELECT UserID, Username, FirstName, LastName, Role, IsActive FROM user WHERE UserID = :id";
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

function getUserStats($conn) {
    $sql = "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN IsActive = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN IsActive = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN Role = 'Admin'           THEN 1 ELSE 0 END) as admin,
                SUM(CASE WHEN Role = 'Adviser'         THEN 1 ELSE 0 END) as adviser,
                SUM(CASE WHEN Role = 'Key_Teacher'     THEN 1 ELSE 0 END) as key_teacher,
                SUM(CASE WHEN Role = 'ICT_Coordinator' THEN 1 ELSE 0 END) as ict,
                SUM(CASE WHEN Role = 'Registrar'       THEN 1 ELSE 0 END) as registrar,
                SUM(CASE WHEN Role = 'Subject_Teacher' THEN 1 ELSE 0 END) as subject_teacher
            FROM user";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $stats]);
}

// ===========================================================================
// POST handlers
// ===========================================================================
function handlePost($conn, $action) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }

    switch ($action) {
        case 'create': createUser($conn, $data); break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function createUser($conn, $data) {
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
        $chk  = $conn->prepare("SELECT COUNT(*) as c FROM user WHERE Role = 'Admin'");
        $chk->execute();
        if ($chk->fetch(PDO::FETCH_ASSOC)['c'] > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cannot create Admin account. Only one Admin (Principal) is allowed.']);
            return;
        }
    }

    // Username uniqueness check
    $chk = $conn->prepare("SELECT UserID FROM user WHERE Username = :username");
    $chk->bindParam(':username', $data['Username']);
    $chk->execute();
    if ($chk->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        return;
    }

    $hashedPassword = password_hash($data['Password'], PASSWORD_DEFAULT);

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

        // Audit log
        $cu     = getCurrentUser();
        $logger = new AuditLogger($conn, $cu['UserID'], $cu['Role']);
        $logger->logUserCreation(
            $userId,
            $data['Username'],
            $data['FirstName'] . ' ' . $data['LastName'],
            $data['Role'],
            ['Username' => $data['Username'], 'FirstName' => $data['FirstName'],
             'LastName'  => $data['LastName'],  'Role'      => $data['Role'],
             'IsActive'  => $isActive]
        );

        echo json_encode(['success' => true, 'message' => 'User account created successfully', 'userId' => $userId]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $e->getMessage()]);
    }
}

// ===========================================================================
// PUT handlers
// ===========================================================================
function handlePut($conn, $action) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }

    switch ($action) {
        case 'update':        updateUser($conn, $data);       break;
        case 'toggle-status': toggleUserStatus($conn, $data); break;
        case 'reset-password': resetPassword($conn, $data);   break;
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

    // Fetch old values for audit log
    $oldStmt = $conn->prepare("SELECT Username, FirstName, LastName, Role FROM user WHERE UserID = :id");
    $oldStmt->bindParam(':id', $data['UserID']);
    $oldStmt->execute();
    $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

    // Only update columns that exist in the schema
    $sql = "UPDATE user SET Username = :Username, FirstName = :FirstName, LastName = :LastName, Role = :Role
            WHERE UserID = :UserID";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':UserID',    $data['UserID']);
        $stmt->bindParam(':Username',  $data['Username']);
        $stmt->bindParam(':FirstName', $data['FirstName']);
        $stmt->bindParam(':LastName',  $data['LastName']);
        $stmt->bindParam(':Role',      $data['Role']);
        $stmt->execute();

        $cu     = getCurrentUser();
        $logger = new AuditLogger($conn, $cu['UserID'], $cu['Role']);
        $logger->logUserUpdate(
            $data['UserID'],
            $data['Username'],
            $data['FirstName'] . ' ' . $data['LastName'],
            $oldData,
            ['Username' => $data['Username'], 'FirstName' => $data['FirstName'],
             'LastName'  => $data['LastName'],  'Role'      => $data['Role']]
        );

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

    $infoStmt = $conn->prepare("SELECT Role, IsActive, Username, FirstName, LastName FROM user WHERE UserID = :id");
    $infoStmt->bindParam(':id', $data['UserID']);
    $infoStmt->execute();
    $userInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

    if ($userInfo && $userInfo['Role'] === 'Admin') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot deactivate the Admin account. This account must remain active.']);
        return;
    }

    try {
        $stmt = $conn->prepare("UPDATE user SET IsActive = NOT IsActive WHERE UserID = :id");
        $stmt->bindParam(':id', $data['UserID']);
        $stmt->execute();

        $cu     = getCurrentUser();
        $logger = new AuditLogger($conn, $cu['UserID'], $cu['Role']);
        $logger->logUserStatusChange(
            $data['UserID'],
            $userInfo['Username'],
            $userInfo['FirstName'] . ' ' . $userInfo['LastName'],
            !$userInfo['IsActive']
        );

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

    $infoStmt = $conn->prepare("SELECT Username, FirstName, LastName FROM user WHERE UserID = :id");
    $infoStmt->bindParam(':id', $data['UserID']);
    $infoStmt->execute();
    $userInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

    $hashedPassword = password_hash($data['NewPassword'], PASSWORD_DEFAULT);

    try {
        $stmt = $conn->prepare("UPDATE user SET Password = :Password WHERE UserID = :UserID");
        $stmt->bindParam(':Password', $hashedPassword);
        $stmt->bindParam(':UserID',   $data['UserID']);
        $stmt->execute();

        $cu     = getCurrentUser();
        $logger = new AuditLogger($conn, $cu['UserID'], $cu['Role']);
        $logger->logPasswordReset(
            $data['UserID'],
            $userInfo['Username'],
            $userInfo['FirstName'] . ' ' . $userInfo['LastName']
        );

        echo json_encode(['success' => true, 'message' => 'Password reset successfully']);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to reset password: ' . $e->getMessage()]);
    }
}

// ===========================================================================
// DELETE handler (soft delete = deactivate)
// ===========================================================================
function handleDelete($conn, $action) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }

    $infoStmt = $conn->prepare("SELECT Username, FirstName, LastName FROM user WHERE UserID = :id");
    $infoStmt->bindParam(':id', $id);
    $infoStmt->execute();
    $userInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

    try {
        $stmt = $conn->prepare("UPDATE user SET IsActive = 0 WHERE UserID = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $cu     = getCurrentUser();
        $logger = new AuditLogger($conn, $cu['UserID'], $cu['Role']);
        $logger->log(
            'user', $id, 'DELETE',
            "Deactivated user account: {$userInfo['Username']}",
            null, null,
            $userInfo['FirstName'] . ' ' . $userInfo['LastName']
        );

        echo json_encode(['success' => true, 'message' => 'User deactivated successfully']);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to deactivate user: ' . $e->getMessage()]);
    }
}
?>