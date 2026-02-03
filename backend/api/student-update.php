<?php
// =====================================================
// Student Update API
// File: backend/api/student-update.php
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

class StudentUpdateAPI {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get all grade levels
     */
    public function getGradeLevels() {
        try {
            $stmt = $this->conn->prepare("
                SELECT GradeLevelID, GradeLevelName, GradeLevelNumber, Department
                FROM GradeLevel
                WHERE IsActive = 1
                ORDER BY GradeLevelNumber
            ");
            $stmt->execute();

            return [
                'success' => true,
                'gradeLevels' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching grade levels: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get sections filtered by grade level and optional strand
     */
    public function getSections($gradeLevelId, $strandId = null) {
        try {
            $query = "
                SELECT SectionID, SectionName, Capacity, CurrentEnrollment
                FROM Section
                WHERE GradeLevelID = :gradeLevelId
                AND IsActive = 1
            ";
            
            if ($strandId) {
                $query .= " AND StrandID = :strandId";
            }
            
            $query .= " ORDER BY SectionName";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':gradeLevelId', $gradeLevelId);
            if ($strandId) {
                $stmt->bindParam(':strandId', $strandId);
            }
            $stmt->execute();

            return [
                'success' => true,
                'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching sections: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update student information
     */
    public function updateStudent($data) {
        try {
            $this->conn->beginTransaction();

            // Update student record
            $query = "UPDATE Student SET
                LRN = :lrn,
                LastName = :lastName,
                FirstName = :firstName,
                MiddleName = :middleName,
                ExtensionName = :extensionName,
                DateOfBirth = :dateOfBirth,
                Age = :age,
                Gender = :gender,
                Religion = :religion,
                ContactNumber = :contactNumber,
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
                FatherLastName = :fatherLastName,
                FatherFirstName = :fatherFirstName,
                FatherMiddleName = :fatherMiddleName,
                MotherLastName = :motherLastName,
                MotherFirstName = :motherFirstName,
                MotherMiddleName = :motherMiddleName,
                GuardianLastName = :guardianLastName,
                GuardianFirstName = :guardianFirstName,
                GuardianMiddleName = :guardianMiddleName,
                EnrollmentStatus = :enrollmentStatus,
                UpdatedBy = :updatedBy
            WHERE StudentID = :studentId";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':lrn' => $data['LRN'],
                ':lastName' => $data['LastName'],
                ':firstName' => $data['FirstName'],
                ':middleName' => $data['MiddleName'],
                ':extensionName' => $data['ExtensionName'],
                ':dateOfBirth' => $data['DateOfBirth'],
                ':age' => $data['Age'],
                ':gender' => $data['Gender'],
                ':religion' => $data['Religion'],
                ':contactNumber' => $data['ContactNumber'],
                ':isIPCommunity' => $data['IsIPCommunity'],
                ':ipCommunitySpecify' => $data['IPCommunitySpecify'],
                ':isPWD' => $data['IsPWD'],
                ':pwdSpecify' => $data['PWDSpecify'],
                ':houseNumber' => $data['HouseNumber'],
                ':sitioStreet' => $data['SitioStreet'],
                ':barangay' => $data['Barangay'],
                ':municipality' => $data['Municipality'],
                ':province' => $data['Province'],
                ':zipCode' => $data['ZipCode'],
                ':fatherLastName' => $data['FatherLastName'],
                ':fatherFirstName' => $data['FatherFirstName'],
                ':fatherMiddleName' => $data['FatherMiddleName'],
                ':motherLastName' => $data['MotherLastName'],
                ':motherFirstName' => $data['MotherFirstName'],
                ':motherMiddleName' => $data['MotherMiddleName'],
                ':guardianLastName' => $data['GuardianLastName'],
                ':guardianFirstName' => $data['GuardianFirstName'],
                ':guardianMiddleName' => $data['GuardianMiddleName'],
                ':enrollmentStatus' => $data['EnrollmentStatus'],
                ':updatedBy' => $data['UpdatedBy'],
                ':studentId' => $data['StudentID']
            ]);

            // Update enrollment if grade level or strand changed
            if (isset($data['GradeLevelID'])) {
                $enrollQuery = "UPDATE Enrollment e
                    INNER JOIN (
                        SELECT StudentID, MAX(EnrollmentID) as LatestEnrollmentID
                        FROM Enrollment
                        WHERE StudentID = :studentId
                        GROUP BY StudentID
                    ) latest ON e.EnrollmentID = latest.LatestEnrollmentID
                    SET e.GradeLevelID = :gradeLevelId,
                        e.StrandID = :strandId,
                        e.AcademicYear = :academicYear
                    WHERE e.StudentID = :studentId2";
                
                $enrollStmt = $this->conn->prepare($enrollQuery);
                $enrollStmt->execute([
                    ':gradeLevelId' => $data['GradeLevelID'],
                    ':strandId' => $data['StrandID'],
                    ':academicYear' => $data['AcademicYear'],
                    ':studentId' => $data['StudentID'],
                    ':studentId2' => $data['StudentID']
                ]);
            }

            // Update section assignment if changed
            if (isset($data['SectionID']) && $data['SectionID']) {
                // Deactivate old assignments
                $deactivateQuery = "UPDATE SectionAssignment 
                    SET IsActive = 0 
                    WHERE StudentID = :studentId AND IsActive = 1";
                $deactivateStmt = $this->conn->prepare($deactivateQuery);
                $deactivateStmt->execute([':studentId' => $data['StudentID']]);

                // Get latest enrollment ID
                $getEnrollmentQuery = "SELECT MAX(EnrollmentID) as EnrollmentID 
                    FROM Enrollment 
                    WHERE StudentID = :studentId";
                $getEnrollmentStmt = $this->conn->prepare($getEnrollmentQuery);
                $getEnrollmentStmt->execute([':studentId' => $data['StudentID']]);
                $enrollmentId = $getEnrollmentStmt->fetch(PDO::FETCH_ASSOC)['EnrollmentID'];

                // Create new assignment
                $assignQuery = "INSERT INTO SectionAssignment 
                    (StudentID, SectionID, EnrollmentID, AssignmentMethod, AssignedBy, IsActive)
                    VALUES (:studentId, :sectionId, :enrollmentId, 'Manual', :assignedBy, 1)";
                $assignStmt = $this->conn->prepare($assignQuery);
                $assignStmt->execute([
                    ':studentId' => $data['StudentID'],
                    ':sectionId' => $data['SectionID'],
                    ':enrollmentId' => $enrollmentId,
                    ':assignedBy' => $data['UpdatedBy']
                ]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Student updated successfully'
            ];

        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Update student error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error updating student: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Add remarks to a student
     */
    public function addRemarks($studentId, $remarks, $userId) {
        try {
            $query = "UPDATE Enrollment 
                SET Remarks = CONCAT(IFNULL(Remarks, ''), '\n[', NOW(), ' - ', 
                    (SELECT CONCAT(FirstName, ' ', LastName) FROM User WHERE UserID = :userId), 
                    ']: ', :remarks)
                WHERE StudentID = :studentId 
                AND EnrollmentID = (
                    SELECT MAX(EnrollmentID) 
                    FROM (SELECT * FROM Enrollment) e2 
                    WHERE e2.StudentID = :studentId2
                )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':studentId' => $studentId,
                ':studentId2' => $studentId,
                ':userId' => $userId,
                ':remarks' => $remarks
            ]);

            return [
                'success' => true,
                'message' => 'Remarks added successfully'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error adding remarks: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Cancel student enrollment
     */
    public function cancelEnrollment($studentId, $reason, $userId) {
        try {
            $this->conn->beginTransaction();

            // Update student status
            $updateStudent = "UPDATE Student 
                SET EnrollmentStatus = 'Cancelled', 
                    UpdatedBy = :userId 
                WHERE StudentID = :studentId";
            $stmt = $this->conn->prepare($updateStudent);
            $stmt->execute([
                ':userId' => $userId,
                ':studentId' => $studentId
            ]);

            // Update enrollment status
            $updateEnrollment = "UPDATE Enrollment 
                SET Status = 'Cancelled',
                    Remarks = CONCAT(IFNULL(Remarks, ''), '\n[CANCELLED - ', NOW(), ']: ', :reason)
                WHERE StudentID = :studentId 
                AND EnrollmentID = (
                    SELECT MAX(EnrollmentID) 
                    FROM (SELECT * FROM Enrollment) e2 
                    WHERE e2.StudentID = :studentId2
                )";
            $stmt2 = $this->conn->prepare($updateEnrollment);
            $stmt2->execute([
                ':studentId' => $studentId,
                ':studentId2' => $studentId,
                ':reason' => $reason
            ]);

            // Deactivate section assignments
            $deactivateSections = "UPDATE SectionAssignment 
                SET IsActive = 0 
                WHERE StudentID = :studentId";
            $stmt3 = $this->conn->prepare($deactivateSections);
            $stmt3->execute([':studentId' => $studentId]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Enrollment cancelled successfully'
            ];
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Error cancelling enrollment: ' . $e->getMessage()
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
    
    $api = new StudentUpdateAPI($db);
    
    // Get action parameter
    $action = $_GET['action'] ?? '';
    
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'get_grade_levels') {
                $result = $api->getGradeLevels();
                echo json_encode($result);
                
            } elseif ($action === 'get_sections') {
                $gradeLevelId = $_GET['grade_level'] ?? null;
                $strandId = $_GET['strand_id'] ?? null;
                
                if (!$gradeLevelId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Grade level ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getSections($gradeLevelId, $strandId);
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($action === 'update') {
                $result = $api->updateStudent($data);
                echo json_encode($result);
                
            } elseif ($action === 'add_remarks') {
                $studentId = $data['StudentID'] ?? null;
                $remarks = $data['Remarks'] ?? null;
                $userId = $data['UserID'] ?? null;
                
                if (!$studentId || !$remarks || !$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }
                
                $result = $api->addRemarks($studentId, $remarks, $userId);
                echo json_encode($result);
                
            } elseif ($action === 'cancel_enrollment') {
                $studentId = $data['StudentID'] ?? null;
                $reason = $data['Reason'] ?? null;
                $userId = $data['UserID'] ?? null;
                
                if (!$studentId || !$reason || !$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }
                
                $result = $api->cancelEnrollment($studentId, $reason, $userId);
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
    error_log("Student Update API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>