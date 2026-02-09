<?php
// ============================================
// FILE: backend/api/section-assignments.php
// Purpose: Handle student-to-section assignments
// FIXED VERSION - with transaction isolation and failure tracking
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'unassigned') {
                getUnassignedStudents($conn);
            } elseif ($action === 'section-students') {
                getSectionStudents($conn);
            } elseif ($action === 'sections-with-students') {
                getSectionsWithStudents($conn);
            }
            break;
            
        case 'POST':
            if ($action === 'assign') {
                assignStudent($conn);
            } elseif ($action === 'auto-assign') {
                autoAssignStudents($conn);
            } elseif ($action === 'clear-section') {
                clearSection($conn);
            }
            break;
            
        case 'DELETE':
            if ($action === 'unassign') {
                unassignStudent($conn);
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getUnassignedStudents($conn) {
    $academicYear = isset($_GET['year']) ? $_GET['year'] : null;
    $gradeLevel = isset($_GET['grade']) ? $_GET['grade'] : null;
    $strand = isset($_GET['strand']) ? $_GET['strand'] : null;
    $search = isset($_GET['search']) ? $_GET['search'] : null;
    
    if (!$academicYear || !$gradeLevel) {
        throw new Exception('Academic year and grade level are required');
    }
    
    $query = "SELECT DISTINCT
                s.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) as StudentName,
                s.Gender,
                s.IsPWD,
                s.Municipality,
                s.Barangay,
                e.EnrollmentID,
                e.LearnerType,
                e.StrandID,
                gl.GradeLevelName,
                st.StrandCode,
                st.StrandName
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
              LEFT JOIN strand st ON e.StrandID = st.StrandID
              WHERE e.AcademicYear = :year
              AND gl.GradeLevelNumber = :grade
              AND e.Status IN ('Confirmed', 'Pending')
              AND NOT EXISTS (
                  SELECT 1 FROM sectionassignment sa
                  WHERE sa.StudentID = s.StudentID
                  AND sa.EnrollmentID = e.EnrollmentID
                  AND sa.IsActive = 1
              )";
    
    $params = [
        ':year' => $academicYear,
        ':grade' => $gradeLevel
    ];
    
    if ($strand) {
        $query .= " AND e.StrandID = :strand";
        $params[':strand'] = $strand;
    }
    
    if ($search) {
        $query .= " AND (s.LRN LIKE :search OR s.FirstName LIKE :search OR s.LastName LIKE :search OR CONCAT(s.LastName, ', ', s.FirstName) LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    $query .= " ORDER BY e.EnrollmentDate ASC, s.LastName, s.FirstName";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $students = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $students[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'students' => $students,
        'count' => count($students)
    ]);
}

function getSectionStudents($conn) {
    $sectionId = isset($_GET['sectionId']) ? $_GET['sectionId'] : null;
    
    if (!$sectionId) {
        throw new Exception('Section ID is required');
    }
    
    $query = "SELECT 
                s.StudentID,
                s.LRN,
                CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) as StudentName,
                s.Gender,
                s.IsPWD,
                sa.AssignmentID,
                sa.AssignmentMethod,
                sa.AssignmentDate
              FROM sectionassignment sa
              INNER JOIN student s ON sa.StudentID = s.StudentID
              WHERE sa.SectionID = :sectionId
              AND sa.IsActive = 1
              ORDER BY s.LastName, s.FirstName";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':sectionId', $sectionId);
    $stmt->execute();
    
    $students = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $students[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'students' => $students
    ]);
}

