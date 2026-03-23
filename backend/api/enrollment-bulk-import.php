<?php
// =====================================================
// Bulk Import API — djihs_enrollment_v2 schema
// File: backend/api/enrollment-bulk-import.php
//
// KEY CHANGES vs old version:
//  - checkLRNAndEnrollment: uses AcademicYearID (int FK), not AcademicYear string
//  - insertStudent: removed Age, Weight, Height, ZipCode, Country,
//      IsTransferee, FatherX, MotherX, GuardianX (flat), EncodedDate,
//      EncodedBy, CreatedBy, UpdatedAt, UpdatedBy (not in schema)
//  - upsertGuardians: new method writing Father/Mother/Guardian rows
//      to the separate parentguardian table
//  - insertEnrollment: uses AcademicYearID FK, correct EnrollmentType enum,
//      removed AcademicYear string, LearnerType, CreatedAt columns
//  - validateStudent: validates against exact EnrollmentType enum values
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

class BulkImportAPI {

    private $conn;
    private $actorID;

    // Valid EnrollmentType ENUM values (must match schema exactly)
    private const VALID_ENROLLMENT_TYPES = [
        'Regular_Old_Student',
        'Regular_New_Student',
        'Late',
        'Transferee',
        'Balik_Aral',
        'Repeater',
        'ALS',
    ];

    public function __construct($db) {
        $this->conn = $db;
    }

    // ──────────────────────────────────────────────
    // ENTRY POINT
    // ──────────────────────────────────────────────
    public function processImport($data) {
        if (!isset($data['students']) || !is_array($data['students']))
            return $this->fail('Invalid data format: students array required');

        if (empty($data['createdBy']))
            return $this->fail('createdBy (UserID) is required');

        $this->actorID = (int)$data['createdBy'];

        // Validate acting user and role
        $chk = $this->conn->prepare(
            "SELECT Role FROM user WHERE UserID = ? AND IsActive = 1 LIMIT 1"
        );
        $chk->execute([$this->actorID]);
        $user = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$user) return $this->fail('Invalid or inactive user');

        $allowed = ['ICT_Coordinator', 'Registrar', 'Admin'];
        if (!in_array($user['Role'], $allowed))
            return $this->fail('User does not have permission to bulk import');

        $students = $data['students'];
        $results  = ['total' => count($students), 'success' => 0, 'failed' => 0, 'errors' => []];

        $this->conn->beginTransaction();

