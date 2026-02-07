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
                    
                    // =====================================================
                    // CRITICAL: Check if student exists and enrollment status
                    // =====================================================
                    $lrnCheck = $this->checkLRNAndEnrollment($student['lrn'], $student['schoolYear']);
                    
                    if ($lrnCheck === true) {
                        // Student already enrolled in this academic year - BLOCK
                        $results['errors'][] = "Row {$rowNum}: LRN {$student['lrn']} already enrolled in {$student['schoolYear']}";
                        $results['failed']++;
                        continue;
                    }
                    
                    $studentID = null;
                    
                    if (is_array($lrnCheck) && $lrnCheck['exists']) {
                        // Student exists but not enrolled in this year
                        // UPDATE student info and CREATE new enrollment
                        $studentID = $lrnCheck['studentID'];
                        $this->updateStudent($studentID, $student);
                    } else {
                        // New student - INSERT
                        $studentID = $this->insertStudent($student);
                    }
                    
                    if (!$studentID) {
                        $results['errors'][] = "Row {$rowNum}: Failed to insert/update student";
                        $results['failed']++;
                        continue;
                    }
                    
                    // Insert enrollment for this academic year
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
    
    /**
     * Check if LRN exists and if already enrolled in given academic year
     * 
     * @param string $lrn - Student LRN
     * @param string $academicYear - Academic year (e.g., "2025-2026")
     * @return mixed - true if enrolled this year, array with studentID if exists but not enrolled, false if new
     */
    private function checkLRNAndEnrollment($lrn, $academicYear) {
        // Check if student exists in student table
        $stmt = $this->conn->prepare("SELECT StudentID FROM student WHERE LRN = ?");
        $stmt->execute([$lrn]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return false; // Student doesn't exist at all - OK to create new
        }
        
        // Student exists - check if already enrolled in THIS academic year
        $stmt = $this->conn->prepare(
            "SELECT EnrollmentID 
             FROM enrollment 
             WHERE StudentID = ? AND AcademicYear = ?"
        );
        $stmt->execute([$student['StudentID'], $academicYear]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($enrollment) {
            return true; // Already enrolled in this year - BLOCK
        }
        
        // Student exists but not enrolled in this year - return StudentID to update
        return ['exists' => true, 'studentID' => $student['StudentID']];
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
    
    /**
     * Update existing student information
     * This happens when a student exists from a previous year
     * and is enrolling in a new academic year
     */
    private function updateStudent($studentID, $data) {
        $sql = "UPDATE student SET
            LastName = ?,
            FirstName = ?,
            MiddleName = ?,
            DateOfBirth = ?,
            Age = ?,
            Gender = ?,
            Religion = ?,
            IsIPCommunity = ?,
            IPCommunitySpecify = ?,
            IsPWD = ?,
            PWDSpecify = ?,
            HouseNumber = ?,
            SitioStreet = ?,
            Barangay = ?,
            Municipality = ?,
            Province = ?,
            GuardianLastName = ?,
            GuardianFirstName = ?,
            GuardianMiddleName = ?,
            ContactNumber = ?,
            Weight = ?,
            Height = ?,
            Is4PsBeneficiary = ?,
            ZipCode = ?,
            Country = ?,
            UpdatedBy = ?,
            UpdatedAt = NOW()
        WHERE StudentID = ?";
        
        $stmt = $this->conn->prepare($sql);
        
        $params = [
            $data['lastName'],
            $data['firstName'],
            $data['middleName'] ?? null,
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
            $data['guardianLastName'] ?? null,
            $data['guardianFirstName'] ?? null,
            $data['guardianMiddleName'] ?? null,
            $data['contactNumber'] ?? '',
            $data['weight'] ?? null,
            $data['height'] ?? null,
            $data['is4PsBeneficiary'] ? 1 : 0,
            $data['zipCode'] ?? null,
            $data['country'] ?? 'Philippines',
            $this->currentUserID,
            $studentID
        ];
        
        return $stmt->execute($params);
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