<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Config ───────────────────────────────────────────────────────────────────
$dbHost     = 'localhost';
$dbUser     = 'root';       // update to your DB user
$dbPass     = '';           // update to your DB password
$dbName     = 'djihs_enrollment_v2';
$backupDir  = __DIR__ . '/../../backups/';  // adjust path as needed

// ── Ensure backup directory exists ───────────────────────────────────────────
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// ── Generate filename with timestamp ─────────────────────────────────────────
$timestamp  = date('Y-m-d_H-i-s');
$filename   = "backup_{$dbName}_{$timestamp}.sql";
$filepath   = $backupDir . $filename;

// ── Run mysqldump ─────────────────────────────────────────────────────────────
$command = sprintf(
    'mysqldump --host=%s --user=%s %s %s > %s 2>&1',
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    $dbPass ? '--password=' . escapeshellarg($dbPass) : '',
    escapeshellarg($dbName),
    escapeshellarg($filepath)
);

exec($command, $output, $returnCode);

if ($returnCode !== 0 || !file_exists($filepath) || filesize($filepath) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'mysqldump failed. Ensure mysqldump is available on the server.',
        'output'  => implode("\n", $output)
    ]);
    exit();
}

// ── Log the backup in auditlog ────────────────────────────────────────────────
try {
    $database = new Database();
    $conn = $database->getConnection();

    $requestedBy = isset($data['requestedBy']) ? intval($data['requestedBy']) : null;

    $stmt = $conn->prepare("
        INSERT INTO auditlog 
            (TableName, RecordID, Action, ActionDescription, ChangedBy, IPAddress, UserRole)
        VALUES 
            ('system', 0, 'INSERT', :desc, :changedBy, :ip, :role)
    ");

    $stmt->execute([
        ':desc'      => "Database backup created: {$filename}",
        ':changedBy' => $requestedBy,
        ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '::1',
        ':role'      => 'System'
    ]);
} catch (Exception $e) {
    // Non-fatal — backup succeeded even if logging fails
}

echo json_encode([
    'success'   => true,
    'message'   => 'Backup completed successfully.',
    'filename'  => $filename,
    'filesize'  => round(filesize($filepath) / 1024, 2) . ' KB',
    'timestamp' => $timestamp
]);