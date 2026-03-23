<?php
// ============================================
// FILE: backend/api/section-assignments.php
// Purpose: Handle student-to-section assignments
// Updated: 2026-03-04 — Revised for normalized DB
//   - enrollment.AcademicYear (string) → AcademicYearID (FK int) via academicyear JOIN
//   - enrollment.LearnerType removed; EnrollmentType used instead
//   - sectionassignment has NO StudentID column; all joins/inserts go via EnrollmentID
//   - section.CurrentEnrollment removed (not stored); computed via COUNT(sectionassignment)
//   - section.AdviserEmployeeID / employee table removed; adviser via user only
//   - All UPDATE section SET CurrentEnrollment removed (column doesn't exist)
//   - section.CreatedAt does not exist; ORDER BY uses SectionID ASC instead
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn     = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            switch ($action) {
                case 'unassigned':             getUnassignedStudents($conn); break;
                case 'section-students':       getSectionStudents($conn);    break;
                case 'sections-with-students': getSectionsWithStudents($conn); break;
                default: throw new Exception("Unknown GET action: $action");
            }
            break;

        case 'POST':
            switch ($action) {
                case 'assign':        assignStudent($conn);       break;
                case 'auto-assign':   autoAssignStudents($conn);  break;
                case 'clear-section': clearSection($conn);        break;
                default: throw new Exception("Unknown POST action: $action");
            }
            break;

        case 'DELETE':
            if ($action === 'unassign') unassignStudent($conn);
            else throw new Exception("Unknown DELETE action: $action");
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ─── Shared helper ──────────────────────────────────────────────────────────
function resolveAcademicYearID(PDO $conn, string $yearLabel): int
{
    $stmt = $conn->prepare(
        "SELECT AcademicYearID FROM academicyear WHERE YearLabel = :yl LIMIT 1"
    );
    $stmt->bindValue(':yl', $yearLabel);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception("Academic year '$yearLabel' not found.");
    return (int)$row['AcademicYearID'];
}

// ─── Live enrollment count for a single section (no stored column) ──────────
function getSectionEnrollmentCount(PDO $conn, int $sectionId): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM sectionassignment WHERE SectionID = :id AND IsActive = 1"
    );
    $stmt->bindValue(':id', $sectionId, PDO::PARAM_INT);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

// ============================================================
// GET: unassigned students
// ============================================================
function getUnassignedStudents(PDO $conn): void
{
    $yearParam   = $_GET['year']   ?? null;
    $gradeParam  = $_GET['grade']  ?? null;
    $strandParam = $_GET['strand'] ?? null;
    $searchParam = $_GET['search'] ?? null;

    if (!$yearParam || !$gradeParam) {
        throw new Exception('Academic year and grade level are required');
    }

    $ayID = resolveAcademicYearID($conn, $yearParam);

    // Students enrolled in this year/grade who have NO active sectionassignment
    // for their enrollment record.
    // NOTE: sectionassignment has no StudentID — NOT EXISTS uses EnrollmentID only.
    // NOTE: EnrollmentType aliased as EnrollmentType (not LearnerType) to match frontend.
    $sql = "SELECT DISTINCT
                s.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName,
                    IFNULL(CONCAT(' ', s.MiddleName), '')) AS StudentName,
                s.Gender,
                s.IsPWD,
                s.Municipality,
                s.Barangay,
                e.EnrollmentID,
                e.EnrollmentType,
                e.StrandID,
                gl.GradeLevelName,
                st.StrandCode,
                st.StrandName
            FROM student s
            INNER JOIN enrollment  e  ON e.StudentID     = s.StudentID
            INNER JOIN gradelevel  gl ON gl.GradeLevelID = e.GradeLevelID
            LEFT JOIN  strand      st ON st.StrandID     = e.StrandID
            WHERE e.AcademicYearID      = :ayID
              AND gl.GradeLevelNumber   = :grade
              AND e.Status IN ('Confirmed', 'Pending')
              AND NOT EXISTS (
                  SELECT 1 FROM sectionassignment sa
                  WHERE sa.EnrollmentID = e.EnrollmentID
                    AND sa.IsActive = 1
              )";

    $params = [':ayID' => $ayID, ':grade' => (int)$gradeParam];

    if ($strandParam) {
        $sql .= " AND e.StrandID = :strand";
        $params[':strand'] = (int)$strandParam;
    }

    if ($searchParam) {
        $sql .= " AND (
                     s.LRN        LIKE :search
                  OR s.FirstName  LIKE :search
                  OR s.LastName   LIKE :search
                  OR CONCAT(s.LastName, ', ', s.FirstName) LIKE :search
                 )";
        $params[':search'] = '%' . $searchParam . '%';
    }

    $sql .= " ORDER BY e.EnrollmentDate ASC, s.LastName, s.FirstName";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'students' => $students,
        'count'    => count($students),
    ]);
}

