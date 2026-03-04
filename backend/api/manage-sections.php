<?php
// ============================================
// FILE: backend/api/manage-sections.php
// Purpose: CRUD for sections
// Updated: 2026-03-04 — Revised for normalized DB
//   - section.AcademicYear (string) → section.AcademicYearID (FK int)
//   - section.CurrentEnrollment removed (computed via sectionassignment)
//   - section.AdviserEmployeeID removed (not in schema)
//   - employee table removed (not in schema); advisers come from user table
//   - teacherassignment table removed (not in schema); stubs return gracefully
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
                case 'create':                  createSection($conn);         break;
                case 'assign-subject-teacher':  assignSubjectTeacher($conn);  break;
                default: throw new Exception("Unknown POST action: $action");
            }
            break;

        case 'PUT':
            if ($action === 'update') updateSection($conn);
            else throw new Exception("Unknown PUT action: $action");
            break;

        case 'DELETE':
            switch ($action) {
                case 'delete':                 deleteSection($conn);          break;
                case 'remove-subject-teacher': removeSubjectTeacher($conn);   break;
                default: throw new Exception("Unknown DELETE action: $action");
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ─── Shared helper ─────────────────────────────────────────────────────────
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

// ─── Computed enrollment subquery (reused across all queries) ───────────────
// Returns the COUNT of active sectionassignment rows per section.
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
// GET: available advisers (from user table only)
// ============================================================
function getAvailableAdvisers(PDO $conn): void
{
    // Advisers are any active users who can be assigned as section advisers.
    // Roles that may serve as adviser: Adviser, Key_Teacher, Subject_Teacher.
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
// GET: teaching employees — employee table not in current schema.
//      Return empty list with a notice so the UI degrades gracefully.
// ============================================================
function getTeachingEmployees(PDO $conn): void
{
    echo json_encode([
        'success'   => true,
        'employees' => [],
        'notice'    => 'Employee records are not yet available in the system.',
    ]);
}

// ============================================================
// GET: subject teachers — teacherassignment table not in schema.
//      Return empty list gracefully.
// ============================================================
function getSubjectTeachers(PDO $conn): void
{
    $sectionId = $_GET['sectionId'] ?? null;
    if (!$sectionId) throw new Exception('Section ID is required');

    echo json_encode([
        'success'  => true,
        'teachers' => [],
        'notice'   => 'Subject teacher assignments are not yet available in the system.',
    ]);
}

// ============================================================
// POST: assign subject teacher — stub (table not in schema)
// ============================================================
function assignSubjectTeacher(PDO $conn): void
{
    echo json_encode([
        'success' => false,
        'message' => 'Subject teacher assignments are not yet supported in the current database version.',
    ]);
}

// ============================================================
// DELETE: remove subject teacher — stub (table not in schema)
// ============================================================
function removeSubjectTeacher(PDO $conn): void
{
    echo json_encode([
        'success' => false,
        'message' => 'Subject teacher assignments are not yet supported in the current database version.',
    ]);
}

// ============================================================
// GET: single section details
// ============================================================
function getSectionDetails(PDO $conn): void
{
    $sectionId = $_GET['id'] ?? null;
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
    $stmt->bindValue(':id', (int)$sectionId, PDO::PARAM_INT);
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

    // Unique constraint: uq_section_year (SectionName, AcademicYearID)
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
    $stmt->bindValue(':strand',    $data['strandId'] ? (int)$data['strandId'] : null);
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
             SectionName  = :name,
             GradeLevelID = :gradeLevel,
             StrandID     = :strand,
             AdviserID    = :adviserID,
             Capacity     = :capacity,
             AcademicYearID = :ayID
         WHERE SectionID = :id"
    );
    $stmt->bindValue(':name',      $data['sectionName']);
    $stmt->bindValue(':gradeLevel',(int)$data['gradeLevelId'],  PDO::PARAM_INT);
    $stmt->bindValue(':strand',    $data['strandId'] ? (int)$data['strandId'] : null);
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
    $sectionId = $_GET['id'] ?? null;
    if (!$sectionId) throw new Exception('Section ID is required');

    // Block deletion if students are assigned
    $chk = $conn->prepare(
        "SELECT COUNT(*) FROM sectionassignment WHERE SectionID = :id AND IsActive = 1"
    );
    $chk->bindValue(':id', (int)$sectionId, PDO::PARAM_INT);
    $chk->execute();
    if ((int)$chk->fetchColumn() > 0) {
        throw new Exception('Cannot delete a section that has assigned students');
    }

    $stmt = $conn->prepare("UPDATE section SET IsActive = 0 WHERE SectionID = :id");
    $stmt->bindValue(':id', (int)$sectionId, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Section deleted successfully']);
}
?>