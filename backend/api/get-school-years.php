<?php
// =====================================================
// Get School Years API - REVISED FOR NORMALIZED DB
// File: backend/api/get-school-years.php
// Updated: 2026-03-04
// Revised to query academicyear table
// =====================================================

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
    
    // Query the academicyear table for all school years
    $query = "SELECT YearLabel 
              FROM academicyear 
              ORDER BY StartYear DESC, EndYear DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $schoolYears = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $schoolYears[] = $row['YearLabel'];
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