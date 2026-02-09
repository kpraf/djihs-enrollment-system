<?php
// backend/api/auditlog.php
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
        case 'all':
            getAllLogs($conn);
            break;
        case 'recent':
            getRecentLogs($conn);
            break;
        case 'by-table':
            getLogsByTable($conn);
            break;
        case 'by-user':
            getLogsByUser($conn);
            break;
        case 'stats':
            getAuditStats($conn);
            break;
        default:
            getAllLogs($conn);
    }
}

function getAllLogs($conn) {
    // Get filter parameters
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $table = isset($_GET['table']) ? $_GET['table'] : '';
    $action = isset($_GET['action_type']) ? $_GET['action_type'] : '';
    $userId = isset($_GET['user_id']) ? $_GET['user_id'] : '';
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
    $userRole = isset($_GET['user_role']) ? $_GET['user_role'] : '';
    
    $sql = "SELECT 
                a.LogID,
                a.TableName,
                a.RecordID,
                a.Action,
                a.ActionDescription,
                a.OldValue,
                a.NewValue,
                a.ChangedBy,
                a.UserRole,
                a.AffectedUserName,
                a.ChangedAt,
                a.IPAddress,
                CONCAT(u.FirstName, ' ', u.LastName) as ChangedByName,
                u.Role as ChangedByRole
            FROM auditlog a
            LEFT JOIN user u ON a.ChangedBy = u.UserID
            WHERE 1=1";
    
    $params = [];
    
    // Role-based filtering (Registrar can only see student-related tables)
    if ($userRole === 'Registrar') {
        $allowedTables = ['student', 'enrollment', 'section', 'sectionassignment', 
                        'StudentRevisionRequest', 'documentsubmission'];
        $placeholders = [];
        foreach ($allowedTables as $index => $tableName) {
            $paramKey = ':allowedTable' . $index;
            $placeholders[] = $paramKey;
            $params[$paramKey] = $tableName;
        }
        $sql .= " AND a.TableName IN (" . implode(',', $placeholders) . ")";
    }
    
    if ($table) {
        $sql .= " AND a.TableName = :table";
        $params[':table'] = $table;
    }
    
    if ($action) {
        $sql .= " AND a.Action = :action";
        $params[':action'] = $action;
    }
    
    if ($userId) {
        $sql .= " AND a.ChangedBy = :userId";
        $params[':userId'] = $userId;
    }
    
    if ($startDate) {
        $sql .= " AND DATE(a.ChangedAt) >= :startDate";
        $params[':startDate'] = $startDate;
    }
    
    if ($endDate) {
        $sql .= " AND DATE(a.ChangedAt) <= :endDate";
        $params[':endDate'] = $endDate;
    }
    
    // Build count query with same filters
    $countSql = "SELECT COUNT(*) as total FROM auditlog a WHERE 1=1";
    
    // Apply same role-based filtering to count
    if ($userRole === 'Registrar') {
        $allowedTables = ['student', 'enrollment', 'section', 'sectionassignment', 
                        'StudentRevisionRequest', 'documentsubmission'];
        $placeholders = [];
        foreach ($allowedTables as $index => $tableName) {
            $placeholders[] = ':allowedTable' . $index;
        }
        $countSql .= " AND a.TableName IN (" . implode(',', $placeholders) . ")";
    }
    
    if ($table) {
        $countSql .= " AND a.TableName = :table";
    }
    
    if ($action) {
        $countSql .= " AND a.Action = :action";
    }
    
    if ($userId) {
        $countSql .= " AND a.ChangedBy = :userId";
    }
    
    if ($startDate) {
        $countSql .= " AND DATE(a.ChangedAt) >= :startDate";
    }
    
    if ($endDate) {
        $countSql .= " AND DATE(a.ChangedAt) <= :endDate";
    }
    
    // Execute main query
    $sql .= " ORDER BY a.ChangedAt DESC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Execute count query
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'count' => count($logs),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function getRecentLogs($conn) {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $userRole = isset($_GET['user_role']) ? $_GET['user_role'] : '';
    
    $sql = "SELECT 
                a.LogID,
                a.TableName,
                a.RecordID,
                a.Action,
                a.ActionDescription,
                a.ChangedAt,
                a.AffectedUserName,
                CONCAT(u.FirstName, ' ', u.LastName) as ChangedByName,
                u.Role as ChangedByRole
            FROM auditlog a
            LEFT JOIN user u ON a.ChangedBy = u.UserID
            WHERE 1=1";
    
    $params = [];
    
    // Role-based filtering for Registrar
    if ($userRole === 'Registrar') {
        $allowedTables = ['student', 'enrollment', 'section', 'sectionassignment', 
                        'StudentRevisionRequest', 'documentsubmission'];
        $placeholders = [];
        foreach ($allowedTables as $index => $tableName) {
            $paramKey = ':allowedTable' . $index;
            $placeholders[] = $paramKey;
            $params[$paramKey] = $tableName;
        }
        $sql .= " AND a.TableName IN (" . implode(',', $placeholders) . ")";
    }
    
    $sql .= " ORDER BY a.ChangedAt DESC LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'count' => count($logs)
    ]);
}

