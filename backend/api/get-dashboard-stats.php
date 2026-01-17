<?php
// ============================================
// FILE: backend/api/get-dashboard-stats.php
// Purpose: Get dashboard statistics for admin
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

    // Get academic year from parameter or use the latest year
    $requestedYear = isset($_GET['year']) ? $_GET['year'] : null;
    
    if ($requestedYear) {
        $currentYear = $requestedYear;
    } else {
        $currentYearQuery = "SELECT AcademicYear FROM enrollment 
                             WHERE AcademicYear IS NOT NULL 
                             ORDER BY AcademicYear DESC LIMIT 1";
        $stmt = $conn->prepare($currentYearQuery);
        $stmt->execute();
        $currentYearResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentYear = $currentYearResult['AcademicYear'] ?? date('Y') . '-' . (date('Y') + 1);
    }

    // ===== 1. TOTAL STUDENTS =====
    $totalStudentsQuery = "SELECT COUNT(DISTINCT e.StudentID) as total
                           FROM enrollment e
                           WHERE e.Status IN ('Confirmed', 'Pending')
                           AND e.AcademicYear = :currentYear";
    $stmt = $conn->prepare($totalStudentsQuery);
    $stmt->bindValue(':currentYear', $currentYear);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalStudents = intval($result['total']);

    // ===== 2. ACTIVE TEACHERS =====
    $activeTeachersQuery = "SELECT COUNT(*) as total
                            FROM employee
                            WHERE EmploymentType = 'Teaching'
                            AND EmploymentStatus = 'Active'
                            AND IsActive = 1";
    $stmt = $conn->prepare($activeTeachersQuery);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $activeTeachers = intval($result['total']);

    // ===== 3. PENDING ENROLLMENTS =====
    $pendingQuery = "SELECT COUNT(*) as total
                     FROM enrollment
                     WHERE Status = 'Pending'
                     AND AcademicYear = :currentYear";
    $stmt = $conn->prepare($pendingQuery);
    $stmt->bindValue(':currentYear', $currentYear);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $pendingEnrollments = intval($result['total']);

    // ===== 4. SYSTEM STATUS (Check database connection) =====
    $systemStatus = 'Online';

    // ===== 5. STUDENTS BY GRADE LEVEL =====
    $gradeDistributionQuery = "SELECT 
                                gl.GradeLevelName,
                                gl.GradeLevelNumber,
                                COUNT(DISTINCT e.StudentID) as count
                               FROM enrollment e
                               INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                               WHERE e.Status IN ('Confirmed', 'Pending')
                               AND e.AcademicYear = :currentYear
                               GROUP BY gl.GradeLevelName, gl.GradeLevelNumber
                               ORDER BY gl.GradeLevelNumber";
    $stmt = $conn->prepare($gradeDistributionQuery);
    $stmt->bindValue(':currentYear', $currentYear);
    $stmt->execute();
    
    $gradeDistribution = [];
    $maxCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = intval($row['count']);
        $gradeDistribution[] = [
            'name' => $row['GradeLevelName'],
            'count' => $count
        ];
        if ($count > $maxCount) $maxCount = $count;
    }
    
    // Calculate percentages for bar heights
    foreach ($gradeDistribution as &$grade) {
        $grade['percentage'] = $maxCount > 0 ? round(($grade['count'] / $maxCount) * 100, 2) : 0;
    }

    // ===== 6. ENROLLMENT STATUS BREAKDOWN =====
    $statusBreakdownQuery = "SELECT 
                              Status,
                              COUNT(*) as count
                             FROM enrollment
                             WHERE AcademicYear = :currentYear
                             GROUP BY Status";
    $stmt = $conn->prepare($statusBreakdownQuery);
    $stmt->bindValue(':currentYear', $currentYear);
    $stmt->execute();
    
    $statusBreakdown = [
        'confirmed' => 0,
        'pending' => 0,
        'cancelled' => 0,
        'for_review' => 0
    ];
    $totalEnrollments = 0;
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = intval($row['count']);
        $totalEnrollments += $count;
        
        switch ($row['Status']) {
            case 'Confirmed':
                $statusBreakdown['confirmed'] = $count;
                break;
            case 'Pending':
                $statusBreakdown['pending'] = $count;
                break;
            case 'Cancelled':
                $statusBreakdown['cancelled'] = $count;
                break;
            case 'For_Review':
                $statusBreakdown['for_review'] = $count;
                break;
        }
    }
    
    // Calculate percentages
    $statusBreakdown['total'] = $totalEnrollments;
    $statusBreakdown['confirmed_percent'] = $totalEnrollments > 0 ? 
        round(($statusBreakdown['confirmed'] / $totalEnrollments) * 100, 1) : 0;
    $statusBreakdown['pending_percent'] = $totalEnrollments > 0 ? 
        round(($statusBreakdown['pending'] / $totalEnrollments) * 100, 1) : 0;
    $statusBreakdown['cancelled_percent'] = $totalEnrollments > 0 ? 
        round(($statusBreakdown['cancelled'] / $totalEnrollments) * 100, 1) : 0;

    // ===== 7. RECENT ACTIVITY =====
    $recentActivityQuery = "SELECT 
                              e.EnrollmentID,
                              e.Status,
                              e.UpdatedAt,
                              e.ProcessedDate,
                              s.FirstName,
                              s.LastName,
                              s.LRN,
                              gl.GradeLevelName,
                              u.FirstName as ProcessedByFirstName,
                              u.LastName as ProcessedByLastName
                            FROM enrollment e
                            INNER JOIN student s ON e.StudentID = s.StudentID
                            INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                            LEFT JOIN user u ON e.ProcessedBy = u.UserID
                            WHERE e.AcademicYear = :currentYear
                            ORDER BY e.UpdatedAt DESC
                            LIMIT 10";
    $stmt = $conn->prepare($recentActivityQuery);
    $stmt->bindValue(':currentYear', $currentYear);
    $stmt->execute();
    
    $recentActivity = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $studentName = $row['FirstName'] . ' ' . $row['LastName'];
        $processedBy = $row['ProcessedByFirstName'] ? 
            $row['ProcessedByFirstName'] . ' ' . $row['ProcessedByLastName'] : 'System';
        
        $activity = [
            'id' => $row['EnrollmentID'],
            'student_name' => $studentName,
            'lrn' => $row['LRN'],
            'grade_level' => $row['GradeLevelName'],
            'status' => $row['Status'],
            'processed_by' => $processedBy,
            'timestamp' => $row['ProcessedDate'] ?? $row['UpdatedAt'],
            'time_ago' => getTimeAgo($row['ProcessedDate'] ?? $row['UpdatedAt'])
        ];
        
        // Create description based on status
        switch ($row['Status']) {
            case 'Confirmed':
                $activity['description'] = "$studentName's enrollment was confirmed";
                $activity['icon'] = 'check_circle';
                $activity['color'] = 'green';
                break;
            case 'Pending':
                $activity['description'] = "New enrollment application from $studentName";
                $activity['icon'] = 'pending';
                $activity['color'] = 'blue';
                break;
            case 'Cancelled':
                $activity['description'] = "$studentName's enrollment was cancelled";
                $activity['icon'] = 'cancel';
                $activity['color'] = 'red';
                break;
            case 'For_Review':
                $activity['description'] = "$studentName's enrollment is under review";
                $activity['icon'] = 'rate_review';
                $activity['color'] = 'amber';
                break;
            default:
                $activity['description'] = "Enrollment updated for $studentName";
                $activity['icon'] = 'edit';
                $activity['color'] = 'gray';
        }
        
        $recentActivity[] = $activity;
    }

    // ===== 8. ADDITIONAL STATS =====
    
    // Total employees
    $totalEmployeesQuery = "SELECT COUNT(*) as total FROM employee WHERE IsActive = 1";
    $stmt = $conn->prepare($totalEmployeesQuery);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalEmployees = intval($result['total']);
    
    // Active users
    $activeUsersQuery = "SELECT COUNT(*) as total FROM user WHERE IsActive = 1";
    $stmt = $conn->prepare($activeUsersQuery);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $activeUsers = intval($result['total']);
    
    // Department breakdown
    $deptBreakdownQuery = "SELECT 
                            gl.Department,
                            COUNT(DISTINCT e.StudentID) as count
                           FROM enrollment e
                           INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                           WHERE e.Status IN ('Confirmed', 'Pending')
                           AND e.AcademicYear = :currentYear
                           GROUP BY gl.Department";
    $stmt = $conn->prepare($deptBreakdownQuery);
    $stmt->bindValue(':currentYear', $currentYear);
    $stmt->execute();
    
    $departmentBreakdown = ['JHS' => 0, 'SHS' => 0];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dept = $row['Department'] === 'Junior_High' ? 'JHS' : 'SHS';
        $departmentBreakdown[$dept] = intval($row['count']);
    }

    // Build response
    $response = [
        'success' => true,
        'data' => [
            'currentYear' => $currentYear,
            'totalStudents' => $totalStudents,
            'activeTeachers' => $activeTeachers,
            'totalEmployees' => $totalEmployees,
            'activeUsers' => $activeUsers,
            'pendingEnrollments' => $pendingEnrollments,
            'systemStatus' => $systemStatus,
            'gradeDistribution' => $gradeDistribution,
            'statusBreakdown' => $statusBreakdown,
            'departmentBreakdown' => $departmentBreakdown,
            'recentActivity' => $recentActivity
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

// Helper function to calculate time ago
function getTimeAgo($datetime) {
    if (!$datetime) return 'Unknown';
    
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return 'Just now';
    } elseif ($difference < 3600) {
        $minutes = floor($difference / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($difference < 86400) {
        $hours = floor($difference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($difference < 604800) {
        $days = floor($difference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}
?>