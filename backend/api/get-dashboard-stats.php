<?php
// ============================================
// FILE: backend/api/get-dashboard-stats.php
// Purpose: Get dashboard statistics for admin
// Updated: 2026-03-04 — Revised for normalized DB
//   - enrollment.AcademicYearID (FK) replaces AcademicYear string
//   - enrollment.EnrollmentDate replaces missing UpdatedAt/ProcessedDate
//   - Removed ProcessedBy (not in schema)
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    require_once '../config/database.php';

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // ===== RESOLVE AcademicYearID from YearLabel =====
    $requestedYear = isset($_GET['year']) ? trim($_GET['year']) : null;

    if ($requestedYear) {
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel
             FROM academicyear
             WHERE YearLabel = :yearLabel
             LIMIT 1"
        );
        $stmt->bindValue(':yearLabel', $requestedYear);
        $stmt->execute();
        $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ayRow) {
            throw new Exception("Academic year '$requestedYear' not found.");
        }
    } else {
        // Default: the currently active academic year
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel
             FROM academicyear
             WHERE IsActive = 1
             LIMIT 1"
        );
        $stmt->execute();
        $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fallback: latest by StartYear
        if (!$ayRow) {
            $stmt = $conn->prepare(
                "SELECT AcademicYearID, YearLabel
                 FROM academicyear
                 ORDER BY StartYear DESC
                 LIMIT 1"
            );
            $stmt->execute();
            $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$ayRow) {
            throw new Exception('No academic year records found.');
        }
    }

    $academicYearID = intval($ayRow['AcademicYearID']);
    $currentYear    = $ayRow['YearLabel'];

    // ===== 1. TOTAL STUDENTS =====
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT e.StudentID) AS total
         FROM enrollment e
         WHERE e.Status IN ('Confirmed', 'Pending')
           AND e.AcademicYearID = :ayID"
    );
    $stmt->bindValue(':ayID', $academicYearID, PDO::PARAM_INT);
    $stmt->execute();
    $totalStudents = intval($stmt->fetchColumn());

    // ===== 2. ACTIVE TEACHERS =====
    // Requires an `employee` table; query is unchanged from original.
    $activeTeachers = 0;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM employee
             WHERE EmploymentType = 'Teaching'
               AND EmploymentStatus = 'Active'
               AND IsActive = 1"
        );
        $stmt->execute();
        $activeTeachers = intval($stmt->fetchColumn());
    } catch (Exception $e) {
        // employee table may not exist yet; leave as 0
    }

    // ===== 3. PENDING ENROLLMENTS =====
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM enrollment
         WHERE Status = 'Pending'
           AND AcademicYearID = :ayID"
    );
    $stmt->bindValue(':ayID', $academicYearID, PDO::PARAM_INT);
    $stmt->execute();
    $pendingEnrollments = intval($stmt->fetchColumn());

    // ===== 4. SYSTEM STATUS =====
    $systemStatus = 'Online';

    // ===== 5. STUDENTS BY GRADE LEVEL =====
    $stmt = $conn->prepare(
        "SELECT
             gl.GradeLevelName,
             gl.GradeLevelNumber,
             COUNT(DISTINCT e.StudentID) AS count
         FROM enrollment e
         INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
         WHERE e.Status IN ('Confirmed', 'Pending')
           AND e.AcademicYearID = :ayID
         GROUP BY gl.GradeLevelID, gl.GradeLevelName, gl.GradeLevelNumber
         ORDER BY gl.GradeLevelNumber"
    );
    $stmt->bindValue(':ayID', $academicYearID, PDO::PARAM_INT);
    $stmt->execute();

    $gradeDistribution = [];
    $maxCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = intval($row['count']);
        $gradeDistribution[] = ['name' => $row['GradeLevelName'], 'count' => $count];
        if ($count > $maxCount) $maxCount = $count;
    }
    foreach ($gradeDistribution as &$grade) {
        $grade['percentage'] = $maxCount > 0
            ? round(($grade['count'] / $maxCount) * 100, 2)
            : 0;
    }
    unset($grade);

    // ===== 6. ENROLLMENT STATUS BREAKDOWN =====
    $stmt = $conn->prepare(
        "SELECT Status, COUNT(*) AS count
         FROM enrollment
         WHERE AcademicYearID = :ayID
         GROUP BY Status"
    );
    $stmt->bindValue(':ayID', $academicYearID, PDO::PARAM_INT);
    $stmt->execute();

    $statusBreakdown = [
        'confirmed'  => 0,
        'pending'    => 0,
        'cancelled'  => 0,
        'for_review' => 0,
    ];
    $totalEnrollments = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = intval($row['count']);
        $totalEnrollments += $count;
        switch ($row['Status']) {
            case 'Confirmed':  $statusBreakdown['confirmed']  = $count; break;
            case 'Pending':    $statusBreakdown['pending']    = $count; break;
            case 'Cancelled':  $statusBreakdown['cancelled']  = $count; break;
            case 'For_Review': $statusBreakdown['for_review'] = $count; break;
        }
    }

    $statusBreakdown['total'] = $totalEnrollments;
    $statusBreakdown['confirmed_percent'] = $totalEnrollments > 0
        ? round(($statusBreakdown['confirmed']  / $totalEnrollments) * 100, 1) : 0;
    $statusBreakdown['pending_percent']   = $totalEnrollments > 0
        ? round(($statusBreakdown['pending']    / $totalEnrollments) * 100, 1) : 0;
    $statusBreakdown['cancelled_percent'] = $totalEnrollments > 0
        ? round(($statusBreakdown['cancelled']  / $totalEnrollments) * 100, 1) : 0;

    // ===== 7. RECENT ACTIVITY =====
    // Uses EnrollmentDate (the only timestamp on enrollment in the current schema).
    $stmt = $conn->prepare(
        "SELECT
             e.EnrollmentID,
             e.Status,
             e.EnrollmentDate,
             s.FirstName,
             s.LastName,
             s.LRN,
             gl.GradeLevelName
         FROM enrollment e
         INNER JOIN student   s  ON e.StudentID    = s.StudentID
         INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
         WHERE e.AcademicYearID = :ayID
         ORDER BY e.EnrollmentDate DESC
         LIMIT 10"
    );
    $stmt->bindValue(':ayID', $academicYearID, PDO::PARAM_INT);
    $stmt->execute();

    $recentActivity = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $studentName = $row['FirstName'] . ' ' . $row['LastName'];
        $activity = [
            'id'          => $row['EnrollmentID'],
            'student_name'=> $studentName,
            'lrn'         => $row['LRN'],
            'grade_level' => $row['GradeLevelName'],
            'status'      => $row['Status'],
            'timestamp'   => $row['EnrollmentDate'],
            'time_ago'    => getTimeAgo($row['EnrollmentDate']),
        ];

        switch ($row['Status']) {
            case 'Confirmed':
                $activity['description'] = "$studentName's enrollment was confirmed";
                $activity['icon']  = 'check_circle';
                $activity['color'] = 'green';
                break;
            case 'Pending':
                $activity['description'] = "New enrollment application from $studentName";
                $activity['icon']  = 'pending';
                $activity['color'] = 'blue';
                break;
            case 'Cancelled':
                $activity['description'] = "$studentName's enrollment was cancelled";
                $activity['icon']  = 'cancel';
                $activity['color'] = 'red';
                break;
            case 'For_Review':
                $activity['description'] = "$studentName's enrollment is under review";
                $activity['icon']  = 'rate_review';
                $activity['color'] = 'amber';
                break;
            default:
                $activity['description'] = "Enrollment updated for $studentName";
                $activity['icon']  = 'edit';
                $activity['color'] = 'gray';
        }

        $recentActivity[] = $activity;
    }

    // ===== 8. ADDITIONAL STATS =====

    // Total employees (graceful fallback if table absent)
    $totalEmployees = 0;
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM employee WHERE IsActive = 1");
        $stmt->execute();
        $totalEmployees = intval($stmt->fetchColumn());
    } catch (Exception $e) { /* table may not exist */ }

    // Active system users
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user WHERE IsActive = 1");
    $stmt->execute();
    $activeUsers = intval($stmt->fetchColumn());

    // Department breakdown (JHS / SHS)
    $stmt = $conn->prepare(
        "SELECT
             gl.Department,
             COUNT(DISTINCT e.StudentID) AS count
         FROM enrollment e
         INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
         WHERE e.Status IN ('Confirmed', 'Pending')
           AND e.AcademicYearID = :ayID
         GROUP BY gl.Department"
    );
    $stmt->bindValue(':ayID', $academicYearID, PDO::PARAM_INT);
    $stmt->execute();

    $departmentBreakdown = ['JHS' => 0, 'SHS' => 0];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = ($row['Department'] === 'Junior_High') ? 'JHS' : 'SHS';
        $departmentBreakdown[$key] = intval($row['count']);
    }

    // ===== BUILD RESPONSE =====
    echo json_encode([
        'success' => true,
        'data' => [
            'currentYear'         => $currentYear,
            'totalStudents'       => $totalStudents,
            'activeTeachers'      => $activeTeachers,
            'totalEmployees'      => $totalEmployees,
            'activeUsers'         => $activeUsers,
            'pendingEnrollments'  => $pendingEnrollments,
            'systemStatus'        => $systemStatus,
            'gradeDistribution'   => $gradeDistribution,
            'statusBreakdown'     => $statusBreakdown,
            'departmentBreakdown' => $departmentBreakdown,
            'recentActivity'      => $recentActivity,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}

// ─── Helper ────────────────────────────────────────────────────────────────
function getTimeAgo(?string $datetime): string {
    if (!$datetime) return 'Unknown';

    $diff = time() - strtotime($datetime);

    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   { $m = floor($diff / 60);   return "$m minute"   . ($m  > 1 ? 's' : '') . ' ago'; }
    if ($diff < 86400)  { $h = floor($diff / 3600);  return "$h hour"     . ($h  > 1 ? 's' : '') . ' ago'; }
    if ($diff < 604800) { $d = floor($diff / 86400); return "$d day"      . ($d  > 1 ? 's' : '') . ' ago'; }

    return date('M d, Y', strtotime($datetime));
}
?>