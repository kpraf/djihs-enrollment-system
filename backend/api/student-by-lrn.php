<?php
// =====================================================
// Enhanced Student LRN Lookup API with Enrollment History
// File: backend/api/student-by-lrn.php
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit();
}

try {
    // Get LRN from query parameter
    $lrn = isset($_GET['lrn']) ? trim($_GET['lrn']) : '';
    
    // Validate LRN
    if (empty($lrn)) {
        echo json_encode([
            'success' => false,
            'message' => 'LRN parameter is required'
        ]);
        exit();
    }
    
    // Validate LRN format (12 digits)
    if (!preg_match('/^\d{12}$/', $lrn)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid LRN format. Must be 12 digits.'
        ]);
        exit();
    }
    
    // Create database connection
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Query to find student by LRN with enrollment history
    $query = "SELECT 
                s.StudentID,
                s.LRN,
                s.LastName,
                s.FirstName,
                s.MiddleName,
                s.ExtensionName,
                s.DateOfBirth,
                s.Age,
                s.Gender,
                s.Religion,
                s.IsIPCommunity,
                s.IPCommunitySpecify,
                s.IsPWD,
                s.PWDSpecify,
                s.Is4PsBeneficiary,
                s.Weight,
                s.Height,
                s.HouseNumber,
                s.SitioStreet,
                s.Barangay,
                s.Municipality,
                s.Province,
                s.ZipCode,
                s.Country,
                s.FatherLastName,
                s.FatherFirstName,
                s.FatherMiddleName,
                s.MotherLastName,
                s.MotherFirstName,
                s.MotherMiddleName,
                s.GuardianLastName,
                s.GuardianFirstName,
                s.GuardianMiddleName,
                s.ContactNumber
              FROM student s
              WHERE s.LRN = :lrn
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':lrn', $lrn, PDO::PARAM_STR);
    $stmt->execute();
    
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        // Calculate age if DateOfBirth exists
        if ($student['DateOfBirth']) {
            $birthDate = new DateTime($student['DateOfBirth']);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            $student['Age'] = $age;
        }
        
        // Convert boolean values
        $student['IsIPCommunity'] = (bool)$student['IsIPCommunity'];
        $student['IsPWD'] = (bool)$student['IsPWD'];
        $student['Is4PsBeneficiary'] = (bool)$student['Is4PsBeneficiary'];
        
        // Rename fields to match frontend expectations
        $student['BirthDate'] = $student['DateOfBirth'];
        $student['Sex'] = $student['Gender'];
        
        // Get enrollment history
        $enrollmentQuery = "SELECT 
                                e.EnrollmentID,
                                e.AcademicYear,
                                e.LearnerType,
                                e.EnrollmentType,
                                e.Status,
                                gl.GradeLevelID,
                                gl.GradeLevelName,
                                st.StrandID,
                                st.StrandName,
                                st.StrandCode,
                                e.CreatedAt
                            FROM enrollment e
                            JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                            LEFT JOIN strand st ON e.StrandID = st.StrandID
                            WHERE e.StudentID = :studentID
                            ORDER BY e.AcademicYear DESC, e.CreatedAt DESC";
        
        $enrollmentStmt = $conn->prepare($enrollmentQuery);
        $enrollmentStmt->bindParam(':studentID', $student['StudentID'], PDO::PARAM_INT);
        $enrollmentStmt->execute();
        
        $enrollmentHistory = $enrollmentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get the most recent enrollment for quick reference
        $latestEnrollment = !empty($enrollmentHistory) ? $enrollmentHistory[0] : null;
        
        echo json_encode([
            'success' => true,
            'message' => 'Student found',
            'student' => $student,
            'enrollmentHistory' => $enrollmentHistory,
            'latestEnrollment' => $latestEnrollment
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Student not found with this LRN',
            'student' => null,
            'enrollmentHistory' => [],
            'latestEnrollment' => null
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Database error in student-by-lrn.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in student-by-lrn.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}
?>