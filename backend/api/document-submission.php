<?php
// =====================================================
// Document Submission API
// File: backend/api/document-submission.php
// Created: 2026-02-08
// Fixed: 2026-02-08 - Resolved JSON parsing errors
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
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get document submission status for a student
     */
    public function getDocumentStatus($studentId, $enrollmentId = null) {
        try {
            $query = "SELECT 
                ds.*,
                CONCAT(psa_user.FirstName, ' ', psa_user.LastName) AS PSAVerifiedByName,
                CONCAT(local_user.FirstName, ' ', local_user.LastName) AS LocalBirthCertVerifiedByName,
                CONCAT(rc_user.FirstName, ' ', rc_user.LastName) AS ReportCardVerifiedByName,
                CONCAT(f137_user.FirstName, ' ', f137_user.LastName) AS Form137VerifiedByName,
                CONCAT(final_user.FirstName, ' ', final_user.LastName) AS FinalVerifiedByName
            FROM DocumentSubmission ds
            LEFT JOIN User psa_user ON ds.PSAVerifiedBy = psa_user.UserID
            LEFT JOIN User local_user ON ds.LocalBirthCertVerifiedBy = local_user.UserID
            LEFT JOIN User rc_user ON ds.ReportCardVerifiedBy = rc_user.UserID
            LEFT JOIN User f137_user ON ds.Form137VerifiedBy = f137_user.UserID
            LEFT JOIN User final_user ON ds.FinalVerifiedBy = final_user.UserID
            WHERE ds.StudentID = :studentId";
            
            if ($enrollmentId) {
                $query .= " AND ds.EnrollmentID = :enrollmentId";
            } else {
                $query .= " ORDER BY ds.CreatedAt DESC LIMIT 1";
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':studentId', $studentId);
            if ($enrollmentId) {
                $stmt->bindParam(':enrollmentId', $enrollmentId);
            }
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
                    'message' => 'No document submission record found'
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
     * Update document submission checklist
     */
    public function updateDocumentChecklist($data) {
        try {
            $this->conn->beginTransaction();
            
            $submissionId = $data['SubmissionID'];
            $documentType = $data['DocumentType'];
            $isChecked = $data['IsChecked'];
            $userId = $data['UserID'];
            $notes = $data['Notes'] ?? null;
            
            // Build update query based on document type
            $updateFields = [];
            $params = [':submissionId' => $submissionId];
            
            switch ($documentType) {
                case 'PSA':
                    $updateFields[] = "HasPSABirthCert = :isChecked";
                    if ($isChecked) {
                        $updateFields[] = "PSASubmissionDate = NOW()";
                        $updateFields[] = "PSAVerifiedBy = :verifiedBy";
                        $params[':verifiedBy'] = $userId;
                    } else {
                        $updateFields[] = "PSASubmissionDate = NULL";
                        $updateFields[] = "PSAVerifiedBy = NULL";
                    }
                    if ($notes) {
                        $updateFields[] = "PSANotes = :notes";
                        $params[':notes'] = $notes;
                    }
                    break;
                    
                case 'Local':
                    $updateFields[] = "HasLocalBirthCert = :isChecked";
                    if ($isChecked) {
                        $updateFields[] = "LocalBirthCertSubmissionDate = NOW()";
                        $updateFields[] = "LocalBirthCertVerifiedBy = :verifiedBy";
                        $params[':verifiedBy'] = $userId;
                    } else {
                        $updateFields[] = "LocalBirthCertSubmissionDate = NULL";
                        $updateFields[] = "LocalBirthCertVerifiedBy = NULL";
                    }
                    if ($notes) {
                        $updateFields[] = "LocalBirthCertNotes = :notes";
                        $params[':notes'] = $notes;
                    }
                    if (isset($data['CertType'])) {
                        $updateFields[] = "LocalBirthCertType = :certType";
                        $params[':certType'] = $data['CertType'];
                    }
                    break;
                    
                case 'ReportCard':
                    $updateFields[] = "HasReportCard = :isChecked";
                    if ($isChecked) {
                        $updateFields[] = "ReportCardSubmissionDate = NOW()";
                        $updateFields[] = "ReportCardVerifiedBy = :verifiedBy";
                        $params[':verifiedBy'] = $userId;
                    } else {
                        $updateFields[] = "ReportCardSubmissionDate = NULL";
                        $updateFields[] = "ReportCardVerifiedBy = NULL";
                    }
                    if ($notes) {
                        $updateFields[] = "ReportCardNotes = :notes";
                        $params[':notes'] = $notes;
                    }
                    break;
                    
                case 'Form137':
                    $updateFields[] = "HasForm137 = :isChecked";
                    if ($isChecked) {
                        $updateFields[] = "Form137SubmissionDate = NOW()";
                        $updateFields[] = "Form137VerifiedBy = :verifiedBy";
                        $params[':verifiedBy'] = $userId;
                    } else {
                        $updateFields[] = "Form137SubmissionDate = NULL";
                        $updateFields[] = "Form137VerifiedBy = NULL";
                    }
                    if ($notes) {
                        $updateFields[] = "Form137Notes = :notes";
                        $params[':notes'] = $notes;
                    }
                    break;
                    
                case 'GoodMoral':
                    $updateFields[] = "HasGoodMoral = :isChecked";
                    if ($isChecked) {
                        $updateFields[] = "GoodMoralSubmissionDate = NOW()";
                    } else {
                        $updateFields[] = "GoodMoralSubmissionDate = NULL";
                    }
                    break;
                    
                case 'TransferCert':
                    $updateFields[] = "HasTransferCert = :isChecked";
                    if ($isChecked) {
                        $updateFields[] = "TransferCertSubmissionDate = NOW()";
                    } else {
                        $updateFields[] = "TransferCertSubmissionDate = NULL";
                    }
                    break;
                    
                default:
                    throw new Exception('Invalid document type');
            }
            
            $params[':isChecked'] = $isChecked;
            
            $updateFields[] = "UpdatedBy = :userId";
            $updateFields[] = "UpdatedAt = NOW()";
            $params[':userId'] = $userId;
            
            $query = "UPDATE DocumentSubmission SET " . 
                     implode(", ", $updateFields) . 
                     " WHERE SubmissionID = :submissionId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            // Check if all required documents are complete
            $this->checkAndUpdateCompletion($submissionId);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Document checklist updated successfully'
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
     * Check and update completion status
     */
    private function checkAndUpdateCompletion($submissionId) {
        $query = "SELECT 
            (HasPSABirthCert OR HasLocalBirthCert) AS HasBirthCert,
            HasReportCard,
            HasForm137
        FROM DocumentSubmission
        WHERE SubmissionID = :submissionId";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':submissionId' => $submissionId]);
        $status = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($status['HasBirthCert'] && $status['HasReportCard'] && $status['HasForm137']) {
            $updateQuery = "UPDATE DocumentSubmission 
                SET AllDocsComplete = 1,
                    CompletionDate = NOW()
                WHERE SubmissionID = :submissionId 
                AND AllDocsComplete = 0";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([':submissionId' => $submissionId]);
        } else {
            $updateQuery = "UPDATE DocumentSubmission 
                SET AllDocsComplete = 0,
                    CompletionDate = NULL
                WHERE SubmissionID = :submissionId 
                AND AllDocsComplete = 1";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([':submissionId' => $submissionId]);
        }
    }
    
    /**
     * Mark all documents as verified (final verification)
     */
    public function finalVerification($submissionId, $userId) {
        try {
            $query = "UPDATE DocumentSubmission
                SET AllDocsComplete = 1,
                    FinalVerifiedBy = :userId,
                    FinalVerificationDate = NOW(),
                    CompletionDate = NOW()
                WHERE SubmissionID = :submissionId";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':submissionId' => $submissionId,
                ':userId' => $userId
            ]);
            
            return [
                'success' => true,
                'message' => 'Documents verified successfully'
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error verifying documents: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get document submission history
     */
    public function getDocumentHistory($submissionId) {
        try {
            $query = "SELECT 
                dsh.*,
                CONCAT(u.FirstName, ' ', u.LastName) AS ActionByName,
                u.Role AS ActionByRole
            FROM DocumentSubmissionHistory dsh
            INNER JOIN User u ON dsh.ActionBy = u.UserID
            WHERE dsh.SubmissionID = :submissionId
            ORDER BY dsh.ActionDate DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':submissionId' => $submissionId]);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error fetching history: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all students with incomplete documents
     */
    public function getIncompleteDocuments($academicYear = null, $gradeLevel = null) {
        try {
            $query = "SELECT * FROM vw_DocumentCompletionStatus
                WHERE RequiredDocsComplete = 0";
            
            $params = [];
            
            if ($academicYear) {
                $query .= " AND AcademicYear = :academicYear";
                $params[':academicYear'] = $academicYear;
            }
            
            if ($gradeLevel) {
                $query .= " AND GradeLevelName = :gradeLevel";
                $params[':gradeLevel'] = $gradeLevel;
            }
            
            $query .= " ORDER BY StudentName";
            
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
     * Create document submission record for new enrollment
     */
    public function createDocumentSubmission($enrollmentId, $userId) {
        try {
            // Get enrollment details
            $query = "SELECT StudentID, AcademicYear 
                FROM Enrollment 
                WHERE EnrollmentID = :enrollmentId";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':enrollmentId' => $enrollmentId]);
            $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$enrollment) {
                throw new Exception('Enrollment not found');
            }
            
            // Check if record already exists
            $checkQuery = "SELECT SubmissionID FROM DocumentSubmission 
                WHERE EnrollmentID = :enrollmentId";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':enrollmentId' => $enrollmentId]);
            
            if ($checkStmt->fetch()) {
                return [
                    'success' => false,
                    'message' => 'Document submission record already exists'
                ];
            }
            
            // Create new record
            $insertQuery = "INSERT INTO DocumentSubmission 
                (StudentID, EnrollmentID, AcademicYear, CreatedBy, UpdatedBy)
                VALUES (:studentId, :enrollmentId, :academicYear, :userId, :userId)";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->execute([
                ':studentId' => $enrollment['StudentID'],
                ':enrollmentId' => $enrollmentId,
                ':academicYear' => $enrollment['AcademicYear'],
                ':userId' => $userId
            ]);
            
            $submissionId = $this->conn->lastInsertId();
            
            return [
                'success' => true,
                'message' => 'Document submission record created',
                'submissionId' => $submissionId
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error creating document submission: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get document completion summary
     */
    public function getCompletionSummary($academicYear) {
        try {
            $query = "SELECT 
                COUNT(*) AS TotalStudents,
                SUM(CASE WHEN RequiredDocsComplete = 1 THEN 1 ELSE 0 END) AS CompleteCount,
                SUM(CASE WHEN RequiredDocsComplete = 0 THEN 1 ELSE 0 END) AS IncompleteCount,
                SUM(CASE WHEN HasPSABirthCert = 1 OR HasLocalBirthCert = 1 THEN 1 ELSE 0 END) AS HasBirthCertCount,
                SUM(CASE WHEN HasReportCard = 1 THEN 1 ELSE 0 END) AS HasReportCardCount,
                SUM(CASE WHEN HasForm137 = 1 THEN 1 ELSE 0 END) AS HasForm137Count
            FROM vw_DocumentCompletionStatus";
            
            if ($academicYear && $academicYear !== 'all') {
                $query .= " WHERE AcademicYear = :academicYear";
            }
            
            $stmt = $this->conn->prepare($query);
            if ($academicYear && $academicYear !== 'all') {
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
                'message' => 'Error fetching summary: ' . $e->getMessage()
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
    
    $api = new DocumentSubmissionAPI($db);
    $action = $_GET['action'] ?? '';
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'get_status') {
                $studentId = $_GET['student_id'] ?? null;
                $enrollmentId = $_GET['enrollment_id'] ?? null;
                
                if (!$studentId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Student ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getDocumentStatus($studentId, $enrollmentId);
                echo json_encode($result);
                
            } elseif ($action === 'get_history') {
                $submissionId = $_GET['submission_id'] ?? null;
                
                if (!$submissionId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Submission ID required'
                    ]);
                    exit;
                }
                
                $result = $api->getDocumentHistory($submissionId);
                echo json_encode($result);
                
            } elseif ($action === 'get_incomplete') {
                $academicYear = $_GET['academic_year'] ?? null;
                $gradeLevel = $_GET['grade_level'] ?? null;
                
                $result = $api->getIncompleteDocuments($academicYear, $gradeLevel);
                echo json_encode($result);
                
            } elseif ($action === 'get_summary') {
                $academicYear = $_GET['academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
                
                $result = $api->getCompletionSummary($academicYear);
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
            
            if ($action === 'update_checklist') {
                $result = $api->updateDocumentChecklist($data);
                echo json_encode($result);
                
            } elseif ($action === 'final_verification') {
                $submissionId = $data['SubmissionID'] ?? null;
                $userId = $data['UserID'] ?? null;
                
                if (!$submissionId || !$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }
                
                $result = $api->finalVerification($submissionId, $userId);
                echo json_encode($result);
                
            } elseif ($action === 'create') {
                $enrollmentId = $data['EnrollmentID'] ?? null;
                $userId = $data['UserID'] ?? null;
                
                if (!$enrollmentId || !$userId) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing required fields'
                    ]);
                    exit;
                }
                
                $result = $api->createDocumentSubmission($enrollmentId, $userId);
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