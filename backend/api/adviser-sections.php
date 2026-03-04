<?php
// backend/api/adviser-sections.php
// API endpoint for advisers to get their assigned sections and students

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function handleGet($db) {
    if (isset($_GET['adviser_id'])) {
        getAdviserSections($db, $_GET['adviser_id']);
    } elseif (isset($_GET['section_id'])) {
        getSectionStudents($db, $_GET['section_id']);
    } elseif (isset($_GET['user_id'])) {
        getAdviserSectionsByUser($db, $_GET['user_id']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    }
}

// -----------------------------------------------------------------------
// FIX 1: AcademicYear now comes from joining `academicyear` (ay.YearLabel)
// FIX 2: CurrentEnrollment computed via subquery (no such column in schema)
// FIX 3: Removed employee/AdviserEmployeeID join — adviser is section.AdviserID → user
// -----------------------------------------------------------------------
function getAdviserSections($db, $adviserId) {
    try {
        $query = "SELECT
                    s.SectionID,
                    s.SectionName,
                    s.Capacity,
                    ay.YearLabel           AS AcademicYear,
                    s.IsActive,
                    gl.GradeLevelName,
                    gl.GradeLevelNumber,
                    st.StrandName,
                    st.StrandCode,
                    CONCAT(u.LastName, ', ', u.FirstName) AS AdviserName,
                    u.Role                 AS AdviserPosition,
                    (
                        SELECT COUNT(*)
                        FROM sectionassignment sa2
                        WHERE sa2.SectionID = s.SectionID
                          AND sa2.IsActive  = 1
                    ) AS CurrentEnrollment
                FROM section s
                INNER JOIN gradelevel   gl ON s.GradeLevelID   = gl.GradeLevelID
                INNER JOIN academicyear ay ON s.AcademicYearID = ay.AcademicYearID
                LEFT  JOIN strand       st ON s.StrandID       = st.StrandID
                LEFT  JOIN user          u ON s.AdviserID      = u.UserID
                WHERE s.AdviserID = :adviser_id
                  AND s.IsActive  = 1
                ORDER BY ay.StartYear DESC, gl.GradeLevelNumber";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':adviser_id', $adviserId, PDO::PARAM_INT);
        $stmt->execute();

        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// -----------------------------------------------------------------------
// Same fixes as getAdviserSections; looks up sections by UserID directly
// -----------------------------------------------------------------------
function getAdviserSectionsByUser($db, $userId) {
    try {
        $query = "SELECT
                    s.SectionID,
                    s.SectionName,
                    s.Capacity,
                    ay.YearLabel           AS AcademicYear,
                    s.IsActive,
                    gl.GradeLevelName,
                    gl.GradeLevelNumber,
                    st.StrandName,
                    st.StrandCode,
                    CONCAT(u.LastName, ', ', u.FirstName) AS AdviserName,
                    (
                        SELECT COUNT(*)
                        FROM sectionassignment sa2
                        WHERE sa2.SectionID = s.SectionID
                          AND sa2.IsActive  = 1
                    ) AS CurrentEnrollment
                FROM section s
                INNER JOIN gradelevel   gl ON s.GradeLevelID   = gl.GradeLevelID
                INNER JOIN academicyear ay ON s.AcademicYearID = ay.AcademicYearID
                LEFT  JOIN strand       st ON s.StrandID       = st.StrandID
                LEFT  JOIN user          u ON s.AdviserID      = u.UserID
                WHERE s.AdviserID = :user_id
                  AND s.IsActive  = 1
                ORDER BY ay.StartYear DESC, gl.GradeLevelNumber";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// -----------------------------------------------------------------------
// FIX 1: sectionassignment has no StudentID — join through enrollment
// FIX 2: Parent/guardian columns removed from student — join parentguardian
// FIX 3: student.Age column removed — derive from DateOfBirth
// FIX 4: AcademicYear comes from academicyear join
// FIX 5: LearnerType column doesn't exist; removed
// -----------------------------------------------------------------------
function getSectionStudents($db, $sectionId) {
    try {
        $query = "SELECT
                    st.StudentID,
                    st.LRN,
                    CONCAT(st.LastName, ', ', st.FirstName,
                           IF(st.MiddleName IS NOT NULL AND st.MiddleName <> '',
                              CONCAT(' ', st.MiddleName), ''))   AS StudentName,
                    st.FirstName,
                    st.LastName,
                    st.MiddleName,
                    st.Gender,
                    st.DateOfBirth,
                    TIMESTAMPDIFF(YEAR, st.DateOfBirth, CURDATE()) AS Age,
                    st.ContactNumber,
                    st.Religion,
                    st.Barangay,
                    st.Municipality,
                    st.Province,
                    CONCAT_WS(' ',
                        NULLIF(st.HouseNumber, ''),
                        NULLIF(st.SitioStreet, ''),
                        st.Barangay,
                        st.Municipality,
                        st.Province
                    )                                             AS CompleteAddress,

                    -- Father (from parentguardian)
                    CONCAT_WS(', ',
                        NULLIF(pgf.LastName,  ''),
                        CONCAT_WS(' ',
                            NULLIF(pgf.FirstName,  ''),
                            NULLIF(pgf.MiddleName, ''))
                    )                                             AS FatherName,

                    -- Mother (from parentguardian)
                    CONCAT_WS(', ',
                        NULLIF(pgm.LastName,  ''),
                        CONCAT_WS(' ',
                            NULLIF(pgm.FirstName,  ''),
                            NULLIF(pgm.MiddleName, ''))
                    )                                             AS MotherName,

                    -- Guardian (from parentguardian)
                    CONCAT_WS(', ',
                        NULLIF(pgg.LastName,  ''),
                        CONCAT_WS(' ',
                            NULLIF(pgg.FirstName,  ''),
                            NULLIF(pgg.MiddleName, ''))
                    )                                             AS GuardianName,
                    pgg.GuardianRelationship,

                    st.EnrollmentStatus,
                    sa.AssignmentDate,
                    sa.AssignmentMethod,
                    e.EnrollmentID,
                    e.EnrollmentType,
                    ay.YearLabel                                  AS AcademicYear,
                    gl.GradeLevelName,
                    s.StrandName
                FROM sectionassignment sa
                -- FIX: StudentID comes from enrollment, not sectionassignment
                INNER JOIN enrollment   e   ON sa.EnrollmentID = e.EnrollmentID
                INNER JOIN student      st  ON e.StudentID     = st.StudentID
                INNER JOIN gradelevel   gl  ON e.GradeLevelID  = gl.GradeLevelID
                INNER JOIN academicyear ay  ON e.AcademicYearID = ay.AcademicYearID
                LEFT  JOIN strand        s  ON e.StrandID      = s.StrandID
                -- Parent/guardian joins
                LEFT  JOIN parentguardian pgf ON pgf.StudentID = st.StudentID
                                              AND pgf.RelationshipType = 'Father'
                LEFT  JOIN parentguardian pgm ON pgm.StudentID = st.StudentID
                                              AND pgm.RelationshipType = 'Mother'
                LEFT  JOIN parentguardian pgg ON pgg.StudentID = st.StudentID
                                              AND pgg.RelationshipType = 'Guardian'
                WHERE sa.SectionID = :section_id
                  AND sa.IsActive  = 1
                  AND e.Status     = 'Confirmed'
                ORDER BY st.LastName, st.FirstName";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':section_id', $sectionId, PDO::PARAM_INT);
        $stmt->execute();

        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Section info — AcademicYear from join
        $sectionQuery = "SELECT
                            s.SectionName,
                            ay.YearLabel AS AcademicYear,
                            gl.GradeLevelName,
                            st.StrandName
                         FROM section s
                         INNER JOIN gradelevel   gl ON s.GradeLevelID   = gl.GradeLevelID
                         INNER JOIN academicyear ay ON s.AcademicYearID = ay.AcademicYearID
                         LEFT  JOIN strand       st ON s.StrandID       = st.StrandID
                         WHERE s.SectionID = :section_id";

        $sectionStmt = $db->prepare($sectionQuery);
        $sectionStmt->bindParam(':section_id', $sectionId, PDO::PARAM_INT);
        $sectionStmt->execute();
        $sectionInfo = $sectionStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data'    => $students,
            'section' => $sectionInfo,
            'count'   => count($students)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>