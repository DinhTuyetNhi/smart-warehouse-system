<?php
// Attempt to load PHPMailer from local vendor folder; disable email if missing.
$__phpmailer_base = __DIR__ . '/../vendor/phpmailer';
$__ex = $__phpmailer_base . '/src/Exception.php';
$__ph = $__phpmailer_base . '/src/PHPMailer.php';
$__sm = $__phpmailer_base . '/src/SMTP.php';

if (file_exists($__ex) && file_exists($__ph) && file_exists($__sm)) {
    require_once $__ex;
    require_once $__ph;
    require_once $__sm;
} else {
    define('EMAILSERVICE_DISABLED', true);
}

class EmailService {
    private $mail;
    private $enabled = true;
    
    public function __construct() {
        if (defined('EMAILSERVICE_DISABLED') && EMAILSERVICE_DISABLED === true) {
            $this->enabled = false;
            return;
        }
    $this->mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $this->configureMailer();
    }
    
    private function configureMailer() {
        if (!$this->enabled) {
            // Mailing disabled; pretend success without sending
            return true;
        }
        try {
            // Cấu hình SMTP với thông tin của bạn
            $this->mail->isSMTP();
            $host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
            $port = (int)(getenv('MAIL_PORT') ?: 587);
            $username = getenv('MAIL_USERNAME');
            $password = getenv('MAIL_PASSWORD');
            $encryption = getenv('MAIL_ENCRYPTION') ?: \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            $this->mail->Host       = $host;
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = $username;
            $this->mail->Password   = $password;
            $this->mail->SMTPSecure = $encryption;
            $this->mail->Port       = $port;
            
            // Cấu hình mã hóa
            $this->mail->CharSet = 'UTF-8';
            $this->mail->Encoding = 'base64';
            
            // Người gửi
            $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: ($username ?: 'no-reply@example.com');
            $fromName = getenv('MAIL_FROM_NAME') ?: 'Smart Warehouse System';
            $this->mail->setFrom($fromAddress, $fromName);
            
        } catch (Exception $e) {
            throw new Exception("Không thể cấu hình email: " . $e->getMessage());
        }
    }
    
    /**
     * Gửi email thông tin đăng nhập cho admin mới
     */
    public function sendWelcomeEmail($adminEmail, $adminName, $username, $password, $warehouseName) {
        if (!$this->enabled) {
            return true;
        }
        try {
            // Reset recipients
            $this->mail->clearAddresses();
            
            // Người nhận
            $this->mail->addAddress($adminEmail, $adminName);
            
            // Nội dung email
            $this->mail->isHTML(true);
            $this->mail->Subject = 'Tài khoản quản lý kho đã được tạo - CN Shoes Stock Company';
            
            $loginUrl = $this->getLoginUrl();
            
            $emailBody = $this->generateWelcomeEmailTemplate(
                $adminName, 
                $username, 
                $password, 
                $warehouseName, 
                $loginUrl
            );
            
            $this->mail->Body = $emailBody;
            $this->mail->AltBody = $this->generatePlainTextEmail($adminName, $username, $password, $warehouseName, $loginUrl);
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function getLoginUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['REQUEST_URI']) . '/../login.html';
        return $protocol . '://' . $host . $path;
    }
    
