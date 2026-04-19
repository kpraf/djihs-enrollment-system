<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-Data');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

// Get user data from custom header
$userDataHeader = $_SERVER['HTTP_X_USER_DATA'] ?? '';

if (!$userDataHeader) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated. Please log in.'
    ]);
    exit;
}

$userData = json_decode($userDataHeader, true);

if (!$userData || !isset($userData['UserID']) || !isset($userData['Role'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid authentication data.'
    ]);
    exit;
}

if ($userData['Role'] !== 'Subject_Teacher') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Subject Teacher role required.'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Use UserID directly — no EmployeeID lookup needed
$userID = (int)$userData['UserID'];

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── List all sections assigned to this teacher ──────────────────
        case 'getSections':
            $query = "SELECT DISTINCT
                        s.SectionID,
                        s.SectionName,
                        gl.GradeLevelName,
                        gl.GradeLevelNumber,
                        st.StrandCode,
                        st.StrandName,
                        ay.YearLabel AS AcademicYear,
                        ta.SubjectCode,
                        ta.SubjectName,
                        (SELECT COUNT(*)
                         FROM sectionassignment sa
                         WHERE sa.SectionID = s.SectionID
                         AND sa.IsActive = 1) as StudentCount
                    FROM teacherassignment ta
                    INNER JOIN section s ON ta.SectionID = s.SectionID
                    INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                    INNER JOIN academicyear ay ON s.AcademicYearID = ay.AcademicYearID
                    LEFT JOIN strand st ON s.StrandID = st.StrandID
                    WHERE ta.UserID = :userID
                    AND ta.AssignmentType = 'Subject_Teacher'
                    AND ta.IsActive = 1
                    AND s.IsActive = 1
                    ORDER BY gl.GradeLevelNumber, s.SectionName";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
            $stmt->execute();

            echo json_encode([
                'success'  => true,
                'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
            break;

        // ── Paginated student list for a section ────────────────────────
        case 'getStudents':
            $sectionID = isset($_GET['sectionID']) ? (int)$_GET['sectionID'] : null;
            $page      = isset($_GET['page'])      ? (int)$_GET['page']      : 1;
            $limit     = isset($_GET['limit'])     ? (int)$_GET['limit']     : 10;
            $offset    = ($page - 1) * $limit;

            if (!$sectionID) throw new Exception('Section ID is required');

            // Verify this teacher owns the section
            $verifyQuery = "SELECT COUNT(*) as cnt
                            FROM teacherassignment ta
                            WHERE ta.UserID = :userID
                            AND ta.SectionID = :sectionID
                            AND ta.AssignmentType = 'Subject_Teacher'
                            AND ta.IsActive = 1";

            $verifyStmt = $db->prepare($verifyQuery);
            $verifyStmt->bindParam(':userID',    $userID,    PDO::PARAM_INT);
            $verifyStmt->bindParam(':sectionID', $sectionID, PDO::PARAM_INT);
            $verifyStmt->execute();
            if ((int)$verifyStmt->fetch(PDO::FETCH_ASSOC)['cnt'] === 0) {
                throw new Exception('You do not have access to this section');
            }

            // Count total — sectionassignment links via EnrollmentID → enrollment → student
            $countQuery = "SELECT COUNT(*) as total
                           FROM student s
                           INNER JOIN enrollment e ON e.StudentID = s.StudentID
                           INNER JOIN sectionassignment sa ON sa.EnrollmentID = e.EnrollmentID
                           WHERE sa.SectionID = :sectionID
                           AND sa.IsActive = 1";

            $countStmt = $db->prepare($countQuery);
            $countStmt->bindParam(':sectionID', $sectionID, PDO::PARAM_INT);
            $countStmt->execute();
            $totalRecords = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Fetch page of students
            $query = "SELECT
                        s.StudentID,
                        s.LRN,
                        s.LastName,
                        s.FirstName,
                        s.MiddleName,
                        s.ExtensionName,
                        CONCAT(s.LastName, ', ', s.FirstName,
                               CASE WHEN s.MiddleName IS NOT NULL AND s.MiddleName != ''
                                    THEN CONCAT(' ', LEFT(s.MiddleName, 1), '.')
                                    ELSE '' END) AS StudentName,
                        s.Gender,
                        s.ContactNumber
                    FROM student s
                    INNER JOIN enrollment e ON e.StudentID = s.StudentID
                    INNER JOIN sectionassignment sa ON sa.EnrollmentID = e.EnrollmentID
                    WHERE sa.SectionID = :sectionID
                    AND sa.IsActive = 1
                    ORDER BY s.LastName, s.FirstName
                    LIMIT :lim OFFSET :off";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':sectionID', $sectionID, PDO::PARAM_INT);
            $stmt->bindParam(':lim',       $limit,     PDO::PARAM_INT);
            $stmt->bindParam(':off',       $offset,    PDO::PARAM_INT);
            $stmt->execute();

            echo json_encode([
                'success'  => true,
                'students' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'pagination' => [
                    'total'      => $totalRecords,
                    'page'       => $page,
                    'limit'      => $limit,
                    'totalPages' => $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1
                ]
            ]);
            break;

        // ── Single section details (for header / print) ─────────────────
        case 'getSectionDetails':
            $sectionID = isset($_GET['sectionID']) ? (int)$_GET['sectionID'] : null;
            if (!$sectionID) throw new Exception('Section ID is required');

            $query = "SELECT
                        s.SectionID,
                        s.SectionName,
                        gl.GradeLevelName,
                        st.StrandCode,
                        st.StrandName,
                        ay.YearLabel AS AcademicYear,
                        ta.SubjectCode,
                        ta.SubjectName,
                        s.Capacity,
                        (SELECT COUNT(*)
                         FROM sectionassignment sa
                         WHERE sa.SectionID = s.SectionID
                         AND sa.IsActive = 1) as CurrentEnrollment
                    FROM section s
                    INNER JOIN gradelevel gl ON s.GradeLevelID = gl.GradeLevelID
                    INNER JOIN academicyear ay ON s.AcademicYearID = ay.AcademicYearID
                    LEFT JOIN strand st ON s.StrandID = st.StrandID
                    INNER JOIN teacherassignment ta ON s.SectionID = ta.SectionID
                    WHERE s.SectionID = :sectionID
                    AND ta.UserID = :userID
                    AND ta.AssignmentType = 'Subject_Teacher'
                    AND ta.IsActive = 1
                    AND s.IsActive = 1
                    LIMIT 1";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':sectionID', $sectionID, PDO::PARAM_INT);
            $stmt->bindParam(':userID',    $userID,    PDO::PARAM_INT);
            $stmt->execute();

            $sectionDetails = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sectionDetails) throw new Exception('Section not found or access denied');

            echo json_encode([
                'success' => true,
                'section' => $sectionDetails
            ]);
            break;

        // ── Full student list for CSV export ────────────────────────────
        case 'exportCSV':
            $sectionID = isset($_GET['sectionID']) ? (int)$_GET['sectionID'] : null;
            if (!$sectionID) throw new Exception('Section ID is required');

            // Ownership check
            $verifyQuery = "SELECT COUNT(*) as cnt
                            FROM teacherassignment ta
                            WHERE ta.UserID = :userID
                            AND ta.SectionID = :sectionID
                            AND ta.AssignmentType = 'Subject_Teacher'
                            AND ta.IsActive = 1";

            $verifyStmt = $db->prepare($verifyQuery);
            $verifyStmt->bindParam(':userID',    $userID,    PDO::PARAM_INT);
            $verifyStmt->bindParam(':sectionID', $sectionID, PDO::PARAM_INT);
            $verifyStmt->execute();
            if ((int)$verifyStmt->fetch(PDO::FETCH_ASSOC)['cnt'] === 0) {
                throw new Exception('You do not have access to this section');
            }

            $query = "SELECT
                        s.LRN,
                        CONCAT(s.LastName, ', ', s.FirstName,
                               CASE WHEN s.MiddleName IS NOT NULL AND s.MiddleName != ''
                                    THEN CONCAT(' ', s.MiddleName)
                                    ELSE '' END) AS StudentName,
                        s.Gender,
                        s.ContactNumber,
                        s.Barangay,
                        s.Municipality,
                        s.Province
                    FROM student s
                    INNER JOIN enrollment e ON e.StudentID = s.StudentID
                    INNER JOIN sectionassignment sa ON sa.EnrollmentID = e.EnrollmentID
                    WHERE sa.SectionID = :sectionID
                    AND sa.IsActive = 1
                    ORDER BY s.LastName, s.FirstName";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':sectionID', $sectionID, PDO::PARAM_INT);
            $stmt->execute();

            echo json_encode([
                'success'  => true,
                'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>