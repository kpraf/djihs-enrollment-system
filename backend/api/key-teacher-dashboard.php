<?php
// ============================================
// FILE: backend/api/key-teacher-dashboard.php
// Purpose: Key Teacher dashboard stats
// Updated: 2026-03-04 — Revised for normalized DB
//   - enrollment.AcademicYearID / section.AcademicYearID (FK int)
//     replace the former AcademicYear string columns
//   - section.CurrentEnrollment removed (not in schema);
//     computed via COUNT of active sectionassignment rows
//   - sectionassignment has no StudentID column;
//     student identity resolved via sectionassignment → enrollment
//   - section.AdviserEmployeeID removed (not in schema);
//     adviser resolved via section.AdviserID → user table only
//   - employee table references removed (not in current schema)
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn     = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : 'overview';

try {
    switch ($action) {
        case 'overview': getDashboardOverview($conn); break;
        case 'sections': getSectionOverview($conn);   break;
        case 'alerts':   getActionAlerts($conn);      break;
        default: throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ─── Shared helper: resolve AcademicYearID from a YearLabel string ─────────
// Returns ['id' => int, 'label' => string].
// When $yearLabel is null, falls back to the active year then the latest.
function resolveAcademicYear(PDO $conn, ?string $yearLabel): array
{
    if ($yearLabel) {
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel FROM academicyear WHERE YearLabel = :yl LIMIT 1"
        );
        $stmt->bindValue(':yl', $yearLabel);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Academic year '$yearLabel' not found.");
        return ['id' => (int)$row['AcademicYearID'], 'label' => $row['YearLabel']];
    }

    $stmt = $conn->prepare(
        "SELECT AcademicYearID, YearLabel FROM academicyear
         ORDER BY IsActive DESC, StartYear DESC LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('No academic year records found.');
    return ['id' => (int)$row['AcademicYearID'], 'label' => $row['YearLabel']];
}

// ============================================================
// ACTION: overview
// ============================================================
function getDashboardOverview(PDO $conn): void
{
    $yearParam  = isset($_GET['year'])  ? trim($_GET['year'])  : null;
    $gradeParam = isset($_GET['grade']) ? trim($_GET['grade']) : null;

    $ay   = resolveAcademicYear($conn, $yearParam);
    $ayID = $ay['id'];

    // ── Total enrolled students (Confirmed + Pending) ──────────────────
    $sql = "SELECT COUNT(DISTINCT e.StudentID) AS total
            FROM enrollment e
            WHERE e.AcademicYearID = :ayID
              AND e.Status IN ('Confirmed','Pending')";
    if ($gradeParam) {
        $sql .= " AND e.GradeLevelID IN (
                     SELECT GradeLevelID FROM gradelevel WHERE GradeLevelNumber = :grade)";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    if ($gradeParam) $stmt->bindValue(':grade', $gradeParam, PDO::PARAM_INT);
    $stmt->execute();
    $totalEnrolled = (int)$stmt->fetchColumn();

    // ── Total active sections ──────────────────────────────────────────
    $sql = "SELECT COUNT(*) AS total
            FROM section s
            WHERE s.AcademicYearID = :ayID AND s.IsActive = 1";
    if ($gradeParam) {
        $sql .= " AND s.GradeLevelID IN (
                     SELECT GradeLevelID FROM gradelevel WHERE GradeLevelNumber = :grade)";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    if ($gradeParam) $stmt->bindValue(':grade', $gradeParam, PDO::PARAM_INT);
    $stmt->execute();
    $totalSections = (int)$stmt->fetchColumn();

    // ── Average fill rate (computed enrollment vs capacity) ───────────
    // CurrentEnrollment is not stored; count active sectionassignment rows per section.
    $sql = "SELECT
                COALESCE(SUM(assigned.cnt), 0) AS totalAssigned,
                COALESCE(SUM(s.Capacity),   0) AS totalCapacity
            FROM section s
            LEFT JOIN (
                SELECT sa.SectionID, COUNT(*) AS cnt
                FROM sectionassignment sa
                WHERE sa.IsActive = 1
                GROUP BY sa.SectionID
            ) assigned ON assigned.SectionID = s.SectionID
            WHERE s.AcademicYearID = :ayID AND s.IsActive = 1";
    if ($gradeParam) {
        $sql .= " AND s.GradeLevelID IN (
                     SELECT GradeLevelID FROM gradelevel WHERE GradeLevelNumber = :grade)";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    if ($gradeParam) $stmt->bindValue(':grade', $gradeParam, PDO::PARAM_INT);
    $stmt->execute();
    $fillRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgFillRate = $fillRow['totalCapacity'] > 0
        ? (int)round(($fillRow['totalAssigned'] / $fillRow['totalCapacity']) * 100)
        : 0;

    // ── Students already assigned to a section ────────────────────────
    // sectionassignment links via EnrollmentID; resolve StudentID through enrollment.
    $sql = "SELECT COUNT(DISTINCT e.StudentID) AS total
            FROM sectionassignment sa
            INNER JOIN enrollment e ON sa.EnrollmentID = e.EnrollmentID
            INNER JOIN section    s ON sa.SectionID    = s.SectionID
            WHERE s.AcademicYearID   = :ayID
              AND e.AcademicYearID   = :ayID2
              AND e.Status IN ('Confirmed','Pending')
              AND sa.IsActive = 1";
    if ($gradeParam) {
        $sql .= " AND s.GradeLevelID IN (
                     SELECT GradeLevelID FROM gradelevel WHERE GradeLevelNumber = :grade)";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':ayID',  $ayID, PDO::PARAM_INT);
    $stmt->bindValue(':ayID2', $ayID, PDO::PARAM_INT);
    if ($gradeParam) $stmt->bindValue(':grade', $gradeParam, PDO::PARAM_INT);
    $stmt->execute();
    $assignedStudents = (int)$stmt->fetchColumn();

    $unassigned = max(0, $totalEnrolled - $assignedStudents);

    echo json_encode([
        'success' => true,
        'data' => [
            'totalEnrolled'     => $totalEnrolled,
            'totalSections'     => $totalSections,
            'avgFillRate'       => $avgFillRate,
            'assignedStudents'  => $assignedStudents,
            'unassignedStudents'=> $unassigned,
            'academicYear'      => $ay['label'],
        ],
    ]);
}

// ============================================================
// ACTION: sections
// ============================================================
function getSectionOverview(PDO $conn): void
{
    $yearParam   = isset($_GET['year'])   ? trim($_GET['year'])   : null;
    $gradeParam  = isset($_GET['grade'])  ? trim($_GET['grade'])  : null;
    $searchParam = isset($_GET['search']) ? trim($_GET['search']) : '';

    $ay   = resolveAcademicYear($conn, $yearParam);
    $ayID = $ay['id'];

    // Compute current enrollment per section from sectionassignment
    $sql = "SELECT
                s.SectionID,
                s.SectionName,
                s.Capacity,
                gl.GradeLevelName,
                st.StrandCode,
                CONCAT(u.LastName, ', ', u.FirstName) AS AdviserName,
                COALESCE(assigned.cnt, 0) AS CurrentEnrollment,
                ROUND(COALESCE(assigned.cnt, 0) / s.Capacity * 100) AS FillPercentage,
                CASE
                    WHEN COALESCE(assigned.cnt, 0) >= s.Capacity             THEN 'Full'
                    WHEN COALESCE(assigned.cnt, 0) >= (s.Capacity * 0.9)    THEN 'Nearing Capacity'
                    ELSE 'Open'
                END AS Status
            FROM section s
            INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
            LEFT JOIN  strand     st ON s.StrandID     = st.StrandID
            LEFT JOIN  user        u ON s.AdviserID    = u.UserID
            LEFT JOIN (
                SELECT sa.SectionID, COUNT(*) AS cnt
                FROM sectionassignment sa
                WHERE sa.IsActive = 1
                GROUP BY sa.SectionID
            ) assigned ON assigned.SectionID = s.SectionID
            WHERE s.AcademicYearID = :ayID
              AND s.IsActive = 1";

    $params = [':ayID' => $ayID];

    if ($gradeParam) {
        $sql .= " AND gl.GradeLevelNumber = :grade";
        $params[':grade'] = $gradeParam;
    }

    if ($searchParam !== '') {
        $sql .= " AND (
                     s.SectionName LIKE :search
                  OR CONCAT(u.LastName, ', ', u.FirstName) LIKE :search
                 )";
        $params[':search'] = '%' . $searchParam . '%';
    }

    $sql .= " ORDER BY gl.GradeLevelNumber, s.SectionName";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'sections' => $sections,
        'count'    => count($sections),
    ]);
}

