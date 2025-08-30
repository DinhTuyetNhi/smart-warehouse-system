<?php
session_start();
header('Content-Type: application/json');

// Include database config
require_once '../config/database.php';

try {
    // Get input data
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate input
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin!']);
        exit;
    }

    // Connect to database
    $database = new Database();
    $db = $database->getConnection();

    // Lấy thông tin user kèm warehouse - sử dụng đúng tên cột
    $query = "SELECT u.*, w.name as warehouse_name 
              FROM users u 
              LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id 
              WHERE u.username = :username AND u.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($password, $user['password_hash'])) {
            // Tạo session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['warehouse_id'] = $user['warehouse_id'];
            $_SESSION['warehouse_name'] = $user['warehouse_name'];
            $_SESSION['logged_in'] = true;
            
            // Cập nhật last login
            $update_query = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':user_id', $user['user_id']);
            $update_stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Đăng nhập thành công!',
                'redirect' => '../index.html',
                'user' => [
                    'id' => $user['user_id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'warehouse_name' => $user['warehouse_name']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Mật khẩu không đúng!']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Tài khoản không tồn tại!']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
}
?>