// ============================================================
// GET: students assigned to a specific section
// sectionassignment has no StudentID — join via enrollment.
// ============================================================
function getSectionStudents(PDO $conn): void
{
    $sectionId = $_GET['sectionId'] ?? null;
    if (!$sectionId) throw new Exception('Section ID is required');

    $stmt = $conn->prepare(
        "SELECT
             s.StudentID,
             s.LRN,
             CONCAT(s.LastName, ', ', s.FirstName,
                 IFNULL(CONCAT(' ', s.MiddleName), '')) AS StudentName,
             s.Gender,
             s.IsPWD,
             sa.AssignmentID,
             sa.AssignmentMethod,
             sa.AssignmentDate
         FROM sectionassignment sa
         INNER JOIN enrollment e ON e.EnrollmentID = sa.EnrollmentID
         INNER JOIN student    s ON s.StudentID    = e.StudentID
         WHERE sa.SectionID = :sectionId
           AND sa.IsActive  = 1
         ORDER BY s.LastName, s.FirstName"
    );
    $stmt->bindValue(':sectionId', (int)$sectionId, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'students' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ============================================================
// GET: sections with their computed enrollment counts
// NOTE: section table has no CreatedAt column — ORDER BY SectionID ASC.
// ============================================================
function getSectionsWithStudents(PDO $conn): void
{
    $yearParam   = $_GET['year']   ?? null;
    $gradeParam  = $_GET['grade']  ?? null;
    $strandParam = $_GET['strand'] ?? null;

    if (!$yearParam || !$gradeParam) {
        throw new Exception('Academic year and grade level are required');
    }

    $ayID = resolveAcademicYearID($conn, $yearParam);

    $sql = "SELECT
                sec.SectionID,
                sec.SectionName,
                sec.Capacity,
                sec.StrandID,
                gl.GradeLevelName,
                st.StrandCode,
                st.StrandName,
                CONCAT(u.LastName, ', ', u.FirstName)      AS AdviserName,
                -- CurrentEnrollment computed from live sectionassignment rows
                (SELECT COUNT(*) FROM sectionassignment sa
                 WHERE sa.SectionID = sec.SectionID AND sa.IsActive = 1) AS CurrentEnrollment,
                sec.Capacity -
                (SELECT COUNT(*) FROM sectionassignment sa
                 WHERE sa.SectionID = sec.SectionID AND sa.IsActive = 1) AS AvailableSlots
            FROM section sec
            INNER JOIN gradelevel gl ON gl.GradeLevelID = sec.GradeLevelID
            LEFT JOIN  strand     st ON st.StrandID     = sec.StrandID
            LEFT JOIN  user       u  ON u.UserID        = sec.AdviserID
            WHERE sec.AcademicYearID  = :ayID
              AND gl.GradeLevelNumber = :grade
              AND sec.IsActive        = 1";

    $params = [':ayID' => $ayID, ':grade' => (int)$gradeParam];

    if ($strandParam) {
        $sql .= " AND sec.StrandID = :strand";
        $params[':strand'] = (int)$strandParam;
    }

    // section has no CreatedAt — use SectionID (AUTO_INCREMENT) as creation order proxy
    $sql .= " ORDER BY sec.SectionID ASC, sec.SectionName ASC";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ============================================================
// POST: manually assign one student to a section
// ============================================================
function assignStudent(PDO $conn): void
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['studentId']) || empty($data['sectionId']) || empty($data['enrollmentId'])) {
        throw new Exception('studentId, sectionId, and enrollmentId are required');
    }

    $userId       = $data['assignedBy'] ?? null;
    $sectionId    = (int)$data['sectionId'];
    $enrollmentId = (int)$data['enrollmentId'];

    // ── Capacity check (computed — no stored CurrentEnrollment) ─────────────
    $stmt = $conn->prepare(
        "SELECT sec.Capacity, sec.StrandID FROM section sec WHERE sec.SectionID = :id"
    );
    $stmt->bindValue(':id', $sectionId, PDO::PARAM_INT);
    $stmt->execute();
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$section) throw new Exception('Section not found');

    $currentCount = getSectionEnrollmentCount($conn, $sectionId);
    if ($currentCount >= (int)$section['Capacity']) {
        throw new Exception('Section is already full');
    }

    // ── Enrollment / strand check ────────────────────────────────────────────
    $stmt = $conn->prepare(
        "SELECT e.StrandID, e.GradeLevelID, e.StudentID
         FROM enrollment e WHERE e.EnrollmentID = :eid"
    );
    $stmt->bindValue(':eid', $enrollmentId, PDO::PARAM_INT);
    $stmt->execute();
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) throw new Exception('Enrollment not found');

    if ($section['StrandID'] !== null && $enrollment['StrandID'] !== null) {
        if ((int)$section['StrandID'] !== (int)$enrollment['StrandID']) {
            throw new Exception('Student strand does not match section strand');
        }
    }

    // ── Already assigned? (via EnrollmentID — no StudentID on sectionassignment) ─
    $stmt = $conn->prepare(
        "SELECT AssignmentID FROM sectionassignment
         WHERE EnrollmentID = :eid AND IsActive = 1 LIMIT 1"
    );
    $stmt->bindValue(':eid', $enrollmentId, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->fetch()) throw new Exception('Student is already assigned to a section');

    $conn->beginTransaction();
    try {
        // Re-activate an old inactive record for this enrollment+section if one exists
        $stmt = $conn->prepare(
            "SELECT AssignmentID FROM sectionassignment
             WHERE EnrollmentID = :eid AND SectionID = :sid AND IsActive = 0 LIMIT 1"
        );
        $stmt->bindValue(':eid', $enrollmentId, PDO::PARAM_INT);
        $stmt->bindValue(':sid', $sectionId,    PDO::PARAM_INT);
        $stmt->execute();
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($old) {
            $stmt = $conn->prepare(
                "UPDATE sectionassignment
                 SET IsActive = 1, AssignmentDate = NOW(),
                     AssignmentMethod = 'Manual', AssignedBy = :by
                 WHERE AssignmentID = :aid"
            );
            $stmt->bindValue(':by',  $userId);
            $stmt->bindValue(':aid', (int)$old['AssignmentID'], PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO sectionassignment
                     (SectionID, EnrollmentID, AssignmentMethod, AssignedBy, IsActive)
                 VALUES (:sid, :eid, 'Manual', :by, 1)"
            );
            $stmt->bindValue(':sid', $sectionId,    PDO::PARAM_INT);
            $stmt->bindValue(':eid', $enrollmentId, PDO::PARAM_INT);
            $stmt->bindValue(':by',  $userId);
            $stmt->execute();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Student assigned successfully']);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

// ============================================================
// POST: auto-assign (FCFS)
// NOTE: section table has no CreatedAt column — ORDER BY SectionID ASC.
// ============================================================
function autoAssignStudents(PDO $conn): void
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['academicYear']) || empty($data['gradeLevel'])) {
        throw new Exception('academicYear and gradeLevel are required');
    }

    $userId   = $data['assignedBy'] ?? null;
    $strandId = !empty($data['strandId']) ? (int)$data['strandId'] : null;

    $ayID = resolveAcademicYearID($conn, $data['academicYear']);

    $assignmentResults = [
        'assigned' => [],
        'failed'   => [
            'strand_mismatch'       => [],
            'all_sections_full'     => [],
            'no_available_sections' => [],
            'database_error'        => [],
        ],
    ];

    try {
        $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        $conn->beginTransaction();

        // ── Lock sections ────────────────────────────────────────────────────
        // section has no CreatedAt — ORDER BY SectionID ASC (insertion order proxy)
        $secSql = "SELECT
                       sec.SectionID,
                       sec.Capacity,
                       sec.StrandID,
                       sec.SectionName,
                       (SELECT COUNT(*) FROM sectionassignment sa
                        WHERE sa.SectionID = sec.SectionID AND sa.IsActive = 1) AS CurrentEnrollment
                   FROM section sec
                   WHERE sec.AcademicYearID = :ayID
                     AND sec.GradeLevelID   = (SELECT GradeLevelID FROM gradelevel
                                               WHERE GradeLevelNumber = :grade LIMIT 1)
                     AND sec.IsActive       = 1";
        if ($strandId) $secSql .= " AND sec.StrandID = :strand";
        $secSql .= " ORDER BY sec.SectionID ASC, sec.SectionName ASC FOR UPDATE";

        $stmt = $conn->prepare($secSql);
        $stmt->bindValue(':ayID',  $ayID,                    PDO::PARAM_INT);
        $stmt->bindValue(':grade', (int)$data['gradeLevel'], PDO::PARAM_INT);
        if ($strandId) $stmt->bindValue(':strand', $strandId, PDO::PARAM_INT);
        $stmt->execute();
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sections)) {
            $conn->rollBack();
            $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            throw new Exception('No sections available for this grade level');
        }

        // ── Unassigned students (FCFS order) ─────────────────────────────────
        $stuSql = "SELECT DISTINCT
                       s.StudentID,
                       s.LRN,
                       CONCAT(s.LastName, ', ', s.FirstName,
                           IFNULL(CONCAT(' ', s.MiddleName), '')) AS StudentName,
                       e.EnrollmentID,
                       e.StrandID,
                       st.StrandCode
                   FROM student    s
                   INNER JOIN enrollment  e  ON e.StudentID     = s.StudentID
                   INNER JOIN gradelevel  gl ON gl.GradeLevelID = e.GradeLevelID
                   LEFT JOIN  strand      st ON st.StrandID     = e.StrandID
                   WHERE e.AcademicYearID    = :ayID
                     AND gl.GradeLevelNumber = :grade
                     AND e.Status IN ('Confirmed', 'Pending')
                     AND NOT EXISTS (
                         SELECT 1 FROM sectionassignment sa
                         WHERE sa.EnrollmentID = e.EnrollmentID AND sa.IsActive = 1
                     )";
        if ($strandId) $stuSql .= " AND e.StrandID = :strand";
        $stuSql .= " ORDER BY e.EnrollmentDate ASC";

        $stmt = $conn->prepare($stuSql);
        $stmt->bindValue(':ayID',  $ayID,                    PDO::PARAM_INT);
        $stmt->bindValue(':grade', (int)$data['gradeLevel'], PDO::PARAM_INT);
        if ($strandId) $stmt->bindValue(':strand', $strandId, PDO::PARAM_INT);
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            $conn->rollBack();
            $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            throw new Exception('No unassigned students found');
        }

        // ── Assign each student (FCFS) ────────────────────────────────────────
        foreach ($students as $student) {
            $assigned            = false;
            $availableSections   = 0;
            $matchingSections    = 0;
            $strandMatchFailures = [];

            for ($i = 0; $i < count($sections); $i++) {
                $section = $sections[$i];

                if ((int)$section['CurrentEnrollment'] < (int)$section['Capacity']) {
                    $availableSections++;
                }

                if (!checkStrandCompatibility($section, $student)) {
                    $strandMatchFailures[] = [
                        'sectionName'   => $section['SectionName'],
                        'reason'        => 'strand_mismatch',
                        'sectionStrand' => $section['StrandID'],
                        'studentStrand' => $student['StrandID'],
                    ];
                    continue;
                }

                $matchingSections++;

                if ((int)$section['CurrentEnrollment'] >= (int)$section['Capacity']) {
                    $strandMatchFailures[] = [
                        'sectionName' => $section['SectionName'],
                        'reason'      => 'full',
                    ];
                    continue;
                }

                try {
                    assignStudentToSection($conn, $student, $section, $userId);
                    $sections[$i]['CurrentEnrollment']++;

                    $assignmentResults['assigned'][] = [
                        'studentId'   => $student['StudentID'],
                        'studentName' => $student['StudentName'],
                        'lrn'         => $student['LRN'],
                        'sectionId'   => $section['SectionID'],
                        'sectionName' => $section['SectionName'],
                    ];
                    $assigned = true;
                    break;
                } catch (Exception $e) {
                    $assignmentResults['failed']['database_error'][] = [
                        'studentId'   => $student['StudentID'],
                        'studentName' => $student['StudentName'],
                        'lrn'         => $student['LRN'],
                        'error'       => $e->getMessage(),
                    ];
                    $assigned = true; // processed — move on
                    break;
                }
            }

            if (!$assigned) {
                if ($matchingSections === 0) {
                    $assignmentResults['failed']['strand_mismatch'][] = [
                        'studentId'         => $student['StudentID'],
                        'studentName'       => $student['StudentName'],
                        'lrn'               => $student['LRN'],
                        'studentStrand'     => $student['StrandCode'] ?: 'None (JHS)',
                        'availableSections' => count($sections),
                        'details'           => $strandMatchFailures,
                    ];
                } elseif ($availableSections === 0) {
                    $assignmentResults['failed']['no_available_sections'][] = [
                        'studentId'       => $student['StudentID'],
                        'studentName'     => $student['StudentName'],
                        'lrn'             => $student['LRN'],
                        'matchingSections'=> $matchingSections,
                        'reason'          => 'All sections in the system are full',
                    ];
                } else {
                    $assignmentResults['failed']['all_sections_full'][] = [
                        'studentId'         => $student['StudentID'],
                        'studentName'       => $student['StudentName'],
                        'lrn'               => $student['LRN'],
                        'matchingSections'  => $matchingSections,
                        'availableSections' => $availableSections,
                        'reason'            => 'All matching sections for this strand are full',
                    ];
                }
            }
        }

        $conn->commit();
        $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");

        $totalFailed =
            count($assignmentResults['failed']['strand_mismatch']) +
            count($assignmentResults['failed']['all_sections_full']) +
            count($assignmentResults['failed']['no_available_sections']) +
            count($assignmentResults['failed']['database_error']);

        echo json_encode([
            'success' => true,
            'summary' => [
                'totalStudents' => count($students),
                'assigned'      => count($assignmentResults['assigned']),
                'failed'        => $totalFailed,
            ],
            'details' => [
                'assigned' => $assignmentResults['assigned'],
                'failures' => [
                    'strandMismatch' => [
                        'count'    => count($assignmentResults['failed']['strand_mismatch']),
                        'students' => $assignmentResults['failed']['strand_mismatch'],
                    ],
                    'sectionsFull' => [
                        'count'    => count($assignmentResults['failed']['all_sections_full']),
                        'students' => $assignmentResults['failed']['all_sections_full'],
                    ],
                    'noAvailableSections' => [
                        'count'    => count($assignmentResults['failed']['no_available_sections']),
                        'students' => $assignmentResults['failed']['no_available_sections'],
                    ],
                    'databaseErrors' => [
                        'count'    => count($assignmentResults['failed']['database_error']),
                        'students' => $assignmentResults['failed']['database_error'],
                    ],
                ],
            ],
            'message' => buildDetailedMessage($assignmentResults, count($students)),
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        throw $e;
    }
}

