<?php
// =====================================================
// Authentication Class
// File: backend/includes/auth.php
// =====================================================

class Auth {
    private $conn;
    private $table = 'User';
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Login user with username, password, and role
     */
    public function login($username, $password, $role) {
        try {
            // Prepare query
            $query = "SELECT 
                        UserID, 
                        Username, 
                        Password, 
                        FirstName, 
                        LastName, 
                        Role, 
                        IsActive
                      FROM " . $this->table . "
                      WHERE Username = :username 
                      AND Role = :role 
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':role', $role);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if user exists
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Invalid username or role'
                ];
            }
            
            // Check if account is active
            if (!$user['IsActive']) {
                return [
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact the administrator.'
                ];
            }
            
            // Verify password
            if (password_verify($password, $user['Password'])) {
                // Remove password from response
                unset($user['Password']);
                unset($user['IsActive']);
                
                return [
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error occurred'
            ];
        }
    }
    
    /**
     * Register new user (admin only)
     */
    public function register($username, $password, $firstName, $lastName, $role) {
        try {
            // Check if username already exists
            $checkQuery = "SELECT UserID FROM " . $this->table . " WHERE Username = :username";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':username', $username);
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                return [
                    'success' => false,
                    'message' => 'Username already exists'
                ];
            }
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            
            // Insert new user
            $query = "INSERT INTO " . $this->table . "
                      (Username, Password, FirstName, LastName, Role, IsActive, CreatedAt)
                      VALUES
                      (:username, :password, :firstName, :lastName, :role, 1, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':firstName', $firstName);
            $stmt->bindParam(':lastName', $lastName);
            $stmt->bindParam(':role', $role);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'User registered successfully',
                    'userId' => $this->conn->lastInsertId()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to register user'
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error occurred'
            ];
        }
    }
    
    /**
     * Validate token (for future JWT implementation)
     */
    public function validateToken($token) {
        // To be implemented with JWT
        return false;
    }
    
    /**
     * Change password
     */
    public function changePassword($userId, $oldPassword, $newPassword) {
        try {
            // Get current password
            $query = "SELECT Password FROM " . $this->table . " WHERE UserID = :userId";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':userId', $userId);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }
            
            // Verify old password
            if (!password_verify($oldPassword, $user['Password'])) {
                return [
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ];
            }
            
            // Hash new password
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            
            // Update password
            $updateQuery = "UPDATE " . $this->table . " 
                           SET Password = :password, UpdatedAt = NOW()
                           WHERE UserID = :userId";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':password', $hashedPassword);
            $updateStmt->bindParam(':userId', $userId);
            
            if ($updateStmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Password changed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to change password'
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Change password error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error occurred'
            ];
        }
    }
}
?>