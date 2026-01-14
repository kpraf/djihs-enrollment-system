<?php
// =====================================================
// Enrollment API - Updated for New Schema
// File: backend/api/enrollment.php
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

class EnrollmentAPI {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Submit enrollment form - creates Student and Enrollment records
     */
    public function submitEnrollment($data) {
        try {
            // Validate required fields
            $required = [
                'schoolYear', 'gradeLevel', 'learnerType',
                'lastName', 'firstName', 'birthdate', 'age', 'sex',
                'barangay', 'municipality', 'province', 'contactNumber',
                'createdBy'
            ];
            
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }
            
            // Validate user permissions
            $userCheck = $this->conn->prepare(
                "SELECT Role FROM User WHERE UserID = ? AND IsActive = 1"
            );
            $userCheck->execute([$data['createdBy']]);
            $user = $userCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Invalid user or user is not active'
                ];
            }
            
            if (!in_array($user['Role'], ['ICT_Coordinator', 'Registrar', 'Adviser'])) {
                return [
                    'success' => false,
                    'message' => 'User does not have permission to enter enrollment forms'
                ];
            }
            
            // Validate strand for Grade 11 & 12
            if (in_array($data['gradeLevel'], [5, 6]) && empty($data['strandID'])) {
                return [
                    'success' => false,
                    'message' => 'Strand/Track is required for Grade 11 & 12'
                ];
            }
            
            // Begin transaction
            $this->conn->beginTransaction();
            
            // 1. Insert Student record
            $studentQuery = "INSERT INTO Student (
                LRN, LastName, FirstName, MiddleName, ExtensionName,
                DateOfBirth, Age, Gender, Religion,
                IsIPCommunity, IPCommunitySpecify, IsPWD, PWDSpecify,
                HouseNumber, SitioStreet, Barangay, Municipality, Province,
                FatherLastName, FatherFirstName, FatherMiddleName,
                MotherLastName, MotherFirstName, MotherMiddleName,
                GuardianLastName, GuardianFirstName, GuardianMiddleName,
                ContactNumber, EnrollmentStatus, IsTransferee,
                CreatedBy, CreatedAt
            ) VALUES (
                :lrn, :lastName, :firstName, :middleName, :extensionName,
                :birthdate, :age, :sex, :religion,
                :isIPCommunity, :ipCommunitySpecify, :isPWD, :pwdSpecify,
                :houseNumber, :sitioStreet, :barangay, :municipality, :province,
                :fatherLastName, :fatherFirstName, :fatherMiddleName,
                :motherLastName, :motherFirstName, :motherMiddleName,
                :guardianLastName, :guardianFirstName, :guardianMiddleName,
                :contactNumber, 'Active', :isTransferee,
                :createdBy, NOW()
            )";
            
            $stmt = $this->conn->prepare($studentQuery);
            
            // Determine if transferee
            $isTransferee = in_array($data['learnerType'], [
                'Irregular_Transferee'
            ]) ? 1 : 0;
            
            // Bind student parameters
            $stmt->bindParam(':lrn', $data['lrn']);
            $stmt->bindParam(':lastName', $data['lastName']);
            $stmt->bindParam(':firstName', $data['firstName']);
            $stmt->bindParam(':middleName', $data['middleName']);
            $stmt->bindParam(':extensionName', $data['extensionName']);
            $stmt->bindParam(':birthdate', $data['birthdate']);
            $stmt->bindParam(':age', $data['age']);
            $stmt->bindParam(':sex', $data['sex']);
            $stmt->bindParam(':religion', $data['religion']);
            
            $isIPCommunity = $data['isIPCommunity'] ? 1 : 0;
            $isPWD = $data['isPWD'] ? 1 : 0;
            $stmt->bindParam(':isIPCommunity', $isIPCommunity);
            $stmt->bindParam(':ipCommunitySpecify', $data['ipCommunitySpecify']);
            $stmt->bindParam(':isPWD', $isPWD);
            $stmt->bindParam(':pwdSpecify', $data['pwdSpecify']);
            
            $stmt->bindParam(':houseNumber', $data['houseNumber']);
            $stmt->bindParam(':sitioStreet', $data['sitioStreet']);
            $stmt->bindParam(':barangay', $data['barangay']);
            $stmt->bindParam(':municipality', $data['municipality']);
            $stmt->bindParam(':province', $data['province']);
            
            $stmt->bindParam(':fatherLastName', $data['fatherLastName']);
            $stmt->bindParam(':fatherFirstName', $data['fatherFirstName']);
            $stmt->bindParam(':fatherMiddleName', $data['fatherMiddleName']);
            
            $stmt->bindParam(':motherLastName', $data['motherLastName']);
            $stmt->bindParam(':motherFirstName', $data['motherFirstName']);
            $stmt->bindParam(':motherMiddleName', $data['motherMiddleName']);
            
            $stmt->bindParam(':guardianLastName', $data['guardianLastName']);
            $stmt->bindParam(':guardianFirstName', $data['guardianFirstName']);
            $stmt->bindParam(':guardianMiddleName', $data['guardianMiddleName']);
            
            $stmt->bindParam(':contactNumber', $data['contactNumber']);
            $stmt->bindParam(':isTransferee', $isTransferee);
            $stmt->bindParam(':createdBy', $data['createdBy']);
            
            $stmt->execute();
            $studentID = $this->conn->lastInsertId();
            
            // 2. Insert Enrollment record
            $enrollmentQuery = "INSERT INTO Enrollment (
                StudentID, GradeLevelID, StrandID, AcademicYear,
                LearnerType, EnrollmentType, Status,
                CreatedAt
            ) VALUES (
                :studentID, :gradeLevel, :strandID, :schoolYear,
                :learnerType, :enrollmentType, 'Pending',
                NOW()
            )";
            
            $stmt2 = $this->conn->prepare($enrollmentQuery);
            
            // Determine enrollment type
            $enrollmentType = ($isTransferee || 
                             $data['learnerType'] === 'Regular_Balik_Aral' ||
                             $data['learnerType'] === 'Irregular_Balik_Aral') 
                             ? 'Transferee' : 'Regular';
            
            $stmt2->bindParam(':studentID', $studentID);
            $stmt2->bindParam(':gradeLevel', $data['gradeLevel']);
            $stmt2->bindParam(':strandID', $data['strandID']);
            $stmt2->bindParam(':schoolYear', $data['schoolYear']);
            $stmt2->bindParam(':learnerType', $data['learnerType']);
            $stmt2->bindParam(':enrollmentType', $enrollmentType);
            
            $stmt2->execute();
            $enrollmentID = $this->conn->lastInsertId();
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Enrollment submitted successfully',
                'studentID' => $studentID,
                'enrollmentID' => $enrollmentID,
                'data' => [
                    'studentName' => $data['firstName'] . ' ' . $data['lastName'],
                    'lrn' => $data['lrn'],
                    'gradeLevel' => $data['gradeLevel'],
                    'schoolYear' => $data['schoolYear'],
                    'status' => 'Pending'
                ]
            ];
            
        } catch (PDOException $e) {
            // Rollback on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            
            error_log("Enrollment submission error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get pending enrollments
     */
    public function getPendingEnrollments() {
        try {
            $query = "SELECT 
                e.EnrollmentID,
                e.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS StudentName,
                gl.GradeLevelName,
                st.StrandName,
                e.LearnerType,
                e.EnrollmentType,
                e.AcademicYear,
                e.Status,
                e.CreatedAt AS EnrollmentDate
            FROM Enrollment e
            JOIN Student s ON e.StudentID = s.StudentID
            JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN Strand st ON e.StrandID = st.StrandID
            WHERE e.Status = 'Pending'
            ORDER BY e.CreatedAt DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching enrollments: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get enrollment details
     */
    public function getEnrollmentDetails($enrollmentID) {
        try {
            $query = "SELECT 
                e.*,
                s.*,
                gl.GradeLevelName,
                st.StrandName,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS FullName
            FROM Enrollment e
            JOIN Student s ON e.StudentID = s.StudentID
            JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN Strand st ON e.StrandID = st.StrandID
            WHERE e.EnrollmentID = :enrollmentID";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':enrollmentID', $enrollmentID);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'success' => true,
                    'data' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Enrollment not found'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching details: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Approve enrollment
     */
    public function approveEnrollment($enrollmentID, $reviewerID) {
        try {
            // Update enrollment status
            $query = "UPDATE Enrollment 
                      SET Status = 'Confirmed',
                          ProcessedBy = :reviewerID,
                          ProcessedDate = NOW()
                      WHERE EnrollmentID = :enrollmentID";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':enrollmentID', $enrollmentID);
            $stmt->bindParam(':reviewerID', $reviewerID);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Enrollment approved successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to approve enrollment'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error approving enrollment: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Reject enrollment
     */
    public function rejectEnrollment($enrollmentID, $reviewerID, $reason) {
        try {
            // Update enrollment status
            $query = "UPDATE Enrollment 
                      SET Status = 'Cancelled',
                          ProcessedBy = :reviewerID,
                          ProcessedDate = NOW(),
                          Remarks = :reason
                      WHERE EnrollmentID = :enrollmentID";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':enrollmentID', $enrollmentID);
            $stmt->bindParam(':reviewerID', $reviewerID);
            $stmt->bindParam(':reason', $reason);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Enrollment rejected'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to reject enrollment'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error rejecting enrollment: ' . $e->getMessage()
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
    
    $api = new EnrollmentAPI($db);
    
    // Get action parameter
    $action = $_GET['action'] ?? 'submit';
    
    // Log the request for debugging
    error_log("Enrollment API - Method: {$_SERVER['REQUEST_METHOD']}, Action: {$action}");
    
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            $inputData = json_decode(file_get_contents('php://input'), true);
            error_log("POST Data: " . json_encode($inputData));
            
            if ($action === 'submit') {
                $result = $api->submitEnrollment($inputData);
                echo json_encode($result);
                
            } elseif ($action === 'approve') {
                if (!isset($inputData['enrollmentID']) || !isset($inputData['reviewerID'])) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing enrollmentID or reviewerID'
                    ]);
                    exit;
                }
                $result = $api->approveEnrollment($inputData['enrollmentID'], $inputData['reviewerID']);
                echo json_encode($result);
                
            } elseif ($action === 'reject') {
                if (!isset($inputData['enrollmentID']) || !isset($inputData['reviewerID']) || !isset($inputData['reason'])) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing enrollmentID, reviewerID, or reason'
                    ]);
                    exit;
                }
                $result = $api->rejectEnrollment($inputData['enrollmentID'], $inputData['reviewerID'], $inputData['reason']);
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action for POST request: ' . $action
                ]);
            }
            break;
            
        case 'GET':
            if ($action === 'pending') {
                $result = $api->getPendingEnrollments();
                echo json_encode($result);
                
            } elseif ($action === 'details') {
                $enrollmentID = $_GET['id'] ?? null;
                if (!$enrollmentID) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Enrollment ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getEnrollmentDetails($enrollmentID);
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action for GET request: ' . $action
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
    error_log("Enrollment API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>