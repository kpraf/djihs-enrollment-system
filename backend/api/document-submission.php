<?php
// =====================================================
// Document Submission API - REVISED FOR NORMALIZED DB
// File: backend/api/document-submission.php
// Updated: 2026-03-04
// Revised to work with normalized documentsubmission table
// =====================================================

// Suppress all errors from being displayed
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

class DocumentSubmissionAPI {
    private $conn;
    
    // Document type mapping
    private $documentTypes = [
        'PSA_Birth_Cert',
        'Local_Birth_Cert', 
        'Report_Card',
        'Form_137',
        'Good_Moral',
        'Transfer_Cert'
    ];
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get document submission status for a student enrollment
     */
    public function getDocumentStatus($enrollmentId) {
        try {
            // Get all document submissions for this enrollment
            $query = "SELECT 
                ds.SubmissionID,
                ds.EnrollmentID,
                ds.DocumentType,
                ds.IsSubmitted,
                ds.IsVerified,
                ds.Notes,
                e.StudentID,
                CONCAT(s.LastName, ', ', s.FirstName, 
                    CASE WHEN s.MiddleName IS NOT NULL 
                    THEN CONCAT(' ', SUBSTRING(s.MiddleName, 1, 1), '.') 
                    ELSE '' END) AS StudentName
            FROM documentsubmission ds
            INNER JOIN enrollment e ON ds.EnrollmentID = e.EnrollmentID
            INNER JOIN student s ON e.StudentID = s.StudentID
            WHERE ds.EnrollmentID = :enrollmentId
            ORDER BY 
                CASE ds.DocumentType
                    WHEN 'PSA_Birth_Cert' THEN 1
                    WHEN 'Local_Birth_Cert' THEN 2
                    WHEN 'Report_Card' THEN 3
                    WHEN 'Form_137' THEN 4
                    WHEN 'Good_Moral' THEN 5
                    WHEN 'Transfer_Cert' THEN 6
                END";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':enrollmentId', $enrollmentId);
            $stmt->execute();
            
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($documents) {
                // Calculate completion status
                $requiredDocs = ['PSA_Birth_Cert', 'Local_Birth_Cert', 'Report_Card', 'Form_137'];
                $hasBirthCert = false;
                $hasReportCard = false;
                $hasForm137 = false;
                
                foreach ($documents as $doc) {
                    if ($doc['DocumentType'] === 'PSA_Birth_Cert' && $doc['IsSubmitted']) {
                        $hasBirthCert = true;
                    }
                    if ($doc['DocumentType'] === 'Local_Birth_Cert' && $doc['IsSubmitted']) {
                        $hasBirthCert = true;
                    }
                    if ($doc['DocumentType'] === 'Report_Card' && $doc['IsSubmitted']) {
                        $hasReportCard = true;
                    }
                    if ($doc['DocumentType'] === 'Form_137' && $doc['IsSubmitted']) {
                        $hasForm137 = true;
                    }
                }
                
                $isComplete = $hasBirthCert && $hasReportCard && $hasForm137;
                
                return [
                    'success' => true,
                    'data' => [
                        'documents' => $documents,
                        'studentName' => $documents[0]['StudentName'],
                        'enrollmentId' => $enrollmentId,
                        'isComplete' => $isComplete,
                        'hasBirthCert' => $hasBirthCert,
                        'hasReportCard' => $hasReportCard,
                        'hasForm137' => $hasForm137
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'No document submission records found'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching document status: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update a single document submission
     */
    public function updateDocument($data) {
        try {
            $this->conn->beginTransaction();
            
            $submissionId = $data['SubmissionID'] ?? null;
            $isSubmitted = isset($data['IsSubmitted']) ? (int)$data['IsSubmitted'] : null;
            $isVerified = isset($data['IsVerified']) ? (int)$data['IsVerified'] : null;
            $notes = $data['Notes'] ?? null;
            
            if (!$submissionId) {
                throw new Exception('Submission ID is required');
            }
            
            // Build update query
            $updates = [];
            $params = [':submissionId' => $submissionId];
            
            if ($isSubmitted !== null) {
                $updates[] = "IsSubmitted = :isSubmitted";
                $params[':isSubmitted'] = $isSubmitted;
            }
            
            if ($isVerified !== null) {
                $updates[] = "IsVerified = :isVerified";
                $params[':isVerified'] = $isVerified;
            }
            
            if ($notes !== null) {
                $updates[] = "Notes = :notes";
                $params[':notes'] = $notes;
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $query = "UPDATE documentsubmission SET " . 
                     implode(", ", $updates) . 
                     " WHERE SubmissionID = :submissionId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            // Log the action in audit log
            $this->logDocumentAction($submissionId, $isSubmitted, $isVerified);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Document updated successfully'
            ];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Error updating document: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Create document submission records for a new enrollment
     */
    public function createDocumentSubmissions($enrollmentId) {
        try {
            $this->conn->beginTransaction();
            
            // Check if enrollment exists
            $checkQuery = "SELECT EnrollmentID FROM enrollment WHERE EnrollmentID = :enrollmentId";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':enrollmentId' => $enrollmentId]);
            
            if (!$checkStmt->fetch()) {
                throw new Exception('Enrollment not found');
            }
            
            // Check if records already exist
            $existQuery = "SELECT COUNT(*) as count FROM documentsubmission 
                          WHERE EnrollmentID = :enrollmentId";
            $existStmt = $this->conn->prepare($existQuery);
            $existStmt->execute([':enrollmentId' => $enrollmentId]);
            $existResult = $existStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existResult['count'] > 0) {
                return [
                    'success' => false,
                    'message' => 'Document submission records already exist for this enrollment'
                ];
            }
            
            // Create a record for each document type
            $insertQuery = "INSERT INTO documentsubmission 
                           (EnrollmentID, DocumentType, IsSubmitted, IsVerified, Notes)
                           VALUES (:enrollmentId, :docType, 0, 0, NULL)";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            
            foreach ($this->documentTypes as $docType) {
                $insertStmt->execute([
                    ':enrollmentId' => $enrollmentId,
                    ':docType' => $docType
                ]);
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Document submission records created successfully'
            ];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Error creating document submissions: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all students with incomplete documents
     */
    public function getIncompleteDocuments($academicYearId = null, $gradeLevelId = null) {
        try {
            $query = "SELECT 
                s.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, 
                    CASE WHEN s.MiddleName IS NOT NULL 
                    THEN CONCAT(' ', SUBSTRING(s.MiddleName, 1, 1), '.') 
                    ELSE '' END) AS StudentName,
                e.EnrollmentID,
                e.AcademicYearID,
                ay.YearLabel,
                gl.GradeLevelName,
                st.StrandCode,
                sec.SectionName,
                -- Count documents
                COUNT(ds.SubmissionID) as TotalDocs,
                SUM(CASE WHEN ds.IsSubmitted = 1 THEN 1 ELSE 0 END) as SubmittedDocs,
                -- Check required docs
                MAX(CASE WHEN ds.DocumentType IN ('PSA_Birth_Cert', 'Local_Birth_Cert') 
                    AND ds.IsSubmitted = 1 THEN 1 ELSE 0 END) as HasBirthCert,
                MAX(CASE WHEN ds.DocumentType = 'Report_Card' 
                    AND ds.IsSubmitted = 1 THEN 1 ELSE 0 END) as HasReportCard,
                MAX(CASE WHEN ds.DocumentType = 'Form_137' 
                    AND ds.IsSubmitted = 1 THEN 1 ELSE 0 END) as HasForm137
            FROM student s
            INNER JOIN enrollment e ON s.StudentID = e.StudentID
            INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
            INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
            LEFT JOIN strand st ON e.StrandID = st.StrandID
            LEFT JOIN sectionassignment sa ON e.EnrollmentID = sa.EnrollmentID AND sa.IsActive = 1
            LEFT JOIN section sec ON sa.SectionID = sec.SectionID
            LEFT JOIN documentsubmission ds ON e.EnrollmentID = ds.EnrollmentID
            WHERE e.Status IN ('Pending', 'Confirmed', 'For_Review')";
            
            $params = [];
            
            if ($academicYearId) {
                $query .= " AND e.AcademicYearID = :academicYearId";
                $params[':academicYearId'] = $academicYearId;
            }
            
            if ($gradeLevelId) {
                $query .= " AND e.GradeLevelID = :gradeLevelId";
                $params[':gradeLevelId'] = $gradeLevelId;
            }
            
            $query .= " GROUP BY s.StudentID, e.EnrollmentID
                       HAVING (HasBirthCert = 0 OR HasReportCard = 0 OR HasForm137 = 0)
                       ORDER BY s.LastName, s.FirstName";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching incomplete documents: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get document completion summary
     */
    public function getCompletionSummary($academicYearId = null) {
        try {
            $query = "SELECT 
                COUNT(DISTINCT e.EnrollmentID) as TotalEnrollments,
                COUNT(DISTINCT CASE 
                    WHEN (SELECT COUNT(*) 
                          FROM documentsubmission ds2 
                          WHERE ds2.EnrollmentID = e.EnrollmentID 
                          AND ds2.DocumentType IN ('PSA_Birth_Cert', 'Local_Birth_Cert')
                          AND ds2.IsSubmitted = 1) > 0
                    AND (SELECT COUNT(*) 
                         FROM documentsubmission ds3 
                         WHERE ds3.EnrollmentID = e.EnrollmentID 
                         AND ds3.DocumentType = 'Report_Card'
                         AND ds3.IsSubmitted = 1) > 0
                    AND (SELECT COUNT(*) 
                         FROM documentsubmission ds4 
                         WHERE ds4.EnrollmentID = e.EnrollmentID 
                         AND ds4.DocumentType = 'Form_137'
                         AND ds4.IsSubmitted = 1) > 0
                    THEN e.EnrollmentID 
                END) as CompleteCount,
                COUNT(DISTINCT ds.SubmissionID) as TotalDocSubmissions,
                SUM(CASE WHEN ds.IsSubmitted = 1 THEN 1 ELSE 0 END) as SubmittedCount,
                SUM(CASE WHEN ds.IsVerified = 1 THEN 1 ELSE 0 END) as VerifiedCount
            FROM enrollment e
            LEFT JOIN documentsubmission ds ON e.EnrollmentID = ds.EnrollmentID
            WHERE e.Status IN ('Pending', 'Confirmed', 'For_Review')";
            
            if ($academicYearId) {
                $query .= " AND e.AcademicYearID = :academicYearId";
            }
            
            $stmt = $this->conn->prepare($query);
            
            if ($academicYearId) {
                $stmt->execute([':academicYearId' => $academicYearId]);
            } else {
                $stmt->execute();
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $result['IncompleteCount'] = $result['TotalEnrollments'] - $result['CompleteCount'];
            
            return [
                'success' => true,
                'data' => $result
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching summary: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Log document action to audit log
     */
    private function logDocumentAction($submissionId, $isSubmitted, $isVerified) {
        try {
            // Get document info
            $query = "SELECT ds.EnrollmentID, ds.DocumentType, e.StudentID
                     FROM documentsubmission ds
                     INNER JOIN enrollment e ON ds.EnrollmentID = e.EnrollmentID
                     WHERE ds.SubmissionID = :submissionId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':submissionId' => $submissionId]);
            $docInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($docInfo) {
                $action = 'UPDATE';
                $actionDesc = "Document {$docInfo['DocumentType']} updated";
                
                if ($isSubmitted !== null) {
                    $action = 'DOCUMENT_SUBMISSION';
                    $actionDesc = $isSubmitted 
                        ? "Document {$docInfo['DocumentType']} marked as submitted"
                        : "Document {$docInfo['DocumentType']} marked as not submitted";
                }
                
                if ($isVerified !== null) {
                    $action = 'DOCUMENT_VERIFICATION';
                    $actionDesc = $isVerified
                        ? "Document {$docInfo['DocumentType']} verified"
                        : "Document {$docInfo['DocumentType']} unverified";
                }
                
                $auditQuery = "INSERT INTO auditlog 
                              (TableName, RecordID, Action, ActionDescription, ChangedAt)
                              VALUES ('documentsubmission', :recordId, :action, :actionDesc, NOW())";
                
                $auditStmt = $this->conn->prepare($auditQuery);
                $auditStmt->execute([
                    ':recordId' => $submissionId,
                    ':action' => $action,
                    ':actionDesc' => $actionDesc
                ]);
            }
            
        } catch (PDOException $e) {
            // Log error but don't fail the main operation
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
    
    $api = new DocumentSubmissionAPI($db);
    $action = $_GET['action'] ?? '';
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'get_status') {
                $enrollmentId = $_GET['enrollment_id'] ?? null;
                
                if (!$enrollmentId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Enrollment ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getDocumentStatus($enrollmentId);
                echo json_encode($result);
                
            } elseif ($action === 'get_incomplete') {
                $academicYearId = $_GET['academic_year_id'] ?? null;
                $gradeLevelId = $_GET['grade_level_id'] ?? null;
                
                $result = $api->getIncompleteDocuments($academicYearId, $gradeLevelId);
                echo json_encode($result);
                
            } elseif ($action === 'get_summary') {
                $academicYearId = $_GET['academic_year_id'] ?? null;
                
                $result = $api->getCompletionSummary($academicYearId);
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
            
            if ($action === 'update_document') {
                $result = $api->updateDocument($data);
                echo json_encode($result);
                
            } elseif ($action === 'create') {
                $enrollmentId = $data['EnrollmentID'] ?? null;
                
                if (!$enrollmentId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Enrollment ID required'
                    ]);
                    exit;
                }
                
                $result = $api->createDocumentSubmissions($enrollmentId);
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
    error_log("Document Submission API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred'
    ]);
}
?>