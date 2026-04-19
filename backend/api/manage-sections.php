<?php
// ============================================
// FILE: backend/api/manage-sections.php
// Purpose: CRUD for sections + subject teacher assignment
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
                case 'list':               getSections($conn);           break;
                case 'advisers':           getAvailableAdvisers($conn);  break;
                case 'details':            getSectionDetails($conn);     break;
                case 'subject-teachers':   getSubjectTeachers($conn);    break;
                case 'teaching-employees': getTeachingEmployees($conn);  break;
                default: throw new Exception("Unknown GET action: $action");
            }
            break;

        case 'POST':
            switch ($action) {
                case 'create':                  createSection($conn);        break;
                case 'assign-subject-teacher':  assignSubjectTeacher($conn); break;
                default: throw new Exception("Unknown POST action: $action");
            }
            break;

        case 'PUT':
            if ($action === 'update') updateSection($conn);
            else throw new Exception("Unknown PUT action: $action");
            break;

        case 'DELETE':
            switch ($action) {
                case 'delete':                 deleteSection($conn);         break;
                case 'remove-subject-teacher': removeSubjectTeacher($conn);  break;
                default: throw new Exception("Unknown DELETE action: $action");
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ─── Helpers ───────────────────────────────────────────────────────────────

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

function enrollmentSubquery(): string
{
    return "(SELECT COUNT(*) FROM sectionassignment sa
             WHERE sa.SectionID = s.SectionID AND sa.IsActive = 1)";
}

