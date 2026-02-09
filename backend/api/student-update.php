<?php
// =====================================================
// Student Update API - Enhanced with Approval Workflow
// File: backend/api/student-update-enhanced-v2.php
// Updated: 2026-02-09 - Added approval workflow for dropout/transfer
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

public function updateStudent($data) {
    try {
        $this->conn->beginTransaction();

        // Update Student table
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

        // *** ADD THIS: Update Enrollment table Status ***
        if (isset($data['EnrollmentStatus'])) {
            // Map Student.EnrollmentStatus to Enrollment.Status
            $enrollmentStatusMap = [
                'Active' => 'Confirmed',
                'Cancelled' => 'Cancelled',
                'Dropped' => 'Dropped',
                'Transferred_Out' => 'Transferred_Out',
                'Graduated' => 'Confirmed' // Graduated students have Confirmed enrollment
            ];
            
            $enrollmentStatus = $enrollmentStatusMap[$data['EnrollmentStatus']] ?? 'Confirmed';
            
            $updateEnrollmentStatusQuery = "UPDATE Enrollment e
                INNER JOIN (
                    SELECT StudentID, MAX(EnrollmentID) as LatestEnrollmentID
                    FROM Enrollment
                    WHERE StudentID = :studentId
                    GROUP BY StudentID
                ) latest ON e.EnrollmentID = latest.LatestEnrollmentID
                SET e.Status = :enrollmentStatus,
                    e.StatusChangedDate = NOW(),
                    e.StatusChangedBy = :updatedBy
                WHERE e.StudentID = :studentId2";
            
            $enrollStatusStmt = $this->conn->prepare($updateEnrollmentStatusQuery);
            $enrollStatusStmt->execute([
                ':enrollmentStatus' => $enrollmentStatus,
                ':updatedBy' => $data['UpdatedBy'],
                ':studentId' => $data['StudentID'],
                ':studentId2' => $data['StudentID']
            ]);
        }

        // Update Grade Level, Strand, Academic Year
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

        // Update Section Assignment
        if (isset($data['SectionID']) && $data['SectionID']) {
            $deactivateQuery = "UPDATE SectionAssignment 
                SET IsActive = 0 
                WHERE StudentID = :studentId AND IsActive = 1";
            $deactivateStmt = $this->conn->prepare($deactivateQuery);
            $deactivateStmt->execute([':studentId' => $data['StudentID']]);

            $getEnrollmentQuery = "SELECT MAX(EnrollmentID) as EnrollmentID 
                FROM Enrollment 
                WHERE StudentID = :studentId";
            $getEnrollmentStmt = $this->conn->prepare($getEnrollmentQuery);
            $getEnrollmentStmt->execute([':studentId' => $data['StudentID']]);
            $enrollmentId = $getEnrollmentStmt->fetch(PDO::FETCH_ASSOC)['EnrollmentID'];

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

        // *** ADD THIS: Deactivate section assignments for non-active statuses ***
        if (isset($data['EnrollmentStatus']) && 
            in_array($data['EnrollmentStatus'], ['Cancelled', 'Dropped', 'Transferred_Out'])) {
            $deactivateSections = "UPDATE SectionAssignment 
                SET IsActive = 0 
                WHERE StudentID = :studentId";
            $stmt3 = $this->conn->prepare($deactivateSections);
            $stmt3->execute([':studentId' => $data['StudentID']]);
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
     * REQUEST status change for dropout/transfer (requires approval)
     */
    public function requestStatusChange($studentId, $newStatus, $reason, $requestedBy, $additionalInfo = null) {
        try {
            $this->conn->beginTransaction();

            // Validate status
            $statusesRequiringApproval = ['Dropped', 'Transferred_Out'];
            
            if (!in_array($newStatus, $statusesRequiringApproval)) {
                throw new Exception('This status does not require approval request');
            }

            // Get latest enrollment ID and student info
            $getEnrollmentQuery = "SELECT e.EnrollmentID, s.LRN, 
                CONCAT(s.LastName, ', ', s.FirstName) as StudentName
                FROM Enrollment e
                INNER JOIN Student s ON e.StudentID = s.StudentID
                WHERE e.StudentID = :studentId
                ORDER BY e.EnrollmentID DESC
                LIMIT 1";
            $getStmt = $this->conn->prepare($getEnrollmentQuery);
            $getStmt->execute([':studentId' => $studentId]);
            $enrollmentData = $getStmt->fetch(PDO::FETCH_ASSOC);

            if (!$enrollmentData) {
                throw new Exception('No enrollment found for student');
            }

            // Create status change revision request
            $fieldsToChange = [
                [
                    'field' => 'EnrollmentStatus',
                    'oldValue' => 'Active',
                    'newValue' => $newStatus
                ]
            ];

            // Add transfer destination if provided
            if ($newStatus === 'Transferred_Out' && $additionalInfo) {
                $reason = "Transfer to: " . $additionalInfo . ". " . $reason;
            }

            $requestQuery = "INSERT INTO StudentRevisionRequest (
                StudentID, EnrollmentID, RequestedBy, RequestType,
                FieldsToChange, Justification, Priority
            ) VALUES (
                :studentId, :enrollmentId, :requestedBy, :requestType,
                :fieldsToChange, :justification, :priority
            )";
            
            $stmt = $this->conn->prepare($requestQuery);
            $stmt->execute([
                ':studentId' => $studentId,
                ':enrollmentId' => $enrollmentData['EnrollmentID'],
                ':requestedBy' => $requestedBy,
                ':requestType' => $newStatus === 'Dropped' ? 'Other' : 'Enrollment_Info',
                ':fieldsToChange' => json_encode($fieldsToChange),
                ':justification' => $reason,
                ':priority' => 'High' // Status changes are high priority
            ]);

            $requestId = $this->conn->lastInsertId();

            // Log to audit
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_REQUEST',
                null,
                json_encode([
                    'type' => 'status_change',
                    'newStatus' => $newStatus,
                    'reason' => $reason
                ]),
                $requestedBy,
                "Status change request: {$newStatus} for {$enrollmentData['StudentName']}"
            );

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Status change request submitted for approval',
                'requestId' => $requestId,
                'requiresApproval' => true
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Error creating status change request: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Change student status (with approval workflow)
     * - Cancelled: Direct (no approval needed)
     * - Dropped: Requires approval
     * - Transferred_Out: Requires approval
     */
    public function changeStatus($studentId, $newStatus, $reason, $userId, $userRole, $additionalInfo = null) {
        try {
            // Check if status requires approval
            $statusesRequiringApproval = ['Dropped', 'Transferred_Out'];
            
            if (in_array($newStatus, $statusesRequiringApproval)) {
                // Only Registrar and ICT Coordinator can approve directly
                if (!in_array($userRole, ['Registrar', 'ICT_Coordinator'])) {
                    // Create approval request instead
                    return $this->requestStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo);
                }
                
                // Registrar/ICT can proceed with direct change
                return $this->executeStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo);
            }
            
            // Cancelled doesn't require approval
            if ($newStatus === 'Cancelled') {
                return $this->executeStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo);
            }

            return [
                'success' => false,
                'message' => 'Invalid status'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error changing status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute the actual status change (called directly or after approval)
     */
    private function executeStatusChange($studentId, $newStatus, $reason, $userId, $additionalInfo = null) {
        try {
            $this->conn->beginTransaction();

            // Validate status
            $validStatuses = ['Cancelled', 'Dropped', 'Transferred_Out', 'Transferred_In'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Invalid status');
            }

            // Get latest enrollment ID
            $getEnrollmentQuery = "SELECT MAX(EnrollmentID) as EnrollmentID 
                FROM Enrollment 
                WHERE StudentID = :studentId";
            $getStmt = $this->conn->prepare($getEnrollmentQuery);
            $getStmt->execute([':studentId' => $studentId]);
            $enrollmentId = $getStmt->fetch(PDO::FETCH_ASSOC)['EnrollmentID'];

            if (!$enrollmentId) {
                throw new Exception('No enrollment found for student');
            }

            // Use stored procedure for status change (handles date tracking)
            $spQuery = "CALL sp_UpdateEnrollmentStatus(:enrollmentId, :newStatus, :userId, :reason)";
            $spStmt = $this->conn->prepare($spQuery);
            $spStmt->execute([
                ':enrollmentId' => $enrollmentId,
                ':newStatus' => $newStatus,
                ':userId' => $userId,
                ':reason' => $reason
            ]);

            // Update student table status
            $updateStudentQuery = "UPDATE Student 
                SET EnrollmentStatus = :newStatus,
                    UpdatedBy = :userId
                WHERE StudentID = :studentId";
            $stmt2 = $this->conn->prepare($updateStudentQuery);
            $stmt2->execute([
                ':newStatus' => $newStatus,
                ':userId' => $userId,
                ':studentId' => $studentId
            ]);

            // Deactivate section assignments for non-active statuses
            if (in_array($newStatus, ['Cancelled', 'Dropped', 'Transferred_Out'])) {
                $deactivateSections = "UPDATE SectionAssignment 
                    SET IsActive = 0 
                    WHERE StudentID = :studentId";
                $stmt3 = $this->conn->prepare($deactivateSections);
                $stmt3->execute([':studentId' => $studentId]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Status updated successfully'
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    public function cancelEnrollment($studentId, $reason, $userId) {
        // Cancelled doesn't require approval
        return $this->executeStatusChange($studentId, 'Cancelled', $reason, $userId);
    }

    private function logAudit($tableName, $recordId, $action, $oldValue, $newValue, $userId, $description) {
        try {
            $query = "INSERT INTO AuditLog (
                TableName, RecordID, Action, OldValue, NewValue, 
                ChangedBy, ActionDescription
            ) VALUES (
                :tableName, :recordId, :action, :oldValue, :newValue,
                :userId, :description
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':tableName' => $tableName,
                ':recordId' => $recordId,
                ':action' => $action,
                ':oldValue' => $oldValue,
                ':newValue' => $newValue,
                ':userId' => $userId,
                ':description' => $description
            ]);
        } catch (PDOException $e) {
            error_log("Audit log error: " . $e->getMessage());
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
    
    $api = new StudentUpdateAPI($db);
    $action = $_GET['action'] ?? '';
    
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
                $userId = $data['UpdatedBy'] ?? null;
                
                if (!$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'User ID required'
                    ]);
                    exit;
                }
                
                // Get user role
                $userStmt = $db->prepare("SELECT Role FROM User WHERE UserID = ?");
                $userStmt->execute([$userId]);
                $userRole = $userStmt->fetch(PDO::FETCH_ASSOC)['Role'] ?? null;
                
                if ($userRole === 'Adviser') {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Advisers must use revision request endpoint',
                        'redirect' => 'revision_request'
                    ]);
                    exit;
                }
                
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
                
            } elseif ($action === 'change_status') {
                $studentId = $data['StudentID'] ?? null;
                $newStatus = $data['NewStatus'] ?? null;
                $reason = $data['Reason'] ?? null;
                $userId = $data['UserID'] ?? null;
                $additionalInfo = $data['AdditionalInfo'] ?? null;
                
                if (!$studentId || !$newStatus || !$reason || !$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }

                // Get user role
                $userStmt = $db->prepare("SELECT Role FROM User WHERE UserID = ?");
                $userStmt->execute([$userId]);
                $userRole = $userStmt->fetch(PDO::FETCH_ASSOC)['Role'] ?? null;
                
                $result = $api->changeStatus($studentId, $newStatus, $reason, $userId, $userRole, $additionalInfo);
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