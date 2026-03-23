<?php
// =====================================================
// Academic Year API — Full Management
// File: backend/api/academic-year.php
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

define('AY_ALLOWED_ROLES', ['ICT_Coordinator', 'Registrar', 'Admin']);

function resolveUser($db, $userID) {
    if (!$userID) return null;
    $s = $db->prepare("SELECT UserID, Role FROM user WHERE UserID = ? AND IsActive = 1 LIMIT 1");
    $s->execute([$userID]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function writeAudit($db, $recordID, $action, $old, $new, $userID, $desc) {
    try {
        $s = $db->prepare("
            INSERT INTO auditlog
                (TableName,RecordID,Action,OldValue,NewValue,ChangedBy,ActionDescription,IPAddress)
            VALUES ('academicyear',:rid,:act,:old,:new,:uid,:desc,:ip)
        ");
        $s->execute([':rid'=>$recordID,':act'=>$action,':old'=>$old,
                     ':new'=>$new,':uid'=>$userID,':desc'=>$desc,
                     ':ip'=>$_SERVER['REMOTE_ADDR']??'unknown']);
    } catch (Exception $e) { error_log('Audit error: '.$e->getMessage()); }
}

try {
    $db = (new Database())->getConnection();
    if (!$db) throw new Exception('Database connection failed');

    $action = $_GET['action'] ?? 'list';

    // ── LIST ─────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $stmt = $db->prepare("
            SELECT ay.AcademicYearID, ay.YearLabel, ay.StartYear, ay.EndYear,
                   ay.IsActive, ay.IsArchived,
                   COUNT(e.EnrollmentID) AS EnrollmentCount
            FROM   academicyear ay
            LEFT   JOIN enrollment e ON ay.AcademicYearID = e.AcademicYearID
            GROUP  BY ay.AcademicYearID
            ORDER  BY ay.StartYear DESC
        ");
        $stmt->execute();
        echo json_encode(['success'=>true,'academicYears'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ── ACTIVE (enrollment form dropdown) ────────────────────────────────
    if ($action === 'active') {
        $stmt = $db->prepare("SELECT AcademicYearID,YearLabel,StartYear,EndYear
                               FROM academicyear WHERE IsActive=1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $row
            ? json_encode(['success'=>true,'academicYear'=>$row])
            : json_encode(['success'=>false,'message'=>'No active academic year configured.']);
        exit();
    }

    // ── ALL NON-ARCHIVED (for enrollment form dropdown list) ─────────────
    if ($action === 'available') {
        $stmt = $db->prepare("SELECT AcademicYearID,YearLabel,StartYear,EndYear,IsActive
                               FROM academicyear WHERE IsArchived=0 ORDER BY StartYear DESC");
        $stmt->execute();
        echo json_encode(['success'=>true,'academicYears'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ── All mutating actions require POST + userID ────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false,'message'=>'POST required for '.$action]);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $user  = resolveUser($db, $input['userID'] ?? null);

    if (!$user || !in_array($user['Role'], AY_ALLOWED_ROLES)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Unauthorized']);
        exit();
    }

    // ── CREATE ───────────────────────────────────────────────────────────
    if ($action === 'create') {
        $startYear = (int)($input['startYear'] ?? 0);
        if ($startYear < 2000 || $startYear > 2100) {
            echo json_encode(['success'=>false,'message'=>'Invalid start year.']); exit();
        }
        $endYear = $startYear + 1;
        $label   = "{$startYear}-{$endYear}";

        $chk = $db->prepare("SELECT AcademicYearID FROM academicyear WHERE YearLabel=:lbl LIMIT 1");
        $chk->execute([':lbl'=>$label]);
        if ($chk->fetch()) {
            echo json_encode(['success'=>false,'message'=>"Academic year {$label} already exists."]); exit();
        }

        $ins = $db->prepare("INSERT INTO academicyear (YearLabel,StartYear,EndYear,IsActive,IsArchived)
                              VALUES (:lbl,:sy,:ey,0,0)");
        $ins->execute([':lbl'=>$label,':sy'=>$startYear,':ey'=>$endYear]);
        $newID = (int)$db->lastInsertId();

        writeAudit($db,$newID,'INSERT',null,$label,$user['UserID'],"Created academic year {$label}");
        echo json_encode(['success'=>true,'message'=>"Academic year {$label} created.",'academicYearID'=>$newID]);
        exit();
    }

    // ── SET ACTIVE ───────────────────────────────────────────────────────
    if ($action === 'set-active') {
        $ayID = (int)($input['academicYearID'] ?? 0);
        if (!$ayID) { echo json_encode(['success'=>false,'message'=>'academicYearID required']); exit(); }

        $chk = $db->prepare("SELECT YearLabel,IsArchived FROM academicyear WHERE AcademicYearID=:id LIMIT 1");
        $chk->execute([':id'=>$ayID]);
        $target = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'Academic year not found.']); exit(); }
        if ($target['IsArchived']) {
            echo json_encode(['success'=>false,'message'=>'Cannot activate an archived academic year.']); exit();
        }

        // Record old active for audit
        $old = $db->query("SELECT YearLabel FROM academicyear WHERE IsActive=1 LIMIT 1")->fetchColumn();

        $db->exec("UPDATE academicyear SET IsActive=0");
        $db->prepare("UPDATE academicyear SET IsActive=1 WHERE AcademicYearID=:id")->execute([':id'=>$ayID]);

        writeAudit($db,$ayID,'STATUS_CHANGE',$old ?: null,$target['YearLabel'],
                   $user['UserID'],"Active year changed to {$target['YearLabel']}");
        echo json_encode(['success'=>true,'message'=>"Academic year {$target['YearLabel']} is now active."]);
        exit();
    }

    // ── ARCHIVE ──────────────────────────────────────────────────────────
    if ($action === 'archive') {
        $ayID = (int)($input['academicYearID'] ?? 0);
        $chk  = $db->prepare("SELECT YearLabel,IsActive FROM academicyear WHERE AcademicYearID=:id LIMIT 1");
        $chk->execute([':id'=>$ayID]);
        $target = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'Academic year not found.']); exit(); }
        if ($target['IsActive']) {
            echo json_encode(['success'=>false,'message'=>'Cannot archive the currently active year. Set another year as active first.']); exit();
        }
        $db->prepare("UPDATE academicyear SET IsArchived=1 WHERE AcademicYearID=:id")->execute([':id'=>$ayID]);
        writeAudit($db,$ayID,'STATUS_CHANGE','Inactive','Archived',$user['UserID'],"Archived {$target['YearLabel']}");
        echo json_encode(['success'=>true,'message'=>"Academic year {$target['YearLabel']} has been archived."]);
        exit();
    }

    // ── UNARCHIVE ────────────────────────────────────────────────────────
    if ($action === 'unarchive') {
        $ayID = (int)($input['academicYearID'] ?? 0);
        $chk  = $db->prepare("SELECT YearLabel FROM academicyear WHERE AcademicYearID=:id LIMIT 1");
        $chk->execute([':id'=>$ayID]);
        $target = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'Academic year not found.']); exit(); }
        $db->prepare("UPDATE academicyear SET IsArchived=0 WHERE AcademicYearID=:id")->execute([':id'=>$ayID]);
        writeAudit($db,$ayID,'STATUS_CHANGE','Archived','Inactive',$user['UserID'],"Unarchived {$target['YearLabel']}");
        echo json_encode(['success'=>true,'message'=>"Academic year {$target['YearLabel']} restored."]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Unknown action: '.$action]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('academic-year.php: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
}
?>