// ============================================================
// GET: sections list
// ============================================================
function getSections(PDO $conn): void
{
    $yearParam   = $_GET['year']   ?? null;
    $gradeParam  = $_GET['grade']  ?? null;
    $strandParam = $_GET['strand'] ?? null;

    $enrSub = enrollmentSubquery();

    $sql = "SELECT
                s.SectionID,
                s.SectionName,
                s.Capacity,
                s.AcademicYearID,
                s.IsActive,
                s.StrandID,
                ay.YearLabel                        AS AcademicYear,
                gl.GradeLevelName,
                gl.GradeLevelNumber,
                st.StrandCode,
                st.StrandName,
                CONCAT(u.LastName, ', ', u.FirstName) AS AdviserName,
                s.AdviserID,
                $enrSub                              AS CurrentEnrollment,
                (s.Capacity - $enrSub)               AS AvailableSlots,
                CASE
                    WHEN $enrSub >= s.Capacity           THEN 'Full'
                    WHEN $enrSub >= (s.Capacity * 0.9)  THEN 'Nearing Full'
                    ELSE 'Open'
                END                                  AS Status
            FROM section s
            INNER JOIN academicyear ay ON ay.AcademicYearID = s.AcademicYearID
            INNER JOIN gradelevel   gl ON gl.GradeLevelID   = s.GradeLevelID
            LEFT JOIN  strand       st ON st.StrandID       = s.StrandID
            LEFT JOIN  user         u  ON u.UserID          = s.AdviserID
            WHERE s.IsActive = 1";

    $params = [];

    if ($yearParam) {
        $sql .= " AND ay.YearLabel = :year";
        $params[':year'] = $yearParam;
    }
    if ($gradeParam) {
        $sql .= " AND gl.GradeLevelNumber = :grade";
        $params[':grade'] = (int)$gradeParam;
    }
    if ($strandParam) {
        $sql .= " AND s.StrandID = :strand";
        $params[':strand'] = (int)$strandParam;
    }

    $sql .= " ORDER BY gl.GradeLevelNumber, s.SectionName";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ============================================================
// GET: available advisers
// ============================================================
function getAvailableAdvisers(PDO $conn): void
{
    $stmt = $conn->prepare(
        "SELECT UserID,
                CONCAT(LastName, ', ', FirstName) AS FullName,
                Role
         FROM user
         WHERE IsActive = 1
           AND Role IN ('Adviser','Key_Teacher','Subject_Teacher')
         ORDER BY LastName, FirstName"
    );
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'advisers' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ============================================================
// GET: teaching employees (alias — returns Subject_Teacher users)
// ============================================================
function getTeachingEmployees(PDO $conn): void
{
    $stmt = $conn->prepare(
        "SELECT UserID,
                CONCAT(LastName, ', ', FirstName) AS FullName,
                Username,
                Role
         FROM user
         WHERE IsActive = 1
           AND Role = 'Subject_Teacher'
         ORDER BY LastName, FirstName"
    );
    $stmt->execute();

    echo json_encode([
        'success'   => true,
        'employees' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ============================================================
// GET: subject teachers assigned to a section
// ============================================================
function getSubjectTeachers(PDO $conn): void
{
    $sectionId = isset($_GET['sectionId']) ? (int)$_GET['sectionId'] : null;
    if (!$sectionId) throw new Exception('Section ID is required');

    $stmt = $conn->prepare(
        "SELECT
             ta.TeacherAssignmentID,
             ta.UserID,
             CONCAT(u.LastName, ', ', u.FirstName) AS FullName,
             u.Username,
             ta.SubjectCode,
             ta.SubjectName,
             ta.IsActive
         FROM teacherassignment ta
         INNER JOIN user u ON u.UserID = ta.UserID
         WHERE ta.SectionID = :sectionId
           AND ta.AssignmentType = 'Subject_Teacher'
           AND ta.IsActive = 1
         ORDER BY u.LastName, u.FirstName"
    );
    $stmt->bindValue(':sectionId', $sectionId, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'teachers' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ============================================================
// POST: assign subject teacher to a section
// ============================================================
function assignSubjectTeacher(PDO $conn): void
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['sectionId']))   throw new Exception('Section ID is required');
    if (empty($data['userId']))      throw new Exception('User ID is required');
    if (empty($data['subjectName'])) throw new Exception('Subject name is required');

    $sectionId   = (int)$data['sectionId'];
    $userId      = (int)$data['userId'];
    $subjectCode = trim($data['subjectCode'] ?? '');
    $subjectName = trim($data['subjectName']);

    // Verify section exists
    $chkSection = $conn->prepare("SELECT SectionID FROM section WHERE SectionID = :id AND IsActive = 1");
    $chkSection->bindValue(':id', $sectionId, PDO::PARAM_INT);
    $chkSection->execute();
    if (!$chkSection->fetch()) throw new Exception('Section not found');

    // Verify user exists and is a Subject_Teacher
    $chkUser = $conn->prepare("SELECT UserID FROM user WHERE UserID = :id AND Role = 'Subject_Teacher' AND IsActive = 1");
    $chkUser->bindValue(':id', $userId, PDO::PARAM_INT);
    $chkUser->execute();
    if (!$chkUser->fetch()) throw new Exception('User not found or is not an active Subject Teacher');

    // Check for duplicate (same teacher + section + subject)
    $chkDupe = $conn->prepare(
        "SELECT TeacherAssignmentID FROM teacherassignment
         WHERE UserID = :userId AND SectionID = :sectionId
           AND SubjectName = :subjectName AND IsActive = 1"
    );
    $chkDupe->bindValue(':userId',      $userId,      PDO::PARAM_INT);
    $chkDupe->bindValue(':sectionId',   $sectionId,   PDO::PARAM_INT);
    $chkDupe->bindValue(':subjectName', $subjectName);
    $chkDupe->execute();
    if ($chkDupe->fetch()) {
        throw new Exception('This teacher is already assigned to this subject in this section');
    }

    $stmt = $conn->prepare(
        "INSERT INTO teacherassignment
             (UserID, SectionID, AssignmentType, SubjectCode, SubjectName, IsActive)
         VALUES
             (:userId, :sectionId, 'Subject_Teacher', :subjectCode, :subjectName, 1)"
    );
    $stmt->bindValue(':userId',      $userId,      PDO::PARAM_INT);
    $stmt->bindValue(':sectionId',   $sectionId,   PDO::PARAM_INT);
    $stmt->bindValue(':subjectCode', $subjectCode ?: null);
    $stmt->bindValue(':subjectName', $subjectName);
    $stmt->execute();

    echo json_encode([
        'success'              => true,
        'message'              => 'Subject teacher assigned successfully',
        'teacherAssignmentId'  => (int)$conn->lastInsertId(),
    ]);
}

// ============================================================
// DELETE: remove subject teacher assignment (soft delete)
// ============================================================
function removeSubjectTeacher(PDO $conn): void
{
    $assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$assignmentId) throw new Exception('Assignment ID is required');

    $stmt = $conn->prepare(
        "UPDATE teacherassignment SET IsActive = 0
         WHERE TeacherAssignmentID = :id"
    );
    $stmt->bindValue(':id', $assignmentId, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        throw new Exception('Assignment not found');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Subject teacher assignment removed successfully',
    ]);
}

// ============================================================
// GET: single section details
// ============================================================
function getSectionDetails(PDO $conn): void
{
    $sectionId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$sectionId) throw new Exception('Section ID is required');

    $enrSub = enrollmentSubquery();

    $stmt = $conn->prepare(
        "SELECT
             s.SectionID,
             s.SectionName,
             s.Capacity,
             s.AcademicYearID,
             s.GradeLevelID,
             s.StrandID,
             s.AdviserID,
             s.IsActive,
             ay.YearLabel                          AS AcademicYear,
             gl.GradeLevelName,
             gl.GradeLevelNumber,
             st.StrandCode,
             st.StrandName,
             CONCAT(u.LastName, ', ', u.FirstName)  AS AdviserName,
             $enrSub                                AS CurrentEnrollment,
             (s.Capacity - $enrSub)                 AS AvailableSlots
         FROM section s
         INNER JOIN academicyear ay ON ay.AcademicYearID = s.AcademicYearID
         INNER JOIN gradelevel   gl ON gl.GradeLevelID   = s.GradeLevelID
         LEFT JOIN  strand       st ON st.StrandID       = s.StrandID
         LEFT JOIN  user         u  ON u.UserID          = s.AdviserID
         WHERE s.SectionID = :id"
    );
    $stmt->bindValue(':id', $sectionId, PDO::PARAM_INT);
    $stmt->execute();

    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$section) throw new Exception('Section not found');

    echo json_encode(['success' => true, 'section' => $section]);
}

