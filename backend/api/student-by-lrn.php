<?php
// =====================================================
// Student LRN Lookup API — aligned with djihs_enrollment_v2 schema
// File: backend/api/student-by-lrn.php
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit();
}

try {
    $lrn = isset($_GET['lrn']) ? trim($_GET['lrn']) : '';

    if (empty($lrn)) {
        echo json_encode(['success' => false, 'message' => 'LRN parameter is required']);
        exit();
    }

    if (!preg_match('/^\d{12}$/', $lrn)) {
        echo json_encode(['success' => false, 'message' => 'Invalid LRN format. Must be 12 digits.']);
        exit();
    }

    require_once '../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn) throw new Exception('Database connection failed');

    // ── 1. Fetch student record (schema-exact columns) ──────────────────────
    $stmt = $conn->prepare("
        SELECT
            s.StudentID,
            s.LRN,
            s.LastName,
            s.FirstName,
            s.MiddleName,
            s.ExtensionName,
            s.DateOfBirth,
            s.Gender,
            s.Religion,
            s.MotherTongue,
            s.IsIPCommunity,
            s.IPCommunitySpecify,
            s.IsPWD,
            s.PWDSpecify,
            s.Is4PsBeneficiary,
            s.HouseNumber,
            s.SitioStreet,
            s.Barangay,
            s.Municipality,
            s.Province,
            s.ContactNumber,
            s.EnrollmentStatus
        FROM student s
        WHERE s.LRN = :lrn
        LIMIT 1
    ");
    $stmt->execute([':lrn' => $lrn]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode([
            'success'           => false,
            'message'           => 'No student found with this LRN.',
            'student'           => null,
            'guardians'         => [],
            'enrollmentHistory' => [],
            'latestEnrollment'  => null,
        ]);
        exit();
    }

    // ── 2. Derive age from DateOfBirth (not stored in DB) ───────────────────
    $student['Age'] = null;
    if ($student['DateOfBirth']) {
        $dob  = new DateTime($student['DateOfBirth']);
        $today = new DateTime();
        $student['Age'] = (int)$today->diff($dob)->y;
    }

    // ── 3. Cast boolean flags ────────────────────────────────────────────────
    $student['IsIPCommunity']    = (bool)$student['IsIPCommunity'];
    $student['IsPWD']            = (bool)$student['IsPWD'];
    $student['Is4PsBeneficiary'] = (bool)$student['Is4PsBeneficiary'];

    // Alias for frontend compatibility
    $student['Sex'] = $student['Gender'];

    // ── 4. Fetch parent/guardian rows ────────────────────────────────────────
    $pgStmt = $conn->prepare("
        SELECT
            RelationshipType,
            LastName,
            FirstName,
            MiddleName,
            GuardianRelationship,
            ContactNumber
        FROM parentguardian
        WHERE StudentID = :sid
        ORDER BY
            FIELD(RelationshipType, 'Father', 'Mother', 'Guardian')
    ");
    $pgStmt->execute([':sid' => $student['StudentID']]);
    $guardians = $pgStmt->fetchAll(PDO::FETCH_ASSOC);

    // Flatten into keyed sub-objects so the frontend can read e.g. guardian.Father
    $guardianMap = [];
    foreach ($guardians as $g) {
        $guardianMap[$g['RelationshipType']] = $g;
    }

    // ── 5. Fetch enrollment history ─────────────────────────────────────────
    $enrollStmt = $conn->prepare("
        SELECT
            e.EnrollmentID,
            e.AcademicYearID,
            ay.YearLabel       AS AcademicYear,
            e.EnrollmentType,
            e.Status,
            gl.GradeLevelID,
            gl.GradeLevelName,
            gl.GradeLevelNumber,
            gl.Department,
            st.StrandID,
            st.StrandName,
            st.StrandCode,
            e.EnrollmentDate,
            e.Remarks
        FROM enrollment e
        JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
        JOIN gradelevel   gl ON e.GradeLevelID   = gl.GradeLevelID
        LEFT JOIN strand  st ON e.StrandID       = st.StrandID
        WHERE e.StudentID = :sid
        ORDER BY ay.StartYear DESC, e.EnrollmentDate DESC
    ");
    $enrollStmt->execute([':sid' => $student['StudentID']]);
    $enrollmentHistory = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);

    $latestEnrollment = !empty($enrollmentHistory) ? $enrollmentHistory[0] : null;

    echo json_encode([
        'success'           => true,
        'message'           => 'Student found',
        'student'           => $student,
        'guardians'         => $guardianMap,   // keyed: Father / Mother / Guardian
        'enrollmentHistory' => $enrollmentHistory,
        'latestEnrollment'  => $latestEnrollment,
    ]);

} catch (PDOException $e) {
    error_log("DB error in student-by-lrn.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in student-by-lrn.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>