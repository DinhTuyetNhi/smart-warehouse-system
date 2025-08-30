<?php
class AuditLogger {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Ghi log hành động của user
     */
    public function log($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
        try {
            $query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                     VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent, NOW())";
            
            $stmt = $this->db->prepare($query);
            
            // Chuyển đổi arrays thành JSON
            $oldValuesJson = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
            $newValuesJson = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
            
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':table_name', $tableName);
            $stmt->bindParam(':record_id', $recordId);
            $stmt->bindParam(':old_values', $oldValuesJson);
            $stmt->bindParam(':new_values', $newValuesJson);
            $stmt->bindValue(':ip_address', $this->getClientIP());
            $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log đăng ký tài khoản mới
     */
    public function logRegistration($userId, $warehouseId, $userData, $warehouseData) {
        $this->log(
            $userId,
            'account_registration',
            'users',
            $userId,
            null,
            [
                'user_data' => $userData,
                'warehouse_data' => $warehouseData,
                'registration_type' => 'admin_account',
                'email_sent' => true
            ]
        );
    }
    
    /**
     * Log gửi email
     */
    public function logEmailSent($userId, $emailType, $recipient, $success = true) {
        $this->log(
            $userId,
            'email_sent',
            null,
            null,
            null,
            [
                'email_type' => $emailType,
                'recipient' => $recipient,
                'success' => $success,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    private function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
?>