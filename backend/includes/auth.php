<?php
class Auth {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Verify user credentials
    public function login($username, $password, $role) {
        // Prepare SQL to prevent SQL injection
        $query = "SELECT UserID, Username, Password, FirstName, LastName, Role, IsActive 
                  FROM User 
                  WHERE Username = :username AND Role = :role AND IsActive = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':role', $role);
        $stmt->execute();

        // Check if user exists
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch();
            
            // Verify password
            if (password_verify($password, $user['Password'])) {
                // Remove password from return data
                unset($user['Password']);
                return [
                    'success' => true,
                    'user' => $user,
                    'message' => 'Login successful'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'User not found or inactive'
            ];
        }
    }

    // Hash password (for creating new users)
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
?>