function getSectionsWithStudents($conn) {
    $academicYear = isset($_GET['year']) ? $_GET['year'] : null;
    $gradeLevel = isset($_GET['grade']) ? $_GET['grade'] : null;
    $strand = isset($_GET['strand']) ? $_GET['strand'] : null;
    
    if (!$academicYear || !$gradeLevel) {
        throw new Exception('Academic year and grade level are required');
    }
    
    $query = "SELECT 
                sec.SectionID,
                sec.SectionName,
                sec.Capacity,
                sec.CurrentEnrollment,
                sec.StrandID,
                sec.CreatedAt,
                gl.GradeLevelName,
                st.StrandCode,
                st.StrandName,
                COALESCE(CONCAT(e.LastName, ', ', e.FirstName), CONCAT(u.LastName, ', ', u.FirstName)) as AdviserName,
                (sec.Capacity - sec.CurrentEnrollment) as AvailableSlots
              FROM section sec
              INNER JOIN gradelevel gl ON sec.GradeLevelID = gl.GradeLevelID
              LEFT JOIN strand st ON sec.StrandID = st.StrandID
              LEFT JOIN employee e ON sec.AdviserEmployeeID = e.EmployeeID
              LEFT JOIN user u ON sec.AdviserID = u.UserID
              WHERE sec.AcademicYear = :year
              AND gl.GradeLevelNumber = :grade
              AND sec.IsActive = 1";
    
    $params = [
        ':year' => $academicYear,
        ':grade' => $gradeLevel
    ];
    
    if ($strand) {
        $query .= " AND sec.StrandID = :strand";
        $params[':strand'] = $strand;
    }
    
    $query .= " ORDER BY sec.CreatedAt ASC, sec.SectionName ASC";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $sections = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sections[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'sections' => $sections
    ]);
}

