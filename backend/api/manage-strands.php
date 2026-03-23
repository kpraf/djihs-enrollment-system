<?php
// backend/api/manage-strands.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'list':
            listStrands($db);
            break;
        case 'create':
            if ($method !== 'POST') { methodNotAllowed(); break; }
            createStrand($db);
            break;
        case 'update':
            if ($method !== 'POST') { methodNotAllowed(); break; }
            updateStrand($db);
            break;
        case 'toggle-status':
            if ($method !== 'POST') { methodNotAllowed(); break; }
            toggleStrandStatus($db);
            break;
        case 'delete':
            if ($method !== 'DELETE') { methodNotAllowed(); break; }
            deleteStrand($db);
            break;
        case 'check-usage':
            checkStrandUsage($db);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function methodNotAllowed() {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed for this action']);
}

// ──────────────────────────────────────────────────────────────────────────────
// LIST
// ──────────────────────────────────────────────────────────────────────────────
function listStrands($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT
                s.StrandID,
                s.StrandCode,
                s.StrandName,
                s.StrandCategory,
                s.Description,
                s.IsActive,
                COUNT(DISTINCT sec.SectionID)  AS SectionCount,
                COUNT(DISTINCT e.EnrollmentID) AS EnrolledStudents
            FROM strand s
            LEFT JOIN section    sec ON sec.StrandID = s.StrandID
                                    AND sec.IsActive  = 1
            LEFT JOIN enrollment e   ON e.StrandID   = s.StrandID
                                    AND e.Status IN ('Pending', 'Confirmed')
            GROUP BY
                s.StrandID, s.StrandCode, s.StrandName,
                s.StrandCategory, s.Description, s.IsActive
            ORDER BY s.StrandCategory, s.StrandCode
        ");
        $stmt->execute();

        echo json_encode([
            'success' => true,
            'strands' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// CREATE
// ──────────────────────────────────────────────────────────────────────────────
function createStrand($conn) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['strandCode']) || empty($data['strandName']) || empty($data['category'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        // FIX: changedBy comes from payload (localStorage auth, no sessions)
        $changedBy = validateChangedBy($conn, $data['userId'] ?? null);
        if ($changedBy === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid or inactive user']);
            return;
        }

        $code = strtoupper(trim($data['strandCode']));

        // Duplicate code check
        $chk = $conn->prepare("SELECT StrandID FROM strand WHERE StrandCode = :code");
        $chk->execute([':code' => $code]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Strand code already exists']);
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO strand (StrandCode, StrandName, StrandCategory, Description, IsActive)
            VALUES (:code, :name, :category, :description, 1)
        ");
        $stmt->execute([
            ':code'        => $code,
            ':name'        => trim($data['strandName']),
            ':category'    => $data['category'],
            ':description' => trim($data['description'] ?? ''),
        ]);
        $lastId = (int)$conn->lastInsertId();

        logAudit($conn, 'strand', $lastId, 'INSERT', null,
            json_encode(['StrandCode' => $code, 'StrandName' => $data['strandName']]),
            $changedBy, 'Strand created: ' . $code);

        echo json_encode([
            'success'  => true,
            'message'  => 'Strand created successfully',
            'strandId' => $lastId
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// UPDATE
// ──────────────────────────────────────────────────────────────────────────────
function updateStrand($conn) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['strandId'])) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }

        // FIX: changedBy from payload
        $changedBy = validateChangedBy($conn, $data['userId'] ?? null);
        if ($changedBy === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid or inactive user']);
            return;
        }

        // Fetch current row for audit
        $old = $conn->prepare("SELECT * FROM strand WHERE StrandID = :id");
        $old->execute([':id' => (int)$data['strandId']]);
        $oldData = $old->fetch(PDO::FETCH_ASSOC);
        if (!$oldData) {
            echo json_encode(['success' => false, 'message' => 'Strand not found']);
            return;
        }

        $newCode = strtoupper(trim($data['strandCode']));

        // Duplicate code check (excluding self)
        if ($newCode !== $oldData['StrandCode']) {
            $chk = $conn->prepare("
                SELECT StrandID FROM strand
                WHERE StrandCode = :code AND StrandID != :id
            ");
            $chk->execute([':code' => $newCode, ':id' => (int)$data['strandId']]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Strand code already exists']);
                return;
            }
        }

        $stmt = $conn->prepare("
            UPDATE strand
            SET StrandCode     = :code,
                StrandName     = :name,
                StrandCategory = :category,
                Description    = :description
            WHERE StrandID = :id
        ");
        $stmt->execute([
            ':code'        => $newCode,
            ':name'        => trim($data['strandName']),
            ':category'    => $data['category'],
            ':description' => trim($data['description'] ?? ''),
            ':id'          => (int)$data['strandId'],
        ]);

        logAudit($conn, 'strand', (int)$data['strandId'], 'UPDATE',
            json_encode($oldData), json_encode($data),
            $changedBy, 'Strand updated: ' . $newCode);

        echo json_encode(['success' => true, 'message' => 'Strand updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// TOGGLE STATUS  (POST — id and userId in JSON body)
// ──────────────────────────────────────────────────────────────────────────────
function toggleStrandStatus($conn) {
    try {
        $data      = json_decode(file_get_contents('php://input'), true);
        $strandId  = $data['strandId'] ?? ($_GET['id'] ?? null);

        if (!$strandId) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }

        // FIX: changedBy from body
        $changedBy = validateChangedBy($conn, $data['userId'] ?? null);
        if ($changedBy === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid or inactive user']);
            return;
        }

        $stmt = $conn->prepare("SELECT IsActive, StrandCode FROM strand WHERE StrandID = :id");
        $stmt->execute([':id' => (int)$strandId]);
        $strand = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$strand) {
            echo json_encode(['success' => false, 'message' => 'Strand not found']);
            return;
        }

        $newStatus = $strand['IsActive'] ? 0 : 1;

        $conn->prepare("UPDATE strand SET IsActive = :status WHERE StrandID = :id")
             ->execute([':status' => $newStatus, ':id' => (int)$strandId]);

        logAudit($conn, 'strand', (int)$strandId, 'TOGGLE_STATUS',
            (string)$strand['IsActive'], (string)$newStatus,
            $changedBy,
            'Strand ' . ($newStatus ? 'activated' : 'deactivated') . ': ' . $strand['StrandCode']);

        echo json_encode([
            'success'   => true,
            'message'   => 'Strand status updated successfully',
            'newStatus' => $newStatus
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// DELETE  (DELETE method — id via query string; userId in JSON body)
// ──────────────────────────────────────────────────────────────────────────────
function deleteStrand($conn) {
    try {
        $strandId = $_GET['id'] ?? null;
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!$strandId) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }

        // FIX: changedBy from body
        $changedBy = validateChangedBy($conn, $data['userId'] ?? null);
        if ($changedBy === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid or inactive user']);
            return;
        }

        // Check if in use
        $chk = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM section    WHERE StrandID = :id) AS section_count,
                (SELECT COUNT(*) FROM enrollment WHERE StrandID = :id) AS enrollment_count
        ");
        $chk->execute([':id' => (int)$strandId]);
        $usage = $chk->fetch(PDO::FETCH_ASSOC);

        if ($usage['section_count'] > 0 || $usage['enrollment_count'] > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete strand — it is used by sections or enrollments. Consider deactivating it instead.'
            ]);
            return;
        }

        // Fetch for audit before deleting
        $info = $conn->prepare("SELECT StrandCode FROM strand WHERE StrandID = :id");
        $info->execute([':id' => (int)$strandId]);
        $strand = $info->fetch(PDO::FETCH_ASSOC);

        $conn->prepare("DELETE FROM strand WHERE StrandID = :id")
             ->execute([':id' => (int)$strandId]);

        logAudit($conn, 'strand', (int)$strandId, 'DELETE',
            json_encode($strand), null,
            $changedBy,
            'Strand deleted: ' . ($strand['StrandCode'] ?? 'Unknown'));

        echo json_encode(['success' => true, 'message' => 'Strand deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// CHECK USAGE
// ──────────────────────────────────────────────────────────────────────────────
function checkStrandUsage($conn) {
    try {
        $strandId = $_GET['id'] ?? null;
        if (!$strandId) {
            echo json_encode(['success' => false, 'message' => 'Strand ID is required']);
            return;
        }

        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM section    WHERE StrandID = :id AND IsActive = 1)           AS active_sections,
                (SELECT COUNT(*) FROM enrollment WHERE StrandID = :id
                                                 AND Status IN ('Pending','Confirmed'))           AS active_enrollments
        ");
        $stmt->execute([':id' => (int)$strandId]);

        echo json_encode(['success' => true, 'usage' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// HELPERS
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Validate that userId is a real, active user.
 * Returns the int UserID on success, or false on failure.
 * FIX: replaces $_SESSION['user_id'] which is never set (localStorage-based auth).
 */
function validateChangedBy($conn, $userId) {
    if (!$userId) return false;
    $stmt = $conn->prepare("SELECT UserID FROM user WHERE UserID = :id AND IsActive = 1");
    $stmt->execute([':id' => (int)$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['UserID'] : false;
}

/**
 * Write to auditlog.
 * Action enum must be one of the values defined in the schema.
 */
function logAudit($conn, $table, $recordId, $action, $oldValue, $newValue, $userId, $description) {
    try {
        $conn->prepare("
            INSERT INTO auditlog
                (TableName, RecordID, Action, OldValue, NewValue,
                 ChangedBy, ActionDescription, IPAddress)
            VALUES
                (:table, :record, :action, :old, :new, :user, :desc, :ip)
        ")->execute([
            ':table'  => $table,
            ':record' => $recordId,
            ':action' => $action,
            ':old'    => $oldValue,
            ':new'    => $newValue,
            ':user'   => $userId,
            ':desc'   => $description,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (PDOException $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}
?>