// ─── Strand compatibility helper ─────────────────────────────────────────────
function checkStrandCompatibility(array $section, array $student): bool
{
    $ss = $section['StrandID'] !== null ? (int)$section['StrandID'] : null;
    $es = $student['StrandID'] !== null ? (int)$student['StrandID'] : null;

    if ($ss === null && $es === null) return true;  // both JHS — no strand required
    if ($ss !== null && $es !== null) return $ss === $es; // both SHS — must match
    return false; // one has a strand, the other doesn't — incompatible
}

// ─── Insert / reactivate one assignment row ───────────────────────────────────
// sectionassignment columns: SectionID, EnrollmentID, AssignmentMethod, AssignedBy, IsActive
// (NO StudentID column on sectionassignment)
function assignStudentToSection(PDO $conn, array $student, array $section, $userId): void
{
    $eid = (int)$student['EnrollmentID'];
    $sid = (int)$section['SectionID'];

    // Reactivate existing inactive record if present (avoids duplicate rows)
    $stmt = $conn->prepare(
        "SELECT AssignmentID FROM sectionassignment
         WHERE EnrollmentID = :eid AND SectionID = :sid AND IsActive = 0 LIMIT 1"
    );
    $stmt->bindValue(':eid', $eid, PDO::PARAM_INT);
    $stmt->bindValue(':sid', $sid, PDO::PARAM_INT);
    $stmt->execute();
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($old) {
        $stmt = $conn->prepare(
            "UPDATE sectionassignment
             SET IsActive = 1, AssignmentDate = NOW(),
                 AssignmentMethod = 'Automatic', AssignedBy = :by
             WHERE AssignmentID = :aid"
        );
        $stmt->bindValue(':by',  $userId);
        $stmt->bindValue(':aid', (int)$old['AssignmentID'], PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO sectionassignment
                 (SectionID, EnrollmentID, AssignmentMethod, AssignedBy, IsActive)
             VALUES (:sid, :eid, 'Automatic', :by, 1)"
        );
        $stmt->bindValue(':sid', $sid, PDO::PARAM_INT);
        $stmt->bindValue(':eid', $eid, PDO::PARAM_INT);
        $stmt->bindValue(':by',  $userId);
        $stmt->execute();
    }
}

