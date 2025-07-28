<?php
session_start();
header('Content-Type: application/json');

// Include database config
require_once '../config/database.php';

try {
    // Get input data
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);

    // Validate input
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin!']);
        exit;
    }

    // Connect to database
    $database = new Database();
    $db = $database->getConnection();

    // Query user from database
    $query = "SELECT id, username, email, password_hash, full_name, role, status FROM users WHERE username = :username AND status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Kiểm tra password
        $password_valid = false;
        if (password_verify($password, $user['password_hash'])) {
            $password_valid = true;
        }
        
        if ($password_valid) {
            // Tạo session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            // Update last login
            $update_query = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':id', $user['id']);
            $update_stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Đăng nhập thành công!',
                'redirect' => 'index.html',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Mật khẩu không đúng!'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Tài khoản không tồn tại hoặc đã bị khóa!'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Có lỗi xảy ra: ' . $e->getMessage()
    ]);
}
?>