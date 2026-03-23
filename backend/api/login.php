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

if ($db === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Validate — role is no longer sent from the frontend
if (empty($data->username) || empty($data->password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide your username and password']);
    exit();
}

$username = htmlspecialchars(strip_tags($data->username));
$password = $data->password; // Raw password — verified against bcrypt hash

// Attempt login — role is looked up from the user table, not provided by the client
$result = $auth->login($username, $password);

if ($result['success']) {
    http_response_code(200);

    session_start();
    $_SESSION['user_id']  = $result['user']['UserID'];
    $_SESSION['username'] = $result['user']['Username'];
    $_SESSION['role']     = $result['user']['Role'];

    echo json_encode($result);
} else {
    http_response_code(401);
    echo json_encode($result);
}
?>