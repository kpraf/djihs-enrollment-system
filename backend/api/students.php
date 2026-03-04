<?php
// =====================================================
// Students API — djihs_enrollment_v2 schema
// File: backend/api/students.php
//
// FIXES vs old version:
//  - getAllStudents(): removed s.Age (derived), removed e.AcademicYear (string col),
//    removed e.LearnerType (removed from schema), fixed sectionassignment JOIN
//    (schema has no StudentID col — must join via EnrollmentID), added academicyear JOIN
//  - getStudentById(): same fixes + parentguardian JOIN returning guardians[] keyed map,
//    explicit column list (no s.* wildcard), TIMESTAMPDIFF for Age
//  - getStatistics(): fixed AcademicYear string → ay.YearLabel via JOIN
//  - getEnrollmentStatsByYear(): removed e.AcademicYear, removed e.LearnerType ref
//  - getStrands(): unchanged ✓
//  - getGradeLevels(): unchanged ✓
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../config/database.php';

class StudentsAPI {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ──────────────────────────────────────────────
    // GET ALL STUDENTS (list view)
    //
    // KEY FIXES:
    //  - No s.Age (derived; not stored in student table)
    //  - No e.AcademicYear (not a column; use ay.YearLabel via academicyear JOIN)
    //  - No e.LearnerType (removed from enrollment table)
    //  - sectionassignment has no StudentID column; must join:
    //      sectionassignment → enrollment → student
    //  - Latest enrollment per student via MAX(EnrollmentID) subquery
    //  - Latest section assignment via latest enrollment's EnrollmentID
    // ──────────────────────────────────────────────
    public function getAllStudents() {
        try {
            $stmt = $this->conn->prepare("
                SELECT
                    s.StudentID,
                    s.LRN,
                    CONCAT(s.LastName, ', ', s.FirstName,
                        IFNULL(CONCAT(' ', s.MiddleName), '')) AS FullName,
                    s.FirstName,
                    s.LastName,
                    s.MiddleName,
                    s.DateOfBirth,
                    TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) AS Age,
                    s.Gender,
                    s.ContactNumber,
                    s.EnrollmentStatus,
                    s.Is4PsBeneficiary,
                    s.IsIPCommunity,
                    s.IsPWD,
                    -- Enrollment (latest per student)
                    e.EnrollmentID,
                    e.EnrollmentType,
                    e.Status         AS EnrollmentConfirmStatus,
                    e.GradeLevelID,
                    e.StrandID,
                    e.AcademicYearID,
                    -- Joined lookups
                    gl.GradeLevelName AS GradeLevel,
                    st.StrandName,
                    st.StrandCode,
                    ay.YearLabel      AS AcademicYear,
                    -- Section (latest active assignment for this enrollment)
                    sec.SectionName   AS Section,
                    sec.SectionID

                FROM student s

                -- Latest enrollment per student
                LEFT JOIN (
                    SELECT StudentID, MAX(EnrollmentID) AS LatestEnrollmentID
                    FROM   enrollment
                    GROUP  BY StudentID
                ) latestEnroll ON s.StudentID = latestEnroll.StudentID
                LEFT JOIN enrollment e  ON e.EnrollmentID  = latestEnroll.LatestEnrollmentID
                LEFT JOIN gradelevel gl ON gl.GradeLevelID = e.GradeLevelID
                LEFT JOIN strand     st ON st.StrandID     = e.StrandID
                LEFT JOIN academicyear ay ON ay.AcademicYearID = e.AcademicYearID

                -- Latest active section assignment (via EnrollmentID, NOT StudentID)
                LEFT JOIN (
                    SELECT EnrollmentID, MAX(AssignmentID) AS LatestAssignmentID
                    FROM   sectionassignment
                    WHERE  IsActive = 1
                    GROUP  BY EnrollmentID
                ) latestSA ON latestSA.EnrollmentID = e.EnrollmentID
                LEFT JOIN sectionassignment sa  ON sa.AssignmentID  = latestSA.LatestAssignmentID
                LEFT JOIN section           sec ON sec.SectionID    = sa.SectionID

                WHERE s.EnrollmentStatus != 'Graduated'
                ORDER BY s.LastName, s.FirstName
            ");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $students, 'count' => count($students)];

        } catch (PDOException $e) {
            error_log("Get students error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error fetching students: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET STUDENT BY ID (detail / edit modal)
    //
    // KEY FIXES:
    //  - Explicit column list (no s.*)
    //  - TIMESTAMPDIFF for Age (not stored)
    //  - academicyear JOIN for YearLabel
    //  - parentguardian JOIN — returns guardians[] keyed map:
    //      { Father: {...}, Mother: {...}, Guardian: {...} }
    //  - sectionassignment join via EnrollmentID
    //  - No e.AcademicYear, no e.LearnerType
    // ──────────────────────────────────────────────
    public function getStudentById($studentId) {
        try {
            $stmt = $this->conn->prepare("
                SELECT
                    -- Student (exact schema columns only)
                    s.StudentID,
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
                    CONCAT(s.LastName, ', ', s.FirstName,
                        IFNULL(CONCAT(' ', s.MiddleName), '')) AS FullName,

                    -- Enrollment (latest)
                    e.EnrollmentID,
                    e.GradeLevelID,
                    e.StrandID,
                    e.AcademicYearID,
                    e.EnrollmentType,
                    e.Status         AS EnrollmentConfirmStatus,
                    e.EnrollmentDate,
                    e.Remarks,

                    -- Lookups
                    gl.GradeLevelName,
                    gl.GradeLevelNumber,
                    gl.Department,
                    st.StrandName,
                    st.StrandCode,
                    ay.YearLabel      AS AcademicYear,
                    ay.AcademicYearID AS AcademicYearIDDisplay,

                    -- Section
                    sec.SectionID,
                    sec.SectionName,
                    CONCAT(u.FirstName, ' ', u.LastName) AS AdviserName

                FROM student s

                LEFT JOIN (
                    SELECT StudentID, MAX(EnrollmentID) AS LatestEnrollmentID
                    FROM   enrollment
                    GROUP  BY StudentID
                ) latestEnroll ON s.StudentID = latestEnroll.StudentID
                LEFT JOIN enrollment   e   ON e.EnrollmentID   = latestEnroll.LatestEnrollmentID
                LEFT JOIN gradelevel   gl  ON gl.GradeLevelID  = e.GradeLevelID
                LEFT JOIN strand       st  ON st.StrandID      = e.StrandID
                LEFT JOIN academicyear ay  ON ay.AcademicYearID = e.AcademicYearID

                LEFT JOIN (
                    SELECT EnrollmentID, MAX(AssignmentID) AS LatestAssignmentID
                    FROM   sectionassignment
                    WHERE  IsActive = 1
                    GROUP  BY EnrollmentID
                ) latestSA ON latestSA.EnrollmentID = e.EnrollmentID
                LEFT JOIN sectionassignment sa  ON sa.AssignmentID = latestSA.LatestAssignmentID
                LEFT JOIN section           sec ON sec.SectionID   = sa.SectionID
                LEFT JOIN user              u   ON u.UserID        = sec.AdviserID

                WHERE s.StudentID = :studentId
            ");
            $stmt->bindValue(':studentId', (int)$studentId, PDO::PARAM_INT);
            $stmt->execute();
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            // Parent/guardian rows from parentguardian table
            // Returned as keyed map { Father:{...}, Mother:{...}, Guardian:{...} }
            $pgStmt = $this->conn->prepare("
                SELECT RelationshipType, LastName, FirstName, MiddleName,
                       GuardianRelationship, ContactNumber
                FROM   parentguardian
                WHERE  StudentID = :sid
                ORDER  BY FIELD(RelationshipType, 'Father', 'Mother', 'Guardian')
            ");
            $pgStmt->execute([':sid' => $student['StudentID']]);
            $guardianRows = $pgStmt->fetchAll(PDO::FETCH_ASSOC);

            $guardianMap = [];
            foreach ($guardianRows as $g) {
                $guardianMap[$g['RelationshipType']] = $g;
            }
            $student['guardians']    = $guardianMap;   // keyed: Father/Mother/Guardian
            $student['guardianList'] = $guardianRows;  // flat list

            return ['success' => true, 'data' => $student];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching student: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // SEARCH STUDENTS
    // ──────────────────────────────────────────────
    public function searchStudents($searchTerm) {
        try {
            $like = "%{$searchTerm}%";
            $stmt = $this->conn->prepare("
                SELECT
                    s.StudentID,
                    s.LRN,
                    CONCAT(s.LastName, ', ', s.FirstName,
                        IFNULL(CONCAT(' ', s.MiddleName), '')) AS FullName,
                    s.EnrollmentStatus,
                    gl.GradeLevelName AS GradeLevel,
                    sec.SectionName   AS Section,
                    e.Status          AS EnrollmentConfirmStatus,
                    ay.YearLabel      AS AcademicYear

                FROM student s

                LEFT JOIN (
                    SELECT StudentID, MAX(EnrollmentID) AS LatestEnrollmentID
                    FROM   enrollment GROUP BY StudentID
                ) latestEnroll ON s.StudentID = latestEnroll.StudentID
                LEFT JOIN enrollment   e   ON e.EnrollmentID   = latestEnroll.LatestEnrollmentID
                LEFT JOIN gradelevel   gl  ON gl.GradeLevelID  = e.GradeLevelID
                LEFT JOIN academicyear ay  ON ay.AcademicYearID = e.AcademicYearID

                LEFT JOIN (
                    SELECT EnrollmentID, MAX(AssignmentID) AS LatestAssignmentID
                    FROM   sectionassignment WHERE IsActive = 1 GROUP BY EnrollmentID
                ) latestSA ON latestSA.EnrollmentID = e.EnrollmentID
                LEFT JOIN sectionassignment sa  ON sa.AssignmentID = latestSA.LatestAssignmentID
                LEFT JOIN section           sec ON sec.SectionID   = sa.SectionID

                WHERE s.FirstName LIKE :s1
                   OR s.LastName  LIKE :s2
                   OR s.LRN       LIKE :s3
                   OR CONCAT(s.LastName, ', ', s.FirstName) LIKE :s4

                ORDER BY s.LastName, s.FirstName
                LIMIT 50
            ");
            $stmt->execute([':s1' => $like, ':s2' => $like, ':s3' => $like, ':s4' => $like]);

            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error searching students: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET STATISTICS (dashboard)
    //
    // FIX: AcademicYear string → ay.YearLabel via JOIN
    // ──────────────────────────────────────────────
    public function getStatistics() {
        try {
            $stats = [];

            // Total active students
            $stmt = $this->conn->query(
                "SELECT COUNT(*) FROM student WHERE EnrollmentStatus = 'Active'"
            );
            $stats['totalActive'] = (int)$stmt->fetchColumn();

            // Total confirmed enrollments for the current active academic year
            $stmt = $this->conn->query("
                SELECT COUNT(*) FROM enrollment e
                JOIN academicyear ay ON ay.AcademicYearID = e.AcademicYearID
                WHERE ay.IsActive = 1 AND e.Status = 'Confirmed'
            ");
            $stats['enrolledThisYear'] = (int)$stmt->fetchColumn();

            // Pending enrollments
            $stmt = $this->conn->query(
                "SELECT COUNT(*) FROM enrollment WHERE Status = 'Pending'"
            );
            $stats['pendingEnrollments'] = (int)$stmt->fetchColumn();

            // Count by grade level (confirmed + active students only)
            $stmt = $this->conn->query("
                SELECT gl.GradeLevelName, COUNT(DISTINCT s.StudentID) AS count
                FROM   student     s
                JOIN   enrollment  e  ON e.StudentID    = s.StudentID
                JOIN   gradelevel  gl ON gl.GradeLevelID = e.GradeLevelID
                WHERE  s.EnrollmentStatus = 'Active' AND e.Status = 'Confirmed'
                GROUP  BY gl.GradeLevelName, gl.GradeLevelNumber
                ORDER  BY gl.GradeLevelNumber
            ");
            $stats['byGradeLevel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $stats];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching statistics: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET STRANDS — unchanged ✓
    // ──────────────────────────────────────────────
    public function getStrands() {
        try {
            $stmt = $this->conn->prepare("
                SELECT StrandID, StrandCode, StrandName, StrandCategory
                FROM   strand
                WHERE  IsActive = 1
                ORDER  BY StrandCode
            ");
            $stmt->execute();
            return ['success' => true, 'strands' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching strands: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────
    // GET ENROLLMENT STATS BY YEAR
    //
    // FIXES:
    //  - e.AcademicYear (string) → filter via academicyear JOIN on ay.YearLabel
    //  - e.LearnerType = 'Irregular_Transferee' → removed; use e.EnrollmentType = 'Transferee'
    // ──────────────────────────────────────────────
    public function getEnrollmentStatsByYear($yearLabel = null) {
        try {
            // Build year condition
            $yearJoin  = "JOIN academicyear ay ON ay.AcademicYearID = e.AcademicYearID";
            $yearWhere = '';
            $params    = [];

            if ($yearLabel && $yearLabel !== 'all') {
                $yearWhere  = "AND ay.YearLabel = :yearLabel";
                $params[':yearLabel'] = $yearLabel;
            }

            $stats = [];

            // Pending
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM enrollment e $yearJoin
                WHERE e.Status = 'Pending' $yearWhere
            ");
            $stmt->execute($params);
            $stats['pending'] = (int)$stmt->fetchColumn();

            // Confirmed
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM enrollment e $yearJoin
                WHERE e.Status = 'Confirmed' $yearWhere
            ");
            $stmt->execute($params);
            $stats['confirmed'] = (int)$stmt->fetchColumn();

            // Total active (Confirmed + Pending)
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM enrollment e $yearJoin
                WHERE e.Status IN ('Confirmed', 'Pending') $yearWhere
            ");
            $stmt->execute($params);
            $stats['total'] = (int)$stmt->fetchColumn();

            // Transferees — use EnrollmentType = 'Transferee' (LearnerType removed)
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM enrollment e $yearJoin
                WHERE e.EnrollmentType = 'Transferee'
                  AND e.Status IN ('Confirmed', 'Pending')
                  $yearWhere
            ");
            $stmt->execute($params);
            $stats['transferees'] = (int)$stmt->fetchColumn();

            return ['success' => true, 'data' => $stats, 'yearLabel' => $yearLabel];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching enrollment statistics: ' . $e->getMessage()];
        }
    }
}

// ──────────────────────────────────────────────
// ROUTER
// ──────────────────────────────────────────────
try {
    $db = (new Database())->getConnection();
    if (!$db) throw new Exception('Database connection failed');

    $api    = new StudentsAPI($db);
    $action = $_GET['action'] ?? 'list';

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    switch ($action) {
        case 'list':
            echo json_encode($api->getAllStudents());
            break;

        case 'details':
            $id = $_GET['id'] ?? null;
            echo json_encode($id
                ? $api->getStudentById($id)
                : ['success' => false, 'message' => 'Student ID required']);
            break;

        case 'search':
            $q = trim($_GET['q'] ?? '');
            echo json_encode($q
                ? $api->searchStudents($q)
                : ['success' => false, 'message' => 'Search term required']);
            break;

        case 'stats':
            echo json_encode($api->getStatistics());
            break;

        case 'get_strands':
            echo json_encode($api->getStrands());
            break;

        case 'enrollment_stats':
            $year = $_GET['year'] ?? 'all';
            echo json_encode($api->getEnrollmentStatsByYear($year));
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("Students API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>