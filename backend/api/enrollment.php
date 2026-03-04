<?php
// =====================================================
// Enrollment API — djihs_enrollment_v2 schema
// File: backend/api/enrollment.php
//
// CHANGES vs old version:
//  - Added getStats() — returns pendingCount, confirmedToday, totalConfirmed
//  - getEnrollmentDetails(): removed e.* wildcard; explicit column list only
//    (no Age, Weight, Height, ZipCode, Country, LearnerType)
//    Age derived at runtime via TIMESTAMPDIFF
//  - getPendingEnrollments(): uses EnrollmentType (not LearnerType)
//  - rejectEnrollment(): uses Remarks column ✓ (exists in schema)
//  - All parent/guardian data comes from parentguardian table (guardians[] key)
//  - submitEnrollment(): unchanged from previous session (already aligned)
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../config/database.php';

class EnrollmentAPI {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ──────────────────────────────────────────────
    // STATS — for the three stat cards on review page
    // ──────────────────────────────────────────────
    public function getStats() {
        try {
            // Pending count
            $p = $this->conn->query(
                "SELECT COUNT(*) FROM enrollment WHERE Status = 'Pending'"
            )->fetchColumn();

            // Confirmed today
            $ct = $this->conn->query(
                "SELECT COUNT(*) FROM enrollment
                 WHERE Status = 'Confirmed'
                   AND DATE(EnrollmentDate) = CURDATE()"
            )->fetchColumn();

            // Total confirmed (all time)
            $total = $this->conn->query(
                "SELECT COUNT(*) FROM enrollment WHERE Status = 'Confirmed'"
            )->fetchColumn();

            return [
                'success' => true,
                'data'    => [
                    'pendingCount'    => (int)$p,
                    'confirmedToday'  => (int)$ct,
                    'totalConfirmed'  => (int)$total,
                ],
            ];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching stats: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET PENDING ENROLLMENTS
    // Uses EnrollmentType (not LearnerType — removed from schema)
    // ──────────────────────────────────────────────
    public function getPendingEnrollments() {
        try {
            $stmt = $this->conn->prepare("
                SELECT
                    e.EnrollmentID,
                    e.StudentID,
                    e.EnrollmentType,
                    e.Status,
                    e.EnrollmentDate,
                    s.LRN,
                    CONCAT(s.LastName, ', ', s.FirstName,
                        IFNULL(CONCAT(' ', s.MiddleName), '')) AS StudentName,
                    gl.GradeLevelName,
                    st.StrandName,
                    ay.YearLabel AS AcademicYear
                FROM   enrollment    e
                JOIN   student       s  ON e.StudentID      = s.StudentID
                JOIN   gradelevel    gl ON e.GradeLevelID   = gl.GradeLevelID
                JOIN   academicyear  ay ON e.AcademicYearID = ay.AcademicYearID
                LEFT   JOIN strand   st ON e.StrandID       = st.StrandID
                WHERE  e.Status = 'Pending'
                ORDER  BY e.EnrollmentDate DESC
            ");
            $stmt->execute();
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching enrollments: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET ENROLLMENT DETAILS (single record)
    //
    // KEY CHANGES:
    //  - No e.* wildcard — explicit column list only
    //  - Age derived via TIMESTAMPDIFF (not stored)
    //  - No Weight, Height, ZipCode, Country, LearnerType
    //  - guardians[] fetched from parentguardian table
    //  - documents[] fetched from documentsubmission table
    // ──────────────────────────────────────────────
    public function getEnrollmentDetails($enrollmentID) {
        try {
            $stmt = $this->conn->prepare("
                SELECT
                    -- Enrollment fields (exact schema columns only)
                    e.EnrollmentID,
                    e.StudentID,
                    e.GradeLevelID,
                    e.StrandID,
                    e.AcademicYearID,
                    e.EnrollmentDate,
                    e.EnrollmentType,
                    e.Status,
                    e.Remarks,

                    -- Student fields (exact schema columns only)
                    s.LRN,
                    s.LastName,
                    s.FirstName,
                    s.MiddleName,
                    s.ExtensionName,
                    s.DateOfBirth,
                    TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) AS Age,
                    s.Gender,
                    s.Religion,
                    s.MotherTongue,
                    s.IsIPCommunity,
                    s.IPCommunitySpecify,
                    s.IsPWD,
                    s.PWDSpecify,
                    s.HouseNumber,
                    s.SitioStreet,
                    s.Barangay,
                    s.Municipality,
                    s.Province,
                    s.ContactNumber,
                    s.Is4PsBeneficiary,
                    s.EnrollmentStatus,

                    -- Derived / joined
                    CONCAT(s.LastName, ', ', s.FirstName,
                        IFNULL(CONCAT(' ', s.MiddleName), '')) AS FullName,
                    CONCAT_WS(', ',
                        NULLIF(TRIM(CONCAT_WS(' ', s.HouseNumber, s.SitioStreet)), ''),
                        s.Barangay, s.Municipality, s.Province
                    ) AS CompleteAddress,
                    gl.GradeLevelName,
                    gl.Department,
                    st.StrandCode,
                    st.StrandName,
                    ay.YearLabel AS AcademicYear

                FROM   enrollment    e
                JOIN   student       s  ON e.StudentID      = s.StudentID
                JOIN   gradelevel    gl ON e.GradeLevelID   = gl.GradeLevelID
                JOIN   academicyear  ay ON e.AcademicYearID = ay.AcademicYearID
                LEFT   JOIN strand   st ON e.StrandID       = st.StrandID
                WHERE  e.EnrollmentID = :eid
            ");
            $stmt->execute([':eid' => (int)$enrollmentID]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return ['success' => false, 'message' => 'Enrollment not found'];
            }

            // Parent/guardian rows (from separate parentguardian table)
            // Returns keyed map: { Father: {...}, Mother: {...}, Guardian: {...} }
            $pgStmt = $this->conn->prepare("
                SELECT RelationshipType, LastName, FirstName, MiddleName,
                       GuardianRelationship, ContactNumber
                FROM   parentguardian
                WHERE  StudentID = :sid
                ORDER  BY FIELD(RelationshipType, 'Father', 'Mother', 'Guardian')
            ");
            $pgStmt->execute([':sid' => $result['StudentID']]);
            $guardianRows = $pgStmt->fetchAll(PDO::FETCH_ASSOC);

            // Key by RelationshipType for easy JS access
            $guardianMap = [];
            foreach ($guardianRows as $g) {
                $guardianMap[$g['RelationshipType']] = $g;
            }
            $result['guardians']   = $guardianMap;
            $result['guardianList'] = $guardianRows; // flat list also available

            // Document submissions
            $docStmt = $this->conn->prepare("
                SELECT DocumentType, IsSubmitted, IsVerified, Notes
                FROM   documentsubmission
                WHERE  EnrollmentID = :eid
                ORDER  BY DocumentType
            ");
            $docStmt->execute([':eid' => (int)$enrollmentID]);
            $result['documents'] = $docStmt->fetchAll(PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $result];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching details: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // APPROVE ENROLLMENT
    // ──────────────────────────────────────────────
    public function approveEnrollment($enrollmentID, $reviewerID) {
        try {
            $this->conn->beginTransaction();

            $upd = $this->conn->prepare("
                UPDATE enrollment
                SET    Status = 'Confirmed'
                WHERE  EnrollmentID = :eid AND Status = 'Pending'
            ");
            $upd->execute([':eid' => (int)$enrollmentID]);

            if ($upd->rowCount() === 0) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Enrollment not found or already processed'];
            }

            // Auto-create DocumentSubmission rows (INSERT IGNORE = safe to re-run)
            $docTypes = [
                'PSA_Birth_Cert', 'Local_Birth_Cert', 'Report_Card',
                'Form_137', 'Good_Moral', 'Transfer_Cert'
            ];
            $docIns = $this->conn->prepare("
                INSERT IGNORE INTO documentsubmission
                    (EnrollmentID, DocumentType, IsSubmitted, IsVerified)
                VALUES
                    (:eid, :dtype, 0, 0)
            ");
            foreach ($docTypes as $dtype) {
                $docIns->execute([':eid' => (int)$enrollmentID, ':dtype' => $dtype]);
            }

            $this->writeAuditLog(
                'enrollment', $enrollmentID, 'STATUS_CHANGE',
                'Pending', 'Confirmed', $reviewerID, 'Enrollment approved'
            );

            $this->conn->commit();
            return ['success' => true, 'message' => 'Enrollment approved successfully'];

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Error approving enrollment: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // REJECT ENROLLMENT
    // Remarks column exists in schema ✓
    // ──────────────────────────────────────────────
    public function rejectEnrollment($enrollmentID, $reviewerID, $reason) {
        try {
            $upd = $this->conn->prepare("
                UPDATE enrollment
                SET    Status = 'Cancelled', Remarks = :reason
                WHERE  EnrollmentID = :eid
            ");
            $upd->execute([':reason' => $reason, ':eid' => (int)$enrollmentID]);

            $this->writeAuditLog(
                'enrollment', $enrollmentID, 'STATUS_CHANGE',
                null, 'Cancelled', $reviewerID, "Enrollment cancelled: $reason"
            );

            return ['success' => true, 'message' => 'Enrollment rejected'];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error rejecting enrollment: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // SUBMIT ENROLLMENT
    // (Unchanged from previous session — already schema-aligned)
    // ──────────────────────────────────────────────
    public function submitEnrollment($data) {
        try {
            // 1. Basic required-field validation
            $required = [
                'academicYearID', 'gradeLevelID', 'enrollmentType',
                'lastName', 'firstName', 'dateOfBirth', 'gender',
                'barangay', 'municipality', 'province', 'contactNumber',
                'createdBy'
            ];
            foreach ($required as $field) {
                if (empty($data[$field]))
                    return ['success' => false, 'message' => "Missing required field: $field"];
            }

            // 2. Validate acting user
            $userCheck = $this->conn->prepare(
                "SELECT Role FROM user WHERE UserID = ? AND IsActive = 1"
            );
            $userCheck->execute([$data['createdBy']]);
            $user = $userCheck->fetch(PDO::FETCH_ASSOC);
            if (!$user)
                return ['success' => false, 'message' => 'Invalid user or user is not active'];

            $allowedRoles = ['ICT_Coordinator', 'Registrar', 'Adviser'];
            if (!in_array($user['Role'], $allowedRoles))
                return ['success' => false, 'message' => 'User does not have permission to submit enrollment forms'];

            // 3. Validate AcademicYear
            $ayCheck = $this->conn->prepare(
                "SELECT AcademicYearID, YearLabel, IsArchived FROM academicyear WHERE AcademicYearID = ?"
            );
            $ayCheck->execute([$data['academicYearID']]);
            $ay = $ayCheck->fetch(PDO::FETCH_ASSOC);
            if (!$ay) return ['success' => false, 'message' => 'Invalid AcademicYearID'];
            if ($ay['IsArchived']) return ['success' => false, 'message' => "Academic year {$ay['YearLabel']} is archived"];

            // 4. Validate GradeLevel
            $glCheck = $this->conn->prepare(
                "SELECT GradeLevelID, GradeLevelNumber, Department FROM gradelevel WHERE GradeLevelID = ? AND IsActive = 1"
            );
            $glCheck->execute([$data['gradeLevelID']]);
            $gl = $glCheck->fetch(PDO::FETCH_ASSOC);
            if (!$gl) return ['success' => false, 'message' => 'Invalid or inactive GradeLevelID'];

            // 5. Strand requirement for SHS
            $isSHS    = ($gl['Department'] === 'Senior_High');
            $strandID = !empty($data['strandID']) ? (int)$data['strandID'] : null;
            if ($isSHS && !$strandID)
                return ['success' => false, 'message' => 'Strand is required for Senior High School (Grade 11 & 12)'];
            if (!$isSHS) $strandID = null;

            // 6. Validate EnrollmentType enum
            $validTypes = ['Regular_Old_Student','Regular_New_Student','Late','Transferee','Balik_Aral','Repeater','ALS'];
            if (!in_array($data['enrollmentType'], $validTypes))
                return ['success' => false, 'message' => 'Invalid enrollmentType value'];

            // 7. Duplicate-enrollment check
            $lrn = !empty($data['lrn']) ? trim($data['lrn']) : null;
            $existingStudentID = null;

            if ($lrn) {
                $dupCheck = $this->conn->prepare("
                    SELECT e.EnrollmentID, e.Status, gl.GradeLevelName,
                           st.StrandName,
                           CONCAT(s.FirstName,' ',s.LastName) AS StudentName
                    FROM   enrollment e
                    JOIN   student    s  ON e.StudentID     = s.StudentID
                    JOIN   gradelevel gl ON e.GradeLevelID  = gl.GradeLevelID
                    LEFT   JOIN strand st ON e.StrandID     = st.StrandID
                    WHERE  s.LRN = :lrn AND e.AcademicYearID = :ayID
                    LIMIT  1
                ");
                $dupCheck->execute([':lrn' => $lrn, ':ayID' => $data['academicYearID']]);
                $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $si = $dup['StrandName'] ? " – {$dup['StrandName']}" : '';
                    return ['success' => false,
                        'message'   => "Duplicate enrollment: LRN {$lrn} already enrolled for {$ay['YearLabel']} ({$dup['GradeLevelName']}{$si}). Status: {$dup['Status']}",
                        'duplicate' => $dup];
                }

                // Strand consistency G11 → G12
                if ($isSHS && $gl['GradeLevelNumber'] == 12 && $strandID) {
                    $prevCheck = $this->conn->prepare("
                        SELECT e.StrandID, st.StrandName
                        FROM   enrollment e
                        JOIN   student    s  ON e.StudentID    = s.StudentID
                        JOIN   gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                        LEFT   JOIN strand st ON e.StrandID    = st.StrandID
                        WHERE  s.LRN = :lrn AND gl.GradeLevelNumber = 11
                          AND  e.Status IN ('Confirmed','Pending')
                        ORDER  BY e.EnrollmentID DESC
                        LIMIT  1
                    ");
                    $prevCheck->execute([':lrn' => $lrn]);
                    $prev = $prevCheck->fetch(PDO::FETCH_ASSOC);
                    if ($prev && $prev['StrandID'] && $prev['StrandID'] != $strandID)
                        return ['success' => false,
                            'message' => "Strand mismatch: student was in {$prev['StrandName']} for Grade 11. Cannot change strand for Grade 12."];
                }

                // Does student already exist?
                $stCheck = $this->conn->prepare("SELECT StudentID FROM student WHERE LRN = :lrn LIMIT 1");
                $stCheck->execute([':lrn' => $lrn]);
                $ex = $stCheck->fetch(PDO::FETCH_ASSOC);
                if ($ex) $existingStudentID = (int)$ex['StudentID'];
            }

            // 8. Sanitise flags
            $isIP  = isset($data['isIPCommunity'])   ? (int)(bool)$data['isIPCommunity']   : 0;
            $isPWD = isset($data['isPWD'])            ? (int)(bool)$data['isPWD']            : 0;
            $is4Ps = isset($data['is4PsBeneficiary']) ? (int)(bool)$data['is4PsBeneficiary'] : 0;
            $mt    = !empty($data['motherTongue'])    ? trim($data['motherTongue'])          : 'Tagalog';

            $this->conn->beginTransaction();

            // 9a. Student upsert
            $studentParams = [
                ':lastName'    => trim($data['lastName']),
                ':firstName'   => trim($data['firstName']),
                ':middleName'  => !empty($data['middleName'])        ? trim($data['middleName'])        : null,
                ':extensionName' => !empty($data['extensionName'])   ? trim($data['extensionName'])     : null,
                ':dateOfBirth' => $data['dateOfBirth'],
                ':gender'      => $data['gender'],
                ':religion'    => !empty($data['religion'])          ? trim($data['religion'])          : null,
                ':mt'          => $mt,
                ':isIP'        => $isIP,
                ':ipSpec'      => !empty($data['ipCommunitySpecify'])? trim($data['ipCommunitySpecify']): null,
                ':isPWD'       => $isPWD,
                ':pwdSpec'     => !empty($data['pwdSpecify'])        ? trim($data['pwdSpecify'])        : null,
                ':houseNumber' => !empty($data['houseNumber'])       ? trim($data['houseNumber'])       : null,
                ':sitioStreet' => !empty($data['sitioStreet'])       ? trim($data['sitioStreet'])       : null,
                ':barangay'    => trim($data['barangay']),
                ':municipality'=> trim($data['municipality']),
                ':province'    => trim($data['province']),
                ':contactNumber' => trim($data['contactNumber']),
                ':is4Ps'       => $is4Ps,
            ];

            if ($existingStudentID) {
                $studentID = $existingStudentID;
                $upd = $this->conn->prepare("
                    UPDATE student SET
                        LastName = :lastName, FirstName = :firstName, MiddleName = :middleName,
                        ExtensionName = :extensionName, DateOfBirth = :dateOfBirth,
                        Gender = :gender, Religion = :religion, MotherTongue = :mt,
                        IsIPCommunity = :isIP, IPCommunitySpecify = :ipSpec,
                        IsPWD = :isPWD, PWDSpecify = :pwdSpec,
                        HouseNumber = :houseNumber, SitioStreet = :sitioStreet,
                        Barangay = :barangay, Municipality = :municipality, Province = :province,
                        ContactNumber = :contactNumber, Is4PsBeneficiary = :is4Ps,
                        EnrollmentStatus = 'Active'
                    WHERE StudentID = :studentID
                ");
                $studentParams[':studentID'] = $studentID;
                $upd->execute($studentParams);
            } else {
                $ins = $this->conn->prepare("
                    INSERT INTO student (
                        LRN, LastName, FirstName, MiddleName, ExtensionName,
                        DateOfBirth, Gender, Religion, MotherTongue,
                        IsIPCommunity, IPCommunitySpecify, IsPWD, PWDSpecify,
                        HouseNumber, SitioStreet, Barangay, Municipality, Province,
                        ContactNumber, Is4PsBeneficiary, EnrollmentStatus
                    ) VALUES (
                        :lrn, :lastName, :firstName, :middleName, :extensionName,
                        :dateOfBirth, :gender, :religion, :mt,
                        :isIP, :ipSpec, :isPWD, :pwdSpec,
                        :houseNumber, :sitioStreet, :barangay, :municipality, :province,
                        :contactNumber, :is4Ps, 'Active'
                    )
                ");
                $studentParams[':lrn'] = $lrn;
                $ins->execute($studentParams);
                $studentID = (int)$this->conn->lastInsertId();
            }

            // 9b. Upsert ParentGuardian rows
            $guardianSets = [
                ['Father',   'fatherLastName',   'fatherFirstName',   'fatherMiddleName',   null,                   null           ],
                ['Mother',   'motherLastName',   'motherFirstName',   'motherMiddleName',   null,                   null           ],
                ['Guardian', 'guardianLastName', 'guardianFirstName', 'guardianMiddleName', 'guardianRelationship', 'contactNumber'],
            ];
            foreach ($guardianSets as [$relType, $lnKey, $fnKey, $mnKey, $relKey, $ctKey]) {
                $fn = !empty($data[$fnKey]) ? trim($data[$fnKey]) : null;
                $ln = !empty($data[$lnKey]) ? trim($data[$lnKey]) : null;
                if (!$fn && !$ln) continue;

                $pgChk = $this->conn->prepare(
                    "SELECT ParentGuardianID FROM parentguardian WHERE StudentID = :sid AND RelationshipType = :rel LIMIT 1"
                );
                $pgChk->execute([':sid' => $studentID, ':rel' => $relType]);
                $pgRow = $pgChk->fetch(PDO::FETCH_ASSOC);

                $contact  = ($ctKey  && !empty($data[$ctKey]))  ? trim($data[$ctKey])  : null;
                $guardRel = ($relKey && !empty($data[$relKey])) ? trim($data[$relKey]) : null;

                if ($pgRow) {
                    $this->conn->prepare("
                        UPDATE parentguardian
                        SET LastName = :ln, FirstName = :fn, MiddleName = :mn,
                            GuardianRelationship = :guardRel, ContactNumber = :contact
                        WHERE ParentGuardianID = :pgid
                    ")->execute([
                        ':ln' => $ln, ':fn' => $fn,
                        ':mn' => !empty($data[$mnKey]) ? trim($data[$mnKey]) : null,
                        ':guardRel' => $guardRel, ':contact' => $contact,
                        ':pgid' => $pgRow['ParentGuardianID'],
                    ]);
                } else {
                    $this->conn->prepare("
                        INSERT INTO parentguardian
                            (StudentID, RelationshipType, LastName, FirstName, MiddleName, GuardianRelationship, ContactNumber)
                        VALUES (:sid, :rel, :ln, :fn, :mn, :guardRel, :contact)
                    ")->execute([
                        ':sid' => $studentID, ':rel' => $relType,
                        ':ln'  => $ln, ':fn' => $fn,
                        ':mn'  => !empty($data[$mnKey]) ? trim($data[$mnKey]) : null,
                        ':guardRel' => $guardRel, ':contact' => $contact,
                    ]);
                }
            }

            // 9c. Insert Enrollment
            $enrollIns = $this->conn->prepare("
                INSERT INTO enrollment
                    (StudentID, GradeLevelID, StrandID, AcademicYearID, EnrollmentType, Status)
                VALUES
                    (:studentID, :gradeLevelID, :strandID, :academicYearID, :enrollmentType, 'Pending')
            ");
            $enrollIns->execute([
                ':studentID'      => $studentID,
                ':gradeLevelID'   => (int)$data['gradeLevelID'],
                ':strandID'       => $strandID,
                ':academicYearID' => (int)$data['academicYearID'],
                ':enrollmentType' => $data['enrollmentType'],
            ]);
            $enrollmentID = (int)$this->conn->lastInsertId();

            // 9d. Audit
            $this->writeAuditLog(
                'enrollment', $enrollmentID, 'INSERT', null,
                json_encode(['studentID' => $studentID, 'gradeLevelID' => $data['gradeLevelID']]),
                (int)$data['createdBy'],
                "Enrollment submitted for {$data['firstName']} {$data['lastName']}",
                $user['Role']
            );

            $this->conn->commit();

            return [
                'success'      => true,
                'message'      => $existingStudentID ? 'Enrollment created for existing student' : 'New student enrolled successfully',
                'studentID'    => $studentID,
                'enrollmentID' => $enrollmentID,
                'isNewStudent' => !$existingStudentID,
                'data' => [
                    'studentName'    => "{$data['firstName']} {$data['lastName']}",
                    'lrn'            => $lrn,
                    'gradeLevelID'   => $data['gradeLevelID'],
                    'academicYear'   => $ay['YearLabel'],
                    'enrollmentType' => $data['enrollmentType'],
                    'status'         => 'Pending',
                ],
            ];

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("Enrollment submission error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // AUDIT LOG
    // ──────────────────────────────────────────────
    private function writeAuditLog($table, $recordID, $action, $old, $new, $changedBy, $desc = null, $role = null) {
        try {
            $this->conn->prepare("
                INSERT INTO auditlog
                    (TableName, RecordID, Action, OldValue, NewValue,
                     ChangedBy, ActionDescription, UserRole, IPAddress)
                VALUES
                    (:tbl, :rid, :act, :old, :new, :uid, :desc, :role, :ip)
            ")->execute([
                ':tbl'  => $table, ':rid'  => $recordID, ':act' => $action,
                ':old'  => $old,   ':new'  => $new,      ':uid' => $changedBy,
                ':desc' => $desc,  ':role' => $role,
                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (PDOException $e) {
            error_log("Audit log write failed: " . $e->getMessage());
        }
    }
}

// ──────────────────────────────────────────────
// ROUTER
// ──────────────────────────────────────────────
try {
    $db = (new Database())->getConnection();
    if (!$db) throw new Exception('Database connection failed');

    $api    = new EnrollmentAPI($db);
    $action = $_GET['action'] ?? 'submit';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        switch ($action) {
            case 'pending':
                echo json_encode($api->getPendingEnrollments());
                break;
            case 'details':
                $id = $_GET['id'] ?? null;
                echo json_encode($id
                    ? $api->getEnrollmentDetails($id)
                    : ['success' => false, 'message' => 'Enrollment ID required']);
                break;
            case 'stats':
                echo json_encode($api->getStats());
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown GET action: ' . $action]);
        }

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        switch ($action) {
            case 'submit':
                echo json_encode($api->submitEnrollment($input));
                break;
            case 'approve':
                if (empty($input['enrollmentID']) || empty($input['reviewerID'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing enrollmentID or reviewerID']);
                } else {
                    echo json_encode($api->approveEnrollment($input['enrollmentID'], $input['reviewerID']));
                }
                break;
            case 'reject':
                if (empty($input['enrollmentID']) || empty($input['reviewerID']) || !isset($input['reason'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing enrollmentID, reviewerID, or reason']);
                } else {
                    echo json_encode($api->rejectEnrollment($input['enrollmentID'], $input['reviewerID'], $input['reason']));
                }
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown POST action: ' . $action]);
        }

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("Enrollment API fatal: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>