function getLogsByTable($conn) {
    $table = isset($_GET['table_name']) ? $_GET['table_name'] : '';
    $userRole = isset($_GET['user_role']) ? $_GET['user_role'] : '';
    
    if (!$table) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Table name is required']);
        return;
    }
    
    // Check if Registrar has access to this table
    if ($userRole === 'Registrar') {
        $allowedTables = ['student', 'enrollment', 'section', 'sectionassignment', 
                        'StudentRevisionRequest', 'documentsubmission'];
        if (!in_array($table, $allowedTables)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied to this table']);
            return;
        }
    }
    
    $sql = "SELECT 
                a.*,
                CONCAT(u.FirstName, ' ', u.LastName) as ChangedByName
            FROM auditlog a
            LEFT JOIN user u ON a.ChangedBy = u.UserID
            WHERE a.TableName = :table
            ORDER BY a.ChangedAt DESC
            LIMIT 100";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':table', $table);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'count' => count($logs)
    ]);
}

function getLogsByUser($conn) {
    $userId = isset($_GET['user_id']) ? $_GET['user_id'] : '';
    $userRole = isset($_GET['user_role']) ? $_GET['user_role'] : '';
    
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }
    
    $sql = "SELECT 
                a.*,
                CONCAT(u.FirstName, ' ', u.LastName) as ChangedByName
            FROM auditlog a
            LEFT JOIN user u ON a.ChangedBy = u.UserID
            WHERE a.ChangedBy = :userId";
    
    $params = [];
    $params[':userId'] = $userId;
    
    // Role-based filtering for Registrar
    if ($userRole === 'Registrar') {
        $allowedTables = ['student', 'enrollment', 'section', 'sectionassignment', 
                        'StudentRevisionRequest', 'documentsubmission'];
        $placeholders = [];
        foreach ($allowedTables as $index => $tableName) {
            $paramKey = ':allowedTable' . $index;
            $placeholders[] = $paramKey;
            $params[$paramKey] = $tableName;
        }
        $sql .= " AND a.TableName IN (" . implode(',', $placeholders) . ")";
    }
    
    $sql .= " ORDER BY a.ChangedAt DESC LIMIT 100";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'count' => count($logs)
    ]);
}

