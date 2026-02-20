<?php
// =====================================================
// Student Revision Request API
// File: backend/api/revision-requests.php
// Updated: 2026-02-20 - Fixed SectionID, ZipCode, duplicate create case, audit log alignment
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
            $supportingDocs = $data['SupportingDocuments'] ?? null;
            $priority       = $data['Priority'] ?? 'Normal';
            
            $query = "INSERT INTO StudentRevisionRequest (
                StudentID, EnrollmentID, RequestedBy, RequestType,
                FieldsToChange, Justification, SupportingDocuments, Priority
            ) VALUES (
                :studentId, :enrollmentId, :requestedBy, :requestType,
                :fieldsToChange, :justification, :supportingDocs, :priority
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':studentId'      => $studentId,
                ':enrollmentId'   => $enrollmentId,
                ':requestedBy'    => $requestedBy,
                ':requestType'    => $requestType,
                ':fieldsToChange' => $fieldsToChange,
                ':justification'  => $justification,
                ':supportingDocs' => $supportingDocs,
                ':priority'       => $priority
            ]);
            
            $requestId = $this->conn->lastInsertId();
            
            // Audit log — store the ChangedFields array as NewValue so the
            // audit log detail view can render a proper diff table.
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_REQUEST',
                null,
                json_encode([
                    'requestType'   => $requestType,
                    'status'        => 'Pending',
                    'ChangedFields' => $data['FieldsToChange']   // field-level diff
                ]),
                $requestedBy,
                "Student edit submitted for approval — Student ID: $studentId, " .
                count($data['FieldsToChange']) . " field(s) changed"
            );
            
            $this->conn->commit();
            
            return [
                'success'   => true,
                'message'   => 'Revision request submitted successfully',
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
            $query  = "SELECT * FROM vw_PendingRevisionRequests WHERE 1=1";
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
                $result['FieldsToChange'] = json_decode($result['FieldsToChange'], true);
                return ['success' => true, 'data' => $result];
            }
            
            return ['success' => false, 'message' => 'Request not found'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching request details: ' . $e->getMessage()];
        }
    }
    
    /**
     * Approve a revision request and automatically implement changes
     */
    public function approveRequest($requestId, $reviewedBy, $reviewNotes = null) {
        try {
            $this->conn->beginTransaction();
            
            $query = "UPDATE StudentRevisionRequest
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
                'StudentRevisionRequest',
                $requestId,
                'REVISION_APPROVED',
                json_encode(['status' => 'Pending']),
                json_encode(['status' => 'Approved', 'reviewNotes' => $reviewNotes]),
                $reviewedBy,
                "Student edit request approved — Request ID: $requestId"
            );
            
            // Auto-implement immediately after approval
            $this->implementRevisionInternal($requestId, $reviewedBy);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Request approved and changes applied successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Error approving request: ' . $e->getMessage()];
        }
    }

    /**
     * Fields that belong to the Student table
     */
    private function getStudentFields() {
        return [
            'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
            'DateOfBirth', 'Age', 'Gender', 'Religion', 'ContactNumber',
            'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province', 'ZipCode',
            'FatherLastName', 'FatherFirstName', 'FatherMiddleName',
            'MotherLastName', 'MotherFirstName', 'MotherMiddleName',
            'GuardianLastName', 'GuardianFirstName', 'GuardianMiddleName',
            'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify'
        ];
    }

    /**
     * Fields that belong to the Enrollment table
     */
    private function getEnrollmentFields() {
        return ['GradeLevelID', 'StrandID', 'AcademicYear'];
    }

    /**
     * Fields that belong to the SectionAssignment table
     */
    private function getSectionFields() {
        return ['SectionID'];
    }

    /**
     * Internal implementation — called automatically after approval.
     * Single source of truth for applying field changes.
     */
    private function implementRevisionInternal($requestId, $implementedBy) {
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
        
        $fieldsToChange  = json_decode($request['FieldsToChange'], true);
        $studentId       = $request['StudentID'];
        $enrollmentId    = $request['EnrollmentID'];

        $studentFields    = $this->getStudentFields();
        $enrollmentFields = $this->getEnrollmentFields();
        $sectionFields    = $this->getSectionFields();
        
        $studentUpdates    = [];
        $studentParams     = [':studentId' => $studentId, ':updatedBy' => $implementedBy];
        $enrollmentUpdates = [];
        $enrollmentParams  = [];
        $newSectionId      = null;
        $enrollmentStatus  = null;

        foreach ($fieldsToChange as $change) {
            $field    = $change['field'];
            $newValue = $change['newValue'];
            
            if ($field === 'EnrollmentStatus') {
                $enrollmentStatus = $newValue;
                // Mirror on the Student table as well
                $studentUpdates[]                  = "EnrollmentStatus = :enrollmentStatus";
                $studentParams[':enrollmentStatus'] = $newValue;

            } elseif (in_array($field, $studentFields)) {
                $paramKey              = ":field_$field";
                $studentUpdates[]      = "$field = $paramKey";
                $studentParams[$paramKey] = $newValue;

            } elseif (in_array($field, $enrollmentFields) && $enrollmentId) {
                $paramKey                  = ":field_$field";
                $enrollmentUpdates[]       = "$field = $paramKey";
                $enrollmentParams[$paramKey] = $newValue;

            } elseif (in_array($field, $sectionFields) && $enrollmentId) {
                // SectionID change → update SectionAssignment
                $newSectionId = $newValue;
            }
        }
        
        // Apply Student table updates
        if (!empty($studentUpdates)) {
            $studentUpdates[] = "UpdatedBy = :updatedBy";
            $sql = "UPDATE Student SET " . implode(', ', $studentUpdates) . " WHERE StudentID = :studentId";
            $this->conn->prepare($sql)->execute($studentParams);
        }
        
        // Apply Enrollment table updates
        if (!empty($enrollmentUpdates) && $enrollmentId) {
            $enrollmentParams[':enrollmentId'] = $enrollmentId;
            $sql = "UPDATE Enrollment SET " . implode(', ', $enrollmentUpdates) . " WHERE EnrollmentID = :enrollmentId";
            $this->conn->prepare($sql)->execute($enrollmentParams);
        }

        // Apply SectionID change via SectionAssignment
        if ($newSectionId && $enrollmentId) {
            // Deactivate current assignment for this enrollment
            $deact = "UPDATE SectionAssignment SET IsActive = 0
                      WHERE StudentID = :studentId AND EnrollmentID = :enrollmentId";
            $this->conn->prepare($deact)->execute([
                ':studentId'    => $studentId,
                ':enrollmentId' => $enrollmentId
            ]);
            // Insert new assignment
            $insert = "INSERT INTO SectionAssignment (StudentID, EnrollmentID, SectionID, IsActive)
                       VALUES (:studentId, :enrollmentId, :sectionId, 1)
                       ON DUPLICATE KEY UPDATE IsActive = 1";
            $this->conn->prepare($insert)->execute([
                ':studentId'    => $studentId,
                ':enrollmentId' => $enrollmentId,
                ':sectionId'    => $newSectionId
            ]);
        }
        
        // Handle EnrollmentStatus change on Enrollment table
        if ($enrollmentStatus && $enrollmentId) {
            $statusMap = [
                'Active'          => 'Confirmed',
                'Cancelled'       => 'Cancelled',
                'Dropped'         => 'Dropped',
                'Transferred_Out' => 'Transferred_Out',
                'Graduated'       => 'Confirmed'
            ];
            $enrollStatus = $statusMap[$enrollmentStatus] ?? 'Confirmed';
            
            $sql = "UPDATE Enrollment
                    SET Status = :status, StatusChangedDate = NOW(), StatusChangedBy = :updatedBy
                    WHERE EnrollmentID = :enrollmentId";
            $this->conn->prepare($sql)->execute([
                ':status'       => $enrollStatus,
                ':updatedBy'    => $implementedBy,
                ':enrollmentId' => $enrollmentId
            ]);
            
            // Deactivate section assignments for terminal statuses
            if (in_array($enrollmentStatus, ['Cancelled', 'Dropped', 'Transferred_Out'])) {
                $this->conn->prepare(
                    "UPDATE SectionAssignment SET IsActive = 0 WHERE StudentID = :studentId"
                )->execute([':studentId' => $studentId]);
            }
        }
        
        // Log specific change types to StudentChangeLog
        if (in_array($request['RequestType'], ['Gender_Correction', 'Name_Correction', 'Bulk_Update'])) {
            $logSql = "INSERT INTO StudentChangeLog
                (StudentID, ChangeType, OldValue, NewValue, Reason, ChangedBy)
                VALUES (:studentId, :changeType, :oldValue, :newValue, :reason, :changedBy)";
            foreach ($fieldsToChange as $change) {
                $this->conn->prepare($logSql)->execute([
                    ':studentId'  => $studentId,
                    ':changeType' => $request['RequestType'],
                    ':oldValue'   => $change['oldValue'],
                    ':newValue'   => $change['newValue'],
                    ':reason'     => "Revision Request #$requestId",
                    ':changedBy'  => $implementedBy
                ]);
            }
        }
        
        // Mark as implemented
        $this->conn->prepare(
            "UPDATE StudentRevisionRequest SET ImplementedBy = :implementedBy, ImplementedDate = NOW()
             WHERE RequestID = :requestId"
        )->execute([':requestId' => $requestId, ':implementedBy' => $implementedBy]);
        
        $this->logAudit(
            'StudentRevisionRequest',
            $requestId,
            'REVISION_IMPLEMENTED',
            json_encode(['status' => 'Approved']),
            json_encode([
                'status'        => 'Implemented',
                'ChangedFields' => $fieldsToChange
            ]),
            $implementedBy,
            "Student edit applied to record — Student ID: $studentId, " .
            count($fieldsToChange) . " field(s) updated"
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
                ':requestId'   => $requestId,
                ':reviewedBy'  => $reviewedBy,
                ':reviewNotes' => $reviewNotes
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Request not found or already processed');
            }
            
            $this->logAudit(
                'StudentRevisionRequest',
                $requestId,
                'REVISION_REJECTED',
                json_encode(['status' => 'Pending']),
                json_encode(['status' => 'Rejected', 'reviewNotes' => $reviewNotes]),
                $reviewedBy,
                "Student edit request rejected — Request ID: $requestId. Reason: $reviewNotes"
            );
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Request rejected'];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Error rejecting request: ' . $e->getMessage()];
        }
    }
    
    /**
     * Public implement endpoint (kept for manual implementation if ever needed,
     * but normally auto-called after approval)
     */
    public function implementRevision($requestId, $implementedBy) {
        try {
            $this->conn->beginTransaction();
            $this->implementRevisionInternal($requestId, $implementedBy);
            $this->conn->commit();
            return ['success' => true, 'message' => 'Changes implemented successfully'];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Error implementing revision: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get requests by user (for advisers to track their own submissions)
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
            
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching requests: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get requests by student (for approvers reviewing a specific student)
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
            
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching requests: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get request statistics
     */
    public function getRequestStatistics($academicYear = null) {
        try {
            $query = "SELECT 
                COUNT(*) AS TotalRequests,
                SUM(CASE WHEN Status = 'Pending'  THEN 1 ELSE 0 END) AS PendingCount,
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
            
            return ['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error fetching statistics: ' . $e->getMessage()];
        }
    }
    
    /**
     * Helper — write to audit log
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
    $db       = $database->getConnection();
    
    if ($db === null) {
        throw new Exception('Database connection failed');
    }
    
    $api    = new RevisionRequestAPI($db);
    $action = $_GET['action'] ?? '';
    
    switch ($_SERVER['REQUEST_METHOD']) {

        // ── GET ──────────────────────────────────────────────────────────────
        case 'GET':
            if ($action === 'get_pending') {
                $filters = [];
                if (isset($_GET['priority']))     $filters['priority']    = $_GET['priority'];
                if (isset($_GET['request_type'])) $filters['requestType'] = $_GET['request_type'];
                if (isset($_GET['grade_level']))  $filters['gradeLevel']  = $_GET['grade_level'];
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
                // Default to Pending so the "Review Revisions" modal shows only pending ones
                $status    = $_GET['status'] ?? 'Pending';
                if (!$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Student ID required']);
                    exit;
                }
                echo json_encode($api->getRequestsByStudent($studentId, $status));

            } elseif ($action === 'get_statistics') {
                $academicYear = $_GET['academic_year'] ?? null;
                echo json_encode($api->getRequestStatistics($academicYear));

            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;

        // ── POST ─────────────────────────────────────────────────────────────
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
                exit;
            }

            // ── create_bulk_update ──────────────────────────────────────────
            // Called by the JS "Edit Information" form (Adviser role).
            // The JS already sends a ChangedFields diff; we re-verify server-side
            // against the database for security, then create the revision request.
            if ($action === 'create_bulk_update') {

                // All fields the form can touch, across all tables
                $allTrackableFields = [
                    // Student table
                    'LRN', 'LastName', 'FirstName', 'MiddleName', 'ExtensionName',
                    'DateOfBirth', 'Age', 'Gender', 'Religion', 'ContactNumber',
                    'IsIPCommunity', 'IPCommunitySpecify', 'IsPWD', 'PWDSpecify',
                    'HouseNumber', 'SitioStreet', 'Barangay', 'Municipality', 'Province', 'ZipCode',
                    'FatherLastName', 'FatherFirstName', 'FatherMiddleName',
                    'MotherLastName', 'MotherFirstName', 'MotherMiddleName',
                    'GuardianLastName', 'GuardianFirstName', 'GuardianMiddleName',
                    // Enrollment table
                    'GradeLevelID', 'StrandID', 'AcademicYear', 'EnrollmentStatus',
                    // SectionAssignment table
                    'SectionID'
                ];

                // Fetch current values from DB for authoritative diff
                $currentStmt = $db->prepare(
                    "SELECT s.*,
                        e.GradeLevelID, e.StrandID, e.AcademicYear,
                        e.EnrollmentID, e.Status AS EnrollmentStatusRaw,
                        sa.SectionID
                    FROM Student s
                    LEFT JOIN (
                        SELECT StudentID, MAX(EnrollmentID) AS LatestEnrollmentID
                        FROM Enrollment
                        GROUP BY StudentID
                    ) latest ON s.StudentID = latest.StudentID
                    LEFT JOIN Enrollment e ON latest.LatestEnrollmentID = e.EnrollmentID
                    LEFT JOIN SectionAssignment sa
                        ON sa.StudentID = s.StudentID
                        AND sa.EnrollmentID = e.EnrollmentID
                        AND sa.IsActive = 1
                    WHERE s.StudentID = ?"
                );
                $currentStmt->execute([$data['StudentID']]);
                $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentData) {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                    exit;
                }

                // Normalise Enrollment.Status → Student.EnrollmentStatus vocabulary
                $statusMap = [
                    'Confirmed'   => 'Active',
                    'Pending'     => 'Active',
                    'For_Review'  => 'Active',
                    'Cancelled'   => 'Cancelled',
                    'Dropped'     => 'Dropped',
                    'Transferred_Out' => 'Transferred_Out',
                    'Graduated'   => 'Graduated'
                ];
                $currentData['EnrollmentStatus'] =
                    $statusMap[$currentData['EnrollmentStatusRaw']] ?? $currentData['EnrollmentStatusRaw'];

                // Server-side diff (authoritative)
                $fieldsToChange = [];
                foreach ($allTrackableFields as $field) {
                    if (!isset($data[$field])) continue;

                    $newVal = ($data[$field] === '' || $data[$field] === null) ? null : $data[$field];
                    $oldVal = ($currentData[$field] === '' || $currentData[$field] === null)
                              ? null
                              : $currentData[$field];

                    // Cast to string for consistent comparison (handles int/bool DB values)
                    if ((string)($newVal ?? '') !== (string)($oldVal ?? '')) {
                        $fieldsToChange[] = [
                            'field'    => $field,
                            'oldValue' => (string)($oldVal ?? ''),
                            'newValue' => (string)($newVal ?? '')
                        ];
                    }
                }

                if (empty($fieldsToChange)) {
                    echo json_encode(['success' => false, 'message' => 'No changes detected']);
                    exit;
                }

                $revisionData = [
                    'StudentID'    => $data['StudentID'],
                    'EnrollmentID' => $currentData['EnrollmentID'] ?? null,
                    'RequestedBy'  => $data['UpdatedBy'],
                    'RequestType'  => 'Bulk_Update',
                    'FieldsToChange' => $fieldsToChange,
                    // Use adviser-supplied justification if provided, else generic
                    'Justification' => $data['Justification']
                        ?? ('Student information edit submitted for approval — ' .
                            count($fieldsToChange) . ' field(s) changed'),
                    'Priority' => $data['Priority'] ?? 'Normal'
                ];

                echo json_encode($api->createRequest($revisionData));

            // ── create (standalone revision request form) ───────────────────
            } elseif ($action === 'create') {
                $result = $api->createRequest($data);
                echo json_encode($result);

            // ── approve ─────────────────────────────────────────────────────
            } elseif ($action === 'approve') {
                $requestId  = $data['RequestID']  ?? null;
                $reviewedBy = $data['ReviewedBy'] ?? null;
                $reviewNotes = $data['ReviewNotes'] ?? null;
                if (!$requestId || !$reviewedBy) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                echo json_encode($api->approveRequest($requestId, $reviewedBy, $reviewNotes));

            // ── reject ──────────────────────────────────────────────────────
            } elseif ($action === 'reject') {
                $requestId   = $data['RequestID']   ?? null;
                $reviewedBy  = $data['ReviewedBy']  ?? null;
                $reviewNotes = $data['ReviewNotes'] ?? null;
                if (!$requestId || !$reviewedBy || !$reviewNotes) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Request ID, reviewer, and rejection reason required'
                    ]);
                    exit;
                }
                echo json_encode($api->rejectRequest($requestId, $reviewedBy, $reviewNotes));

            // ── implement (manual, kept for backwards compatibility) ─────────
            } elseif ($action === 'implement') {
                $requestId     = $data['RequestID']     ?? null;
                $implementedBy = $data['ImplementedBy'] ?? null;
                if (!$requestId || !$implementedBy) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                echo json_encode($api->implementRevision($requestId, $implementedBy));

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