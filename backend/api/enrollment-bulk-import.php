<?php
// =====================================================
// Bulk Import API - Student Enrollment
// File: backend/api/enrollment-bulk-import.php
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

class BulkImportAPI {
    private $conn;
    private $currentUserID;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function processImport($data) {
        // Validate input
        if (!isset($data['students']) || !is_array($data['students'])) {
            return $this->error('Invalid data format');
        }
        
        if (!isset($data['createdBy'])) {
            return $this->error('User ID is required');
        }
        
        $this->currentUserID = $data['createdBy'];
        $students = $data['students'];
        
        // Initialize counters
        $results = [
            'total' => count($students),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        // Begin transaction
        $this->conn->beginTransaction();
        
        try {
            foreach ($students as $index => $student) {
                $rowNum = $index + 1;
                
                try {
                    // Validate student data
                    $validation = $this->validateStudent($student, $rowNum);
                    if ($validation !== true) {
                        $results['errors'][] = $validation;
                        $results['failed']++;
                        continue;
                    }
                    
                    // Check if LRN already exists
                    if ($this->lrnExists($student['lrn'])) {
                        $results['errors'][] = "Row {$rowNum}: LRN {$student['lrn']} already exists";
                        $results['failed']++;
                        continue;
                    }
                    
                    // Insert student
                    $studentID = $this->insertStudent($student);
                    
                    if (!$studentID) {
                        $results['errors'][] = "Row {$rowNum}: Failed to insert student";
                        $results['failed']++;
                        continue;
                    }
                    
                    // Insert enrollment
                    $enrollmentID = $this->insertEnrollment($studentID, $student);
                    
                    if (!$enrollmentID) {
                        $results['errors'][] = "Row {$rowNum}: Failed to create enrollment";
                        $results['failed']++;
                        continue;
                    }
                    
                    $results['success']++;
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Row {$rowNum}: " . $e->getMessage();
                    $results['failed']++;
                }
            }
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => "Import completed: {$results['success']} successful, {$results['failed']} failed",
                'results' => $results
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return $this->error('Import failed: ' . $e->getMessage());
        }
    }
    
    private function validateStudent($student, $rowNum) {
        // Required fields
        $required = ['lrn', 'lastName', 'firstName', 'birthdate', 'sex', 'gradeLevel', 'schoolYear', 'learnerType'];
        
        foreach ($required as $field) {
            if (empty($student[$field])) {
                return "Row {$rowNum}: Missing required field: {$field}";
            }
        }
        
        // Validate LRN format (12 digits)
        if (!preg_match('/^\d{12}$/', $student['lrn'])) {
            return "Row {$rowNum}: Invalid LRN format (must be 12 digits)";
        }
        
        // Validate grade level
        if (!in_array($student['gradeLevel'], [1, 2, 3, 4, 5, 6])) {
            return "Row {$rowNum}: Invalid grade level";
        }
        
        // Validate strand for Grade 11 & 12
        if (in_array($student['gradeLevel'], [5, 6]) && empty($student['strandID'])) {
            return "Row {$rowNum}: Strand is required for Grade 11 & 12";
        }
        
        return true;
    }
    
    private function lrnExists($lrn) {
        $stmt = $this->conn->prepare("SELECT StudentID FROM student WHERE LRN = ?");
        $stmt->execute([$lrn]);
        return $stmt->fetch() !== false;
    }
    
    private function insertStudent($data) {
        $sql = "INSERT INTO student (
            LRN, LastName, FirstName, MiddleName, ExtensionName,
            DateOfBirth, Age, Gender, Religion,
            IsIPCommunity, IPCommunitySpecify, IsPWD, PWDSpecify,
            HouseNumber, SitioStreet, Barangay, Municipality, Province,
            FatherLastName, FatherFirstName, FatherMiddleName,
            MotherLastName, MotherFirstName, MotherMiddleName,
            GuardianLastName, GuardianFirstName, GuardianMiddleName,
            ContactNumber, EnrollmentStatus, IsTransferee,
            Weight, Height, Is4PsBeneficiary, ZipCode, Country,
            EncodedDate, EncodedBy, CreatedBy
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, 'Active', ?,
            ?, ?, ?, ?, ?,
            NOW(), ?, ?
        )";
        
        $stmt = $this->conn->prepare($sql);
        
        $isTransferee = str_starts_with($data['learnerType'], 'Irregular') ? 1 : 0;
        
        $params = [
            $data['lrn'],
            $data['lastName'],
            $data['firstName'],
            $data['middleName'] ?? null,
            $data['extensionName'] ?? null,
            $data['birthdate'],
            $data['age'] ?? null,
            $data['sex'],
            $data['religion'] ?? null,
            $data['isIPCommunity'] ? 1 : 0,
            $data['ipCommunitySpecify'] ?? null,
            $data['isPWD'] ? 1 : 0,
            $data['pwdSpecify'] ?? null,
            $data['houseNumber'] ?? null,
            $data['sitioStreet'] ?? null,
            $data['barangay'] ?? '',
            $data['municipality'] ?? '',
            $data['province'] ?? '',
            $data['fatherLastName'] ?? null,
            $data['fatherFirstName'] ?? null,
            $data['fatherMiddleName'] ?? null,
            $data['motherLastName'] ?? null,
            $data['motherFirstName'] ?? null,
            $data['motherMiddleName'] ?? null,
            $data['guardianLastName'] ?? null,
            $data['guardianFirstName'] ?? null,
            $data['guardianMiddleName'] ?? null,
            $data['contactNumber'] ?? '',
            $isTransferee,
            $data['weight'] ?? null,
            $data['height'] ?? null,
            $data['is4PsBeneficiary'] ? 1 : 0,
            $data['zipCode'] ?? null,
            $data['country'] ?? 'Philippines',
            $this->currentUserID,
            $this->currentUserID
        ];
        
        if ($stmt->execute($params)) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    private function insertEnrollment($studentID, $data) {
        $sql = "INSERT INTO enrollment (
            StudentID, GradeLevelID, StrandID, AcademicYear,
            LearnerType, EnrollmentType, Status,
            EnrollmentDate, CreatedAt
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, 'Pending',
            NOW(), NOW()
        )";
        
        $stmt = $this->conn->prepare($sql);
        
        // Determine enrollment type
        $enrollmentType = 'Regular';
        if ($data['learnerType'] === 'Irregular_Transferee') {
            $enrollmentType = 'Transferee';
        }
        
        $params = [
            $studentID,
            $data['gradeLevel'],
            $data['strandID'] ?? null,
            $data['schoolYear'],
            $data['learnerType'],
            $enrollmentType
        ];
        
        if ($stmt->execute($params)) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    private function error($message) {
        return [
            'success' => false,
            'message' => $message
        ];
    }
}

// Handle request
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $api = new BulkImportAPI($db);
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data');
    }
    
    $result = $api->processImport($data);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}