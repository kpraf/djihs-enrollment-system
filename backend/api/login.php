<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include_once '../config/database.php';
include_once '../includes/auth.php';

// Initialize database and auth
$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Check if database connection is successful
if ($db === null) {
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
if (empty($data->username) || empty($data->password) || empty($data->role)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide username, password, and role'
    ]);
    exit();
}

// Sanitize inputs
$username = htmlspecialchars(strip_tags($data->username));
$password = $data->password; // Don't sanitize password (will be hashed)
$role = htmlspecialchars(strip_tags($data->role));

// Convert frontend role values to database ENUM values
$roleMap = [
    'admin' => 'Admin',
    'adviser' => 'Adviser',
    'key-teacher' => 'Key_Teacher',
    'ict-coordinator' => 'ICT_Coordinator',
    'registrar' => 'Registrar',
    'subject-teacher' => 'Subject_Teacher'
];

$dbRole = $roleMap[$role] ?? null;

if (!$dbRole) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid role selected'
    ]);
    exit();
}

// Attempt login
$result = $auth->login($username, $password, $dbRole);

if ($result['success']) {
    http_response_code(200);
    
    // Start session for logged in user (optional)
    session_start();
    $_SESSION['user_id'] = $result['user']['UserID'];
    $_SESSION['username'] = $result['user']['Username'];
    $_SESSION['role'] = $result['user']['Role'];
    
    echo json_encode($result);
} else {
    http_response_code(401);
    echo json_encode($result);
}
?>