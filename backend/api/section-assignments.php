<?php
// ============================================
// FILE: backend/api/section-assignments.php
// Purpose: Handle student-to-section assignments
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
                gl.GradeLevelName
              FROM student s
              INNER JOIN enrollment e ON s.StudentID = e.StudentID
              INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
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
    
    if (!$academicYear || !$gradeLevel) {
        throw new Exception('Academic year and grade level are required');
    }
    
    $query = "SELECT 
                sec.SectionID,
                sec.SectionName,
                sec.Capacity,
                sec.CurrentEnrollment,
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
              AND sec.IsActive = 1
              ORDER BY sec.SectionName";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':year', $academicYear);
    $stmt->bindValue(':grade', $gradeLevel);
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
    
    // Check section capacity
    $checkQuery = "SELECT Capacity, CurrentEnrollment FROM section WHERE SectionID = :sectionId";
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
    
    $conn->beginTransaction();
    
    try {
        // Get available sections for this grade/year
        $sectionsQuery = "SELECT SectionID, Capacity, CurrentEnrollment 
                          FROM section 
                          WHERE AcademicYear = :year 
                          AND GradeLevelID = (SELECT GradeLevelID FROM gradelevel WHERE GradeLevelNumber = :grade)
                          AND IsActive = 1";
        
        if ($strandId) {
            $sectionsQuery .= " AND StrandID = :strand";
        }
        
        $sectionsQuery .= " ORDER BY SectionName";
        
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
        
        // Get unassigned students (first come, first served - ordered by enrollment date)
        $studentsQuery = "SELECT DISTINCT s.StudentID, e.EnrollmentID
                          FROM student s
                          INNER JOIN enrollment e ON s.StudentID = e.StudentID
                          INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
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
            throw new Exception('No unassigned students found');
        }
        
        // Assign students to sections in order
        $currentSectionIndex = 0;
        $assignedCount = 0;
        
        foreach ($students as $student) {
            // Find next available section
            $assigned = false;
            $attempts = 0;
            
            while (!$assigned && $attempts < count($sections)) {
                $section = $sections[$currentSectionIndex];
                
                if ($section['CurrentEnrollment'] < $section['Capacity']) {
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
                    
                    // Update section enrollment count
                    $sections[$currentSectionIndex]['CurrentEnrollment']++;
                    $assignedCount++;
                    $assigned = true;
                    
                    // If section is now full, move to next section
                    if ($sections[$currentSectionIndex]['CurrentEnrollment'] >= $sections[$currentSectionIndex]['Capacity']) {
                        $currentSectionIndex++;
                        if ($currentSectionIndex >= count($sections)) {
                            break; // No more sections available
                        }
                    }
                } else {
                    // Move to next section
                    $currentSectionIndex++;
                    if ($currentSectionIndex >= count($sections)) {
                        break; // No more sections available
                    }
                }
                
                $attempts++;
            }
            
            if (!$assigned) {
                break; // No more available slots
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
        
        $unassignedCount = count($students) - $assignedCount;
        
        echo json_encode([
            'success' => true,
            'message' => "Automatically assigned $assignedCount student(s)",
            'assignedCount' => $assignedCount,
            'unassignedCount' => $unassignedCount
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function unassignStudent($conn) {
    $assignmentId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$assignmentId) {
        throw new Exception('Assignment ID is required');
    }
    
    $conn->beginTransaction();
    
    try {
        // Get section ID before updating
        $getQuery = "SELECT SectionID FROM sectionassignment WHERE AssignmentID = :id AND IsActive = 1";
        $stmt = $conn->prepare($getQuery);
        $stmt->bindValue(':id', $assignmentId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception('Active assignment not found');
        }
        
        $sectionId = $result['SectionID'];
        
        // Soft delete assignment
        $deleteQuery = "UPDATE sectionassignment SET IsActive = 0 WHERE AssignmentID = :id";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bindValue(':id', $assignmentId);
        $stmt->execute();
        
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
?>