function assignStudent($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['studentId']) || !isset($data['sectionId']) || !isset($data['enrollmentId'])) {
        throw new Exception('Student ID, Section ID, and Enrollment ID are required');
    }
    
    $userId = isset($data['assignedBy']) ? $data['assignedBy'] : null;
    
    // Check section capacity and strand
    $checkQuery = "SELECT sec.Capacity, sec.CurrentEnrollment, sec.StrandID, sec.GradeLevelID
                   FROM section sec
                   WHERE sec.SectionID = :sectionId";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bindValue(':sectionId', $data['sectionId']);
    $stmt->execute();
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section) {
        throw new Exception('Section not found');
    }
    
    if ($section['CurrentEnrollment'] >= $section['Capacity']) {
        throw new Exception('Section is already full');
    }
    
    // Get student's enrollment details
    $studentQuery = "SELECT e.StrandID, e.GradeLevelID
                     FROM enrollment e
                     WHERE e.EnrollmentID = :enrollmentId";
    $stmt = $conn->prepare($studentQuery);
    $stmt->bindValue(':enrollmentId', $data['enrollmentId']);
    $stmt->execute();
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        throw new Exception('Enrollment not found');
    }
    
    // Validate strand matching (only for Grade 11-12)
    if ($section['StrandID'] !== null && $enrollment['StrandID'] !== null) {
        if ($section['StrandID'] != $enrollment['StrandID']) {
            throw new Exception('Student strand does not match section strand');
        }
    }
    
    // Check if student is already assigned to an active section
    $checkAssign = "SELECT AssignmentID FROM sectionassignment 
                    WHERE StudentID = :studentId 
                    AND EnrollmentID = :enrollmentId 
                    AND IsActive = 1";
    $stmt = $conn->prepare($checkAssign);
    $stmt->bindValue(':studentId', $data['studentId']);
    $stmt->bindValue(':enrollmentId', $data['enrollmentId']);
    $stmt->execute();
    
    if ($stmt->fetch()) {
        throw new Exception('Student is already assigned to a section');
    }
    
    $conn->beginTransaction();
    
    try {
        // Check if there's an old inactive assignment for this student/section combo
        $checkOld = "SELECT AssignmentID FROM sectionassignment 
                     WHERE StudentID = :studentId 
                     AND SectionID = :sectionId 
                     AND IsActive = 0";
        $stmt = $conn->prepare($checkOld);
        $stmt->bindValue(':studentId', $data['studentId']);
        $stmt->bindValue(':sectionId', $data['sectionId']);
        $stmt->execute();
        $oldAssignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oldAssignment) {
            // Reactivate the old assignment
            $updateQuery = "UPDATE sectionassignment 
                            SET IsActive = 1, 
                                AssignmentDate = NOW(),
                                AssignmentMethod = 'Manual',
                                AssignedBy = :assignedBy
                            WHERE AssignmentID = :assignmentId";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bindValue(':assignedBy', $userId);
            $stmt->bindValue(':assignmentId', $oldAssignment['AssignmentID']);
            $stmt->execute();
        } else {
            // Insert new assignment
            $insertQuery = "INSERT INTO sectionassignment 
                            (StudentID, SectionID, EnrollmentID, AssignmentMethod, AssignedBy, IsActive) 
                            VALUES 
                            (:studentId, :sectionId, :enrollmentId, 'Manual', :assignedBy, 1)";
            
            $stmt = $conn->prepare($insertQuery);
            $stmt->bindValue(':studentId', $data['studentId']);
            $stmt->bindValue(':sectionId', $data['sectionId']);
            $stmt->bindValue(':enrollmentId', $data['enrollmentId']);
            $stmt->bindValue(':assignedBy', $userId);
            $stmt->execute();
        }
        
        // Update section enrollment count
        $updateQuery = "UPDATE section 
                        SET CurrentEnrollment = CurrentEnrollment + 1 
                        WHERE SectionID = :sectionId";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bindValue(':sectionId', $data['sectionId']);
        $stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Student assigned successfully'
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function autoAssignStudents($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['academicYear']) || !isset($data['gradeLevel'])) {
        throw new Exception('Academic year and grade level are required');
    }
    
    $userId = isset($data['assignedBy']) ? $data['assignedBy'] : null;
    $strandId = isset($data['strandId']) ? $data['strandId'] : null;
    
    // Initialize detailed tracking
    $assignmentResults = [
        'assigned' => [],
        'failed' => [
            'strand_mismatch' => [],
            'all_sections_full' => [],
            'no_available_sections' => [],
            'database_error' => []
        ]
    ];
    
    try {
        // Set SERIALIZABLE isolation level to prevent phantom reads and race conditions
        $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        $conn->beginTransaction();
        
        // Get available sections with FOR UPDATE lock to prevent concurrent modifications
        $sectionsQuery = "SELECT sec.SectionID, sec.Capacity, sec.CurrentEnrollment, 
                                 sec.StrandID, sec.SectionName, sec.CreatedAt
                          FROM section sec
                          WHERE sec.AcademicYear = :year 
                          AND sec.GradeLevelID = (SELECT GradeLevelID FROM gradelevel WHERE GradeLevelNumber = :grade)
                          AND sec.IsActive = 1";
        
        if ($strandId) {
            $sectionsQuery .= " AND sec.StrandID = :strand";
        }
        
        $sectionsQuery .= " ORDER BY sec.CreatedAt ASC, sec.SectionName ASC
                           FOR UPDATE"; // Lock sections to prevent concurrent modifications
        
        $stmt = $conn->prepare($sectionsQuery);
        $stmt->bindValue(':year', $data['academicYear']);
        $stmt->bindValue(':grade', $data['gradeLevel']);
        if ($strandId) {
            $stmt->bindValue(':strand', $strandId);
        }
        $stmt->execute();
        
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sections)) {
            throw new Exception('No sections available for this grade level');
        }
        
        // Get unassigned students (FCFS - ordered by enrollment date)
        $studentsQuery = "SELECT DISTINCT 
                                 s.StudentID, 
                                 s.LRN,
                                 CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) as StudentName,
                                 e.EnrollmentID, 
                                 e.StrandID,
                                 st.StrandCode
                          FROM student s
                          INNER JOIN enrollment e ON s.StudentID = e.StudentID
                          INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                          LEFT JOIN strand st ON e.StrandID = st.StrandID
                          WHERE e.AcademicYear = :year
                          AND gl.GradeLevelNumber = :grade
                          AND e.Status IN ('Confirmed', 'Pending')
                          AND NOT EXISTS (
                              SELECT 1 FROM sectionassignment sa
                              WHERE sa.StudentID = s.StudentID
                              AND sa.EnrollmentID = e.EnrollmentID
                              AND sa.IsActive = 1
                          )";
        
        if ($strandId) {
            $studentsQuery .= " AND e.StrandID = :strand";
        }
        
        $studentsQuery .= " ORDER BY e.EnrollmentDate ASC";
        
        $stmt = $conn->prepare($studentsQuery);
        $stmt->bindValue(':year', $data['academicYear']);
        $stmt->bindValue(':grade', $data['gradeLevel']);
        if ($strandId) {
            $stmt->bindValue(':strand', $strandId);
        }
        $stmt->execute();
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($students)) {
            $conn->rollBack();
            $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            throw new Exception('No unassigned students found');
        }
        
        // Process each student with detailed tracking
        foreach ($students as $student) {
            $assigned = false;
            $availableSections = 0;
            $matchingSections = 0;
            $strandMatchFailures = [];
            
            // Try to assign to first available matching section
            for ($i = 0; $i < count($sections); $i++) {
                $section = $sections[$i];
                
                // Track available sections (with space)
                if ($section['CurrentEnrollment'] < $section['Capacity']) {
                    $availableSections++;
                }
                
                // Check strand compatibility
                $strandMatch = checkStrandCompatibility($section, $student);
                
                if ($strandMatch) {
                    $matchingSections++;
                    
                    // Check if section has space
                    if ($section['CurrentEnrollment'] < $section['Capacity']) {
                        try {
                            // Attempt assignment
                            $assignResult = assignStudentToSection($conn, $student, $section, $userId);
                            
                            if ($assignResult['success']) {
                                // Update local section counter
                                $sections[$i]['CurrentEnrollment']++;
                                
                                // Track successful assignment
                                $assignmentResults['assigned'][] = [
                                    'studentId' => $student['StudentID'],
                                    'studentName' => $student['StudentName'],
                                    'lrn' => $student['LRN'],
                                    'sectionId' => $section['SectionID'],
                                    'sectionName' => $section['SectionName']
                                ];
                                
                                $assigned = true;
                                break; // Move to next student
                            }
                        } catch (Exception $e) {
                            // Database error during assignment
                            $assignmentResults['failed']['database_error'][] = [
                                'studentId' => $student['StudentID'],
                                'studentName' => $student['StudentName'],
                                'lrn' => $student['LRN'],
                                'error' => $e->getMessage()
                            ];
                            $assigned = true; // Mark as processed
                            break;
                        }
                    } else {
                        // Section is full
                        $strandMatchFailures[] = [
                            'sectionName' => $section['SectionName'],
                            'reason' => 'full'
                        ];
                    }
                } else {
                    // Strand mismatch
                    $strandMatchFailures[] = [
                        'sectionName' => $section['SectionName'],
                        'reason' => 'strand_mismatch',
                        'sectionStrand' => $section['StrandID'],
                        'studentStrand' => $student['StrandID']
                    ];
                }
            }
            
            // Track failure reason if not assigned
            if (!$assigned) {
                if ($matchingSections === 0) {
                    // No sections match student's strand
                    $assignmentResults['failed']['strand_mismatch'][] = [
                        'studentId' => $student['StudentID'],
                        'studentName' => $student['StudentName'],
                        'lrn' => $student['LRN'],
                        'studentStrand' => $student['StrandCode'] ?: 'None (JHS)',
                        'availableSections' => count($sections),
                        'details' => $strandMatchFailures
                    ];
                } elseif ($availableSections === 0) {
                    // All sections are full (across all strands)
                    $assignmentResults['failed']['no_available_sections'][] = [
                        'studentId' => $student['StudentID'],
                        'studentName' => $student['StudentName'],
                        'lrn' => $student['LRN'],
                        'matchingSections' => $matchingSections,
                        'reason' => 'All sections in the system are full'
                    ];
                } else {
                    // Matching sections exist but all are full
                    $assignmentResults['failed']['all_sections_full'][] = [
                        'studentId' => $student['StudentID'],
                        'studentName' => $student['StudentName'],
                        'lrn' => $student['LRN'],
                        'matchingSections' => $matchingSections,
                        'availableSections' => $availableSections,
                        'reason' => 'All matching sections for this strand are full'
                    ];
                }
            }
        }
        
        // Update all section enrollment counts in database
        foreach ($sections as $section) {
            $updateQuery = "UPDATE section 
                            SET CurrentEnrollment = (
                                SELECT COUNT(*) FROM sectionassignment 
                                WHERE SectionID = :sectionId AND IsActive = 1
                            )
                            WHERE SectionID = :sectionId";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bindValue(':sectionId', $section['SectionID']);
            $stmt->execute();
        }
        
        $conn->commit();
        
        // Reset isolation level
        $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        
        // Build comprehensive response
        $totalFailed = count($assignmentResults['failed']['strand_mismatch']) +
                       count($assignmentResults['failed']['all_sections_full']) +
                       count($assignmentResults['failed']['no_available_sections']) +
                       count($assignmentResults['failed']['database_error']);
        
        $response = [
            'success' => true,
            'summary' => [
                'totalStudents' => count($students),
                'assigned' => count($assignmentResults['assigned']),
                'failed' => $totalFailed
            ],
            'details' => [
                'assigned' => $assignmentResults['assigned'],
                'failures' => [
                    'strandMismatch' => [
                        'count' => count($assignmentResults['failed']['strand_mismatch']),
                        'students' => $assignmentResults['failed']['strand_mismatch']
                    ],
                    'sectionsFull' => [
                        'count' => count($assignmentResults['failed']['all_sections_full']),
                        'students' => $assignmentResults['failed']['all_sections_full']
                    ],
                    'noAvailableSections' => [
                        'count' => count($assignmentResults['failed']['no_available_sections']),
                        'students' => $assignmentResults['failed']['no_available_sections']
                    ],
                    'databaseErrors' => [
                        'count' => count($assignmentResults['failed']['database_error']),
                        'students' => $assignmentResults['failed']['database_error']
                    ]
                ]
            ],
            'message' => buildDetailedMessage($assignmentResults, count($students))
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        $conn->rollBack();
        // Reset isolation level
        $conn->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        throw $e;
    }
}