// ============================================================
// POST: create section
// ============================================================
function createSection(PDO $conn): void
{
    $data = json_decode(file_get_contents('php://input'), true);

    foreach (['sectionName', 'gradeLevelId', 'academicYear', 'capacity', 'adviserUserId'] as $f) {
        if (empty($data[$f])) throw new Exception("Field '$f' is required");
    }

    $ayID = resolveAcademicYearID($conn, $data['academicYear']);

    $chk = $conn->prepare(
        "SELECT SectionID FROM section
         WHERE SectionName = :name AND AcademicYearID = :ayID AND IsActive = 1"
    );
    $chk->bindValue(':name', $data['sectionName']);
    $chk->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    $chk->execute();
    if ($chk->fetch()) throw new Exception('Section name already exists for this academic year');

    $stmt = $conn->prepare(
        "INSERT INTO section
             (SectionName, GradeLevelID, StrandID, AdviserID, Capacity, AcademicYearID, IsActive)
         VALUES
             (:name, :gradeLevel, :strand, :adviserID, :capacity, :ayID, 1)"
    );
    $stmt->bindValue(':name',      $data['sectionName']);
    $stmt->bindValue(':gradeLevel',(int)$data['gradeLevelId'],  PDO::PARAM_INT);
    $stmt->bindValue(':strand',    !empty($data['strandId']) ? (int)$data['strandId'] : null);
    $stmt->bindValue(':adviserID', (int)$data['adviserUserId'], PDO::PARAM_INT);
    $stmt->bindValue(':capacity',  (int)$data['capacity'],      PDO::PARAM_INT);
    $stmt->bindValue(':ayID',      $ayID,                       PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'   => true,
        'message'   => 'Section created successfully',
        'sectionId' => (int)$conn->lastInsertId(),
    ]);
}

// ============================================================
// PUT: update section
// ============================================================
function updateSection(PDO $conn): void
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['sectionId']))    throw new Exception('Section ID is required');
    if (empty($data['adviserUserId'])) throw new Exception('Adviser is required');

    $ayID = resolveAcademicYearID($conn, $data['academicYear']);

    $stmt = $conn->prepare(
        "UPDATE section SET
             SectionName    = :name,
             GradeLevelID   = :gradeLevel,
             StrandID       = :strand,
             AdviserID      = :adviserID,
             Capacity       = :capacity,
             AcademicYearID = :ayID
         WHERE SectionID = :id"
    );
    $stmt->bindValue(':name',      $data['sectionName']);
    $stmt->bindValue(':gradeLevel',(int)$data['gradeLevelId'],  PDO::PARAM_INT);
    $stmt->bindValue(':strand',    !empty($data['strandId']) ? (int)$data['strandId'] : null);
    $stmt->bindValue(':adviserID', (int)$data['adviserUserId'], PDO::PARAM_INT);
    $stmt->bindValue(':capacity',  (int)$data['capacity'],      PDO::PARAM_INT);
    $stmt->bindValue(':ayID',      $ayID,                       PDO::PARAM_INT);
    $stmt->bindValue(':id',        (int)$data['sectionId'],     PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Section updated successfully']);
}

// ============================================================
// DELETE: soft-delete section
// ============================================================
function deleteSection(PDO $conn): void
{
    $sectionId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$sectionId) throw new Exception('Section ID is required');

    $chk = $conn->prepare(
        "SELECT COUNT(*) FROM sectionassignment WHERE SectionID = :id AND IsActive = 1"
    );
    $chk->bindValue(':id', $sectionId, PDO::PARAM_INT);
    $chk->execute();
    if ((int)$chk->fetchColumn() > 0) {
        throw new Exception('Cannot delete a section that has assigned students');
    }

    $stmt = $conn->prepare("UPDATE section SET IsActive = 0 WHERE SectionID = :id");
    $stmt->bindValue(':id', $sectionId, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Section deleted successfully']);
}
?>