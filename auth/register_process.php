<?php
// Bật hiển thị lỗi để debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: application/json');

try {
    // Include các file cần thiết
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/User.php';
    require_once __DIR__ . '/../classes/Warehouse.php';
    require_once __DIR__ . '/../helpers/UsernameGenerator.php';
    require_once __DIR__ . '/../helpers/EmailService.php';
    require_once __DIR__ . '/../helpers/AuditLogger.php';

    // Kiểm tra method POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // Lấy dữ liệu từ form
    $warehouseName = trim($_POST['warehouse_name'] ?? '');
    $warehouseAddress = trim($_POST['warehouse_address'] ?? '');
    $warehousePhone = trim($_POST['warehouse_phone'] ?? '');
    $warehouseEmail = trim($_POST['warehouse_email'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Debug: Log dữ liệu nhận được
    error_log("Register data received: " . json_encode($_POST));

    // Validate dữ liệu cơ bản
    if (empty($warehouseName) || empty($warehouseAddress) || empty($warehousePhone) || 
        empty($warehouseEmail) || empty($adminName) || empty($password)) {
        throw new Exception('Vui lòng điền đầy đủ thông tin bắt buộc');
    }

    if ($password !== $confirmPassword) {
        throw new Exception('Mật khẩu xác nhận không khớp');
    }

    // Kết nối database (dùng cấu hình chung)
    $database = new Database();
    $db = $database->getConnection();

    // Bắt đầu transaction
    $db->beginTransaction();

    // Tạo warehouse
    $warehouse = new Warehouse($db);
    $warehouse->name = $warehouseName;
    $warehouse->address = $warehouseAddress;
    $warehouse->phone = $warehousePhone;
    $warehouse->email = $warehouseEmail;
    $warehouse->manager_name = $adminName;
    $warehouse->status = 'active';

    // Validate warehouse
    $warehouseErrors = $warehouse->validate();
    if (!empty($warehouseErrors)) {
        throw new Exception(implode(', ', $warehouseErrors));
    }

    // Tạo warehouse
    if (!$warehouse->create()) {
        throw new Exception('Không thể tạo kho');
    }

    // Tạo username (unique theo DB)
    $username = UsernameGenerator::generateUniqueUsername($adminName, $warehouseName, $warehouse->warehouse_id, $db);

    // Tạo user
    $user = new User($db);
    $user->username = $username;
    $user->password_hash = password_hash($password, PASSWORD_DEFAULT);
    $user->full_name = $adminName;
    $user->email = $warehouseEmail;
    $user->role = 'admin';
    $user->warehouse_id = $warehouse->warehouse_id;
    $user->status = 'active';

    // Validate user
    $userErrors = $user->validate();
    if (!empty($userErrors)) {
        throw new Exception(implode(', ', $userErrors));
    }

    // Tạo user
    if (!$user->create()) {
        throw new Exception('Không thể tạo tài khoản người dùng');
    }

    // Gửi email xác nhận
    $emailSent = false;
    try {
        $emailService = new EmailService();
        // Truyền mật khẩu thuần theo chữ ký hàm (đang dùng app password email)
        $emailSent = $emailService->sendWelcomeEmail($warehouseEmail, $adminName, $username, $password, $warehouseName);
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
    }

    // Log audit
    try {
        $auditLogger = new AuditLogger($db);
        $auditLogger->log($user->user_id, 'register', 'users', $user->user_id, null, [
            'username' => $username,
            'warehouse_id' => $warehouse->warehouse_id
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }

    // Commit transaction
    $db->commit();

    // Trả về kết quả thành công
    echo json_encode([
        'success' => true,
        'message' => 'Đăng ký thành công!',
        'data' => [
            'username' => $username,
            'warehouse_id' => $warehouse->warehouse_id,
            'email' => $warehouseEmail,
            'email_sent' => $emailSent
        ]
    ]);

} catch (PDOException $e) {
    // Rollback transaction nếu có lỗi DB
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    // Log lỗi database
    error_log("Database error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage(),
        'debug' => [
            'sql_error' => $e->getMessage(),
            'sql_code' => $e->getCode()
        ]
    ]);
} catch (Exception $e) {
    // Rollback transaction nếu có lỗi
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    // Log lỗi chi tiết
    error_log("Register error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // Trả về lỗi
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>