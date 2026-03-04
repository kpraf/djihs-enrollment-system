<?php
// =====================================================
// Student Update API — djihs_enrollment_v2 schema
// File: backend/api/student-update.php
//
// FIXES vs old version:
//  - updateStudent(): removed Age, ZipCode (not in schema), removed all flat
//    Father/Mother/Guardian columns (not in student table) → upsertGuardians() instead,
//    removed UpdatedBy/UpdatedAt (not in schema), removed enrollment.AcademicYear (string),
//    removed StatusChangedDate/StatusChangedBy (not in schema)
//  - upsertGuardians(): new — writes Father/Mother/Guardian rows to parentguardian table
//  - sectionassignment: no StudentID column in schema → deactivate/insert via EnrollmentID
//  - getSections(): removed CurrentEnrollment (not in schema)
//  - executeStatusChange(): removed all phantom enrollment/student columns
//  - addRemarks(): simplified to use only enrollment.Remarks (exists in schema)
//  - getGradeLevels(): unchanged ✓
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../config/database.php';

class StudentUpdateAPI {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ──────────────────────────────────────────────
    // GET GRADE LEVELS — unchanged ✓
    // ──────────────────────────────────────────────
    public function getGradeLevels() {
        try {
            $stmt = $this->conn->prepare("
                SELECT GradeLevelID, GradeLevelName, GradeLevelNumber, Department
                FROM   gradelevel
                WHERE  IsActive = 1
                ORDER  BY GradeLevelNumber
            ");
            $stmt->execute();
            return ['success' => true, 'gradeLevels' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching grade levels: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET SECTIONS
    //
    // FIX: Removed CurrentEnrollment column (not in schema).
    //      section table: SectionID, SectionName, GradeLevelID, StrandID,
    //                     AdviserID, Capacity, AcademicYearID, IsActive
    // ──────────────────────────────────────────────
    public function getSections($gradeLevelId, $strandId = null) {
        try {
            $sql = "
                SELECT sec.SectionID, sec.SectionName, sec.Capacity,
                       COUNT(sa.AssignmentID) AS CurrentEnrollment
                FROM   section sec
                LEFT   JOIN sectionassignment sa
                            ON sa.SectionID = sec.SectionID AND sa.IsActive = 1
                WHERE  sec.GradeLevelID = :gradeLevelId
                  AND  sec.IsActive = 1
            ";
            $params = [':gradeLevelId' => (int)$gradeLevelId];

            if ($strandId) {
                $sql .= " AND sec.StrandID = :strandId";
                $params[':strandId'] = (int)$strandId;
            }

            $sql .= " GROUP BY sec.SectionID, sec.SectionName, sec.Capacity ORDER BY sec.SectionName";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return ['success' => true, 'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC)];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching sections: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // UPDATE STUDENT
    //
    // KEY FIXES:
    //  - student UPDATE: removed Age, ZipCode, all flat parent columns,
    //    UpdatedBy, UpdatedAt (none in schema)
    //  - parentguardian: upserted via upsertGuardians() (separate table)
    //  - enrollment UPDATE: removed AcademicYear string, StatusChangedDate,
    //    StatusChangedBy, UpdatedAt; StrandID updated as nullable FK
    //  - sectionassignment: deactivated and re-inserted via EnrollmentID
    //    (schema has no StudentID column on sectionassignment)
    // ──────────────────────────────────────────────
    public function updateStudent($data) {
        try {
            $this->conn->beginTransaction();

            // ── 1. Update student table (schema-exact columns only) ──
            $this->conn->prepare("
                UPDATE student SET
                    LRN                = :lrn,
                    LastName           = :lastName,
                    FirstName          = :firstName,
                    MiddleName         = :middleName,
                    ExtensionName      = :extensionName,
                    DateOfBirth        = :dateOfBirth,
                    Gender             = :gender,
                    Religion           = :religion,
                    MotherTongue       = :motherTongue,
                    IsIPCommunity      = :isIPCommunity,
                    IPCommunitySpecify = :ipCommunitySpecify,
                    IsPWD              = :isPWD,
                    PWDSpecify         = :pwdSpecify,
                    HouseNumber        = :houseNumber,
                    SitioStreet        = :sitioStreet,
                    Barangay           = :barangay,
                    Municipality       = :municipality,
                    Province           = :province,
                    ContactNumber      = :contactNumber,
                    Is4PsBeneficiary   = :is4Ps,
                    EnrollmentStatus   = :enrollmentStatus
                WHERE StudentID = :studentId
            ")->execute([
                ':lrn'               => $data['LRN']                ?? null,
                ':lastName'          => $data['LastName']           ?? '',
                ':firstName'         => $data['FirstName']          ?? '',
                ':middleName'        => $data['MiddleName']         ?? null,
                ':extensionName'     => $data['ExtensionName']      ?? null,
                ':dateOfBirth'       => $data['DateOfBirth']        ?? null,
                ':gender'            => $data['Gender']             ?? 'Male',
                ':religion'          => $data['Religion']           ?? null,
                ':motherTongue'      => $data['MotherTongue']       ?? 'Tagalog',
                ':isIPCommunity'     => (int)($data['IsIPCommunity']     ?? 0),
                ':ipCommunitySpecify'=> $data['IPCommunitySpecify'] ?? null,
                ':isPWD'             => (int)($data['IsPWD']             ?? 0),
                ':pwdSpecify'        => $data['PWDSpecify']         ?? null,
                ':houseNumber'       => $data['HouseNumber']        ?? null,
                ':sitioStreet'       => $data['SitioStreet']        ?? null,
                ':barangay'          => $data['Barangay']           ?? '',
                ':municipality'      => $data['Municipality']       ?? '',
                ':province'          => $data['Province']           ?? '',
                ':contactNumber'     => $data['ContactNumber']      ?? '',
                ':is4Ps'             => (int)($data['Is4PsBeneficiary'] ?? 0),
                ':enrollmentStatus'  => $data['EnrollmentStatus']   ?? 'Active',
                ':studentId'         => (int)$data['StudentID'],
            ]);

            // ── 2. Upsert parent/guardian rows (parentguardian table) ──
            $this->upsertGuardians((int)$data['StudentID'], $data);

            // ── 3. Update enrollment (latest) — schema-exact columns only ──
            if (!empty($data['GradeLevelID'])) {
                // Map student EnrollmentStatus → enrollment.Status ENUM
                $statusMap = [
                    'Active'         => 'Confirmed',
                    'Cancelled'      => 'Cancelled',
                    'Dropped'        => 'Dropped',
                    'Transferred_Out'=> 'Transferred_Out',
                    'Graduated'      => 'Confirmed',
                ];
                $enrollStatus = $statusMap[$data['EnrollmentStatus'] ?? 'Active'] ?? 'Confirmed';

                $this->conn->prepare("
                    UPDATE enrollment e
                    JOIN (
                        SELECT MAX(EnrollmentID) AS LatestID
                        FROM   enrollment
                        WHERE  StudentID = :sid
                    ) latest ON e.EnrollmentID = latest.LatestID
                    SET e.GradeLevelID = :gradeLevelId,
                        e.StrandID     = :strandId,
                        e.Status       = :enrollStatus
                    WHERE e.StudentID = :sid2
                ")->execute([
                    ':gradeLevelId' => (int)$data['GradeLevelID'],
                    ':strandId'     => !empty($data['StrandID']) ? (int)$data['StrandID'] : null,
                    ':enrollStatus' => $enrollStatus,
                    ':sid'          => (int)$data['StudentID'],
                    ':sid2'         => (int)$data['StudentID'],
                ]);
            }

            // ── 4. Update section assignment ──
            // sectionassignment has no StudentID column — must work via EnrollmentID
            if (!empty($data['SectionID'])) {
                // Get latest EnrollmentID for this student
                $latestEnroll = $this->conn->prepare(
                    "SELECT MAX(EnrollmentID) AS EID FROM enrollment WHERE StudentID = :sid"
                );
                $latestEnroll->execute([':sid' => (int)$data['StudentID']]);
                $enrollmentId = (int)$latestEnroll->fetchColumn();

                if ($enrollmentId) {
                    // Deactivate current assignment for this enrollment
                    $this->conn->prepare("
                        UPDATE sectionassignment
                        SET    IsActive = 0
                        WHERE  EnrollmentID = :eid
                    ")->execute([':eid' => $enrollmentId]);

                    // Insert new assignment
                    $this->conn->prepare("
                        INSERT INTO sectionassignment
                            (EnrollmentID, SectionID, AssignmentMethod, AssignedBy, IsActive)
                        VALUES
                            (:eid, :sid, 'Manual', :assignedBy, 1)
                    ")->execute([
                        ':eid'        => $enrollmentId,
                        ':sid'        => (int)$data['SectionID'],
                        ':assignedBy' => (int)$data['UpdatedBy'],
                    ]);
                }
            }

            // ── 5. Deactivate sections for inactive enrollment statuses ──
            if (in_array($data['EnrollmentStatus'] ?? '', ['Cancelled', 'Dropped', 'Transferred_Out'])) {
                $this->conn->prepare("
                    UPDATE sectionassignment sa
                    JOIN   enrollment e ON e.EnrollmentID = sa.EnrollmentID
                    SET    sa.IsActive = 0
                    WHERE  e.StudentID = :sid
                ")->execute([':sid' => (int)$data['StudentID']]);
            }

            $this->conn->commit();
            return ['success' => true, 'message' => 'Student updated successfully'];

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("Update student error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating student: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // UPSERT GUARDIANS
    // Writes Father / Mother / Guardian rows to parentguardian table.
    // Called from updateStudent(); also usable standalone.
    // ──────────────────────────────────────────────
    private function upsertGuardians(int $studentId, array $data) {
        // Expected keys in $data:
        //   guardians.Father.LastName / FirstName / MiddleName / ContactNumber
        //   guardians.Mother.*
        //   guardians.Guardian.* (+ GuardianRelationship)
        // OR flat:
        //   fatherLastName / fatherFirstName / fatherMiddleName
        //   motherLastName / motherFirstName / motherMiddleName
        //   guardianLastName / guardianFirstName / guardianMiddleName / guardianRelationship

        $sets = [
            ['Father',   'fatherLastName',   'fatherFirstName',   'fatherMiddleName',   null,                   null           ],
            ['Mother',   'motherLastName',   'motherFirstName',   'motherMiddleName',   null,                   null           ],
            ['Guardian', 'guardianLastName', 'guardianFirstName', 'guardianMiddleName', 'guardianRelationship', 'guardianContact'],
        ];

        // Support both flat keys and nested guardians map
        $g = $data['guardians'] ?? [];

        foreach ($sets as [$relType, $lnKey, $fnKey, $mnKey, $relKey, $ctKey]) {
            // Try nested map first, fall back to flat keys
            $nested = $g[$relType] ?? [];
            $ln = trim($nested['LastName']  ?? $data[$lnKey] ?? '');
            $fn = trim($nested['FirstName'] ?? $data[$fnKey] ?? '');
            $mn = trim($nested['MiddleName'] ?? $data[$mnKey] ?? '') ?: null;
            $gr = $relKey ? trim($nested['GuardianRelationship'] ?? $data[$relKey] ?? '') ?: null : null;
            $ct = $ctKey  ? trim($nested['ContactNumber']        ?? $data[$ctKey]  ?? '') ?: null : null;

            if (!$fn && !$ln) continue; // skip empty rows

            $exist = $this->conn->prepare(
                "SELECT ParentGuardianID FROM parentguardian WHERE StudentID = :sid AND RelationshipType = :rel LIMIT 1"
            );
            $exist->execute([':sid' => $studentId, ':rel' => $relType]);
            $row = $exist->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $this->conn->prepare("
                    UPDATE parentguardian
                    SET LastName = :ln, FirstName = :fn, MiddleName = :mn,
                        GuardianRelationship = :gr, ContactNumber = :ct
                    WHERE ParentGuardianID = :pgid
                ")->execute([
                    ':ln' => $ln, ':fn' => $fn, ':mn' => $mn,
                    ':gr' => $gr, ':ct' => $ct,
                    ':pgid' => $row['ParentGuardianID'],
                ]);
            } else {
                $this->conn->prepare("
                    INSERT INTO parentguardian
                        (StudentID, RelationshipType, LastName, FirstName, MiddleName, GuardianRelationship, ContactNumber)
                    VALUES (:sid, :rel, :ln, :fn, :mn, :gr, :ct)
                ")->execute([
                    ':sid' => $studentId, ':rel' => $relType,
                    ':ln'  => $ln, ':fn' => $fn, ':mn' => $mn,
                    ':gr'  => $gr, ':ct' => $ct,
                ]);
            }
        }
    }

    // ──────────────────────────────────────────────
    // ADD REMARKS
    //
    // FIX: enrollment.Remarks column exists in schema ✓
    //      Removed phantom StatusChangedDate, UpdatedAt columns.
    //      Appends timestamped note to existing Remarks text.
    // ──────────────────────────────────────────────
    public function addRemarks($studentId, $remarks, $userId) {
        try {
            // Get user name for the note prefix
            $userStmt = $this->conn->prepare(
                "SELECT CONCAT(FirstName, ' ', LastName) AS Name FROM user WHERE UserID = :uid"
            );
            $userStmt->execute([':uid' => (int)$userId]);
            $userName = $userStmt->fetchColumn() ?: "User #{$userId}";

            $prefix = '[' . date('Y-m-d H:i') . " – {$userName}]: ";

            $this->conn->prepare("
                UPDATE enrollment e
                JOIN (
                    SELECT MAX(EnrollmentID) AS LatestID
                    FROM   enrollment
                    WHERE  StudentID = :sid
                ) latest ON e.EnrollmentID = latest.LatestID
                SET e.Remarks = CONCAT(
                    IFNULL(NULLIF(TRIM(e.Remarks), ''), ''),
                    IF(e.Remarks IS NULL OR TRIM(e.Remarks) = '', '', '\n'),
                    :prefix, :remarks
                )
                WHERE e.StudentID = :sid2
            ")->execute([
                ':sid'     => (int)$studentId,
                ':sid2'    => (int)$studentId,
                ':prefix'  => $prefix,
                ':remarks' => trim($remarks),
            ]);

            return ['success' => true, 'message' => 'Remarks added successfully'];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error adding remarks: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // CHANGE STATUS (with approval workflow)
    //
    // Dropped / Transferred_Out → approval required unless Registrar/ICT
    // Cancelled → direct (no approval needed)
    // ──────────────────────────────────────────────
    public function changeStatus($studentId, $newStatus, $reason, $userId, $userRole, $additionalInfo = null) {
        $requireApproval = ['Dropped', 'Transferred_Out'];
        $direct          = ['Cancelled'];
        $valid           = array_merge($requireApproval, $direct);

        if (!in_array($newStatus, $valid)) {
            return ['success' => false, 'message' => 'Invalid status for change'];
        }

        if (in_array($newStatus, $requireApproval) && !in_array($userRole, ['Registrar', 'ICT_Coordinator'])) {
            return $this->requestStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo);
        }

        return $this->executeStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo);
    }

    // ──────────────────────────────────────────────
    // EXECUTE STATUS CHANGE
    //
    // KEY FIXES:
    //  - enrollment UPDATE: removed StatusChangedDate, StatusChangedBy, UpdatedAt
    //  - student UPDATE: removed UpdatedBy, UpdatedAt (not in schema)
    //  - sectionassignment: deactivate via EnrollmentID (not StudentID)
    // ──────────────────────────────────────────────
    private function executeStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo = null) {
        try {
            $this->conn->beginTransaction();

            // Map to enrollment.Status ENUM value
            $enrollStatusMap = [
                'Cancelled'       => 'Cancelled',
                'Dropped'         => 'Dropped',
                'Transferred_Out' => 'Transferred_Out',
            ];
            $enrollStatus = $enrollStatusMap[$newStatus] ?? 'Cancelled';

            // Build remark
            $remark = trim($reason);
            if ($newStatus === 'Transferred_Out' && $additionalInfo) {
                $remark = "Transfer to: {$additionalInfo}. {$remark}";
            }

            // Get latest EnrollmentID
            $eidStmt = $this->conn->prepare(
                "SELECT MAX(EnrollmentID) FROM enrollment WHERE StudentID = :sid"
            );
            $eidStmt->execute([':sid' => (int)$studentId]);
            $enrollmentId = (int)$eidStmt->fetchColumn();

            if (!$enrollmentId) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'No enrollment found for student'];
            }

            // Update enrollment (only schema columns: Status, Remarks)
            $this->conn->prepare("
                UPDATE enrollment
                SET    Status  = :status,
                       Remarks = CONCAT(IFNULL(NULLIF(TRIM(Remarks),''),''),
                                        IF(Remarks IS NULL OR TRIM(Remarks)='','','\n'),
                                        :remark)
                WHERE  EnrollmentID = :eid
            ")->execute([
                ':status' => $enrollStatus,
                ':remark' => '[Status change] ' . $remark,
                ':eid'    => $enrollmentId,
            ]);

            // Update student.EnrollmentStatus (exact ENUM: Active|Cancelled|Transferred_Out|Graduated|Dropped)
            $this->conn->prepare("
                UPDATE student
                SET    EnrollmentStatus = :status
                WHERE  StudentID = :sid
            ")->execute([
                ':status' => $newStatus,
                ':sid'    => (int)$studentId,
            ]);

            // Deactivate all active section assignments for this enrollment
            $this->conn->prepare("
                UPDATE sectionassignment
                SET    IsActive = 0
                WHERE  EnrollmentID = :eid
            ")->execute([':eid' => $enrollmentId]);

            // Audit log
            $this->logAudit(
                'enrollment', $enrollmentId, 'STATUS_CHANGE',
                null, $enrollStatus, $userId,
                "Status changed to {$newStatus}: {$remark}"
            );

            $this->conn->commit();
            return ['success' => true, 'message' => 'Status updated successfully'];

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Error changing status: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // REQUEST STATUS CHANGE (creates revision request)
    // ──────────────────────────────────────────────
    private function requestStatusChange($studentId, $newStatus, $reason, $requestedBy, $additionalInfo = null) {
        try {
            $this->conn->beginTransaction();

            $eidStmt = $this->conn->prepare(
                "SELECT MAX(EnrollmentID) FROM enrollment WHERE StudentID = :sid"
            );
            $eidStmt->execute([':sid' => (int)$studentId]);
            $enrollmentId = (int)$eidStmt->fetchColumn();

            if (!$enrollmentId) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'No enrollment found for student'];
            }

            $remark = trim($reason);
            if ($newStatus === 'Transferred_Out' && $additionalInfo) {
                $remark = "Transfer to: {$additionalInfo}. {$remark}";
            }

            $fieldsToChange = json_encode([[
                'field'    => 'EnrollmentStatus',
                'oldValue' => 'Active',
                'newValue' => $newStatus,
            ]]);

            $stmt = $this->conn->prepare("
                INSERT INTO studentrevisionrequest
                    (StudentID, EnrollmentID, RequestedBy, RequestType,
                     FieldsToChange, Justification, Priority)
                VALUES
                    (:sid, :eid, :reqBy, 'Other', :fields, :just, 'High')
            ");
            $stmt->execute([
                ':sid'   => (int)$studentId,
                ':eid'   => $enrollmentId,
                ':reqBy' => (int)$requestedBy,
                ':fields'=> $fieldsToChange,
                ':just'  => $remark,
            ]);
            $requestId = (int)$this->conn->lastInsertId();

            $this->logAudit(
                'studentrevisionrequest', $requestId, 'REVISION_REQUEST',
                null, $newStatus, $requestedBy,
                "Status change request: {$newStatus}"
            );

            $this->conn->commit();
            return [
                'success'         => true,
                'message'         => 'Status change request submitted for approval',
                'requestId'       => $requestId,
                'requiresApproval'=> true,
            ];

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Error creating request: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // CANCEL ENROLLMENT (direct, no approval)
    // ──────────────────────────────────────────────
    public function cancelEnrollment($studentId, $reason, $userId) {
        return $this->executeStatusChange($studentId, 'Cancelled', $reason, $userId);
    }

    // ──────────────────────────────────────────────
    // AUDIT LOG
    // ──────────────────────────────────────────────
    private function logAudit($table, $recordId, $action, $old, $new, $userId, $desc = null) {
        try {
            $this->conn->prepare("
                INSERT INTO auditlog
                    (TableName, RecordID, Action, OldValue, NewValue, ChangedBy, ActionDescription, IPAddress)
                VALUES
                    (:tbl, :rid, :act, :old, :new, :uid, :desc, :ip)
            ")->execute([
                ':tbl'  => $table,
                ':rid'  => $recordId,
                ':act'  => $action,
                ':old'  => $old,
                ':new'  => $new,
                ':uid'  => $userId,
                ':desc' => $desc,
                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (PDOException $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }
}

// ──────────────────────────────────────────────
// ROUTER
// ──────────────────────────────────────────────
try {
    $db = (new Database())->getConnection();
    if (!$db) throw new Exception('Database connection failed');

    $api    = new StudentUpdateAPI($db);
    $action = $_GET['action'] ?? '';

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'get_grade_levels') {
                echo json_encode($api->getGradeLevels());
            } elseif ($action === 'get_sections') {
                $gradeLevelId = $_GET['grade_level'] ?? null;
                $strandId     = $_GET['strand_id']   ?? null;
                echo json_encode($gradeLevelId
                    ? $api->getSections($gradeLevelId, $strandId)
                    : ['success' => false, 'message' => 'Grade level ID required']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid GET action']);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            if ($action === 'update') {
                $userId = $data['UpdatedBy'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'User ID required']);
                    exit;
                }
                $userStmt = $db->prepare("SELECT Role FROM user WHERE UserID = ?");
                $userStmt->execute([$userId]);
                $role = $userStmt->fetchColumn();

                if ($role === 'Adviser') {
                    echo json_encode([
                        'success'  => false,
                        'message'  => 'Advisers must use revision request endpoint',
                        'redirect' => 'revision_request',
                    ]);
                    exit;
                }
                echo json_encode($api->updateStudent($data));

            } elseif ($action === 'add_remarks') {
                ['StudentID' => $sid, 'Remarks' => $rem, 'UserID' => $uid] =
                    array_merge(['StudentID' => null, 'Remarks' => null, 'UserID' => null], $data);
                if (!$sid || !$rem || !$uid) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                echo json_encode($api->addRemarks($sid, $rem, $uid));

            } elseif ($action === 'change_status') {
                $sid    = $data['StudentID']     ?? null;
                $status = $data['NewStatus']     ?? null;
                $reason = $data['Reason']        ?? null;
                $uid    = $data['UserID']        ?? null;
                $extra  = $data['AdditionalInfo']?? null;

                if (!$sid || !$status || !$reason || !$uid) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                $userStmt = $db->prepare("SELECT Role FROM user WHERE UserID = ?");
                $userStmt->execute([$uid]);
                $role = $userStmt->fetchColumn();

                echo json_encode($api->changeStatus($sid, $status, $reason, $uid, $role, $extra));

            } elseif ($action === 'cancel_enrollment') {
                ['StudentID' => $sid, 'Reason' => $reason, 'UserID' => $uid] =
                    array_merge(['StudentID' => null, 'Reason' => null, 'UserID' => null], $data);
                if (!$sid || !$reason || !$uid) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                echo json_encode($api->cancelEnrollment($sid, $reason, $uid));

            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid POST action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("Student Update API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>