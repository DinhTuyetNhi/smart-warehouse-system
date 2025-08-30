<?php
require_once __DIR__ . '/auth/auth_middleware.php';
require_once __DIR__ . '/helpers/ImageUtils.php';
require_once __DIR__ . '/helpers/AISuggester.php';
require_once __DIR__ . '/helpers/AuditLogger.php';
require_once __DIR__ . '/classes/Product.php';
require_once __DIR__ . '/classes/ProductVariant.php';

header('Content-Type: application/json; charset=utf-8');
$user = requireAuth(); // any authenticated user; role check could be added

$database = new Database();
$db = $database->getConnection();
$logger = new AuditLogger($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'validate_and_suggest':
            handleValidateAndSuggest($db);
            break;
        case 'save':
            handleSave($db, $logger);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('product_api error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage(), 'debug' => true]);
}

function handleValidateAndSuggest(PDO $db) {
    error_log('Starting handleValidateAndSuggest');
    
    if (!isset($_FILES['images'])) {
        error_log('No images in $_FILES');
        echo json_encode(['success' => false, 'message' => 'Thiếu ảnh upload']); return;
    }
    
    error_log('Images found: ' . count($_FILES['images']['name']));
    
    $errors = ImageUtils::validateUploadBatch($_FILES['images']);
    if (!empty($errors)) {
        error_log('Validation errors: ' . implode(', ', $errors));
        echo json_encode(['success' => false, 'message' => implode('\n', $errors)]); return;
    }

    // Save to temp folder after processing
    $token = bin2hex(random_bytes(8));
    $tmpDir = __DIR__ . '/uploads/tmp/' . $token;
    ImageUtils::ensureDir($tmpDir);
    $outPaths = [];
    $names = $_FILES['images']['name'];
    $tmps  = $_FILES['images']['tmp_name'];
    
    error_log('Processing ' . count($names) . ' images');
    
    for ($i = 0; $i < count($names); $i++) {
        $safe = ImageUtils::sanitizeFileName($names[$i]);
        $dest = $tmpDir . '/' . $safe;
        [$ok,$msg,$out,$w,$h] = ImageUtils::processAndSave($tmps[$i], $dest, 'jpg');
        if (!$ok) { 
            error_log("Image processing failed for {$names[$i]}: $msg");
            echo json_encode(['success'=>false,'message'=>$msg]); return; 
        }
        $outPaths[] = $out;
        error_log("Processed image {$i}: $out");
    }

    // Call AI stub
    error_log('Calling AI suggester');
    $ai = new AISuggester($db);
    $aiRes = $ai->callSuggest($outPaths);
    error_log('AI result: ' . json_encode($aiRes));
    if (!$aiRes['ok']) {
        echo json_encode([
            'success' => true,
            'upload_token' => $token,
            'ai_error' => true,
            'data' => [
                'name' => '', 'category_id' => null, 'color' => null, 'size' => null,
                'description' => '', 'tags' => [], 'sku' => ''
            ]
        ]); return;
    }
    // Consider returning ai confidence for UI hinting
    echo json_encode([
        'success' => true,
        'upload_token' => $token,
        'ai_error' => false,
        'data' => $aiRes['data']
    ]);
}

function handleSave(PDO $db, AuditLogger $logger) {
    // Expect fields and upload_token
    $name = trim($_POST['name'] ?? '');
    $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $description = $_POST['description'] ?? null;
    $sku = trim($_POST['sku'] ?? '');
    $color = $_POST['color'] ?? null;
    $size = $_POST['size'] ?? null;
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
    $tagsStr = trim($_POST['tags'] ?? '');
    $tags = $tagsStr !== '' ? array_values(array_filter(array_map('trim', explode(',', $tagsStr)))) : [];
    $min_stock_level = isset($_POST['min_stock_level']) ? (int)$_POST['min_stock_level'] : 0;
    $upload_token = $_POST['upload_token'] ?? '';

    if ($name === '' || $sku === '' || $upload_token === '') {
        echo json_encode(['success'=>false,'message'=>'Thiếu thông tin bắt buộc']); return;
    }

    $tmpDir = __DIR__ . '/uploads/tmp/' . basename($upload_token);
    if (!is_dir($tmpDir)) { echo json_encode(['success'=>false,'message'=>'Phiên upload không hợp lệ']); return; }
    $images = glob($tmpDir . '/*');
    if (count($images) < 2 || count($images) > 4) { echo json_encode(['success'=>false,'message'=>'Số lượng ảnh sản phẩm phải từ 2 đến 4']); return; }

    try {
        $db->beginTransaction();

        $product = new Product($db);
        $productId = $product->create([
            'category_id' => $category_id,
            'name' => $name,
            'description' => $description,
        ]);

        $variantModel = new ProductVariant($db);
        // Ensure unique SKU (DB has unique index). If duplicate, fail gracefully.
        try {
            $variantId = $variantModel->createVariant([
                'product_id' => $productId,
                'sku' => $sku,
                'color' => $color,
                'size' => $size,
                'price' => $price,
            ]);
        } catch (Exception $ex) {
            $db->rollBack();
            echo json_encode(['success'=>false,'message'=>'SKU đã tồn tại']); return;
        }

        // Persist images to permanent folder and record
        $destBase = __DIR__ . '/uploads/products/' . $productId;
        ImageUtils::ensureDir($destBase);
        $i = 0;
        foreach ($images as $img) {
            $baseName = ImageUtils::sanitizeFileName(basename($img));
            $dest = $destBase . '/' . $baseName;
            if (!@rename($img, $dest)) {
                // fallback copy+unlink
                @copy($img, $dest); @unlink($img);
            }
            $webPath = 'uploads/products/' . $productId . '/' . $baseName; // forward slashes for web
            $variantModel->addImage($productId, $variantId, $webPath, $i===0?1:0);
            $i++;
        }
        // Cleanup tmp dir
        @rmdir($tmpDir);

        // Optional: save min_stock_level by creating inventory rows per warehouse/location if needed
        // Since schema doesn't have min_stock_level column, we'll log it in audit for traceability
        $logger->log($_SESSION['user_id'], 'create_product', 'products', $productId, null, [
            'product' => ['name'=>$name,'category_id'=>$category_id,'description'=>$description],
            'variant' => ['sku'=>$sku,'color'=>$color,'size'=>$size,'price'=>$price],
            'min_stock_level' => $min_stock_level,
            'tags' => $tags
        ]);

        $db->commit();
        echo json_encode(['success'=>true,'product_id'=>$productId,'variant_id'=>$variantId]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('save product failed: '.$e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Không thể thêm sản phẩm, vui lòng thử lại']);
    }
}
?>
