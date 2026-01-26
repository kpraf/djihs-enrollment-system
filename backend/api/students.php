<?php
// =====================================================
// Students API
// File: backend/api/students.php
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

class StudentsAPI {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get all students with their enrollment information
     */
    public function getAllStudents() {
        try {
            $query = "SELECT 
                s.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS FullName,
                s.FirstName,
                s.LastName,
                s.MiddleName,
                s.DateOfBirth,
                s.Age,
                s.Gender,
                s.ContactNumber,
                s.EnrollmentStatus AS Status,
                e.AcademicYear,
                gl.GradeLevelName AS GradeLevel,
                st.StrandName,
                sec.SectionName AS Section,
                e.Status AS EnrollmentStatus,
                e.LearnerType,
                e.EnrollmentType
            FROM Student s
            LEFT JOIN (
                SELECT StudentID, MAX(EnrollmentID) as LatestEnrollmentID
                FROM Enrollment
                GROUP BY StudentID
            ) latest ON s.StudentID = latest.StudentID
            LEFT JOIN Enrollment e ON latest.LatestEnrollmentID = e.EnrollmentID
            LEFT JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN Strand st ON e.StrandID = st.StrandID
            LEFT JOIN (
                SELECT StudentID, SectionID, MAX(AssignmentID) as LatestAssignmentID
                FROM SectionAssignment
                WHERE IsActive = 1
                GROUP BY StudentID
            ) latestSection ON s.StudentID = latestSection.StudentID
            LEFT JOIN SectionAssignment sa ON latestSection.LatestAssignmentID = sa.AssignmentID
            LEFT JOIN Section sec ON sa.SectionID = sec.SectionID
            WHERE s.EnrollmentStatus != 'Graduated'
            ORDER BY s.LastName, s.FirstName";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $students,
                'count' => count($students)
            ];
            
        } catch (PDOException $e) {
            error_log("Get students error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error fetching students: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get student by ID with complete information
     */
    public function getStudentById($studentId) {
        try {
            $query = "SELECT 
                s.*,
                e.EnrollmentID,
                e.AcademicYear,
                e.LearnerType,
                e.EnrollmentType,
                e.Status AS EnrollmentStatus,
                gl.GradeLevelName,
                st.StrandName,
                st.StrandCode,
                sec.SectionName,
                sec.SectionID,
                CONCAT(adviser.FirstName, ' ', adviser.LastName) AS AdviserName
            FROM Student s
            LEFT JOIN (
                SELECT StudentID, MAX(EnrollmentID) as LatestEnrollmentID
                FROM Enrollment
                GROUP BY StudentID
            ) latest ON s.StudentID = latest.StudentID
            LEFT JOIN Enrollment e ON latest.LatestEnrollmentID = e.EnrollmentID
            LEFT JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN Strand st ON e.StrandID = st.StrandID
            LEFT JOIN (
                SELECT StudentID, SectionID, MAX(AssignmentID) as LatestAssignmentID
                FROM SectionAssignment
                WHERE IsActive = 1
                GROUP BY StudentID
            ) latestSection ON s.StudentID = latestSection.StudentID
            LEFT JOIN SectionAssignment sa ON latestSection.LatestAssignmentID = sa.AssignmentID
            LEFT JOIN Section sec ON sa.SectionID = sec.SectionID
            LEFT JOIN User adviser ON sec.AdviserID = adviser.UserID
            WHERE s.StudentID = :studentId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':studentId', $studentId);
            $stmt->execute();
            
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                return [
                    'success' => true,
                    'data' => $student
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Student not found'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching student: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Search students by name or LRN
     */
    public function searchStudents($searchTerm) {
        try {
            $searchTerm = "%{$searchTerm}%";
            
            $query = "SELECT 
                s.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS FullName,
                s.EnrollmentStatus AS Status,
                gl.GradeLevelName AS GradeLevel,
                sec.SectionName AS Section,
                e.Status AS EnrollmentStatus
            FROM Student s
            LEFT JOIN (
                SELECT StudentID, MAX(EnrollmentID) as LatestEnrollmentID
                FROM Enrollment
                GROUP BY StudentID
            ) latest ON s.StudentID = latest.StudentID
            LEFT JOIN Enrollment e ON latest.LatestEnrollmentID = e.EnrollmentID
            LEFT JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN (
                SELECT StudentID, SectionID
                FROM SectionAssignment
                WHERE IsActive = 1
            ) latestSection ON s.StudentID = latestSection.StudentID
            LEFT JOIN Section sec ON latestSection.SectionID = sec.SectionID
            WHERE (s.FirstName LIKE :search1 
                   OR s.LastName LIKE :search2 
                   OR s.LRN LIKE :search3
                   OR CONCAT(s.LastName, ', ', s.FirstName) LIKE :search4)
            ORDER BY s.LastName, s.FirstName
            LIMIT 50";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':search1', $searchTerm);
            $stmt->bindParam(':search2', $searchTerm);
            $stmt->bindParam(':search3', $searchTerm);
            $stmt->bindParam(':search4', $searchTerm);
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error searching students: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get statistics for dashboard
     */
    public function getStatistics() {
        try {
            $stats = [];
            
            // Total active students
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM Student WHERE EnrollmentStatus = 'Active'");
            $stmt->execute();
            $stats['totalActive'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Total enrolled this year
            $currentYear = date('Y') . '-' . (date('Y') + 1);
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM Enrollment WHERE AcademicYear = ? AND Status = 'Confirmed'");
            $stmt->execute([$currentYear]);
            $stats['enrolledThisYear'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Pending enrollments
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM Enrollment WHERE Status = 'Pending'");
            $stmt->execute();
            $stats['pendingEnrollments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // By grade level
            $stmt = $this->conn->prepare("
                SELECT gl.GradeLevelName, COUNT(DISTINCT s.StudentID) as count
                FROM Student s
                JOIN Enrollment e ON s.StudentID = e.StudentID
                JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
                WHERE s.EnrollmentStatus = 'Active' AND e.Status = 'Confirmed'
                GROUP BY gl.GradeLevelName
                ORDER BY gl.GradeLevelNumber
            ");
            $stmt->execute();
            $stats['byGradeLevel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $stats
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching statistics: ' . $e->getMessage()
            ];
        }
    }

    public function getStrands() {
        try {
            $stmt = $this->conn->prepare("
                SELECT StrandID, StrandCode, StrandName
                FROM Strand
                ORDER BY StrandCode
            ");
            $stmt->execute();

            return [
                'success' => true,
                'strands' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching strands: ' . $e->getMessage()
            ];
        }
    }
}

// =====================================================
// API Route Handler
// =====================================================

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db === null) {
        throw new Exception('Database connection failed');
    }
    
    $api = new StudentsAPI($db);
    
    // Get action parameter
    $action = $_GET['action'] ?? 'list';
    
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'list') {
                $result = $api->getAllStudents();
                echo json_encode($result);
                
            } elseif ($action === 'details') {
                $studentId = $_GET['id'] ?? null;
                if (!$studentId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Student ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getStudentById($studentId);
                echo json_encode($result);
                
            } elseif ($action === 'search') {
                $searchTerm = $_GET['q'] ?? '';
                if (empty($searchTerm)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Search term required'
                    ]);
                    exit;
                }
                
                $result = $api->searchStudents($searchTerm);
                echo json_encode($result);
                
            } elseif ($action === 'stats') {
                $result = $api->getStatistics();
                echo json_encode($result);

            } elseif ($action === 'get_strands') {
                $result = $api->getStrands();
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("Students API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>