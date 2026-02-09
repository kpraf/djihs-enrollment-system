<?php
// backend/helpers/audit_logger.php
// Helper functions to easily log audit trail entries

class AuditLogger {
    private $conn;
    private $userId;
    private $userRole;
    
    public function __construct($dbConnection, $userId, $userRole) {
        $this->conn = $dbConnection;
        $this->userId = $userId;
        $this->userRole = $userRole;
    }
    
    /**
     * Log an audit entry
     */
    public function log($tableName, $recordId, $action, $description, $oldValue = null, $newValue = null, $affectedUserName = null) {
        $sql = "INSERT INTO auditlog (
                    TableName, RecordID, Action, ActionDescription, 
                    OldValue, NewValue, ChangedBy, UserRole, 
                    AffectedUserName, IPAddress
                ) VALUES (
                    :TableName, :RecordID, :Action, :ActionDescription,
                    :OldValue, :NewValue, :ChangedBy, :UserRole,
                    :AffectedUserName, :IPAddress
                )";
        
        try {
            $stmt = $this->conn->prepare($sql);
            
            $oldValueJson = $oldValue ? json_encode($oldValue) : null;
            $newValueJson = $newValue ? json_encode($newValue) : null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            
            $stmt->bindParam(':TableName', $tableName);
            $stmt->bindParam(':RecordID', $recordId);
            $stmt->bindParam(':Action', $action);
            $stmt->bindParam(':ActionDescription', $description);
            $stmt->bindParam(':OldValue', $oldValueJson);
            $stmt->bindParam(':NewValue', $newValueJson);
            $stmt->bindParam(':ChangedBy', $this->userId);
            $stmt->bindParam(':UserRole', $this->userRole);
            $stmt->bindParam(':AffectedUserName', $affectedUserName);
            $stmt->bindParam(':IPAddress', $ipAddress);
            
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log user account creation
     */
    public function logUserCreation($userId, $username, $fullName, $role, $userData) {
        return $this->log(
            'user',
            $userId,
            'INSERT',
            "Created new user account: {$username} with role {$role}",
            null,
            $userData,
            $fullName
        );
    }
    
    /**
     * Log user account update
     */
    public function logUserUpdate($userId, $username, $fullName, $oldData, $newData) {
        return $this->log(
            'user',
            $userId,
            'UPDATE',
            "Updated user account: {$username}",
            $oldData,
            $newData,
            $fullName
        );
    }
    
    /**
     * Log user status change (activate/deactivate)
     */
    public function logUserStatusChange($userId, $username, $fullName, $newStatus) {
        $statusText = $newStatus ? 'activated' : 'deactivated';
        return $this->log(
            'user',
            $userId,
            'STATUS_CHANGE',
            "User account {$statusText}: {$username}",
            ['IsActive' => !$newStatus],
            ['IsActive' => $newStatus],
            $fullName
        );
    }
    
    /**
     * Log password reset
     */
    public function logPasswordReset($userId, $username, $fullName) {
        return $this->log(
            'user',
            $userId,
            'PASSWORD_RESET',
            "Password reset for user: {$username}",
            null,
            null,
            $fullName
        );
    }
    
    /**
     * Log employee creation
     */
    public function logEmployeeCreation($employeeId, $fullName, $position, $employeeData) {
        return $this->log(
            'employee',
            $employeeId,
            'INSERT',
            "Added new employee: {$fullName} as {$position}",
            null,
            $employeeData,
            $fullName
        );
    }
    
    /**
     * Log employee update
     */
    public function logEmployeeUpdate($employeeId, $fullName, $oldData, $newData) {
        return $this->log(
            'employee',
            $employeeId,
            'UPDATE',
            "Updated employee information: {$fullName}",
            $oldData,
            $newData,
            $fullName
        );
    }
    
    /**
     * Log employee status change
     */
    public function logEmployeeStatusChange($employeeId, $fullName, $newStatus) {
        $statusText = $newStatus ? 'activated' : 'deactivated';
        return $this->log(
            'employee',
            $employeeId,
            'STATUS_CHANGE',
            "Employee {$statusText}: {$fullName}",
            ['IsActive' => !$newStatus],
            ['IsActive' => $newStatus],
            $fullName
        );
    }
    
    /**
     * Log student enrollment
     */
    public function logStudentEnrollment($enrollmentId, $studentName, $gradeLevel, $enrollmentData) {
        return $this->log(
            'enrollment',
            $enrollmentId,
            'INSERT',
            "Enrolled student: {$studentName} in {$gradeLevel}",
            null,
            $enrollmentData,
            $studentName
        );
    }
    
    /**
     * Log enrollment status change
     */
    public function logEnrollmentStatusChange($enrollmentId, $studentName, $oldStatus, $newStatus) {
        return $this->log(
            'enrollment',
            $enrollmentId,
            'UPDATE',
            "Enrollment status changed from {$oldStatus} to {$newStatus} for: {$studentName}",
            ['Status' => $oldStatus],
            ['Status' => $newStatus],
            $studentName
        );
    }
    
    /**
     * Log section creation
     */
    public function logSectionCreation($sectionId, $sectionName, $gradeLevel, $sectionData) {
        return $this->log(
            'section',
            $sectionId,
            'INSERT',
            "Created new section: {$sectionName} for {$gradeLevel}",
            null,
            $sectionData,
            null
        );
    }
    
    /**
     * Log section assignment
     */
    public function logSectionAssignment($assignmentId, $studentName, $sectionName) {
        return $this->log(
            'sectionassignment',
            $assignmentId,
            'INSERT',
            "Assigned student {$studentName} to section {$sectionName}",
            null,
            ['StudentName' => $studentName, 'SectionName' => $sectionName],
            $studentName
        );
    }
    
    /**
     * Log student information update
     */
    public function logStudentUpdate($studentId, $studentName, $oldData, $newData) {
        return $this->log(
            'student',
            $studentId,
            'UPDATE',
            "Updated student information: {$studentName}",
            $oldData,
            $newData,
            $studentName
        );
    }
}

// USAGE EXAMPLES:
// ================

// Example 1: In users.php when creating a user
/*
require_once '../helpers/audit_logger.php';

function createUser($conn, $data) {
    // ... existing user creation code ...
    
    $userId = $conn->lastInsertId();
    
    // Log the action
    $currentUser = getCurrentUser(); // Your function to get logged in user
    $logger = new AuditLogger($conn, $currentUser['UserID'], $currentUser['Role']);
    
    $logger->logUserCreation(
        $userId,
        $data['Username'],
        $data['FirstName'] . ' ' . $data['LastName'],
        $data['Role'],
        [
            'Username' => $data['Username'],
            'FirstName' => $data['FirstName'],
            'LastName' => $data['LastName'],
            'Role' => $data['Role'],
            'EmployeeID' => $data['EmployeeID'] ?? null
        ]
    );
    
    // ... rest of code ...
}
*/

// Example 2: When toggling user status
/*
function toggleUserStatus($conn, $data) {
    // Get old status
    $checkSql = "SELECT IsActive, Username, FirstName, LastName FROM user WHERE UserID = :UserID";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bindParam(':UserID', $data['UserID']);
    $checkStmt->execute();
    $oldUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Update status
    $sql = "UPDATE user SET IsActive = NOT IsActive WHERE UserID = :UserID";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':UserID', $data['UserID']);
    $stmt->execute();
    
    // Log the action
    $currentUser = getCurrentUser();
    $logger = new AuditLogger($conn, $currentUser['UserID'], $currentUser['Role']);
    
    $logger->logUserStatusChange(
        $data['UserID'],
        $oldUser['Username'],
        $oldUser['FirstName'] . ' ' . $oldUser['LastName'],
        !$oldUser['IsActive']
    );
}
*/

// Example 3: When resetting password
/*
function resetPassword($conn, $data) {
    // Get user info
    $checkSql = "SELECT Username, FirstName, LastName FROM user WHERE UserID = :UserID";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bindParam(':UserID', $data['UserID']);
    $checkStmt->execute();
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Update password
    $hashedPassword = password_hash($data['NewPassword'], PASSWORD_DEFAULT);
    $sql = "UPDATE user SET Password = :Password WHERE UserID = :UserID";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':Password', $hashedPassword);
    $stmt->bindParam(':UserID', $data['UserID']);
    $stmt->execute();
    
    // Log the action
    $currentUser = getCurrentUser();
    $logger = new AuditLogger($conn, $currentUser['UserID'], $currentUser['Role']);
    
    $logger->logPasswordReset(
        $data['UserID'],
        $user['Username'],
        $user['FirstName'] . ' ' . $user['LastName']
    );
}
*/

?>