/**
 * Check if a student's strand is compatible with a section's strand
 */
function checkStrandCompatibility($section, $student) {
    $sectionStrand = $section['StrandID'];
    $studentStrand = $student['StrandID'];
    
    // Both null (JHS) - perfect match
    if ($sectionStrand === null && $studentStrand === null) {
        return true;
    }
    
    // Both have strands (SHS) - must match exactly
    if ($sectionStrand !== null && $studentStrand !== null) {
        return ($sectionStrand == $studentStrand);
    }
    
    // One has strand, other doesn't - no match
    return false;
}

/**
 * Assign a student to a section
 */
function assignStudentToSection($conn, $student, $section, $userId) {
    try {
        // Check if there's an old inactive assignment
        $checkOld = "SELECT AssignmentID FROM sectionassignment 
                     WHERE StudentID = :studentId 
                     AND SectionID = :sectionId 
                     AND IsActive = 0";
        $stmt = $conn->prepare($checkOld);
        $stmt->bindValue(':studentId', $student['StudentID']);
        $stmt->bindValue(':sectionId', $section['SectionID']);
        $stmt->execute();
        $oldAssignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oldAssignment) {
            // Reactivate the old assignment
            $updateQuery = "UPDATE sectionassignment 
                            SET IsActive = 1, 
                                AssignmentDate = NOW(),
                                AssignmentMethod = 'Automatic',
                                AssignedBy = :assignedBy
                            WHERE AssignmentID = :assignmentId";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bindValue(':assignedBy', $userId);
            $stmt->bindValue(':assignmentId', $oldAssignment['AssignmentID']);
            $stmt->execute();
        } else {
            // Insert new assignment
            $insertQuery = "INSERT INTO sectionassignment 
                            (StudentID, SectionID, EnrollmentID, AssignmentMethod, AssignedBy, IsActive) 
                            VALUES 
                            (:studentId, :sectionId, :enrollmentId, 'Automatic', :assignedBy, 1)";
            
            $stmt = $conn->prepare($insertQuery);
            $stmt->bindValue(':studentId', $student['StudentID']);
            $stmt->bindValue(':sectionId', $section['SectionID']);
            $stmt->bindValue(':enrollmentId', $student['EnrollmentID']);
            $stmt->bindValue(':assignedBy', $userId);
            $stmt->execute();
        }
        
        return ['success' => true];
        
    } catch (PDOException $e) {
        throw new Exception("Database error: " . $e->getMessage());
    }
}

