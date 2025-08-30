<?php
// Bật hiển thị lỗi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Debug Đăng Ký</h2>";

// Test database connection
echo "<h3>1. Kiểm tra kết nối database:</h3>";
try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "✅ Kết nối database thành công<br>";
        
        // Kiểm tra bảng warehouses
        $query = "SELECT COUNT(*) as count FROM warehouses";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "✅ Bảng warehouses có {$result['count']} record<br>";
        
        // Kiểm tra bảng users
        $query = "SELECT COUNT(*) as count FROM users";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "✅ Bảng users có {$result['count']} record<br>";
        
    } else {
        echo "❌ Không thể kết nối database<br>";
    }
} catch (Exception $e) {
    echo "❌ Lỗi database: " . $e->getMessage() . "<br>";
}

// Test UsernameGenerator
echo "<h3>2. Kiểm tra UsernameGenerator:</h3>";
try {
    require_once 'helpers/UsernameGenerator.php';
    $username = UsernameGenerator::generate('Nguyễn Văn An', 'Kho Giày ABC', 1);
    echo "✅ UsernameGenerator hoạt động: $username<br>";
} catch (Exception $e) {
    echo "❌ Lỗi UsernameGenerator: " . $e->getMessage() . "<br>";
}

// Test AuditLogger
echo "<h3>3. Kiểm tra AuditLogger:</h3>";
try {
    require_once 'helpers/AuditLogger.php';
    $auditLogger = new AuditLogger($db);
    echo "✅ AuditLogger khởi tạo thành công<br>";
} catch (Exception $e) {
    echo "❌ Lỗi AuditLogger: " . $e->getMessage() . "<br>";
}

// Test EmailService (không cần gửi thật)
echo "<h3>4. Kiểm tra EmailService:</h3>";
try {
    // Chỉ test class load, không gửi email
    if (file_exists('vendor/phpmailer/PHPMailer/src/PHPMailer.php')) {
        echo "✅ PHPMailer files tồn tại<br>";
        require_once 'helpers/EmailService.php';
        echo "✅ EmailService class load thành công<br>";
    } else {
        echo "❌ PHPMailer files không tồn tại<br>";
    }
} catch (Exception $e) {
    echo "❌ Lỗi EmailService: " . $e->getMessage() . "<br>";
}

echo "<h3>5. Kiểm tra files:</h3>";
$files = [
    'config/database.php',
    'helpers/UsernameGenerator.php',
    'helpers/AuditLogger.php',
    'helpers/EmailService.php',
    'auth/register_process.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✅ $file<br>";
    } else {
        echo "❌ $file - KHÔNG TỒN TẠI<br>";
    }
}
?>