// ─── Build human-readable summary message ────────────────────────────────────
function buildDetailedMessage(array $results, int $total): string
{
    $a = count($results['assigned']);
    $f = count($results['failed']['strand_mismatch'])
       + count($results['failed']['all_sections_full'])
       + count($results['failed']['no_available_sections'])
       + count($results['failed']['database_error']);

    $msg = "Auto-assignment completed: $a of $total students assigned successfully.";
    if ($f > 0) {
        $msg .= "\n\n$f student(s) could not be assigned:";
        if (count($results['failed']['strand_mismatch']) > 0)
            $msg .= "\n• " . count($results['failed']['strand_mismatch']) . " due to strand mismatch";
        if (count($results['failed']['all_sections_full']) > 0)
            $msg .= "\n• " . count($results['failed']['all_sections_full']) . " because all matching sections are full";
        if (count($results['failed']['no_available_sections']) > 0)
            $msg .= "\n• " . count($results['failed']['no_available_sections']) . " because no sections have space";
        if (count($results['failed']['database_error']) > 0)
            $msg .= "\n• " . count($results['failed']['database_error']) . " due to database errors";
    }
    return $msg;
}

// ============================================================
// DELETE: unassign one student from their section
// ============================================================
function unassignStudent(PDO $conn): void
{
    $assignmentId = $_GET['id'] ?? null;
    if (!$assignmentId) throw new Exception('Assignment ID is required');

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "SELECT AssignmentID, SectionID, EnrollmentID
             FROM sectionassignment WHERE AssignmentID = :id AND IsActive = 1"
        );
        $stmt->bindValue(':id', (int)$assignmentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Active assignment not found');

        // Soft-delete; if an inactive record for this enrollment already exists,
        // hard-delete the current row to avoid a unique constraint violation on re-assignment.
        $stmt = $conn->prepare(
            "SELECT AssignmentID FROM sectionassignment
             WHERE EnrollmentID = :eid AND IsActive = 0 LIMIT 1"
        );
        $stmt->bindValue(':eid', (int)$row['EnrollmentID'], PDO::PARAM_INT);
        $stmt->execute();
        $inactiveExists = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($inactiveExists) {
            $op = $conn->prepare("DELETE FROM sectionassignment WHERE AssignmentID = :id");
        } else {
            $op = $conn->prepare("UPDATE sectionassignment SET IsActive = 0 WHERE AssignmentID = :id");
        }
        $op->bindValue(':id', (int)$assignmentId, PDO::PARAM_INT);
        $op->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Student unassigned successfully']);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

// ============================================================
// POST: clear all students from a section
// ============================================================
function clearSection(PDO $conn): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['sectionId'])) throw new Exception('sectionId is required');

    $sectionId = (int)$data['sectionId'];

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "SELECT AssignmentID, EnrollmentID
             FROM sectionassignment WHERE SectionID = :sid AND IsActive = 1"
        );
        $stmt->bindValue(':sid', $sectionId, PDO::PARAM_INT);
        $stmt->execute();
        $assignments  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $removedCount = count($assignments);

        foreach ($assignments as $assignment) {
            // Hard-delete if an inactive record for this enrollment already exists
            $chk = $conn->prepare(
                "SELECT AssignmentID FROM sectionassignment
                 WHERE EnrollmentID = :eid AND IsActive = 0 LIMIT 1"
            );
            $chk->bindValue(':eid', (int)$assignment['EnrollmentID'], PDO::PARAM_INT);
            $chk->execute();

            if ($chk->fetch()) {
                $op = $conn->prepare("DELETE FROM sectionassignment WHERE AssignmentID = :aid");
            } else {
                $op = $conn->prepare("UPDATE sectionassignment SET IsActive = 0 WHERE AssignmentID = :aid");
            }
            $op->bindValue(':aid', (int)$assignment['AssignmentID'], PDO::PARAM_INT);
            $op->execute();
        }

        $conn->commit();
        echo json_encode([
            'success'      => true,
            'message'      => "Removed $removedCount student(s) from section",
            'removedCount' => $removedCount,
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
?>