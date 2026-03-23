<?php
// =====================================================
// Student Revision Request API - REVISED FOR NORMALIZED DB
// File: backend/api/revision-requests.php
// Updated: 2026-03-04
// Revised to work with normalized database schema
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

class RevisionRequestAPI {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create a new revision request
     */
    public function createRequest($data) {
        try {
            $this->conn->beginTransaction();
            
            $studentId      = $data['StudentID'];
            $enrollmentId   = $data['EnrollmentID'] ?? null;
            $requestedBy    = $data['RequestedBy'];
            $requestType    = $data['RequestType'];
            $fieldsToChange = json_encode($data['FieldsToChange']);
            $justification  = $data['Justification'];
            $priority       = $data['Priority'] ?? 'Normal';
            
            $query = "INSERT INTO studentrevisionrequest (
                StudentID, EnrollmentID, RequestedBy, RequestType,
                FieldsToChange, Justification, Priority
            ) VALUES (
                :studentId, :enrollmentId, :requestedBy, :requestType,
                :fieldsToChange, :justification, :priority
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':studentId'      => $studentId,
                ':enrollmentId'   => $enrollmentId,
                ':requestedBy'    => $requestedBy,
                ':requestType'    => $requestType,
                ':fieldsToChange' => $fieldsToChange,
                ':justification'  => $justification,
                ':priority'       => $priority
            ]);
            
            $requestId = $this->conn->lastInsertId();
            
            // Audit log
            $this->logAudit(
                'studentrevisionrequest',
                $requestId,
                'REVISION_REQUEST',
                null,
                json_encode([
                    'requestType'   => $requestType,
                    'status'        => 'Pending',
                    'fieldsChanged' => count($data['FieldsToChange'])
                ]),
                $requestedBy,
                "Revision request created for Student ID: $studentId"
            );
            
            $this->conn->commit();
            
            return [
                'success'   => true,
                'message'   => 'Revision request submitted successfully',
                'requestId' => $requestId
            ];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Error creating request: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all pending requests
     */
    public function getPendingRequests($filters = []) {
        try {
            $query = "SELECT 
                srr.RequestID,
                srr.StudentID,
                srr.EnrollmentID,
                srr.RequestType,
                srr.Priority,
                srr.Status,
                srr.Justification,
                DATE_FORMAT(srr.ReviewedDate, '%Y-%m-%d %H:%i') as ReviewedDate,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, 
                    CASE WHEN s.MiddleName IS NOT NULL 
                    THEN CONCAT(' ', SUBSTRING(s.MiddleName, 1, 1), '.') 
                    ELSE '' END) AS StudentName,
                CONCAT(u.FirstName, ' ', u.LastName) AS RequestedByName,
                u.Role AS RequesterRole,
                gl.GradeLevelName,
                ay.YearLabel AS AcademicYear
            FROM studentrevisionrequest srr
            INNER JOIN student s ON srr.StudentID = s.StudentID
            INNER JOIN user u ON srr.RequestedBy = u.UserID
            LEFT JOIN enrollment e ON srr.EnrollmentID = e.EnrollmentID
            LEFT JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
            WHERE srr.Status = 'Pending'";
            
            $params = [];
            
            if (isset($filters['priority'])) {
                $query .= " AND srr.Priority = :priority";
                $params[':priority'] = $filters['priority'];
            }
            if (isset($filters['requestType'])) {
                $query .= " AND srr.RequestType = :requestType";
                $params[':requestType'] = $filters['requestType'];
            }
            if (isset($filters['gradeLevel'])) {
                $query .= " AND gl.GradeLevelName = :gradeLevel";
                $params[':gradeLevel'] = $filters['gradeLevel'];
            }
            
            $query .= " ORDER BY 
                CASE srr.Priority
                    WHEN 'Urgent' THEN 1
                    WHEN 'High' THEN 2
                    WHEN 'Normal' THEN 3
                    WHEN 'Low' THEN 4
                END,
                srr.RequestID DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching requests: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get request details
     */
    public function getRequestDetails($requestId) {
        try {
            $query = "SELECT 
                srr.*,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', 
                    IFNULL(CONCAT(SUBSTRING(s.MiddleName, 1, 1), '.'), '')) AS StudentName,
                s.Gender,
                s.DateOfBirth,
                CONCAT(requester.FirstName, ' ', requester.LastName) AS RequestedByName,
                requester.Role AS RequesterRole,
                CONCAT(reviewer.FirstName, ' ', reviewer.LastName) AS ReviewedByName,
                reviewer.Role AS ReviewerRole,
                CONCAT(implementer.FirstName, ' ', implementer.LastName) AS ImplementedByName,
                ay.YearLabel AS AcademicYear,
                gl.GradeLevelName,
                sec.SectionName
            FROM studentrevisionrequest srr
            INNER JOIN student s ON srr.StudentID = s.StudentID
            INNER JOIN user requester ON srr.RequestedBy = requester.UserID
            LEFT JOIN user reviewer ON srr.ReviewedBy = reviewer.UserID
            LEFT JOIN user implementer ON srr.ImplementedBy = implementer.UserID
            LEFT JOIN enrollment e ON srr.EnrollmentID = e.EnrollmentID
            LEFT JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
            LEFT JOIN sectionassignment sa ON e.EnrollmentID = sa.EnrollmentID AND sa.IsActive = 1
            LEFT JOIN section sec ON sa.SectionID = sec.SectionID
            WHERE srr.RequestID = :requestId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':requestId' => $requestId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $result['FieldsToChange'] = json_decode($result['FieldsToChange'], true);
                return ['success' => true, 'data' => $result];
            }
            
            return ['success' => false, 'message' => 'Request not found'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching request details: ' . $e->getMessage()];
        }
    }
    