// ============================================================
// ACTION: alerts
// ============================================================
function getActionAlerts(PDO $conn): void
{
    $yearParam = isset($_GET['year']) ? trim($_GET['year']) : null;

    $ay   = resolveAcademicYear($conn, $yearParam);
    $ayID = $ay['id'];

    $alerts = [];

    // ── Sections nearing capacity (90–99 %) ───────────────────────────
    $stmt = $conn->prepare(
        "SELECT
             s.SectionName,
             s.Capacity,
             COALESCE(assigned.cnt, 0)                                AS CurrentEnrollment,
             ROUND(COALESCE(assigned.cnt, 0) / s.Capacity * 100)     AS FillPercentage
         FROM section s
         LEFT JOIN (
             SELECT sa.SectionID, COUNT(*) AS cnt
             FROM sectionassignment sa WHERE sa.IsActive = 1
             GROUP BY sa.SectionID
         ) assigned ON assigned.SectionID = s.SectionID
         WHERE s.AcademicYearID = :ayID
           AND s.IsActive = 1
           AND COALESCE(assigned.cnt, 0) >= (s.Capacity * 0.9)
           AND COALESCE(assigned.cnt, 0) <  s.Capacity
         ORDER BY FillPercentage DESC"
    );
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = [
            'type'        => 'warning',
            'icon'        => 'priority_high',
            'title'       => "{$row['SectionName']} is {$row['FillPercentage']}% full.",
            'description' => "Consider closing enrollment soon. ({$row['CurrentEnrollment']}/{$row['Capacity']} students)",
        ];
    }

    // ── Full sections ─────────────────────────────────────────────────
    $stmt = $conn->prepare(
        "SELECT
             s.SectionName,
             s.Capacity
         FROM section s
         LEFT JOIN (
             SELECT sa.SectionID, COUNT(*) AS cnt
             FROM sectionassignment sa WHERE sa.IsActive = 1
             GROUP BY sa.SectionID
         ) assigned ON assigned.SectionID = s.SectionID
         WHERE s.AcademicYearID = :ayID
           AND s.IsActive = 1
           AND COALESCE(assigned.cnt, 0) >= s.Capacity
         ORDER BY s.SectionName"
    );
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = [
            'type'        => 'danger',
            'icon'        => 'group',
            'title'       => "{$row['SectionName']} is now full.",
            'description' => "No further enrollments are possible. ({$row['Capacity']}/{$row['Capacity']} students)",
        ];
    }

    // ── Pending enrollments with no section assignment ────────────────
    // sectionassignment links via EnrollmentID (no StudentID column on that table).
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM enrollment e
         WHERE e.AcademicYearID = :ayID
           AND e.Status = 'Pending'
           AND NOT EXISTS (
               SELECT 1 FROM sectionassignment sa
               WHERE sa.EnrollmentID = e.EnrollmentID
                 AND sa.IsActive = 1
           )"
    );
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    $stmt->execute();
    $pendingCount = (int)$stmt->fetchColumn();

    if ($pendingCount > 0) {
        $alerts[] = [
            'type'        => 'info',
            'icon'        => 'person_add',
            'title'       => "New enrollments pending.",
            'description' => "$pendingCount student" . ($pendingCount > 1 ? 's are' : ' is')
                           . " waiting for section assignment.",
        ];
    }

    // ── Confirmed enrollments with no section assignment ─────────────
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM enrollment e
         WHERE e.AcademicYearID = :ayID
           AND e.Status = 'Confirmed'
           AND NOT EXISTS (
               SELECT 1 FROM sectionassignment sa
               WHERE sa.EnrollmentID = e.EnrollmentID
                 AND sa.IsActive = 1
           )"
    );
    $stmt->bindValue(':ayID', $ayID, PDO::PARAM_INT);
    $stmt->execute();
    $unassignedCount = (int)$stmt->fetchColumn();

    if ($unassignedCount > 0) {
        $alerts[] = [
            'type'        => 'warning',
            'icon'        => 'assignment_late',
            'title'       => "Confirmed students not assigned.",
            'description' => "$unassignedCount confirmed student"
                           . ($unassignedCount > 1 ? 's need' : ' needs')
                           . " section assignment.",
        ];
    }

    // ── All clear ─────────────────────────────────────────────────────
    if (empty($alerts)) {
        $alerts[] = [
            'type'        => 'success',
            'icon'        => 'check_circle',
            'title'       => "All systems running smoothly!",
            'description' => "No action items require your attention at this time.",
        ];
    }

    echo json_encode([
        'success' => true,
        'alerts'  => $alerts,
        'count'   => count($alerts),
    ]);
}
?>