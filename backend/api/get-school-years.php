<?php
// backend/api/get-school-years.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once '../config/database.php';
    
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $query = "SELECT DISTINCT AcademicYear 
              FROM Enrollment 
              WHERE AcademicYear IS NOT NULL AND AcademicYear != ''
              ORDER BY AcademicYear DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $schoolYears = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $schoolYears[] = $row['AcademicYear'];
    }
    
    echo json_encode([
        'success' => true,
        'schoolYears' => $schoolYears
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
}
?>