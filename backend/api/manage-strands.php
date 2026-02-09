<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            listStrands($db);
            break;
            
        case 'create':
            createStrand($db);
            break;
            
        case 'update':
            updateStrand($db);
            break;
            
        case 'toggle-status':
            toggleStrandStatus($db);
            break;
            
        case 'delete':
            deleteStrand($db);
            break;
            
        case 'check-usage':
            checkStrandUsage($db);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function listStrands($conn) {
    try {
        $query = "SELECT 
                    s.StrandID,
                    s.StrandCode,
                    s.StrandName,
                    s.StrandCategory,
                    s.Description,
                    s.IsActive,
                    COUNT(DISTINCT sec.SectionID) as SectionCount,
                    COUNT(DISTINCT e.EnrollmentID) as EnrolledStudents
                  FROM strand s
                  LEFT JOIN section sec ON s.StrandID = sec.StrandID AND sec.IsActive = 1
                  LEFT JOIN enrollment e ON s.StrandID = e.StrandID 
                      AND e.Status IN ('Pending', 'Confirmed')
                  GROUP BY s.StrandID, s.StrandCode, s.StrandName, s.StrandCategory, s.Description, s.IsActive
                  ORDER BY s.StrandCategory, s.StrandCode";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $strands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'strands' => $strands
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function createStrand($conn) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        // Validate required fields
        if (empty($data['strandCode']) || empty($data['strandName']) || empty($data['category'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }
        
        // Check if strand code already exists
        $checkQuery = "SELECT StrandID FROM strand WHERE StrandCode = :code";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->execute([':code' => strtoupper(trim($data['strandCode']))]);
        
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Strand code already exists']);
            return;
        }
        
        // Insert new strand
        $query = "INSERT INTO strand (StrandCode, StrandName, StrandCategory, Description, IsActive) 
                  VALUES (:code, :name, :category, :description, 1)";
        
        $stmt = $conn->prepare($query);
        $result = $stmt->execute([
            ':code' => strtoupper(trim($data['strandCode'])),
            ':name' => trim($data['strandName']),
            ':category' => $data['category'],
            ':description' => trim($data['description'] ?? '')
        ]);
        
        $lastId = $conn->lastInsertId();
        
        // Log the action (optional - may fail if auditlog table doesn't exist)
        try {
            logAudit($conn, 'strand', $lastId, 'INSERT', null, json_encode($data), 
                     $_SESSION['user_id'] ?? 0, 'Strand created: ' . $data['strandCode']);
        } catch (Exception $e) {
            // Continue even if audit log fails
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Strand created successfully',
            'strandId' => $lastId
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateStrand($conn) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['strandId'])) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }
        
        // Get old values for audit
        $oldQuery = "SELECT * FROM strand WHERE StrandID = :id";
        $oldStmt = $conn->prepare($oldQuery);
        $oldStmt->execute([':id' => $data['strandId']]);
        $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldData) {
            echo json_encode(['success' => false, 'message' => 'Strand not found']);
            return;
        }
        
        // Check if strand code is being changed and if it already exists
        if (strtoupper(trim($data['strandCode'])) !== $oldData['StrandCode']) {
            $checkQuery = "SELECT StrandID FROM strand WHERE StrandCode = :code AND StrandID != :id";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->execute([
                ':code' => strtoupper(trim($data['strandCode'])),
                ':id' => $data['strandId']
            ]);
            
            if ($checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Strand code already exists']);
                return;
            }
        }
        
        // Update strand
        $query = "UPDATE strand 
                  SET StrandCode = :code,
                      StrandName = :name,
                      StrandCategory = :category,
                      Description = :description
                  WHERE StrandID = :id";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':code' => strtoupper(trim($data['strandCode'])),
            ':name' => trim($data['strandName']),
            ':category' => $data['category'],
            ':description' => trim($data['description'] ?? ''),
            ':id' => $data['strandId']
        ]);
        
        // Log the action (optional - may fail if auditlog table doesn't exist)
        try {
            logAudit($conn, 'strand', $data['strandId'], 'UPDATE', 
                     json_encode($oldData), json_encode($data), $_SESSION['user_id'] ?? 0,
                     'Strand updated: ' . $data['strandCode']);
        } catch (Exception $e) {
            // Continue even if audit log fails
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Strand updated successfully'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function toggleStrandStatus($conn) {
    try {
        $strandId = $_GET['id'] ?? null;
        
        if (!$strandId) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }
        
        // Get current status
        $query = "SELECT IsActive, StrandCode FROM strand WHERE StrandID = :id";
        $stmt = $conn->prepare($query);
        $stmt->execute([':id' => $strandId]);
        $strand = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$strand) {
            echo json_encode(['success' => false, 'message' => 'Strand not found']);
            return;
        }
        
        $newStatus = $strand['IsActive'] ? 0 : 1;
        
        // Update status
        $updateQuery = "UPDATE strand SET IsActive = :status WHERE StrandID = :id";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([
            ':status' => $newStatus,
            ':id' => $strandId
        ]);
        
        // Log the action (optional - may fail if auditlog table doesn't exist)
        try {
            logAudit($conn, 'strand', $strandId, 'TOGGLE_STATUS', 
                     $strand['IsActive'], $newStatus, $_SESSION['user_id'] ?? 0,
                     'Strand ' . ($newStatus ? 'activated' : 'deactivated') . ': ' . $strand['StrandCode']);
        } catch (Exception $e) {
            // Continue even if audit log fails
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Strand status updated successfully',
            'newStatus' => $newStatus
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteStrand($conn) {
    try {
        $strandId = $_GET['id'] ?? null;
        
        if (!$strandId) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }
        
        // Check if strand is being used
        $checkQuery = "SELECT 
                        (SELECT COUNT(*) FROM section WHERE StrandID = :id) as section_count,
                        (SELECT COUNT(*) FROM enrollment WHERE StrandID = :id) as enrollment_count";
        
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->execute([':id' => $strandId]);
        $usage = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usage['section_count'] > 0 || $usage['enrollment_count'] > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete strand. It is being used by sections or enrollments. Consider deactivating it instead.'
            ]);
            return;
        }
        
        // Get strand info for audit
        $strandQuery = "SELECT StrandCode FROM strand WHERE StrandID = :id";
        $strandStmt = $conn->prepare($strandQuery);
        $strandStmt->execute([':id' => $strandId]);
        $strand = $strandStmt->fetch(PDO::FETCH_ASSOC);
        
        // Delete strand
        $deleteQuery = "DELETE FROM strand WHERE StrandID = :id";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->execute([':id' => $strandId]);
        
        // Log the action (optional - may fail if auditlog table doesn't exist)
        try {
            logAudit($conn, 'strand', $strandId, 'DELETE', 
                     json_encode($strand), null, $_SESSION['user_id'] ?? 0,
                     'Strand deleted: ' . ($strand['StrandCode'] ?? 'Unknown'));
        } catch (Exception $e) {
            // Continue even if audit log fails
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Strand deleted successfully'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function checkStrandUsage($conn) {
    try {
        $strandId = $_GET['id'] ?? null;
        
        if (!$strandId) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }
        
        $query = "SELECT 
                    (SELECT COUNT(*) FROM section WHERE StrandID = :id AND IsActive = 1) as active_sections,
                    (SELECT COUNT(*) FROM enrollment WHERE StrandID = :id 
                     AND Status IN ('Pending', 'Confirmed')) as active_enrollments";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([':id' => $strandId]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'usage' => $usage
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function logAudit($conn, $table, $recordId, $action, $oldValue, $newValue, $userId, $description) {
    try {
        $query = "INSERT INTO auditlog 
                  (TableName, RecordID, Action, OldValue, NewValue, ChangedBy, ActionDescription, IPAddress)
                  VALUES (:table, :record, :action, :old, :new, :user, :desc, :ip)";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':table' => $table,
            ':record' => $recordId,
            ':action' => $action,
            ':old' => $oldValue,
            ':new' => $newValue,
            ':user' => $userId,
            ':desc' => $description,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    } catch (PDOException $e) {
        // Log error but don't fail the main operation
        error_log("Audit log error: " . $e->getMessage());
    }
}
?>