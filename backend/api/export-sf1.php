<?php
// ============================================
// FILE: backend/api/export-sf1.php
// Purpose: Export SF1 (School Form 1) using DepEd template
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display errors for clean output
set_time_limit(300); // 5 minutes for large exports

require_once '../config/database.php';
require_once '../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$database = new Database();
$conn = $database->getConnection();

// Fixed school information (update these for your school)
const SCHOOL_INFO = [
    'school_id' => '301239',
    'region' => 'REGION IV-A',
    'school_name' => 'Don Jose Integrated High School',
    'division' => 'SANTA ROSA CITY',
    'district' => 'SANTA ROSA CITY'
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $academicYear = $_GET['year'] ?? null;
            $gradeLevel = $_GET['grade'] ?? null;
            $sectionId = $_GET['section'] ?? null;
        $download = $_GET['download'] ?? 'false'; // Check if we should download directly
        
        if (!$academicYear || !$gradeLevel || !$sectionId) {
            throw new Exception('Academic year, grade level, and section are required');
        }
        
        // Get section details
        $sectionQuery = "SELECT 
                            sec.SectionName,
                            gl.GradeLevelName,
                            sec.StrandID,
                            st.StrandCode
                         FROM section sec
                         INNER JOIN gradelevel gl ON sec.GradeLevelID = gl.GradeLevelID
                         LEFT JOIN strand st ON sec.StrandID = st.StrandID
                         WHERE sec.SectionID = :sectionId
                         AND sec.AcademicYear = :year";
        
        $stmt = $conn->prepare($sectionQuery);
        $stmt->bindValue(':sectionId', $sectionId);
        $stmt->bindValue(':year', $academicYear);
        $stmt->execute();
        $section = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section) {
            throw new Exception('Section not found');
        }
        
        // Get students assigned to this section
        $studentsQuery = "SELECT 
                            s.LRN,
                            s.LastName,
                            s.FirstName,
                            s.MiddleName,
                            s.Gender,
                            s.DateOfBirth,
                            s.Province as BirthProvince,
                            s.MotherTongue,
                            s.IsIPCommunity,
                            s.IPCommunitySpecify,
                            s.Religion,
                            s.HouseNumber,
                            s.SitioStreet,
                            s.Barangay,
                            s.Municipality,
                            s.Province,
                            s.FatherFirstName,
                            s.FatherLastName,
                            s.MotherFirstName,
                            s.MotherMiddleName,
                            s.MotherLastName,
                            s.GuardianFirstName,
                            s.GuardianMiddleName,
                            s.GuardianLastName,
                            s.GuardianRelationship,
                            s.ContactNumber,
                            s.IsPWD,
                            s.PWDSpecify,
                            s.Is4PsBeneficiary,
                            e.LearnerType,
                            e.EnrollmentType,
                            e.EnrollmentDate,
                            e.Remarks
                          FROM sectionassignment sa
                          INNER JOIN student s ON sa.StudentID = s.StudentID
                          INNER JOIN enrollment e ON sa.EnrollmentID = e.EnrollmentID
                          WHERE sa.SectionID = :sectionId
                          AND sa.IsActive = 1
                          ORDER BY s.Gender DESC, s.LastName, s.FirstName";
        
        $stmt = $conn->prepare($studentsQuery);
        $stmt->bindValue(':sectionId', $sectionId);
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($students)) {
            throw new Exception('No students found in this section');
        }
        
        // Load the SF1 template
        $templatePath = '../templates/SF1_Template.xlsx';
        
        if (!file_exists($templatePath)) {
            throw new Exception('SF1 template not found. Please upload SF1_Template.xlsx to backend/templates/');
        }
        
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        // Fill in header information
        $sheet->setCellValue('F4', SCHOOL_INFO['school_id']);
        $sheet->setCellValue('J4', SCHOOL_INFO['region']);
        $sheet->setCellValue('F6', SCHOOL_INFO['school_name']);
        $sheet->setCellValue('M4', SCHOOL_INFO['division']);
        $sheet->setCellValue('O6', $academicYear);
        $sheet->setCellValue('S4', SCHOOL_INFO['district']);
        $sheet->setCellValue('S6', $section['GradeLevelName'] . ($section['StrandCode'] ? ' - ' . $section['StrandCode'] : ''));
        $sheet->setCellValue('V6', $section['SectionName']);
        
        // Calculate first Friday of June for age calculation
        $yearStart = (int)substr($academicYear, 0, 4);
        $firstFridayOfJune = getFirstFridayOfJune($yearStart);
        
        // Fill in student data starting from row 10
        $row = 10;
        $maleCount = 0;
        $femaleCount = 0;
        
        foreach ($students as $student) {
            // Count by gender
            if ($student['Gender'] === 'Male') {
                $maleCount++;
            } else {
                $femaleCount++;
            }
            
            // LRN
            $sheet->setCellValue('B' . $row, $student['LRN']);
            
            // Name (Last, First, Middle)
            $fullName = $student['LastName'] . ', ' . $student['FirstName'];
            if ($student['MiddleName']) {
                $fullName .= ' ' . $student['MiddleName'];
            }
            $sheet->setCellValue('C' . $row, strtoupper($fullName));
            
            // Sex (M/F)
            $sheet->setCellValue('G' . $row, $student['Gender'] === 'Male' ? 'M' : 'F');
            
            // Birthdate (mm/dd/yyyy)
            if ($student['DateOfBirth']) {
                $birthdate = date('m/d/Y', strtotime($student['DateOfBirth']));
                $sheet->setCellValue('H' . $row, $birthdate);
            }
            
            // Age as of first Friday of June
            if ($student['DateOfBirth']) {
                $age = calculateAge($student['DateOfBirth'], $firstFridayOfJune);
                $sheet->setCellValue('I' . $row, $age);
            }
            
            // Birth Place (Province)
            $sheet->setCellValue('J' . $row, $student['BirthProvince'] ?? '');
            
            // Mother Tongue
            $sheet->setCellValue('K' . $row, $student['MotherTongue'] ?? '');
            
            // IP (specify ethnic group)
            if ($student['IsIPCommunity']) {
                $sheet->setCellValue('L' . $row, $student['IPCommunitySpecify'] ?? 'Yes');
            }
            
            // Religion
            $sheet->setCellValue('M' . $row, $student['Religion'] ?? '');
            
            // House # / Street / Sitio / Purok
            $address = trim(($student['HouseNumber'] ?? '') . ' ' . ($student['SitioStreet'] ?? ''));
            $sheet->setCellValue('N' . $row, $address);
            
            // Barangay
            $sheet->setCellValue('O' . $row, $student['Barangay']);
            
            // Municipality/City
            $sheet->setCellValue('P' . $row, $student['Municipality']);
            
            // Province
            $sheet->setCellValue('Q' . $row, $student['Province']);
            
            // Father (1st name only if family name identical to learner)
            if ($student['FatherFirstName']) {
                if ($student['FatherLastName'] === $student['LastName']) {
                    $sheet->setCellValue('R' . $row, $student['FatherFirstName']);
                } else {
                    $fatherName = $student['FatherFirstName'];
                    if ($student['FatherLastName']) {
                        $fatherName .= ' ' . $student['FatherLastName'];
                    }
                    $sheet->setCellValue('R' . $row, $fatherName);
                }
            }
            
            // Mother (Maiden: 1st Name, Middle & Last Name)
            if ($student['MotherFirstName']) {
                $motherName = $student['MotherFirstName'];
                if ($student['MotherMiddleName']) {
                    $motherName .= ' ' . $student['MotherMiddleName'];
                }
                if ($student['MotherLastName']) {
                    $motherName .= ' ' . $student['MotherLastName'];
                }
                $sheet->setCellValue('T' . $row, $motherName);
            }
            
            // Guardian (If not Parent) Name
            if ($student['GuardianFirstName']) {
                $guardianName = $student['GuardianFirstName'];
                if ($student['GuardianMiddleName']) {
                    $guardianName .= ' ' . $student['GuardianMiddleName'];
                }
                if ($student['GuardianLastName']) {
                    $guardianName .= ' ' . $student['GuardianLastName'];
                }
                $sheet->setCellValue('V' . $row, $guardianName);
            }
            
            // Guardian Relationship
            $sheet->setCellValue('W' . $row, $student['GuardianRelationship'] ?? '');
            
            // Contact Number
            $sheet->setCellValue('X' . $row, $student['ContactNumber']);
            
            // Remarks - build indicator codes
            $remarks = [];
            
            // Check for enrollment type indicators
            if ($student['EnrollmentType'] === 'Late') {
                $remarks[] = 'LE';
            }
            if ($student['EnrollmentType'] === 'Transferee') {
                $remarks[] = 'T/I';
            }
            
            // Check for learner type
            if (strpos($student['LearnerType'], 'Balik_Aral') !== false) {
                $remarks[] = 'B/A';
            }
            
            // Check for PWD
            if ($student['IsPWD']) {
                $remarks[] = 'LWD';
                if ($student['PWDSpecify']) {
                    $remarks[] = '(' . $student['PWDSpecify'] . ')';
                }
            }
            
            // Check for 4Ps
            if ($student['Is4PsBeneficiary']) {
                $remarks[] = 'CCT';
            }
            
            // Add custom remarks from enrollment
            if ($student['Remarks']) {
                $remarks[] = $student['Remarks'];
            }
            
            $sheet->setCellValue('Y' . $row, implode(', ', $remarks));
            
            $row++;
        }
        
        // Fill in enrollment summary
        $sheet->setCellValue('R53', $maleCount);   
        $sheet->setCellValue('S53', $maleCount);   
        $sheet->setCellValue('R54', $femaleCount); 
        $sheet->setCellValue('S54', $femaleCount); 
        $sheet->setCellValue('R55', $maleCount + $femaleCount);   
        $sheet->setCellValue('S55', $maleCount + $femaleCount);   
        
        // Generate filename with auto-increment
        $baseFilename = 'SF1_' . $section['GradeLevelName'] . '_' . 
                        str_replace(' ', '_', $section['SectionName']) . '_' . 
                        $academicYear;

        // Function to get unique filename
        function getUniqueFilename($baseFilename, $extension = '.xlsx') {
            $filename = $baseFilename . $extension;
            $counter = 1;
            
            // Check in browser's typical download location won't work server-side
            // Instead, add timestamp for uniqueness
            $timestamp = date('_YmdHis'); // Format: _20260207143055
            $filename = $baseFilename . $timestamp . $extension;
            
            return $filename;
        }
        
$filename = getUniqueFilename($baseFilename);

        // If download parameter is true, send file directly to browser
        if ($download === 'true') {
            // Clear any previous output
            if (ob_get_length()) {
                ob_clean();
            }
            
            // Set headers for file download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Cache-Control: max-age=1');
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('Cache-Control: cache, must-revalidate');
            header('Pragma: public');
            
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        } else {
            // Return JSON with file info (for AJAX requests)
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'studentCount' => count($students),
                'maleCount' => $maleCount,
                'femaleCount' => $femaleCount
            ]);
        }
        
    } else {
        throw new Exception('Invalid request method');
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Get the first Friday of June for a given year
 */
function getFirstFridayOfJune($year) {
    $date = new DateTime("$year-06-01");
    
    // Find the first Friday
    while ($date->format('N') != 5) { // 5 = Friday
        $date->modify('+1 day');
    }
    
    return $date->format('Y-m-d');
}

/**
 * Calculate age as of a specific date
 */
function calculateAge($birthDate, $asOfDate) {
    $birth = new DateTime($birthDate);
    $asOf = new DateTime($asOfDate);
    $age = $birth->diff($asOf)->y;
    return $age;
}
?>