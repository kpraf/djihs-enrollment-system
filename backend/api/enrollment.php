<?php
// =====================================================
// Enhanced Enrollment API with Duplicate Prevention
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
            
            // Check for duplicate LRN enrollment in same academic year
            if (!empty($data['lrn'])) {
                $duplicateCheck = $this->conn->prepare("
                    SELECT 
                        e.EnrollmentID,
                        e.AcademicYear,
                        e.Status,
                        gl.GradeLevelName,
                        st.StrandName,
                        CONCAT(s.FirstName, ' ', s.LastName) as StudentName
                    FROM enrollment e
                    JOIN student s ON e.StudentID = s.StudentID
                    JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                    LEFT JOIN strand st ON e.StrandID = st.StrandID
                    WHERE s.LRN = :lrn 
                    AND e.AcademicYear = :academicYear
                    LIMIT 1
                ");
                
                $duplicateCheck->execute([
                    ':lrn' => $data['lrn'],
                    ':academicYear' => $data['schoolYear']
                ]);
                
                $duplicate = $duplicateCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($duplicate) {
                    $strandInfo = $duplicate['StrandName'] ? " - {$duplicate['StrandName']}" : '';
                    return [
                        'success' => false,
                        'message' => "Duplicate enrollment detected: Student with LRN {$data['lrn']} is already enrolled for {$duplicate['AcademicYear']} ({$duplicate['GradeLevelName']}{$strandInfo}). Status: {$duplicate['Status']}",
                        'duplicate' => $duplicate
                    ];
                }
            }
            
            // Validate strand consistency for Grade 11 to Grade 12 progression
            if (!empty($data['lrn']) && in_array($data['gradeLevel'], [5, 6])) {
                $previousEnrollment = $this->conn->prepare("
                    SELECT 
                        e.GradeLevelID,
                        e.StrandID,
                        st.StrandName,
                        e.AcademicYear
                    FROM enrollment e
                    JOIN student s ON e.StudentID = s.StudentID
                    LEFT JOIN strand st ON e.StrandID = st.StrandID
                    WHERE s.LRN = :lrn
                    AND e.Status IN ('Confirmed', 'Pending')
                    ORDER BY e.AcademicYear DESC, e.CreatedAt DESC
                    LIMIT 1
                ");
                
                $previousEnrollment->execute([':lrn' => $data['lrn']]);
                $previous = $previousEnrollment->fetch(PDO::FETCH_ASSOC);
                
                // If student was Grade 11 and now enrolling in Grade 12
                if ($previous && $previous['GradeLevelID'] == 5 && $data['gradeLevel'] == 6) {
                    if ($previous['StrandID'] && $data['strandID'] && $previous['StrandID'] != $data['strandID']) {
                        $strandNames = [
                            1 => 'ABM', 2 => 'HUMSS', 3 => 'STEM',
                            4 => 'HE-COOKERY', 5 => 'ICT-CSS', 6 => 'IA-EIM'
                        ];
                        
                        return [
                            'success' => false,
                            'message' => "Strand mismatch: Student was enrolled in {$previous['StrandName']} for Grade 11 ({$previous['AcademicYear']}). Cannot change to {$strandNames[$data['strandID']]} for Grade 12."
                        ];
                    }
                }
            }
            
            // Set default values for new fields
            $weight = !empty($data['weight']) ? floatval($data['weight']) : null;
            $height = !empty($data['height']) ? floatval($data['height']) : null;
            $is4PsBeneficiary = isset($data['is4PsBeneficiary']) ? (int)$data['is4PsBeneficiary'] : 0;
            $zipCode = !empty($data['zipCode']) ? trim($data['zipCode']) : null;
            $country = !empty($data['country']) ? trim($data['country']) : 'Philippines';
            
            // Set enrollment type - auto-determine based on learner type if not provided
            if (empty($data['enrollmentType'])) {
                $data['enrollmentType'] = $this->determineEnrollmentType($data['learnerType']);
            }
            
            // Validate enrollment type
            $validEnrollmentTypes = ['Regular', 'Late', 'Transferee'];
            if (!in_array($data['enrollmentType'], $validEnrollmentTypes)) {
                return [
                    'success' => false,
                    'message' => 'Invalid enrollment type. Must be Regular, Late, or Transferee'
                ];
            }
            
            // Check if student exists by LRN
            $existingStudent = null;
            if (!empty($data['lrn'])) {
                $studentCheck = $this->conn->prepare("SELECT StudentID FROM student WHERE LRN = :lrn LIMIT 1");
                $studentCheck->execute([':lrn' => $data['lrn']]);
                $existingStudent = $studentCheck->fetch(PDO::FETCH_ASSOC);
            }
            
            // Begin transaction
            $this->conn->beginTransaction();
            
            $studentID = null;
            
            if ($existingStudent) {
                // Student exists - use existing StudentID
                $studentID = $existingStudent['StudentID'];
                
                // Optionally update student information
                $updateQuery = "UPDATE student SET
                    LastName = :lastName,
                    FirstName = :firstName,
                    MiddleName = :middleName,
                    ExtensionName = :extensionName,
                    DateOfBirth = :birthdate,
                    Age = :age,
                    Gender = :sex,
                    Religion = :religion,
                    IsIPCommunity = :isIPCommunity,
                    IPCommunitySpecify = :ipCommunitySpecify,
                    IsPWD = :isPWD,
                    PWDSpecify = :pwdSpecify,
                    HouseNumber = :houseNumber,
                    SitioStreet = :sitioStreet,
                    Barangay = :barangay,
                    Municipality = :municipality,
                    Province = :province,
                    ZipCode = :zipCode,
                    Country = :country,
                    Weight = :weight,
                    Height = :height,
                    Is4PsBeneficiary = :is4PsBeneficiary,
                    FatherLastName = :fatherLastName,
                    FatherFirstName = :fatherFirstName,
                    FatherMiddleName = :fatherMiddleName,
                    MotherLastName = :motherLastName,
                    MotherFirstName = :motherFirstName,
                    MotherMiddleName = :motherMiddleName,
                    GuardianLastName = :guardianLastName,
                    GuardianFirstName = :guardianFirstName,
                    GuardianMiddleName = :guardianMiddleName,
                    ContactNumber = :contactNumber,
                    EncodedDate = NOW(),
                    EncodedBy = :encodedBy
                WHERE StudentID = :studentID";
                
                $updateStmt = $this->conn->prepare($updateQuery);
                
                $isIPCommunity = $data['isIPCommunity'] ? 1 : 0;
                $isPWD = $data['isPWD'] ? 1 : 0;
                
                $updateStmt->bindParam(':lastName', $data['lastName']);
                $updateStmt->bindParam(':firstName', $data['firstName']);
                $updateStmt->bindParam(':middleName', $data['middleName']);
                $updateStmt->bindParam(':extensionName', $data['extensionName']);
                $updateStmt->bindParam(':birthdate', $data['birthdate']);
                $updateStmt->bindParam(':age', $data['age'], PDO::PARAM_INT);
                $updateStmt->bindParam(':sex', $data['sex']);
                $updateStmt->bindParam(':religion', $data['religion']);
                $updateStmt->bindParam(':isIPCommunity', $isIPCommunity, PDO::PARAM_INT);
                $updateStmt->bindParam(':ipCommunitySpecify', $data['ipCommunitySpecify']);
                $updateStmt->bindParam(':isPWD', $isPWD, PDO::PARAM_INT);
                $updateStmt->bindParam(':pwdSpecify', $data['pwdSpecify']);
                $updateStmt->bindParam(':houseNumber', $data['houseNumber']);
                $updateStmt->bindParam(':sitioStreet', $data['sitioStreet']);
                $updateStmt->bindParam(':barangay', $data['barangay']);
                $updateStmt->bindParam(':municipality', $data['municipality']);
                $updateStmt->bindParam(':province', $data['province']);
                $updateStmt->bindParam(':zipCode', $zipCode);
                $updateStmt->bindParam(':country', $country);
                $updateStmt->bindParam(':weight', $weight);
                $updateStmt->bindParam(':height', $height);
                $updateStmt->bindParam(':is4PsBeneficiary', $is4PsBeneficiary, PDO::PARAM_INT);
                $updateStmt->bindParam(':fatherLastName', $data['fatherLastName']);
                $updateStmt->bindParam(':fatherFirstName', $data['fatherFirstName']);
                $updateStmt->bindParam(':fatherMiddleName', $data['fatherMiddleName']);
                $updateStmt->bindParam(':motherLastName', $data['motherLastName']);
                $updateStmt->bindParam(':motherFirstName', $data['motherFirstName']);
                $updateStmt->bindParam(':motherMiddleName', $data['motherMiddleName']);
                $updateStmt->bindParam(':guardianLastName', $data['guardianLastName']);
                $updateStmt->bindParam(':guardianFirstName', $data['guardianFirstName']);
                $updateStmt->bindParam(':guardianMiddleName', $data['guardianMiddleName']);
                $updateStmt->bindParam(':contactNumber', $data['contactNumber']);
                $updateStmt->bindParam(':encodedBy', $data['createdBy'], PDO::PARAM_INT);
                $updateStmt->bindParam(':studentID', $studentID, PDO::PARAM_INT);
                
                $updateStmt->execute();
                
            } else {
                // New student - insert record
                $studentQuery = "INSERT INTO Student (
                    LRN, LastName, FirstName, MiddleName, ExtensionName,
                    DateOfBirth, Age, Gender, Religion,
                    IsIPCommunity, IPCommunitySpecify, IsPWD, PWDSpecify,
                    HouseNumber, SitioStreet, Barangay, Municipality, Province,
                    ZipCode, Country,
                    Weight, Height, Is4PsBeneficiary,
                    FatherLastName, FatherFirstName, FatherMiddleName,
                    MotherLastName, MotherFirstName, MotherMiddleName,
                    GuardianLastName, GuardianFirstName, GuardianMiddleName,
                    ContactNumber, EnrollmentStatus, IsTransferee,
                    EncodedDate, EncodedBy,
                    CreatedBy, CreatedAt
                ) VALUES (
                    :lrn, :lastName, :firstName, :middleName, :extensionName,
                    :birthdate, :age, :sex, :religion,
                    :isIPCommunity, :ipCommunitySpecify, :isPWD, :pwdSpecify,
                    :houseNumber, :sitioStreet, :barangay, :municipality, :province,
                    :zipCode, :country,
                    :weight, :height, :is4PsBeneficiary,
                    :fatherLastName, :fatherFirstName, :fatherMiddleName,
                    :motherLastName, :motherFirstName, :motherMiddleName,
                    :guardianLastName, :guardianFirstName, :guardianMiddleName,
                    :contactNumber, 'Active', :isTransferee,
                    NOW(), :encodedBy,
                    :createdBy, NOW()
                )";
                
                $stmt = $this->conn->prepare($studentQuery);
                
                $isTransferee = in_array($data['learnerType'], [
                    'Irregular_Transferee'
                ]) ? 1 : 0;
                
                $isIPCommunity = $data['isIPCommunity'] ? 1 : 0;
                $isPWD = $data['isPWD'] ? 1 : 0;
                
                $stmt->bindParam(':lrn', $data['lrn']);
                $stmt->bindParam(':lastName', $data['lastName']);
                $stmt->bindParam(':firstName', $data['firstName']);
                $stmt->bindParam(':middleName', $data['middleName']);
                $stmt->bindParam(':extensionName', $data['extensionName']);
                $stmt->bindParam(':birthdate', $data['birthdate']);
                $stmt->bindParam(':age', $data['age'], PDO::PARAM_INT);
                $stmt->bindParam(':sex', $data['sex']);
                $stmt->bindParam(':religion', $data['religion']);
                $stmt->bindParam(':isIPCommunity', $isIPCommunity, PDO::PARAM_INT);
                $stmt->bindParam(':ipCommunitySpecify', $data['ipCommunitySpecify']);
                $stmt->bindParam(':isPWD', $isPWD, PDO::PARAM_INT);
                $stmt->bindParam(':pwdSpecify', $data['pwdSpecify']);
                $stmt->bindParam(':houseNumber', $data['houseNumber']);
                $stmt->bindParam(':sitioStreet', $data['sitioStreet']);
                $stmt->bindParam(':barangay', $data['barangay']);
                $stmt->bindParam(':municipality', $data['municipality']);
                $stmt->bindParam(':province', $data['province']);
                $stmt->bindParam(':zipCode', $zipCode);
                $stmt->bindParam(':country', $country);
                $stmt->bindParam(':weight', $weight);
                $stmt->bindParam(':height', $height);
                $stmt->bindParam(':is4PsBeneficiary', $is4PsBeneficiary, PDO::PARAM_INT);
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
                $stmt->bindParam(':isTransferee', $isTransferee, PDO::PARAM_INT);
                $stmt->bindParam(':encodedBy', $data['createdBy'], PDO::PARAM_INT);
                $stmt->bindParam(':createdBy', $data['createdBy'], PDO::PARAM_INT);
                
                $stmt->execute();
                $studentID = $this->conn->lastInsertId();
            }
            
            // Insert Enrollment record
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
            
            $stmt2->bindParam(':studentID', $studentID, PDO::PARAM_INT);
            $stmt2->bindParam(':gradeLevel', $data['gradeLevel'], PDO::PARAM_INT);
            $stmt2->bindParam(':strandID', $data['strandID'], PDO::PARAM_INT);
            $stmt2->bindParam(':schoolYear', $data['schoolYear']);
            $stmt2->bindParam(':learnerType', $data['learnerType']);
            $stmt2->bindParam(':enrollmentType', $data['enrollmentType']);
            
            $stmt2->execute();
            $enrollmentID = $this->conn->lastInsertId();
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => $existingStudent ? 'Enrollment created for existing student' : 'New student enrolled successfully',
                'studentID' => $studentID,
                'enrollmentID' => $enrollmentID,
                'isNewStudent' => !$existingStudent,
                'data' => [
                    'studentName' => $data['firstName'] . ' ' . $data['lastName'],
                    'lrn' => $data['lrn'],
                    'gradeLevel' => $data['gradeLevel'],
                    'schoolYear' => $data['schoolYear'],
                    'enrollmentType' => $data['enrollmentType'],
                    'status' => 'Pending'
                ]
            ];
            
        } catch (PDOException $e) {
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
     * Helper: Determine enrollment type based on learner type
     */
    private function determineEnrollmentType($learnerType) {
        $transfereeTypes = [
            'Irregular_Transferee',
            'Regular_Balik_Aral',
            'Irregular_Balik_Aral'
        ];
        
        if (in_array($learnerType, $transfereeTypes)) {
            return 'Transferee';
        }
        
        return 'Regular';
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
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS FullName,
                CONCAT(
                    IFNULL(s.HouseNumber, ''), ' ',
                    IFNULL(s.SitioStreet, ''), ', ',
                    s.Barangay, ', ',
                    s.Municipality, ', ',
                    s.Province,
                    IFNULL(CONCAT(' ', s.ZipCode), ''),
                    IFNULL(CONCAT(', ', s.Country), '')
                ) AS CompleteAddress
            FROM Enrollment e
            JOIN Student s ON e.StudentID = s.StudentID
            JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN Strand st ON e.StrandID = st.StrandID
            WHERE e.EnrollmentID = :enrollmentID";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':enrollmentID', $enrollmentID, PDO::PARAM_INT);
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
        $this->conn->beginTransaction();

        // Update enrollment status
        $query = "UPDATE Enrollment 
                  SET Status = 'Confirmed',
                      ProcessedBy = :reviewerID,
                      ProcessedDate = NOW()
                  WHERE EnrollmentID = :enrollmentID";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':enrollmentID', $enrollmentID, PDO::PARAM_INT);
        $stmt->bindParam(':reviewerID', $reviewerID, PDO::PARAM_INT);
        $stmt->execute();

        // Auto-create documentsubmission record if it doesn't exist yet
        $docQuery = "INSERT INTO documentsubmission (StudentID, EnrollmentID, AcademicYear, CreatedBy)
                     SELECT e.StudentID, e.EnrollmentID, e.AcademicYear, :reviewerID
                     FROM enrollment e
                     WHERE e.EnrollmentID = :enrollmentID
                     AND NOT EXISTS (
                         SELECT 1 FROM documentsubmission ds 
                         WHERE ds.EnrollmentID = :enrollmentID
                     )";

        $docStmt = $this->conn->prepare($docQuery);
        $docStmt->bindParam(':enrollmentID', $enrollmentID, PDO::PARAM_INT);
        $docStmt->bindParam(':reviewerID', $reviewerID, PDO::PARAM_INT);
        $docStmt->execute();

        $this->conn->commit();

        return [
            'success' => true,
            'message' => 'Enrollment approved successfully'
        ];

    } catch (PDOException $e) {
        if ($this->conn->inTransaction()) {
            $this->conn->rollBack();
        }
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
            $query = "UPDATE Enrollment 
                      SET Status = 'Cancelled',
                          ProcessedBy = :reviewerID,
                          ProcessedDate = NOW(),
                          Remarks = :reason
                      WHERE EnrollmentID = :enrollmentID";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':enrollmentID', $enrollmentID, PDO::PARAM_INT);
            $stmt->bindParam(':reviewerID', $reviewerID, PDO::PARAM_INT);
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
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db === null) {
        throw new Exception('Database connection failed');
    }
    
    $api = new EnrollmentAPI($db);
    
    $action = $_GET['action'] ?? 'submit';
    
    error_log("Enrollment API - Method: {$_SERVER['REQUEST_METHOD']}, Action: {$action}");
    
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