/**
 * Build a detailed human-readable message from assignment results
 */
function buildDetailedMessage($results, $totalStudents) {
    $assigned = count($results['assigned']);
    $failed = count($results['failed']['strand_mismatch']) +
              count($results['failed']['all_sections_full']) +
              count($results['failed']['no_available_sections']) +
              count($results['failed']['database_error']);
    
    $message = "Auto-assignment completed: {$assigned} of {$totalStudents} students assigned successfully.";
    
    if ($failed > 0) {
        $message .= "\n\n{$failed} student(s) could not be assigned:";
        
        if (count($results['failed']['strand_mismatch']) > 0) {
            $message .= "\n• " . count($results['failed']['strand_mismatch']) . " due to strand mismatch (no matching sections)";
        }
        
        if (count($results['failed']['all_sections_full']) > 0) {
            $message .= "\n• " . count($results['failed']['all_sections_full']) . " because all matching sections are full";
        }
        
        if (count($results['failed']['no_available_sections']) > 0) {
            $message .= "\n• " . count($results['failed']['no_available_sections']) . " because no sections have available space";
        }
        
        if (count($results['failed']['database_error']) > 0) {
            $message .= "\n• " . count($results['failed']['database_error']) . " due to database errors";
        }
    }
    
    return $message;
}

