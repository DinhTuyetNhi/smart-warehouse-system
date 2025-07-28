<?php
require_once __DIR__ . '/../config/database.php';

class User {
    private $conn;
    private $table_name = "users";
    
    public $id;
    public $username;
    public $email;
    public $password_hash;
    public $full_name;
    public $phone;
    public $role;
    public $status;
    public $avatar;
    public $last_login;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function login($username, $password) {
        $query = "SELECT id, username, email, password_hash, full_name, phone, role, status, avatar, last_login 
                  FROM " . $this->table_name . " 
                  WHERE (username = :username OR email = :username) AND status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $row['password_hash'])) {
                $this->id = $row['id'];
                $this->username = $row['username'];
                $this->email = $row['email'];
                $this->full_name = $row['full_name'];
                $this->phone = $row['phone'];
                $this->role = $row['role'];
                $this->status = $row['status'];
                $this->avatar = $row['avatar'];
                $this->last_login = $row['last_login'];
                
                // Update last login time
                $this->updateLastLogin();
                
                return true;
            }
        }
        
        return false;
    }
    
    public function updateLastLogin() {
        $query = "UPDATE " . $this->table_name . " 
                  SET last_login = CURRENT_TIMESTAMP 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $this->id);
        $stmt->execute();
    }
    
    public function createSession($remember_me = false) {
        $session_token = bin2hex(random_bytes(32));
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Set expiration time
        if ($remember_me) {
            $expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
        } else {
            $expires_at = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 1 day
        }
        
        $query = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                  VALUES (:user_id, :session_token, :ip_address, :user_agent, :expires_at)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $this->id);
        $stmt->bindParam(":session_token", $session_token);
        $stmt->bindParam(":ip_address", $ip_address);
        $stmt->bindParam(":user_agent", $user_agent);
        $stmt->bindParam(":expires_at", $expires_at);
        
        if ($stmt->execute()) {
            return $session_token;
        }
        
        return false;
    }
    
    public function validateSession($session_token) {
        $query = "SELECT u.*, s.expires_at 
                  FROM " . $this->table_name . " u
                  INNER JOIN user_sessions s ON u.id = s.user_id
                  WHERE s.session_token = :session_token 
                  AND s.expires_at > NOW() 
                  AND u.status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":session_token", $session_token);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->id = $row['id'];
            $this->username = $row['username'];
            $this->email = $row['email'];
            $this->full_name = $row['full_name'];
            $this->phone = $row['phone'];
            $this->role = $row['role'];
            $this->status = $row['status'];
            $this->avatar = $row['avatar'];
            $this->last_login = $row['last_login'];
            
            return true;
        }
        
        return false;
    }
    
    public function logout($session_token) {
        $query = "DELETE FROM user_sessions WHERE session_token = :session_token";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":session_token", $session_token);
        $stmt->execute();
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public function logActivity($action, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
        $query = "INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $this->id);
        $stmt->bindParam(":action", $action);
        $stmt->bindParam(":table_name", $table_name);
        $stmt->bindParam(":record_id", $record_id);
        $stmt->bindParam(":old_values", $old_values ? json_encode($old_values) : null);
        $stmt->bindParam(":new_values", $new_values ? json_encode($new_values) : null);
        $stmt->bindParam(":ip_address", $_SERVER['REMOTE_ADDR'] ?? '');
        $stmt->bindParam(":user_agent", $_SERVER['HTTP_USER_AGENT'] ?? '');
        
        $stmt->execute();
    }
}
?>