function getAuditStats($conn) {
    $userRole = isset($_GET['user_role']) ? $_GET['user_role'] : '';
    
    $whereClause = "WHERE 1=1";
    $params = [];
    
    // Role-based filtering for Registrar
    if ($userRole === 'Registrar') {
        $allowedTables = ['student', 'enrollment', 'section', 'sectionassignment', 
                        'StudentRevisionRequest', 'documentsubmission'];
        $placeholders = [];
        foreach ($allowedTables as $index => $tableName) {
            $paramKey = ':allowedTable' . $index;
            $placeholders[] = $paramKey;
            $params[$paramKey] = $tableName;
        }
        $whereClause .= " AND TableName IN (" . implode(',', $placeholders) . ")";
    }
    
    $sql = "SELECT 
                COUNT(*) as total_logs,
                COUNT(DISTINCT TableName) as tables_tracked,
                COUNT(DISTINCT ChangedBy) as active_users,
                SUM(CASE WHEN Action = 'INSERT' THEN 1 ELSE 0 END) as total_inserts,
                SUM(CASE WHEN Action = 'UPDATE' THEN 1 ELSE 0 END) as total_updates,
                SUM(CASE WHEN Action = 'DELETE' THEN 1 ELSE 0 END) as total_deletes,
                SUM(CASE WHEN Action = 'STATUS_CHANGE' THEN 1 ELSE 0 END) as total_status_changes,
                SUM(CASE WHEN Action = 'PASSWORD_RESET' THEN 1 ELSE 0 END) as total_password_resets,
                SUM(CASE WHEN Action = 'REVISION_REQUEST' THEN 1 ELSE 0 END) as total_revision_requests,
                SUM(CASE WHEN Action = 'REVISION_APPROVED' THEN 1 ELSE 0 END) as total_revision_approved,
                SUM(CASE WHEN DATE(ChangedAt) = CURDATE() THEN 1 ELSE 0 END) as today_logs,
                SUM(CASE WHEN DATE(ChangedAt) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as week_logs,
                SUM(CASE WHEN DATE(ChangedAt) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as month_logs
            FROM auditlog
            $whereClause";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get activity by table
    $tableSql = "SELECT 
                    TableName,
                    COUNT(*) as activity_count,
                    MAX(ChangedAt) as last_activity
                 FROM auditlog
                 $whereClause
                 GROUP BY TableName
                 ORDER BY activity_count DESC";
    
    $tableStmt = $conn->prepare($tableSql);
    foreach ($params as $key => $value) {
        $tableStmt->bindValue($key, $value);
    }
    $tableStmt->execute();
    $tableActivity = $tableStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'overall' => $stats,
            'by_table' => $tableActivity
        ]
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
        case 'log':
            createAuditLog($conn, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function createAuditLog($conn, $data) {
    $required = ['TableName', 'RecordID', 'Action', 'ChangedBy'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            return;
        }
    }
    
    $sql = "INSERT INTO auditlog (
                TableName, RecordID, Action, ActionDescription, 
                OldValue, NewValue, ChangedBy, UserRole, 
                AffectedUserName, IPAddress
            ) VALUES (
                :TableName, :RecordID, :Action, :ActionDescription,
                :OldValue, :NewValue, :ChangedBy, :UserRole,
                :AffectedUserName, :IPAddress
            )";
    
    try {
        $stmt = $conn->prepare($sql);
        
        $stmt->bindParam(':TableName', $data['TableName']);
        $stmt->bindParam(':RecordID', $data['RecordID']);
        $stmt->bindParam(':Action', $data['Action']);
        
        $actionDesc = isset($data['ActionDescription']) ? $data['ActionDescription'] : null;
        $oldValue = isset($data['OldValue']) ? json_encode($data['OldValue']) : null;
        $newValue = isset($data['NewValue']) ? json_encode($data['NewValue']) : null;
        $userRole = isset($data['UserRole']) ? $data['UserRole'] : null;
        $affectedUserName = isset($data['AffectedUserName']) ? $data['AffectedUserName'] : null;
        $ipAddress = isset($data['IPAddress']) ? $data['IPAddress'] : $_SERVER['REMOTE_ADDR'];
        
        $stmt->bindParam(':ActionDescription', $actionDesc);
        $stmt->bindParam(':OldValue', $oldValue);
        $stmt->bindParam(':NewValue', $newValue);
        $stmt->bindParam(':ChangedBy', $data['ChangedBy']);
        $stmt->bindParam(':UserRole', $userRole);
        $stmt->bindParam(':AffectedUserName', $affectedUserName);
        $stmt->bindParam(':IPAddress', $ipAddress);
        
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Audit log created successfully',
            'logId' => $conn->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create audit log: ' . $e->getMessage()
        ]);
    }
}

// Helper function to format audit log entry
function formatAuditEntry($tableName, $recordId, $action, $description, $oldValue, $newValue, $userId, $userRole, $affectedName) {
    return [
        'TableName' => $tableName,
        'RecordID' => $recordId,
        'Action' => $action,
        'ActionDescription' => $description,
        'OldValue' => $oldValue,
        'NewValue' => $newValue,
        'ChangedBy' => $userId,
        'UserRole' => $userRole,
        'AffectedUserName' => $affectedName,
        'IPAddress' => $_SERVER['REMOTE_ADDR']
    ];
}
?>