    /**
     * Approve a revision request
     */
    public function approveRequest($requestId, $reviewedBy, $reviewNotes = null) {
        try {
            $this->conn->beginTransaction();
            
            $query = "UPDATE studentrevisionrequest
                SET Status = 'Approved',
                    ReviewedBy = :reviewedBy,
                    ReviewedDate = NOW(),
                    ReviewNotes = :reviewNotes
                WHERE RequestID = :requestId
                AND Status = 'Pending'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':requestId'   => $requestId,
                ':reviewedBy'  => $reviewedBy,
                ':reviewNotes' => $reviewNotes
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Request not found or already processed');
            }
            
            $this->logAudit(
                'studentrevisionrequest',
                $requestId,
                'REVISION_APPROVED',
                json_encode(['status' => 'Pending']),
                json_encode(['status' => 'Approved']),
                $reviewedBy,
                "Revision request approved - Request ID: $requestId"
            );
            
            // Auto-implement the changes
            $this->implementRevisionInternal($requestId, $reviewedBy);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Request approved and changes applied successfully'
            ];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Error approving request: ' . $e->getMessage()];
        }
    }

    /**
     * Reject a revision request
     */
    public function rejectRequest($requestId, $reviewedBy, $reviewNotes) {
        try {
            $this->conn->beginTransaction();
            
            if (empty($reviewNotes)) {
                throw new Exception('Rejection reason is required');
            }
            
            $query = "UPDATE studentrevisionrequest
                SET Status = 'Rejected',
                    ReviewedBy = :reviewedBy,
                    ReviewedDate = NOW(),
                    ReviewNotes = :reviewNotes
                WHERE RequestID = :requestId
                AND Status = 'Pending'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':requestId'   => $requestId,
                ':reviewedBy'  => $reviewedBy,
                ':reviewNotes' => $reviewNotes
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Request not found or already processed');
            }
            
            $this->logAudit(
                'studentrevisionrequest',
                $requestId,
                'REVISION_REJECTED',
                json_encode(['status' => 'Pending']),
                json_encode(['status' => 'Rejected']),
                $reviewedBy,
                "Revision request rejected - Request ID: $requestId"
            );
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Request rejected'];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Error rejecting request: ' . $e->getMessage()];
        }
    }
    
    /**
     * Internal implementation - called automatically after approval
     */
    private function implementRevisionInternal($requestId, $implementedBy) {
        // Get request details
        $query = "SELECT StudentID, EnrollmentID, FieldsToChange, Status
            FROM studentrevisionrequest
            WHERE RequestID = :requestId";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':requestId' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        if ($request['Status'] !== 'Approved') {
            throw new Exception('Request must be approved before implementation');
        }
        
        $fieldsToChange = json_decode($request['FieldsToChange'], true);
        $studentId      = $request['StudentID'];
        $enrollmentId   = $request['EnrollmentID'];

        // Field mappings
        $studentFields = [
            'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
            'DateOfBirth', 'Gender', 'Religion', 'MotherTongue',
            'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify',
            'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province',
            'ContactNumber', 'Is4PsBeneficiary', 'EnrollmentStatus'
        ];
        
        $enrollmentFields = ['GradeLevelID', 'StrandID', 'Status', 'EnrollmentType'];
        $sectionFields = ['SectionID'];
        $parentFields = [
            'FatherLastName', 'FatherFirstName', 'FatherMiddleName',
            'MotherLastName', 'MotherFirstName', 'MotherMiddleName',
            'GuardianLastName', 'GuardianFirstName', 'GuardianMiddleName',
            'GuardianRelationship', 'ParentContactNumber'
        ];
        
        $studentUpdates = [];
        $studentParams = [':studentId' => $studentId];
        $enrollmentUpdates = [];
        $enrollmentParams = [];
        $newSectionId = null;
        $parentUpdates = [];

        foreach ($fieldsToChange as $change) {
            $field = $change['field'];
            $newValue = $change['newValue'];
            
            if (in_array($field, $studentFields)) {
                $paramKey = ":field_$field";
                $studentUpdates[] = "$field = $paramKey";
                $studentParams[$paramKey] = $newValue;
                
            } elseif (in_array($field, $enrollmentFields) && $enrollmentId) {
                $paramKey = ":field_$field";
                $enrollmentUpdates[] = "$field = $paramKey";
                $enrollmentParams[$paramKey] = $newValue;
                
            } elseif (in_array($field, $sectionFields) && $enrollmentId) {
                $newSectionId = $newValue;
                
            } elseif (in_array($field, $parentFields)) {
                $parentUpdates[$field] = $newValue;
            }
        }
        
        // Apply Student table updates
        if (!empty($studentUpdates)) {
            $sql = "UPDATE student SET " . implode(', ', $studentUpdates) . 
                   " WHERE StudentID = :studentId";
            $this->conn->prepare($sql)->execute($studentParams);
        }
        
        // Apply Enrollment table updates
        if (!empty($enrollmentUpdates) && $enrollmentId) {
            $enrollmentParams[':enrollmentId'] = $enrollmentId;
            $sql = "UPDATE enrollment SET " . implode(', ', $enrollmentUpdates) . 
                   " WHERE EnrollmentID = :enrollmentId";
            $this->conn->prepare($sql)->execute($enrollmentParams);
        }

        // Apply SectionID change
        if ($newSectionId && $enrollmentId) {
            // Deactivate current assignments
            $this->conn->prepare(
                "UPDATE sectionassignment SET IsActive = 0
                 WHERE EnrollmentID = :enrollmentId"
            )->execute([':enrollmentId' => $enrollmentId]);
            
            // Create new assignment
            $this->conn->prepare(
                "INSERT INTO sectionassignment (EnrollmentID, SectionID, AssignmentMethod, IsActive)
                 VALUES (:enrollmentId, :sectionId, 'Manual', 1)"
            )->execute([
                ':enrollmentId' => $enrollmentId,
                ':sectionId' => $newSectionId
            ]);
        }
        
        // Handle parent/guardian updates
        if (!empty($parentUpdates)) {
            $this->updateParentGuardianInfo($studentId, $parentUpdates);
        }
        
        // Mark as implemented
        $this->conn->prepare(
            "UPDATE studentrevisionrequest 
             SET ImplementedBy = :implementedBy, ImplementedDate = NOW()
             WHERE RequestID = :requestId"
        )->execute([
            ':requestId' => $requestId,
            ':implementedBy' => $implementedBy
        ]);
        
        $this->logAudit(
            'studentrevisionrequest',
            $requestId,
            'REVISION_IMPLEMENTED',
            json_encode(['status' => 'Approved']),
            json_encode(['status' => 'Implemented', 'fieldsUpdated' => count($fieldsToChange)]),
            $implementedBy,
            "Revision implemented - Student ID: $studentId, " . count($fieldsToChange) . " field(s) updated"
        );
    }
    
    /**
     * Update parent/guardian information in parentguardian table
     */
    private function updateParentGuardianInfo($studentId, $parentUpdates) {
        // Get existing parent/guardian records
        $query = "SELECT * FROM parentguardian WHERE StudentID = :studentId";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':studentId' => $studentId]);
        $existingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize by relationship type
        $recordsByType = [];
        foreach ($existingRecords as $record) {
            $recordsByType[$record['RelationshipType']] = $record;
        }
        
        // Handle Father fields
        if (isset($parentUpdates['FatherLastName']) || isset($parentUpdates['FatherFirstName']) || 
            isset($parentUpdates['FatherMiddleName'])) {
            $this->updateOrCreateParentRecord(
                $studentId,
                'Father',
                [
                    'LastName' => $parentUpdates['FatherLastName'] ?? null,
                    'FirstName' => $parentUpdates['FatherFirstName'] ?? null,
                    'MiddleName' => $parentUpdates['FatherMiddleName'] ?? null
                ],
                $recordsByType['Father'] ?? null
            );
        }
        
        // Handle Mother fields
        if (isset($parentUpdates['MotherLastName']) || isset($parentUpdates['MotherFirstName']) || 
            isset($parentUpdates['MotherMiddleName'])) {
            $this->updateOrCreateParentRecord(
                $studentId,
                'Mother',
                [
                    'LastName' => $parentUpdates['MotherLastName'] ?? null,
                    'FirstName' => $parentUpdates['MotherFirstName'] ?? null,
                    'MiddleName' => $parentUpdates['MotherMiddleName'] ?? null
                ],
                $recordsByType['Mother'] ?? null
            );
        }
        
        // Handle Guardian fields
        if (isset($parentUpdates['GuardianLastName']) || isset($parentUpdates['GuardianFirstName']) || 
            isset($parentUpdates['GuardianMiddleName']) || isset($parentUpdates['GuardianRelationship'])) {
            $this->updateOrCreateParentRecord(
                $studentId,
                'Guardian',
                [
                    'LastName' => $parentUpdates['GuardianLastName'] ?? null,
                    'FirstName' => $parentUpdates['GuardianFirstName'] ?? null,
                    'MiddleName' => $parentUpdates['GuardianMiddleName'] ?? null,
                    'GuardianRelationship' => $parentUpdates['GuardianRelationship'] ?? null,
                    'ContactNumber' => $parentUpdates['ParentContactNumber'] ?? null
                ],
                $recordsByType['Guardian'] ?? null
            );
        }
    }
    
    /**
     * Update or create a parent/guardian record
     */
    private function updateOrCreateParentRecord($studentId, $relationshipType, $data, $existingRecord) {
        // Filter out null values
        $data = array_filter($data, function($value) {
            return $value !== null;
        });
        
        if (empty($data)) {
            return;
        }
        
        if ($existingRecord) {
            // Update existing record
            $updates = [];
            $params = [':parentId' => $existingRecord['ParentGuardianID']];
            
            foreach ($data as $field => $value) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $value;
            }
            
            $sql = "UPDATE parentguardian SET " . implode(', ', $updates) . 
                   " WHERE ParentGuardianID = :parentId";
            $this->conn->prepare($sql)->execute($params);
            
        } else {
            // Create new record
            $data['StudentID'] = $studentId;
            $data['RelationshipType'] = $relationshipType;
            
            // Set default empty values for required fields
            $data['LastName'] = $data['LastName'] ?? '';
            $data['FirstName'] = $data['FirstName'] ?? '';
            
            $fields = array_keys($data);
            $placeholders = array_map(function($f) { return ":$f"; }, $fields);
            
            $sql = "INSERT INTO parentguardian (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $params = [];
            foreach ($data as $key => $value) {
                $params[":$key"] = $value;
            }
            
            $this->conn->prepare($sql)->execute($params);
        }
    }
    
    /**
     * Get requests by user
     */
    public function getRequestsByUser($userId, $status = null) {
        try {
            $query = "SELECT 
                srr.RequestID,
                srr.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName) AS StudentName,
                srr.RequestType,
                srr.Priority,
                srr.Status,
                DATE_FORMAT(srr.ReviewedDate, '%Y-%m-%d %H:%i') as ReviewedDate,
                CONCAT(reviewer.FirstName, ' ', reviewer.LastName) AS ReviewedByName,
                DATEDIFF(NOW(), srr.ReviewedDate) AS DaysSinceRequest
            FROM studentrevisionrequest srr
            INNER JOIN student s ON srr.StudentID = s.StudentID
            LEFT JOIN user reviewer ON srr.ReviewedBy = reviewer.UserID
            WHERE srr.RequestedBy = :userId";
            
            $params = [':userId' => $userId];
            
            if ($status) {
                $query .= " AND srr.Status = :status";
                $params[':status'] = $status;
            }
            $query .= " ORDER BY srr.RequestID DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching requests: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get requests by student
     */
    public function getRequestsByStudent($studentId, $status = null) {
        try {
            $query = "SELECT 
                srr.RequestID,
                srr.StudentID,
                srr.RequestType,
                srr.Priority,
                srr.Status,
                srr.Justification,
                DATE_FORMAT(srr.ReviewedDate, '%Y-%m-%d %H:%i') as ReviewedDate,
                srr.RequestedBy,
                CONCAT(requester.FirstName, ' ', requester.LastName) AS RequestedByName,
                requester.Role AS RequesterRole,
                CONCAT(reviewer.FirstName, ' ', reviewer.LastName) AS ReviewedByName
            FROM studentrevisionrequest srr
            INNER JOIN user requester ON srr.RequestedBy = requester.UserID
            LEFT JOIN user reviewer ON srr.ReviewedBy = reviewer.UserID
            WHERE srr.StudentID = :studentId";
            
            $params = [':studentId' => $studentId];
            
            if ($status) {
                $query .= " AND srr.Status = :status";
                $params[':status'] = $status;
            }
            $query .= " ORDER BY srr.RequestID DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching requests: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get request statistics
     */
    public function getRequestStatistics($academicYearId = null) {
        try {
            $query = "SELECT 
                COUNT(*) AS TotalRequests,
                SUM(CASE WHEN Status = 'Pending'  THEN 1 ELSE 0 END) AS PendingCount,
                SUM(CASE WHEN Status = 'Approved' THEN 1 ELSE 0 END) AS ApprovedCount,
                SUM(CASE WHEN Status = 'Rejected' THEN 1 ELSE 0 END) AS RejectedCount,
                SUM(CASE WHEN ImplementedDate IS NOT NULL THEN 1 ELSE 0 END) AS ImplementedCount,
                AVG(DATEDIFF(ReviewedDate, RequestID)) AS AvgReviewDays
            FROM studentrevisionrequest srr";
            
            $params = [];
            
            if ($academicYearId) {
                $query .= " INNER JOIN enrollment e ON srr.EnrollmentID = e.EnrollmentID
                    WHERE e.AcademicYearID = :academicYearId";
                $params[':academicYearId'] = $academicYearId;
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return ['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching statistics: ' . $e->getMessage()];
        }
    }
    
    /**
     * Helper - write to audit log
     */
    private function logAudit($tableName, $recordId, $action, $oldValue, $newValue, $userId, $description) {
        try {
            $query = "INSERT INTO auditlog (
                TableName, RecordID, Action, OldValue, NewValue,
                ChangedBy, ActionDescription
            ) VALUES (
                :tableName, :recordId, :action, :oldValue, :newValue,
                :userId, :description
            )";
            $this->conn->prepare($query)->execute([
                ':tableName'   => $tableName,
                ':recordId'    => $recordId,
                ':action'      => $action,
                ':oldValue'    => $oldValue,
                ':newValue'    => $newValue,
                ':userId'      => $userId,
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
    
    $api = new RevisionRequestAPI($db);
    $action = $_GET['action'] ?? '';
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'get_pending') {
                $filters = [];
                if (isset($_GET['priority'])) $filters['priority'] = $_GET['priority'];
                if (isset($_GET['request_type'])) $filters['requestType'] = $_GET['request_type'];
                if (isset($_GET['grade_level'])) $filters['gradeLevel'] = $_GET['grade_level'];
                echo json_encode($api->getPendingRequests($filters));

            } elseif ($action === 'get_details') {
                $requestId = $_GET['request_id'] ?? null;
                if (!$requestId) {
                    echo json_encode(['success' => false, 'message' => 'Request ID required']);
                    exit;
                }
                echo json_encode($api->getRequestDetails($requestId));

            } elseif ($action === 'get_by_user') {
                $userId = $_GET['user_id'] ?? null;
                $status = $_GET['status'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'User ID required']);
                    exit;
                }
                echo json_encode($api->getRequestsByUser($userId, $status));

            } elseif ($action === 'get_by_student') {
                $studentId = $_GET['student_id'] ?? null;
                $status = $_GET['status'] ?? 'Pending';
                if (!$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Student ID required']);
                    exit;
                }
                echo json_encode($api->getRequestsByStudent($studentId, $status));

            } elseif ($action === 'get_statistics') {
                $academicYearId = $_GET['academic_year_id'] ?? null;
                echo json_encode($api->getRequestStatistics($academicYearId));

            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
                exit;
            }

            if ($action === 'create') {
                echo json_encode($api->createRequest($data));

            } elseif ($action === 'create_bulk_update') {
                // This is called when editing student information from the student management page
                // It automatically detects changes and creates a revision request
                
                // Define all trackable fields
                $studentFields = [
                    'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
                    'DateOfBirth', 'Gender', 'Religion', 'MotherTongue',
                    'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify',
                    'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province',
                    'ContactNumber', 'Is4PsBeneficiary', 'EnrollmentStatus'
                ];
                
                $enrollmentFields = ['GradeLevelID', 'StrandID'];
                $sectionFields = ['SectionID'];
                
                // All fields that can be tracked
                $allTrackableFields = array_merge($studentFields, $enrollmentFields, $sectionFields);
                
                // Get current values from database
                $currentQuery = "SELECT s.*,
                    e.GradeLevelID, e.StrandID, e.EnrollmentID, e.Status AS EnrollmentStatusDB,
                    sa.SectionID
                FROM student s
                LEFT JOIN (
                    SELECT StudentID, MAX(EnrollmentID) AS LatestEnrollmentID
                    FROM enrollment
                    GROUP BY StudentID
                ) latest ON s.StudentID = latest.StudentID
                LEFT JOIN enrollment e ON latest.LatestEnrollmentID = e.EnrollmentID
                LEFT JOIN sectionassignment sa ON sa.EnrollmentID = e.EnrollmentID AND sa.IsActive = 1
                WHERE s.StudentID = :studentId";
                
                $currentStmt = $db->prepare($currentQuery);
                $currentStmt->execute([':studentId' => $data['StudentID']]);
                $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentData) {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                    exit;
                }
                
                // Normalize EnrollmentStatus
                $statusMap = [
                    'Confirmed' => 'Active',
                    'Pending' => 'Active',
                    'For_Review' => 'Active',
                    'Cancelled' => 'Cancelled',
                    'Dropped' => 'Dropped',
                    'Transferred_Out' => 'Transferred_Out'
                ];
                $currentData['EnrollmentStatus'] = $statusMap[$currentData['EnrollmentStatusDB']] ?? $currentData['EnrollmentStatusDB'];
                
                // Compare and build fields to change
                $fieldsToChange = [];
                foreach ($allTrackableFields as $field) {
                    if (!isset($data[$field])) continue;
                    
                    $newVal = ($data[$field] === '' || $data[$field] === null) ? null : $data[$field];
                    $oldVal = ($currentData[$field] === '' || $currentData[$field] === null) ? null : $currentData[$field];
                    
                    // Cast to string for consistent comparison
                    if ((string)($newVal ?? '') !== (string)($oldVal ?? '')) {
                        $fieldsToChange[] = [
                            'field' => $field,
                            'oldValue' => (string)($oldVal ?? ''),
                            'newValue' => (string)($newVal ?? '')
                        ];
                    }
                }
                
                if (empty($fieldsToChange)) {
                    echo json_encode(['success' => false, 'message' => 'No changes detected']);
                    exit;
                }
                
                // Create revision request
                $revisionData = [
                    'StudentID' => $data['StudentID'],
                    'EnrollmentID' => $currentData['EnrollmentID'] ?? null,
                    'RequestedBy' => $data['UpdatedBy'] ?? $data['RequestedBy'],
                    'RequestType' => 'Personal_Info',
                    'FieldsToChange' => $fieldsToChange,
                    'Justification' => $data['Justification'] ?? 
                        ('Student information update - ' . count($fieldsToChange) . ' field(s) changed'),
                    'Priority' => $data['Priority'] ?? 'Normal'
                ];
                
                echo json_encode($api->createRequest($revisionData));

            } elseif ($action === 'approve') {
                $requestId = $data['RequestID'] ?? null;
                $reviewedBy = $data['ReviewedBy'] ?? null;
                $reviewNotes = $data['ReviewNotes'] ?? null;
                if (!$requestId || !$reviewedBy) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                echo json_encode($api->approveRequest($requestId, $reviewedBy, $reviewNotes));

            } elseif ($action === 'reject') {
                $requestId = $data['RequestID'] ?? null;
                $reviewedBy = $data['ReviewedBy'] ?? null;
                $reviewNotes = $data['ReviewNotes'] ?? null;
                if (!$requestId || !$reviewedBy || !$reviewNotes) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Request ID, reviewer, and rejection reason required'
                    ]);
                    exit;
                }
                echo json_encode($api->rejectRequest($requestId, $reviewedBy, $reviewNotes));

            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("Revision Request API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>