<?php
// Test file để kiểm tra cấu hình
echo "Testing configuration...\n\n";

// Test database connection
echo "1. Testing database connection:\n";
try {
    require_once __DIR__ . '/config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    echo "✓ Database connection: OK\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
}

// Test uploads directory
echo "\n2. Testing upload directories:\n";
$dirs = [
    __DIR__ . '/uploads/tmp',
    __DIR__ . '/uploads/products'
];
foreach ($dirs as $dir) {
    if (is_dir($dir) && is_writable($dir)) {
        echo "✓ $dir: writable\n";
    } else {
        echo "✗ $dir: not writable or doesn't exist\n";
    }
}

// Test AI config
echo "\n3. Testing AI configuration:\n";
try {
    $cfg = require __DIR__ . '/config/ai.php';
    echo "✓ AI config loaded\n";
    echo "  - HF enabled: " . ($cfg['use_hf'] ? 'Yes' : 'No') . "\n";
    echo "  - HF token: " . (empty($cfg['hf_token']) ? 'Missing' : 'Present') . "\n";
    echo "  - OCR enabled: " . ($cfg['use_ocr'] ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ AI config failed: " . $e->getMessage() . "\n";
}

// Test GD extension
echo "\n4. Testing GD extension:\n";
if (function_exists('imagecreatetruecolor')) {
    echo "✓ GD extension: Available\n";
} else {
    echo "✗ GD extension: Missing (will use fallback)\n";
}

// Test file permissions
echo "\n5. Testing file creation:\n";
$testFile = __DIR__ . '/uploads/tmp/test.txt';
if (@file_put_contents($testFile, 'test')) {
    echo "✓ File creation: OK\n";
    @unlink($testFile);
} else {
    echo "✗ File creation: Failed\n";
}

echo "\nTest completed.\n";
?>
