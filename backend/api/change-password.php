<?php
// backend/api/change-password.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../helpers/audit_logger.php';

$database = new Database();
$conn = $database->getConnection();

// Check if database connection is successful
if ($conn === null) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Validate input
if (empty($data->username) || empty($data->currentPassword) || empty($data->newPassword)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Username, current password, and new password are required'
    ]);
    exit();
}

// Sanitize inputs
$username = htmlspecialchars(strip_tags($data->username));
$currentPassword = $data->currentPassword;
$newPassword = $data->newPassword;

// Validate new password length
if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'New password must be at least 6 characters long'
    ]);
    exit();
}

try {
    // First, verify the user exists and the current password is correct
    $sql = "SELECT UserID, Username, Password, FirstName, LastName, Role, IsActive 
            FROM user 
            WHERE Username = :username";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username'
        ]);
        exit();
    }
    
    // Check if account is active
    if ($user['IsActive'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Account is not active. Please contact the administrator.'
        ]);
        exit();
    }
    
    // Verify current password
    if (!password_verify($currentPassword, $user['Password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Current password is incorrect'
        ]);
        exit();
    }
    
    // Hash the new password
    $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update the password
    $updateSql = "UPDATE user SET Password = :password WHERE UserID = :userId";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bindParam(':password', $hashedNewPassword);
    $updateStmt->bindParam(':userId', $user['UserID']);
    
    if ($updateStmt->execute()) {
        // Log the password change in audit log
        try {
            $logger = new AuditLogger($conn, $user['UserID'], $user['Role']);
            $fullName = $user['FirstName'] . ' ' . $user['LastName'];
            $logger->logPasswordReset(
                $user['UserID'],
                $user['Username'],
                $fullName
            );
        } catch (Exception $e) {
            // Log error but don't fail the password change
            error_log('Failed to log password change: ' . $e->getMessage());
        }
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update password'
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>