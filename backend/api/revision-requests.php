<?php
// =====================================================
// Student Revision Request API
// File: backend/api/revision-requests.php
// Created: 2026-02-08
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
            
            $studentId = $data['StudentID'];
            $enrollmentId = $data['EnrollmentID'] ?? null;
            $requestedBy = $data['RequestedBy'];
            $requestType = $data['RequestType'];
            $fieldsToChange = json_encode($data['FieldsToChange']);
            $justification = $data['Justification'];
            $supportingDocs = $data['SupportingDocuments'] ?? null;
            $priority = $data['Priority'] ?? 'Normal';
            
            $query = "INSERT INTO StudentRevisionRequest (
                StudentID, EnrollmentID, RequestedBy, RequestType,
                FieldsToChange, Justification, SupportingDocuments, Priority
            ) VALUES (
                :studentId, :enrollmentId, :requestedBy, :requestType,
                :fieldsToChange, :justification, :supportingDocs, :priority
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':studentId' => $studentId,
                ':enrollmentId' => $enrollmentId,
                ':requestedBy' => $requestedBy,
                ':requestType' => $requestType,
                ':fieldsToChange' => $fieldsToChange,
                ':justification' => $justification,
                ':supportingDocs' => $supportingDocs,
                ':priority' => $priority
            ]);
            
            $requestId = $this->conn->lastInsertId();
            
            // Log to audit
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_REQUEST',
                null,
                json_encode(['requestType' => $requestType, 'status' => 'Pending']),
                $requestedBy,
                "Revision request created for Student ID: $studentId"
            );
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Revision request submitted successfully',
                'requestId' => $requestId
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Error creating request: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all pending requests (for Registrar/ICT Coordinator)
     */
    public function getPendingRequests($filters = []) {
        try {
            $query = "SELECT * FROM vw_PendingRevisionRequests WHERE 1=1";
            $params = [];
            
            if (isset($filters['priority'])) {
                $query .= " AND Priority = :priority";
                $params[':priority'] = $filters['priority'];
            }
            
            if (isset($filters['requestType'])) {
                $query .= " AND RequestType = :requestType";
                $params[':requestType'] = $filters['requestType'];
            }
            
            if (isset($filters['gradeLevel'])) {
                $query .= " AND GradeLevelName = :gradeLevel";
                $params[':gradeLevel'] = $filters['gradeLevel'];
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
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
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS StudentName,
                s.Gender,
                s.DateOfBirth,
                CONCAT(requester.FirstName, ' ', requester.LastName) AS RequestedByName,
                requester.Role AS RequesterRole,
                CONCAT(reviewer.FirstName, ' ', reviewer.LastName) AS ReviewedByName,
                reviewer.Role AS ReviewerRole,
                e.AcademicYear,
                gl.GradeLevelName,
                sec.SectionName
            FROM StudentRevisionRequest srr
            INNER JOIN Student s ON srr.StudentID = s.StudentID
            INNER JOIN User requester ON srr.RequestedBy = requester.UserID
            LEFT JOIN User reviewer ON srr.ReviewedBy = reviewer.UserID
            LEFT JOIN Enrollment e ON srr.EnrollmentID = e.EnrollmentID
            LEFT JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN (
                SELECT sa.StudentID, sa.EnrollmentID, sec.SectionName
                FROM SectionAssignment sa
                INNER JOIN Section sec ON sa.SectionID = sec.SectionID
                WHERE sa.IsActive = 1
            ) sec ON srr.StudentID = sec.StudentID AND srr.EnrollmentID = sec.EnrollmentID
            WHERE srr.RequestID = :requestId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':requestId' => $requestId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Decode JSON fields
                $result['FieldsToChange'] = json_decode($result['FieldsToChange'], true);
                
                return [
                    'success' => true,
                    'data' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Request not found'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching request details: ' . $e->getMessage()
            ];
        }
    }
    
    public function approveRequest($requestId, $reviewedBy, $reviewNotes = null) {
        try {
            $this->conn->beginTransaction();
            
            // Update request status
            $query = "UPDATE StudentRevisionRequest
                SET Status = 'Approved',
                    ReviewedBy = :reviewedBy,
                    ReviewedDate = NOW(),
                    ReviewNotes = :reviewNotes
                WHERE RequestID = :requestId
                AND Status = 'Pending'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':requestId' => $requestId,
                ':reviewedBy' => $reviewedBy,
                ':reviewNotes' => $reviewNotes
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Request not found or already processed');
            }
            
            // Log to audit
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_APPROVED',
                'Pending',
                'Approved',
                $reviewedBy,
                "Revision request approved"
            );
            
            // AUTOMATICALLY IMPLEMENT THE CHANGES
            $this->implementRevisionInternal($requestId, $reviewedBy);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Request approved and changes applied successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Error approving request: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Internal implementation method (called automatically after approval)
     */
    private function implementRevisionInternal($requestId, $implementedBy) {
        // Get request details
        $query = "SELECT StudentID, EnrollmentID, FieldsToChange, Status, RequestType
            FROM StudentRevisionRequest
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
        $studentId = $request['StudentID'];
        $enrollmentId = $request['EnrollmentID'];
        
        // Build update query for Student table
        $studentUpdates = [];
        $studentParams = [':studentId' => $studentId, ':updatedBy' => $implementedBy];
        
        // Build update query for Enrollment table if needed
        $enrollmentUpdates = [];
        $enrollmentParams = [];
        
        // Track if we need to update enrollment status
        $enrollmentStatusUpdate = null;
        
        foreach ($fieldsToChange as $change) {
            $field = $change['field'];
            $newValue = $change['newValue'];
            
            // Determine which table the field belongs to
            $studentFields = [
                'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
                'DateOfBirth', 'Age', 'Gender', 'Religion', 'ContactNumber',
                'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province', 'ZipCode',
                'FatherLastName', 'FatherFirstName', 'FatherMiddleName',
                'MotherLastName', 'MotherFirstName', 'MotherMiddleName',
                'GuardianLastName', 'GuardianFirstName', 'GuardianMiddleName',
                'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify'
            ];
            
            $enrollmentFields = ['GradeLevelID', 'StrandID', 'AcademicYear'];
            
            if ($field === 'EnrollmentStatus') {
                // Handle enrollment status separately
                $enrollmentStatusUpdate = $newValue;
                // Also update student table
                $studentUpdates[] = "EnrollmentStatus = :enrollmentStatus";
                $studentParams[":enrollmentStatus"] = $newValue;
            } elseif (in_array($field, $studentFields)) {
                $studentUpdates[] = "$field = :$field";
                $studentParams[":$field"] = $newValue;
            } elseif (in_array($field, $enrollmentFields) && $enrollmentId) {
                $enrollmentUpdates[] = "$field = :$field";
                $enrollmentParams[":$field"] = $newValue;
            }
        }
        
        // Update Student table
        if (!empty($studentUpdates)) {
            $studentUpdates[] = "UpdatedBy = :updatedBy";
            $updateStudentQuery = "UPDATE Student SET " . 
                implode(", ", $studentUpdates) . 
                " WHERE StudentID = :studentId";
            
            $updateStmt = $this->conn->prepare($updateStudentQuery);
            $updateStmt->execute($studentParams);
        }
        
        // Update Enrollment table
        if (!empty($enrollmentUpdates) && $enrollmentId) {
            $enrollmentParams[':enrollmentId'] = $enrollmentId;
            $updateEnrollmentQuery = "UPDATE Enrollment SET " . 
                implode(", ", $enrollmentUpdates) . 
                " WHERE EnrollmentID = :enrollmentId";
            
            $updateEnrollStmt = $this->conn->prepare($updateEnrollmentQuery);
            $updateEnrollStmt->execute($enrollmentParams);
        }
        
        // Update enrollment status if changed
        if ($enrollmentStatusUpdate && $enrollmentId) {
            // Map Student.EnrollmentStatus to Enrollment.Status
            $statusMap = [
                'Active' => 'Confirmed',
                'Cancelled' => 'Cancelled',
                'Dropped' => 'Dropped',
                'Transferred_Out' => 'Transferred_Out',
                'Graduated' => 'Confirmed'
            ];
            
            $enrollStatus = $statusMap[$enrollmentStatusUpdate] ?? 'Confirmed';
            
            $updateEnrollStatusQuery = "UPDATE Enrollment 
                SET Status = :status,
                    StatusChangedDate = NOW(),
                    StatusChangedBy = :updatedBy
                WHERE EnrollmentID = :enrollmentId";
            
            $statusStmt = $this->conn->prepare($updateEnrollStatusQuery);
            $statusStmt->execute([
                ':status' => $enrollStatus,
                ':updatedBy' => $implementedBy,
                ':enrollmentId' => $enrollmentId
            ]);
            
            // Deactivate section assignments for non-active statuses
            if (in_array($enrollmentStatusUpdate, ['Cancelled', 'Dropped', 'Transferred_Out'])) {
                $deactivateSections = "UPDATE SectionAssignment 
                    SET IsActive = 0 
                    WHERE StudentID = :studentId";
                $deactStmt = $this->conn->prepare($deactivateSections);
                $deactStmt->execute([':studentId' => $studentId]);
            }
        }
        
        // Log to StudentChangeLog for specific change types
        if (in_array($request['RequestType'], ['Gender_Correction', 'Name_Correction'])) {
            $changeLogQuery = "INSERT INTO StudentChangeLog (
                StudentID, ChangeType, OldValue, NewValue, Reason, ChangedBy
            ) VALUES (
                :studentId, :changeType, :oldValue, :newValue, :reason, :changedBy
            )";
            
            foreach ($fieldsToChange as $change) {
                $changeLogStmt = $this->conn->prepare($changeLogQuery);
                $changeLogStmt->execute([
                    ':studentId' => $studentId,
                    ':changeType' => $request['RequestType'],
                    ':oldValue' => $change['oldValue'],
                    ':newValue' => $change['newValue'],
                    ':reason' => "Revision Request #$requestId",
                    ':changedBy' => $implementedBy
                ]);
            }
        }
        
        // Update request implementation tracking
        $updateRequestQuery = "UPDATE StudentRevisionRequest
            SET ImplementedBy = :implementedBy,
                ImplementedDate = NOW()
            WHERE RequestID = :requestId";
        
        $updateRequestStmt = $this->conn->prepare($updateRequestQuery);
        $updateRequestStmt->execute([
            ':requestId' => $requestId,
            ':implementedBy' => $implementedBy
        ]);
        
        // Log to audit
        $this->logAudit(
            'StudentRevisionRequest',
            $requestId,
            'REVISION_IMPLEMENTED',
            'Approved',
            'Implemented',
            $implementedBy,
            "Revision changes applied to Student ID: $studentId"
        );
    }
    
    /**
     * Reject revision request
     */
    public function rejectRequest($requestId, $reviewedBy, $reviewNotes) {
        try {
            $this->conn->beginTransaction();
            
            if (empty($reviewNotes)) {
                throw new Exception('Rejection reason is required');
            }
            
            $query = "UPDATE StudentRevisionRequest
                SET Status = 'Rejected',
                    ReviewedBy = :reviewedBy,
                    ReviewedDate = NOW(),
                    ReviewNotes = :reviewNotes
                WHERE RequestID = :requestId
                AND Status = 'Pending'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':requestId' => $requestId,
                ':reviewedBy' => $reviewedBy,
                ':reviewNotes' => $reviewNotes
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Request not found or already processed');
            }
            
            // Log to audit
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_REJECTED',
                'Pending',
                'Rejected',
                $reviewedBy,
                "Revision request rejected: $reviewNotes"
            );
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Request rejected'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Error rejecting request: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Implement approved revision (apply changes to student record)
     */
    public function implementRevision($requestId, $implementedBy) {
        try {
            $this->conn->beginTransaction();
            
            // Get request details
            $query = "SELECT StudentID, EnrollmentID, FieldsToChange, Status, RequestType
                FROM StudentRevisionRequest
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
            $studentId = $request['StudentID'];
            $enrollmentId = $request['EnrollmentID'];
            
            // Build update query for Student table
            $studentUpdates = [];
            $studentParams = [':studentId' => $studentId, ':updatedBy' => $implementedBy];
            
            // Build update query for Enrollment table if needed
            $enrollmentUpdates = [];
            $enrollmentParams = [];
            
            foreach ($fieldsToChange as $change) {
                $field = $change['field'];
                $newValue = $change['newValue'];
                
                // Determine which table the field belongs to
                $studentFields = [
                    'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
                    'DateOfBirth', 'Age', 'Gender', 'Religion', 'ContactNumber',
                    'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province',
                    'FatherLastName', 'FatherFirstName', 'FatherMiddleName',
                    'MotherLastName', 'MotherFirstName', 'MotherMiddleName',
                    'GuardianLastName', 'GuardianFirstName', 'GuardianMiddleName',
                    'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify'
                ];
                
                $enrollmentFields = ['GradeLevelID', 'StrandID', 'AcademicYear'];
                
                if (in_array($field, $studentFields)) {
                    $studentUpdates[] = "$field = :$field";
                    $studentParams[":$field"] = $newValue;
                } elseif (in_array($field, $enrollmentFields) && $enrollmentId) {
                    $enrollmentUpdates[] = "$field = :$field";
                    $enrollmentParams[":$field"] = $newValue;
                }
            }
            
            // Update Student table
            if (!empty($studentUpdates)) {
                $studentUpdates[] = "UpdatedBy = :updatedBy";
                $updateStudentQuery = "UPDATE Student SET " . 
                    implode(", ", $studentUpdates) . 
                    " WHERE StudentID = :studentId";
                
                $updateStmt = $this->conn->prepare($updateStudentQuery);
                $updateStmt->execute($studentParams);
            }
            
            // Update Enrollment table
            if (!empty($enrollmentUpdates) && $enrollmentId) {
                $enrollmentParams[':enrollmentId'] = $enrollmentId;
                $updateEnrollmentQuery = "UPDATE Enrollment SET " . 
                    implode(", ", $enrollmentUpdates) . 
                    " WHERE EnrollmentID = :enrollmentId";
                
                $updateEnrollStmt = $this->conn->prepare($updateEnrollmentQuery);
                $updateEnrollStmt->execute($enrollmentParams);
            }
            
            // Log to StudentChangeLog for specific change types
            if (in_array($request['RequestType'], ['Gender_Correction', 'Name_Correction'])) {
                $changeLogQuery = "INSERT INTO StudentChangeLog (
                    StudentID, ChangeType, OldValue, NewValue, Reason, ChangedBy
                ) VALUES (
                    :studentId, :changeType, :oldValue, :newValue, :reason, :changedBy
                )";
                
                foreach ($fieldsToChange as $change) {
                    $changeLogStmt = $this->conn->prepare($changeLogQuery);
                    $changeLogStmt->execute([
                        ':studentId' => $studentId,
                        ':changeType' => $request['RequestType'],
                        ':oldValue' => $change['oldValue'],
                        ':newValue' => $change['newValue'],
                        ':reason' => "Revision Request #$requestId",
                        ':changedBy' => $implementedBy
                    ]);
                }
            }
            
            // Update request status
            $updateRequestQuery = "UPDATE StudentRevisionRequest
                SET ImplementedBy = :implementedBy,
                    ImplementedDate = NOW()
                WHERE RequestID = :requestId";
            
            $updateRequestStmt = $this->conn->prepare($updateRequestQuery);
            $updateRequestStmt->execute([
                ':requestId' => $requestId,
                ':implementedBy' => $implementedBy
            ]);
            
            // Log to audit
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_IMPLEMENTED',
                'Approved',
                'Implemented',
                $implementedBy,
                "Revision changes applied to Student ID: $studentId"
            );
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Changes implemented successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Error implementing revision: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get requests by user (for advisers to see their own requests)
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
                srr.CreatedAt,
                srr.ReviewedDate,
                CONCAT(reviewer.FirstName, ' ', reviewer.LastName) AS ReviewedByName,
                DATEDIFF(NOW(), srr.CreatedAt) AS DaysSinceRequest
            FROM StudentRevisionRequest srr
            INNER JOIN Student s ON srr.StudentID = s.StudentID
            LEFT JOIN User reviewer ON srr.ReviewedBy = reviewer.UserID
            WHERE srr.RequestedBy = :userId";
            
            $params = [':userId' => $userId];
            
            if ($status) {
                $query .= " AND srr.Status = :status";
                $params[':status'] = $status;
            }
            
            $query .= " ORDER BY srr.CreatedAt DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching requests: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get request statistics
     */
    public function getRequestStatistics($academicYear = null) {
        try {
            $query = "SELECT 
                COUNT(*) AS TotalRequests,
                SUM(CASE WHEN Status = 'Pending' THEN 1 ELSE 0 END) AS PendingCount,
                SUM(CASE WHEN Status = 'Approved' THEN 1 ELSE 0 END) AS ApprovedCount,
                SUM(CASE WHEN Status = 'Rejected' THEN 1 ELSE 0 END) AS RejectedCount,
                SUM(CASE WHEN ImplementedDate IS NOT NULL THEN 1 ELSE 0 END) AS ImplementedCount,
                AVG(DATEDIFF(ReviewedDate, CreatedAt)) AS AvgReviewDays
            FROM StudentRevisionRequest srr";
            
            if ($academicYear) {
                $query .= " INNER JOIN Enrollment e ON srr.EnrollmentID = e.EnrollmentID
                    WHERE e.AcademicYear = :academicYear";
            }
            
            $stmt = $this->conn->prepare($query);
            if ($academicYear) {
                $stmt->execute([':academicYear' => $academicYear]);
            } else {
                $stmt->execute();
            }
            
            return [
                'success' => true,
                'data' => $stmt->fetch(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching statistics: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Helper function to log to audit
     */
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
    /**
     * Get requests by student (for approvers)
     */
    public function getRequestsByStudent($studentId, $status = null) {
        try {
            $query = "SELECT 
                srr.RequestID,
                srr.StudentID,
                srr.RequestType,
                srr.Priority,
                srr.Status,
                srr.CreatedAt,
                srr.RequestedBy,
                CONCAT(requester.FirstName, ' ', requester.LastName) AS RequestedByName,
                requester.Role AS RequesterRole,
                srr.ReviewedDate,
                CONCAT(reviewer.FirstName, ' ', reviewer.LastName) AS ReviewedByName
            FROM StudentRevisionRequest srr
            INNER JOIN User requester ON srr.RequestedBy = requester.UserID
            LEFT JOIN User reviewer ON srr.ReviewedBy = reviewer.UserID
            WHERE srr.StudentID = :studentId";
            
            $params = [':studentId' => $studentId];
            
            if ($status) {
                $query .= " AND srr.Status = :status";
                $params[':status'] = $status;
            }
            
            $query .= " ORDER BY srr.CreatedAt DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching requests: ' . $e->getMessage()
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
    
    $api = new RevisionRequestAPI($db);
    $action = $_GET['action'] ?? '';
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'get_pending') {
                $filters = [];
                if (isset($_GET['priority'])) $filters['priority'] = $_GET['priority'];
                if (isset($_GET['request_type'])) $filters['requestType'] = $_GET['request_type'];
                if (isset($_GET['grade_level'])) $filters['gradeLevel'] = $_GET['grade_level'];
                
                $result = $api->getPendingRequests($filters);
                echo json_encode($result);
                
            } elseif ($action === 'get_details') {
                $requestId = $_GET['request_id'] ?? null;
                
                if (!$requestId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Request ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getRequestDetails($requestId);
                echo json_encode($result);
                
            } elseif ($action === 'get_by_user') {
                $userId = $_GET['user_id'] ?? null;
                $status = $_GET['status'] ?? null;
                
                if (!$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'User ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getRequestsByUser($userId, $status);
                echo json_encode($result);
                
            } elseif ($action === 'get_statistics') {
                $academicYear = $_GET['academic_year'] ?? null;
                
                $result = $api->getRequestStatistics($academicYear);
                echo json_encode($result);

            } elseif ($action === 'get_by_student') {
                $studentId = $_GET['student_id'] ?? null;
                $status = $_GET['status'] ?? 'Pending'; // Default to pending
                
                if (!$studentId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Student ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getRequestsByStudent($studentId, $status);
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
                        
            if ($action === 'create_bulk_update') {
                // Get current student data
                $currentStmt = $db->prepare("SELECT s.*, e.GradeLevelID, e.StrandID, e.AcademicYear, e.EnrollmentID,
                    e.Status as EnrollmentStatus
                    FROM Student s
                    LEFT JOIN (
                        SELECT StudentID, MAX(EnrollmentID) as LatestEnrollmentID
                        FROM Enrollment
                        GROUP BY StudentID
                    ) latest ON s.StudentID = latest.StudentID
                    LEFT JOIN Enrollment e ON latest.LatestEnrollmentID = e.EnrollmentID
                    WHERE s.StudentID = ?");
                $currentStmt->execute([$data['StudentID']]);
                $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentData) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Student not found'
                    ]);
                    exit;
                }
                
                // Map Enrollment.Status to Student.EnrollmentStatus format for comparison
                $statusMap = [
                    'Confirmed' => 'Active',
                    'Pending' => 'Active',
                    'Cancelled' => 'Cancelled',
                    'Dropped' => 'Dropped',
                    'Transferred_Out' => 'Transferred_Out',
                    'For_Review' => 'Active'
                ];
                
                // Convert enrollment status to student status format
                $currentData['EnrollmentStatus'] = $statusMap[$currentData['EnrollmentStatus']] ?? $currentData['EnrollmentStatus'];
                
                // Build fields array for ALL changed values
                $fieldsToChange = [];
                
                // Define all checkable fields
                $fieldsToCheck = [
                    // Student table fields
                    'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
                    'DateOfBirth', 'Age', 'Gender', 'Religion', 'ContactNumber',
                    'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify',
                    'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province', 'ZipCode',
                    'FatherLastName', 'FatherFirstName', 'FatherMiddleName',
                    'MotherLastName', 'MotherFirstName', 'MotherMiddleName',
                    'GuardianLastName', 'GuardianFirstName', 'GuardianMiddleName',
                    // Enrollment table fields
                    'GradeLevelID', 'StrandID', 'AcademicYear', 'EnrollmentStatus'
                ];
                
                foreach ($fieldsToCheck as $field) {
                    if (isset($data[$field])) {
                        // Normalize empty values
                        $newValue = $data[$field] === '' ? null : $data[$field];
                        $oldValue = $currentData[$field] === '' ? null : $currentData[$field];
                        
                        // Compare values (handle null/empty string equivalence)
                        if ($newValue != $oldValue) {
                            $fieldsToChange[] = [
                                'field' => $field,
                                'oldValue' => $oldValue ?? '',
                                'newValue' => $newValue ?? ''
                            ];
                        }
                    }
                }
                
                // Check if there are any changes
                if (empty($fieldsToChange)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'No changes detected'
                    ]);
                    exit;
                }
                
                $revisionData = [
                    'StudentID' => $data['StudentID'],
                    'EnrollmentID' => $currentData['EnrollmentID'] ?? null,
                    'RequestedBy' => $data['UpdatedBy'],
                    'RequestType' => 'Bulk_Update',
                    'FieldsToChange' => $fieldsToChange,
                    'Justification' => 'Bulk student information update submitted by Adviser',
                    'Priority' => 'Normal'
                ];
                
                $result = $api->createRequest($revisionData);
                echo json_encode($result);
            }
            
            elseif ($action === 'create') {
            
            $revisionData = [
                'StudentID' => $data['StudentID'],
                'EnrollmentID' => null, // fetch if needed
                'RequestedBy' => $data['UpdatedBy'],
                'RequestType' => 'Bulk_Update',
                'FieldsToChange' => $fieldsToChange,
                'Justification' => 'Bulk student information update',
                'Priority' => 'Normal'
            ];
            
            $result = $api->createRequest($revisionData);
            echo json_encode($result);
            
        } elseif ($action === 'create') {
                $result = $api->createRequest($data);
                echo json_encode($result);
                
            } elseif ($action === 'approve') {
                $requestId = $data['RequestID'] ?? null;
                $reviewedBy = $data['ReviewedBy'] ?? null;
                $reviewNotes = $data['ReviewNotes'] ?? null;
                
                if (!$requestId || !$reviewedBy) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }
                
                $result = $api->approveRequest($requestId, $reviewedBy, $reviewNotes);
                echo json_encode($result);
                
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
                
                $result = $api->rejectRequest($requestId, $reviewedBy, $reviewNotes);
                echo json_encode($result);
                
            } elseif ($action === 'implement') {
                $requestId = $data['RequestID'] ?? null;
                $implementedBy = $data['ImplementedBy'] ?? null;
                
                if (!$requestId || !$implementedBy) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }
                
                $result = $api->implementRevision($requestId, $implementedBy);
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
    error_log("Revision Request API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>