        try {
            foreach ($students as $i => $student) {
                $rowNum = $i + 1;
                try {
                    $err = $this->validateStudent($student, $rowNum);
                    if ($err) {
                        $results['errors'][] = $err;
                        $results['failed']++;
                        continue;
                    }

                    $ayID = (int)($student['academicYearID'] ?? 0);

                    // Verify academicYearID exists and is not archived
                    $ayCk = $this->conn->prepare(
                        "SELECT YearLabel, IsArchived FROM academicyear WHERE AcademicYearID = ? LIMIT 1"
                    );
                    $ayCk->execute([$ayID]);
                    $ay = $ayCk->fetch(PDO::FETCH_ASSOC);

                    if (!$ay) {
                        $results['errors'][] = "Row {$rowNum}: Invalid academicYearID {$ayID}";
                        $results['failed']++; continue;
                    }
                    if ($ay['IsArchived']) {
                        $results['errors'][] = "Row {$rowNum}: Academic year {$ay['YearLabel']} is archived";
                        $results['failed']++; continue;
                    }

                    // Duplicate check (uses AcademicYearID FK — not string)
                    $dupResult = $this->checkDuplicate($student['lrn'], $ayID);

                    if ($dupResult === true) {
                        // Already enrolled this year — skip
                        $results['errors'][] = "Row {$rowNum}: LRN {$student['lrn']} already enrolled for {$ay['YearLabel']}";
                        $results['failed']++; continue;
                    }

                    if (is_array($dupResult)) {
                        // Student exists, not yet enrolled this year — update info
                        $studentID = $dupResult['studentID'];
                        $this->updateStudent($studentID, $student);
                        $this->upsertGuardians($studentID, $student);
                    } else {
                        // Brand new student
                        $studentID = $this->insertStudent($student);
                        if (!$studentID) {
                            $results['errors'][] = "Row {$rowNum}: Failed to insert student";
                            $results['failed']++; continue;
                        }
                        $this->upsertGuardians($studentID, $student);
                    }

                    $enrollmentID = $this->insertEnrollment($studentID, $student, $ayID);
                    if (!$enrollmentID) {
                        $results['errors'][] = "Row {$rowNum}: Failed to create enrollment record";
                        $results['failed']++; continue;
                    }

                    $this->writeAudit(
                        'enrollment', $enrollmentID, 'INSERT', null,
                        json_encode(['studentID' => $studentID, 'ayID' => $ayID]),
                        "Bulk import: {$student['firstName']} {$student['lastName']}"
                    );

                    $results['success']++;

                } catch (Exception $e) {
                    $results['errors'][] = "Row {$rowNum}: " . $e->getMessage();
                    $results['failed']++;
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => "{$results['success']} imported, {$results['failed']} failed",
                'results' => $results,
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return $this->fail('Import transaction failed: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    // VALIDATE SINGLE ROW
    // ──────────────────────────────────────────────
    private function validateStudent($s, $rowNum) {
        // Required fields
        $required = ['lrn', 'lastName', 'firstName', 'dateOfBirth',
                     'gender', 'gradeLevelID', 'enrollmentType', 'academicYearID'];
        foreach ($required as $f) {
            if (empty($s[$f]))
                return "Row {$rowNum}: Missing required field: {$f}";
        }

        if (!preg_match('/^\d{12}$/', $s['lrn']))
            return "Row {$rowNum}: LRN must be exactly 12 digits";

        if (!in_array((int)$s['gradeLevelID'], [1,2,3,4,5,6]))
            return "Row {$rowNum}: Invalid gradeLevelID (must be 1–6)";

        if (in_array((int)$s['gradeLevelID'], [5,6]) && empty($s['strandID']))
            return "Row {$rowNum}: Strand is required for Grade 11 & 12";

        if (!in_array($s['enrollmentType'], self::VALID_ENROLLMENT_TYPES))
            return "Row {$rowNum}: Invalid enrollmentType \"{$s['enrollmentType']}\"";

        return null; // OK
    }

    // ──────────────────────────────────────────────
    // DUPLICATE CHECK
    // Returns: true             = already enrolled this year (block)
    //          ['studentID'=>N] = exists, not enrolled this year (update)
    //          false            = brand-new student (insert)
    //
    // Uses AcademicYearID (int FK) — not the old AcademicYear string
    // ──────────────────────────────────────────────
    private function checkDuplicate($lrn, $ayID) {
        $st = $this->conn->prepare("SELECT StudentID FROM student WHERE LRN = ? LIMIT 1");
        $st->execute([$lrn]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $en = $this->conn->prepare(
            "SELECT EnrollmentID FROM enrollment
             WHERE StudentID = ? AND AcademicYearID = ? LIMIT 1"
        );
        $en->execute([$row['StudentID'], $ayID]);
        if ($en->fetch()) return true;

        return ['studentID' => $row['StudentID']];
    }

    // ──────────────────────────────────────────────
    // INSERT STUDENT
    // Only columns that exist in the revised student table.
    // REMOVED: Age, Weight, Height, ZipCode, Country,
    //          IsTransferee, FatherX/MotherX/GuardianX (flat),
    //          EncodedDate, EncodedBy, CreatedBy, UpdatedAt, UpdatedBy
    // ──────────────────────────────────────────────
    private function insertStudent($s) {
        $stmt = $this->conn->prepare("
            INSERT INTO student (
                LRN, LastName, FirstName, MiddleName, ExtensionName,
                DateOfBirth, Gender, Religion, MotherTongue,
                IsIPCommunity, IPCommunitySpecify, IsPWD, PWDSpecify,
                HouseNumber, SitioStreet, Barangay, Municipality, Province,
                ContactNumber, Is4PsBeneficiary, EnrollmentStatus
            ) VALUES (
                :lrn, :ln, :fn, :mn, :ext,
                :dob, :gender, :religion, :mt,
                :isIP, :ipSpec, :isPWD, :pwdSpec,
                :house, :street, :brgy, :muni, :prov,
                :contact, :is4ps, 'Active'
            )
        ");

        $stmt->execute([
            ':lrn'     => $s['lrn'],
            ':ln'      => $s['lastName'],
            ':fn'      => $s['firstName'],
            ':mn'      => $s['middleName']        ?? null,
            ':ext'     => $s['extensionName']     ?? null,
            ':dob'     => $s['dateOfBirth'],
            ':gender'  => $s['gender'],
            ':religion'=> $s['religion']          ?? null,
            ':mt'      => $s['motherTongue']      ?? 'Tagalog',
            ':isIP'    => ($s['isIPCommunity']    ?? false) ? 1 : 0,
            ':ipSpec'  => $s['ipCommunitySpecify']?? null,
            ':isPWD'   => ($s['isPWD']            ?? false) ? 1 : 0,
            ':pwdSpec' => $s['pwdSpecify']        ?? null,
            ':house'   => $s['houseNumber']       ?? null,
            ':street'  => $s['sitioStreet']       ?? null,
            ':brgy'    => $s['barangay']          ?? '',
            ':muni'    => $s['municipality']      ?? '',
            ':prov'    => $s['province']          ?? '',
            ':contact' => $s['contactNumber']     ?? '',
            ':is4ps'   => ($s['is4PsBeneficiary'] ?? false) ? 1 : 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    // ──────────────────────────────────────────────
    // UPDATE STUDENT (re-enrolling in new year)
    // Same column restrictions as insertStudent
    // ──────────────────────────────────────────────
    private function updateStudent($studentID, $s) {
        $stmt = $this->conn->prepare("
            UPDATE student SET
                LastName           = :ln,
                FirstName          = :fn,
                MiddleName         = :mn,
                DateOfBirth        = :dob,
                Gender             = :gender,
                Religion           = :religion,
                MotherTongue       = :mt,
                IsIPCommunity      = :isIP,
                IPCommunitySpecify = :ipSpec,
                IsPWD              = :isPWD,
                PWDSpecify         = :pwdSpec,
                HouseNumber        = :house,
                SitioStreet        = :street,
                Barangay           = :brgy,
                Municipality       = :muni,
                Province           = :prov,
                ContactNumber      = :contact,
                Is4PsBeneficiary   = :is4ps,
                EnrollmentStatus   = 'Active'
            WHERE StudentID = :sid
        ");

        $stmt->execute([
            ':ln'      => $s['lastName'],
            ':fn'      => $s['firstName'],
            ':mn'      => $s['middleName']        ?? null,
            ':dob'     => $s['dateOfBirth'],
            ':gender'  => $s['gender'],
            ':religion'=> $s['religion']          ?? null,
            ':mt'      => $s['motherTongue']      ?? 'Tagalog',
            ':isIP'    => ($s['isIPCommunity']    ?? false) ? 1 : 0,
            ':ipSpec'  => $s['ipCommunitySpecify']?? null,
            ':isPWD'   => ($s['isPWD']            ?? false) ? 1 : 0,
            ':pwdSpec' => $s['pwdSpecify']        ?? null,
            ':house'   => $s['houseNumber']       ?? null,
            ':street'  => $s['sitioStreet']       ?? null,
            ':brgy'    => $s['barangay']          ?? '',
            ':muni'    => $s['municipality']      ?? '',
            ':prov'    => $s['province']          ?? '',
            ':contact' => $s['contactNumber']     ?? '',
            ':is4ps'   => ($s['is4PsBeneficiary'] ?? false) ? 1 : 0,
            ':sid'     => $studentID,
        ]);
    }

    // ──────────────────────────────────────────────
    // UPSERT PARENT/GUARDIAN ROWS
    // The revised schema has a separate parentguardian table
    // with RelationshipType ENUM('Father','Mother','Guardian').
    // We upsert one row per relationship type that has data.
    // ──────────────────────────────────────────────
    private function upsertGuardians($studentID, $s) {
        $sets = [
            // [ RelationshipType, lastNameKey, firstNameKey, middleNameKey, contactKey ]
            ['Father',   'fatherLastName',   'fatherFirstName',   'fatherMiddleName',   null           ],
            ['Mother',   'motherLastName',   'motherFirstName',   'motherMiddleName',   null           ],
            ['Guardian', 'guardianLastName', 'guardianFirstName', 'guardianMiddleName', 'contactNumber'],
        ];

        foreach ($sets as [$relType, $lnKey, $fnKey, $mnKey, $ctKey]) {
            $fn = !empty($s[$fnKey]) ? $s[$fnKey] : null;
            $ln = !empty($s[$lnKey]) ? $s[$lnKey] : null;
            if (!$fn && !$ln) continue; // Nothing to write for this relationship

            $contact = ($ctKey && !empty($s[$ctKey])) ? $s[$ctKey] : null;

            $chk = $this->conn->prepare(
                "SELECT ParentGuardianID FROM parentguardian
                 WHERE StudentID = :sid AND RelationshipType = :rel LIMIT 1"
            );
            $chk->execute([':sid' => $studentID, ':rel' => $relType]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $this->conn->prepare("
                    UPDATE parentguardian
                    SET LastName = :ln, FirstName = :fn, MiddleName = :mn, ContactNumber = :ct
                    WHERE ParentGuardianID = :pgid
                ")->execute([
                    ':ln'   => $ln,
                    ':fn'   => $fn,
                    ':mn'   => !empty($s[$mnKey]) ? $s[$mnKey] : null,
                    ':ct'   => $contact,
                    ':pgid' => $existing['ParentGuardianID'],
                ]);
            } else {
                $this->conn->prepare("
                    INSERT INTO parentguardian
                        (StudentID, RelationshipType, LastName, FirstName, MiddleName, ContactNumber)
                    VALUES (:sid, :rel, :ln, :fn, :mn, :ct)
                ")->execute([
                    ':sid' => $studentID,
                    ':rel' => $relType,
                    ':ln'  => $ln,
                    ':fn'  => $fn,
                    ':mn'  => !empty($s[$mnKey]) ? $s[$mnKey] : null,
                    ':ct'  => $contact,
                ]);
            }
        }
    }

    // ──────────────────────────────────────────────
    // INSERT ENROLLMENT
    // Uses AcademicYearID (int FK) — not a year string.
    // Removed: AcademicYear, LearnerType, CreatedAt, ProcessedBy, ProcessedDate
    //          (not in enrollment table per revised schema)
    // ──────────────────────────────────────────────
    private function insertEnrollment($studentID, $s, $ayID) {
        $isSHS    = in_array((int)$s['gradeLevelID'], [5, 6]);
        $strandID = ($isSHS && !empty($s['strandID'])) ? (int)$s['strandID'] : null;

        $stmt = $this->conn->prepare("
            INSERT INTO enrollment
                (StudentID, GradeLevelID, StrandID, AcademicYearID, EnrollmentType, Status)
            VALUES
                (:sid, :gl, :strand, :ayid, :etype, 'Pending')
        ");
        $stmt->execute([
            ':sid'    => $studentID,
            ':gl'     => (int)$s['gradeLevelID'],
            ':strand' => $strandID,
            ':ayid'   => $ayID,
            ':etype'  => $s['enrollmentType'],
        ]);

        return (int)$this->conn->lastInsertId();
    }

    // ──────────────────────────────────────────────
    // AUDIT LOG
    // ──────────────────────────────────────────────
    private function writeAudit($table, $recordID, $action, $old, $new, $desc) {
        try {
            $this->conn->prepare("
                INSERT INTO auditlog
                    (TableName, RecordID, Action, OldValue, NewValue,
                     ChangedBy, ActionDescription, IPAddress)
                VALUES (:tbl, :rid, :act, :old, :new, :uid, :desc, :ip)
            ")->execute([
                ':tbl'  => $table,
                ':rid'  => $recordID,
                ':act'  => $action,
                ':old'  => $old,
                ':new'  => $new,
                ':uid'  => $this->actorID,
                ':desc' => $desc,
                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }

    private function fail($msg) {
        return ['success' => false, 'message' => $msg];
    }
}

// ── Router ─────────────────────────────────────────────────────
try {
    $db = (new Database())->getConnection();
    if (!$db) throw new Exception('Database connection failed');

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE)
        throw new Exception('Invalid JSON payload');

    echo json_encode((new BulkImportAPI($db))->processImport($input));

} catch (Exception $e) {
    http_response_code(500);
    error_log('enrollment-bulk-import.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>