function unassignStudent($conn) {
    $assignmentId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$assignmentId) {
        throw new Exception('Assignment ID is required');
    }
    
    $conn->beginTransaction();
    
    try {
        // Get assignment details before deleting
        $getQuery = "SELECT SectionID, StudentID, EnrollmentID FROM sectionassignment WHERE AssignmentID = :id AND IsActive = 1";
        $stmt = $conn->prepare($getQuery);
        $stmt->bindValue(':id', $assignmentId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception('Active assignment not found');
        }
        
        $sectionId = $result['SectionID'];
        $studentId = $result['StudentID'];
        $enrollmentId = $result['EnrollmentID'];
        
        // Check if there's already an inactive record with same student/enrollment
        $checkInactive = "SELECT AssignmentID FROM sectionassignment 
                          WHERE StudentID = :studentId 
                          AND EnrollmentID = :enrollmentId 
                          AND IsActive = 0 
                          LIMIT 1";
        $stmt = $conn->prepare($checkInactive);
        $stmt->bindValue(':studentId', $studentId);
        $stmt->bindValue(':enrollmentId', $enrollmentId);
        $stmt->execute();
        $inactiveExists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($inactiveExists) {
            // If inactive record exists, hard delete the current assignment to avoid constraint violation
            $deleteQuery = "DELETE FROM sectionassignment WHERE AssignmentID = :id";
            $stmt = $conn->prepare($deleteQuery);
            $stmt->bindValue(':id', $assignmentId);
            $stmt->execute();
        } else {
            // No inactive record exists, safe to soft delete
            $deleteQuery = "UPDATE sectionassignment SET IsActive = 0 WHERE AssignmentID = :id";
            $stmt = $conn->prepare($deleteQuery);
            $stmt->bindValue(':id', $assignmentId);
            $stmt->execute();
        }
        
        // Update section enrollment count
        $updateQuery = "UPDATE section 
                        SET CurrentEnrollment = CurrentEnrollment - 1 
                        WHERE SectionID = :sectionId 
                        AND CurrentEnrollment > 0";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bindValue(':sectionId', $sectionId);
        $stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Student unassigned successfully'
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function clearSection($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['sectionId'])) {
        throw new Exception('Section ID is required');
    }
    
    $conn->beginTransaction();
    
    try {
        // Get all active assignments for this section
        $getAssignments = "SELECT AssignmentID, StudentID, EnrollmentID 
                           FROM sectionassignment 
                           WHERE SectionID = :sectionId AND IsActive = 1";
        $stmt = $conn->prepare($getAssignments);
        $stmt->bindValue(':sectionId', $data['sectionId']);
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $removedCount = count($assignments);
        
        // Process each assignment
        foreach ($assignments as $assignment) {
            // Check if there's already an inactive record for this student/enrollment
            $checkInactive = "SELECT AssignmentID FROM sectionassignment 
                              WHERE StudentID = :studentId 
                              AND EnrollmentID = :enrollmentId 
                              AND IsActive = 0 
                              LIMIT 1";
            $stmt = $conn->prepare($checkInactive);
            $stmt->bindValue(':studentId', $assignment['StudentID']);
            $stmt->bindValue(':enrollmentId', $assignment['EnrollmentID']);
            $stmt->execute();
            $inactiveExists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inactiveExists) {
                // Hard delete to avoid constraint violation
                $deleteQuery = "DELETE FROM sectionassignment WHERE AssignmentID = :id";
                $stmt = $conn->prepare($deleteQuery);
                $stmt->bindValue(':id', $assignment['AssignmentID']);
                $stmt->execute();
            } else {
                // Soft delete
                $updateQuery = "UPDATE sectionassignment SET IsActive = 0 WHERE AssignmentID = :id";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bindValue(':id', $assignment['AssignmentID']);
                $stmt->execute();
            }
        }
        
        // Reset section enrollment count
        $updateQuery = "UPDATE section 
                        SET CurrentEnrollment = 0 
                        WHERE SectionID = :sectionId";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bindValue(':sectionId', $data['sectionId']);
        $stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Removed $removedCount student(s) from section",
            'removedCount' => $removedCount
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
?>