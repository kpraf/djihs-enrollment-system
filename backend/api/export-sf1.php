<?php
// ============================================
// FILE: backend/api/export-sf1.php
// Purpose: Export SF1 (School Form 1) using DepEd template
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(300);

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$database = new Database();
$conn = $database->getConnection();

const SCHOOL_INFO = [
    'school_id'   => '301239',
    'region'      => 'REGION IV-A',
    'school_name' => 'Don Jose Integrated High School',
    'division'    => 'SANTA ROSA CITY',
    'district'    => 'SANTA ROSA CITY'
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Invalid request method');
    }

    $academicYear = $_GET['year']     ?? null;
    $gradeLevel   = $_GET['grade']    ?? null;
    $sectionId    = $_GET['section']  ?? null;
    $download     = $_GET['download'] ?? 'false';

    if (!$academicYear || !$gradeLevel || !$sectionId) {
        throw new Exception('Academic year, grade level, and section are required');
    }

    // ------------------------------------------------------------------
    // FIX: AcademicYear is now in academicyear.YearLabel, not section.AcademicYear
    // ------------------------------------------------------------------
    $sectionQuery = "SELECT
                        sec.SectionName,
                        gl.GradeLevelName,
                        sec.StrandID,
                        st.StrandCode,
                        ay.YearLabel AS AcademicYear
                     FROM section sec
                     INNER JOIN gradelevel   gl ON sec.GradeLevelID   = gl.GradeLevelID
                     INNER JOIN academicyear ay ON sec.AcademicYearID = ay.AcademicYearID
                     LEFT  JOIN strand       st ON sec.StrandID       = st.StrandID
                     WHERE sec.SectionID = :sectionId
                       AND ay.YearLabel  = :year";

    $stmt = $conn->prepare($sectionQuery);
    $stmt->bindValue(':sectionId', $sectionId);
    $stmt->bindValue(':year', $academicYear);
    $stmt->execute();
    $section = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        throw new Exception('Section not found');
    }

    // ------------------------------------------------------------------
    // FIX 1: sectionassignment has no StudentID — derive via enrollment
    // FIX 2: Parent columns removed from student — join parentguardian
    // FIX 3: LearnerType column removed — EnrollmentType covers Balik_Aral
    // ------------------------------------------------------------------
    $studentsQuery = "SELECT
                        st.LRN,
                        st.LastName,
                        st.FirstName,
                        st.MiddleName,
                        st.ExtensionName,
                        st.Gender,
                        st.DateOfBirth,
                        st.Province          AS BirthProvince,
                        st.MotherTongue,
                        st.IsIPCommunity,
                        st.IPCommunitySpecify,
                        st.Religion,
                        st.HouseNumber,
                        st.SitioStreet,
                        st.Barangay,
                        st.Municipality,
                        st.Province,
                        st.IsPWD,
                        st.PWDSpecify,
                        st.Is4PsBeneficiary,
                        -- Father
                        pgf.FirstName        AS FatherFirstName,
                        pgf.MiddleName       AS FatherMiddleName,
                        pgf.LastName         AS FatherLastName,
                        -- Mother
                        pgm.FirstName        AS MotherFirstName,
                        pgm.MiddleName       AS MotherMiddleName,
                        pgm.LastName         AS MotherLastName,
                        -- Guardian
                        pgg.FirstName        AS GuardianFirstName,
                        pgg.MiddleName       AS GuardianMiddleName,
                        pgg.LastName         AS GuardianLastName,
                        pgg.GuardianRelationship,
                        -- Enrollment
                        e.EnrollmentType,
                        e.EnrollmentDate,
                        e.Remarks
                      FROM sectionassignment sa
                      -- FIX: get StudentID from enrollment
                      INNER JOIN enrollment   e   ON sa.EnrollmentID = e.EnrollmentID
                      INNER JOIN student      st  ON e.StudentID     = st.StudentID
                      -- Parent / guardian
                      LEFT  JOIN parentguardian pgf ON pgf.StudentID = st.StudentID
                                                    AND pgf.RelationshipType = 'Father'
                      LEFT  JOIN parentguardian pgm ON pgm.StudentID = st.StudentID
                                                    AND pgm.RelationshipType = 'Mother'
                      LEFT  JOIN parentguardian pgg ON pgg.StudentID = st.StudentID
                                                    AND pgg.RelationshipType = 'Guardian'
                      WHERE sa.SectionID = :sectionId
                        AND sa.IsActive  = 1
                      ORDER BY st.Gender DESC, st.LastName, st.FirstName";

    $stmt = $conn->prepare($studentsQuery);
    $stmt->bindValue(':sectionId', $sectionId);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        throw new Exception('No students found in this section');
    }

    // Load SF1 template
    $templatePath = '../templates/SF1_Template.xlsx';
    if (!file_exists($templatePath)) {
        throw new Exception('SF1 template not found. Please upload SF1_Template.xlsx to backend/templates/');
    }

    $spreadsheet = IOFactory::load($templatePath);
    $sheet = $spreadsheet->getActiveSheet();

    // Fill header
    $sheet->setCellValue('F4', SCHOOL_INFO['school_id']);
    $sheet->setCellValue('J4', SCHOOL_INFO['region']);
    $sheet->setCellValue('F6', SCHOOL_INFO['school_name']);
    $sheet->setCellValue('M4', SCHOOL_INFO['division']);
    $sheet->setCellValue('O6', $academicYear);
    $sheet->setCellValue('S4', SCHOOL_INFO['district']);
    $sheet->setCellValue('S6', $section['GradeLevelName'] .
                               ($section['StrandCode'] ? ' - ' . $section['StrandCode'] : ''));
    $sheet->setCellValue('V6', $section['SectionName']);
    $footerRow = 60; // adjust to the actual footer row in your SF1_Template.xlsx
    $sheet->setCellValue('A' . $footerRow, 'Generated by DJIHS Enrollment System');
    $sheet->setCellValue('A' . ($footerRow + 1), 'Date & Time: ' . date('F d, Y h:i:s A'));
    $sheet->setCellValue('A' . ($footerRow + 2), 'This is a system-generated document.');
    $sheet->getStyle('A' . $footerRow . ':Y' . ($footerRow + 2))
        ->getFont()->setSize(8)->setItalic(true);

    $yearStart         = (int) substr($academicYear, 0, 4);
    $firstFridayOfJune = getFirstFridayOfJune($yearStart);

    $row         = 10;
    $maleCount   = 0;
    $femaleCount = 0;

    foreach ($students as $student) {
        if ($student['Gender'] === 'Male') {
            $maleCount++;
        } else {
            $femaleCount++;
        }

        // LRN
        $sheet->setCellValue('B' . $row, $student['LRN']);

        // Full name
        $fullName = $student['LastName'] . ', ' . $student['FirstName'];
        if (!empty($student['MiddleName'])) {
            $fullName .= ' ' . $student['MiddleName'];
        }
        if (!empty($student['ExtensionName'])) {
            $fullName .= ' ' . $student['ExtensionName'];
        }
        $sheet->setCellValue('C' . $row, strtoupper($fullName));

        // Sex
        $sheet->setCellValue('G' . $row, $student['Gender'] === 'Male' ? 'M' : 'F');

        // Birthdate
        if (!empty($student['DateOfBirth'])) {
            $sheet->setCellValue('H' . $row, date('m/d/Y', strtotime($student['DateOfBirth'])));
            $sheet->setCellValue('I' . $row, calculateAge($student['DateOfBirth'], $firstFridayOfJune));
        }

        // Birth place (province)
        $sheet->setCellValue('J' . $row, $student['BirthProvince'] ?? '');

        // Mother tongue
        $sheet->setCellValue('K' . $row, $student['MotherTongue'] ?? '');

        // IP community
        if (!empty($student['IsIPCommunity'])) {
            $sheet->setCellValue('L' . $row, $student['IPCommunitySpecify'] ?? 'Yes');
        }

        // Religion
        $sheet->setCellValue('M' . $row, $student['Religion'] ?? '');

        // House # / Street / Sitio
        $sheet->setCellValue('N' . $row,
            trim(($student['HouseNumber'] ?? '') . ' ' . ($student['SitioStreet'] ?? '')));

        // Barangay / Municipality / Province
        $sheet->setCellValue('O' . $row, $student['Barangay']);
        $sheet->setCellValue('P' . $row, $student['Municipality']);
        $sheet->setCellValue('Q' . $row, $student['Province']);

        // Father — show first name only when last name matches learner's
        if (!empty($student['FatherFirstName'])) {
            if ($student['FatherLastName'] === $student['LastName']) {
                $sheet->setCellValue('R' . $row, $student['FatherFirstName']);
            } else {
                $sheet->setCellValue('R' . $row,
                    trim($student['FatherFirstName'] . ' ' . ($student['FatherLastName'] ?? '')));
            }
        }

        // Mother (maiden: First Middle Last)
        if (!empty($student['MotherFirstName'])) {
            $motherName = $student['MotherFirstName'];
            if (!empty($student['MotherMiddleName'])) $motherName .= ' ' . $student['MotherMiddleName'];
            if (!empty($student['MotherLastName']))   $motherName .= ' ' . $student['MotherLastName'];
            $sheet->setCellValue('T' . $row, $motherName);
        }

        // Guardian
        if (!empty($student['GuardianFirstName'])) {
            $guardianName = $student['GuardianFirstName'];
            if (!empty($student['GuardianMiddleName'])) $guardianName .= ' ' . $student['GuardianMiddleName'];
            if (!empty($student['GuardianLastName']))   $guardianName .= ' ' . $student['GuardianLastName'];
            $sheet->setCellValue('V' . $row, $guardianName);
        }

        // Guardian relationship
        $sheet->setCellValue('W' . $row, $student['GuardianRelationship'] ?? '');

        // Contact number
        $sheet->setCellValue('X' . $row, $student['ContactNumber'] ?? '');

        // Remarks — build indicator codes
        // FIX: LearnerType removed; Balik_Aral is now in EnrollmentType
        $remarks = [];

        if ($student['EnrollmentType'] === 'Late')         $remarks[] = 'LE';
        if ($student['EnrollmentType'] === 'Transferee')   $remarks[] = 'T/I';
        if ($student['EnrollmentType'] === 'Balik_Aral')   $remarks[] = 'B/A';  // was LearnerType check
        if ($student['EnrollmentType'] === 'Repeater')     $remarks[] = 'R';
        if ($student['EnrollmentType'] === 'ALS')          $remarks[] = 'ALS';

        if (!empty($student['IsPWD'])) {
            $remarks[] = 'LWD';
            if (!empty($student['PWDSpecify'])) $remarks[] = '(' . $student['PWDSpecify'] . ')';
        }

        if (!empty($student['Is4PsBeneficiary'])) $remarks[] = 'CCT';
        if (!empty($student['Remarks']))           $remarks[] = $student['Remarks'];

        $sheet->setCellValue('Y' . $row, implode(', ', $remarks));

        $row++;
    }

    // Enrollment summary rows
    $sheet->setCellValue('R53', $maleCount);
    $sheet->setCellValue('S53', $maleCount);
    $sheet->setCellValue('R54', $femaleCount);
    $sheet->setCellValue('S54', $femaleCount);
    $sheet->setCellValue('R55', $maleCount + $femaleCount);
    $sheet->setCellValue('S55', $maleCount + $femaleCount);

    // Filename with timestamp for uniqueness
    $baseFilename = 'SF1_' .
                    str_replace(' ', '_', $section['GradeLevelName']) . '_' .
                    str_replace(' ', '_', $section['SectionName'])    . '_' .
                    $academicYear;
    $filename = $baseFilename . '_' . date('YmdHis') . '.xlsx';

    if ($download === 'true') {
        if (ob_get_length()) ob_clean();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'filename'     => $filename,
            'studentCount' => count($students),
            'maleCount'    => $maleCount,
            'femaleCount'  => $femaleCount
        ]);
    }

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Returns the first Friday of June for the given year (Y-m-d).
 */
function getFirstFridayOfJune(int $year): string {
    $date = new DateTime("$year-06-01");
    while ($date->format('N') != 5) {   // 5 = Friday (ISO-8601)
        $date->modify('+1 day');
    }
    return $date->format('Y-m-d');
}

/**
 * Returns age in whole years as of $asOfDate.
 */
function calculateAge(string $birthDate, string $asOfDate): int {
    return (new DateTime($birthDate))->diff(new DateTime($asOfDate))->y;
}
?>