    private function generateWelcomeEmailTemplate($adminName, $username, $password, $warehouseName, $loginUrl) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4e73df; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #f8f9fc; padding: 30px; border-radius: 0 0 5px 5px; }
                .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #4e73df; }
                .login-info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 15px 0; font-family: monospace; }
                .button { display: inline-block; background: #4e73df; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
                .logo { font-size: 24px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>👟 CN Shoes Stock Company</div>
                    <h1>Smart Warehouse System</h1>
                    <p>Chào mừng bạn đến với hệ thống quản lý kho giày thông minh!</p>
                </div>
                
                <div class='content'>
                    <h2>Xin chào {$adminName}!</h2>
                    <p>Tài khoản quản lý kho của bạn đã được tạo thành công tại <strong>CN Shoes Stock Company</strong>. 
                    Bạn hiện là <strong>Administrator</strong> của kho <strong>{$warehouseName}</strong>.</p>
                    
                    <div class='info-box'>
                        <h3>📋 Thông tin đăng nhập của bạn:</h3>
                        <div class='login-info'>
                            <p><strong>🏢 Công ty:</strong> CN Shoes Stock Company</p>
                            <p><strong>🏬 Kho quản lý:</strong> {$warehouseName}</p>
                            <p><strong>👤 Tên đăng nhập:</strong> {$username}</p>
                            <p><strong>🔑 Mật khẩu:</strong> {$password}</p>
                            <p><strong>🎯 Quyền hạn:</strong> Administrator (Quản lý toàn quyền)</p>
                        </div>
                    </div>
                    
                    <div class='warning'>
                        <h4>⚠️ Lưu ý bảo mật quan trọng:</h4>
                        <ul>
                            <li>🔄 Vui lòng <strong>đổi mật khẩu</strong> ngay sau lần đăng nhập đầu tiên</li>
                            <li>🔒 Không chia sẻ thông tin đăng nhập với bất kỳ ai</li>
                            <li>💪 Sử dụng mật khẩu mạnh có ít nhất 8 ký tự, bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt</li>
                            <li>🚪 Luôn đăng xuất sau khi sử dụng xong</li>
                            <li>🚫 Không đăng nhập trên máy tính công cộng</li>
                        </ul>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='{$loginUrl}' class='button'>🚀 Đăng nhập ngay bây giờ</a>
                    </div>
                    
                    <div class='info-box'>
                        <h4>🎯 Với quyền Administrator, bạn có thể:</h4>
                        <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px;'>
                            <div>
                                <p>✅ Quản lý toàn bộ kho hàng</p>
                                <p>✅ Tạo và quản lý tài khoản nhân viên</p>
                                <p>✅ Quản lý sản phẩm và danh mục</p>
                                <p>✅ Quản lý nhà cung cấp</p>
                            </div>
                            <div>
                                <p>✅ Xử lý đơn hàng và khách hàng</p>
                                <p>✅ Quản lý xuất nhập kho</p>
                                <p>✅ Xem báo cáo và thống kê</p>
                                <p>✅ Cấu hình hệ thống</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class='info-box' style='background: #e8f5e8;'>
                        <h4>📞 Hỗ trợ kỹ thuật:</h4>
                        <p>Nếu bạn gặp khó khăn trong quá trình sử dụng, vui lòng liên hệ:</p>
                        <p><strong>📧 Email:</strong> support@cnshoesstock.com</p>
                        <p><strong>☎️ Hotline:</strong> 1900-xxxx (8:00 - 18:00, T2-T6)</p>
                    </div>
                </div>
                
                <div class='footer'>
                    <p><strong>CN Shoes Stock Company</strong> - Hệ thống quản lý kho giày thông minh</p>
                    <p>Email này được gửi tự động từ hệ thống. Vui lòng không trả lời email này.</p>
                    <p>© 2024 CN Shoes Stock Company. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function generatePlainTextEmail($adminName, $username, $password, $warehouseName, $loginUrl) {
        return "
CN SHOES STOCK COMPANY - Smart Warehouse System
==============================================

Xin chào {$adminName}!

Tài khoản quản lý kho của bạn đã được tạo thành công.

THÔNG TIN ĐĂNG NHẬP:
- Công ty: CN Shoes Stock Company
- Kho quản lý: {$warehouseName}
- Tên đăng nhập: {$username}
- Mật khẩu: {$password}
- Quyền hạn: Administrator

LINK ĐĂNG NHẬP: {$loginUrl}

LƯU Ý BẢO MẬT:
- Vui lòng đổi mật khẩu ngay sau lần đăng nhập đầu tiên
- Không chia sẻ thông tin đăng nhập với người khác
- Sử dụng mật khẩu mạnh có ít nhất 8 ký tự
- Luôn đăng xuất sau khi sử dụng xong

HỖ TRỢ KỸ THUẬT:
Email: support@cnshoesstock.com
Hotline: 1900-xxxx (8:00-18:00, T2-T6)

---
CN Shoes Stock Company
© 2024 All rights reserved.
        ";
    }
}
?>