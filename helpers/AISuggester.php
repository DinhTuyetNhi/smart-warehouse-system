<?php
/**
 * AISuggester: Stub for AI suggestions and duplicate detection.
 * In production, replace callSuggest() with actual HTTP call to AI service.
 */
class AISuggester {
    private $db;
    private $cfg;
    public function __construct($db) {
        $this->db = $db;
        $cfgPath = __DIR__ . '/../config/ai.php';
        $this->cfg = file_exists($cfgPath) ? (require $cfgPath) : ['enabled'=>false];
    }

    public function callSuggest(array $imagePaths): array {
        // If custom endpoint configured, try it first
        if (!empty($this->cfg['enabled']) && !empty($this->cfg['endpoint'])) {
            try {
                $res = $this->callExternalAI($imagePaths);
                if ($res && $res['ok']) return $res;
            } catch (\Throwable $e) {
                // fall through to stub
            }
        }

    // Option 2: Hugging Face + OCR integrations
        if (!empty($this->cfg['use_hf']) && !empty($this->cfg['hf_token'])) {
            try {
                $res = $this->callHuggingFacePipeline($imagePaths);
                if ($res && $res['ok']) return $res;
            } catch (\Throwable $e) {
                // continue to stub
            }
        }

        // Fallback: stub inference from filename
        $name = 'Giày thể thao';
        $color = null; $size = null; $tags = ['shoes']; $category_id = null;
        foreach ($imagePaths as $p) {
            $n = strtolower(basename($p));
            if (str_contains($n, 'den') || str_contains($n, 'black')) $color = 'Black';
            if (preg_match('/size[_-]?(\d{2})/', $n, $m)) $size = $m[1];
            if (str_contains($n, 'boot')) $tags[] = 'boot';
        }
        $sku = $this->generateSku($color, $size);
        return [
            'ok' => true,
            'data' => [
                'name' => $name,
                'category_id' => $category_id,
                'color' => $color,
                'size' => $size,
                'description' => 'Sản phẩm được gợi ý bởi AI (stub)',
                'tags' => $tags,
                'sku' => $sku,
                'confidence' => 0.5,
                'duplicate' => $this->checkDuplicateByHeuristics($name, $color, $size)
            ]
        ];
    }

    private function callExternalAI(array $imagePaths): array {
        $url = $this->cfg['endpoint'];
        $apiKey = $this->cfg['api_key'] ?? '';
        $timeout = $this->cfg['timeout'] ?? 10;

        // Use cURL multipart POST
        $ch = curl_init($url);
        $payload = [];
        foreach ($imagePaths as $i => $p) {
            // Ensure absolute path
            $real = realpath($p) ?: $p;
            $payload['images['.$i.']'] = new CURLFile($real, mime_content_type($real), basename($real));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_filter([
                $apiKey ? 'Authorization: Bearer '.$apiKey : null
            ]),
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            curl_close($ch);
            return ['ok'=>false,'error'=>'AI request failed'];
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http < 200 || $http >= 300) return ['ok'=>false,'error'=>'AI http '.$http];

        $json = json_decode($resp, true);
        if (!is_array($json)) return ['ok'=>false,'error'=>'AI invalid JSON'];

        // Expect: { name, category_id, color, size, description, tags[], sku, confidence }
        $data = [
            'name' => $json['name'] ?? '',
            'category_id' => $json['category_id'] ?? null,
            'color' => $json['color'] ?? null,
            'size' => $json['size'] ?? null,
            'description' => $json['description'] ?? '',
            'tags' => $json['tags'] ?? [],
            'sku' => $json['sku'] ?? $this->generateSku($json['color'] ?? null, $json['size'] ?? null),
            'confidence' => $json['confidence'] ?? null,
        ];
        $data['duplicate'] = $this->checkDuplicateByHeuristics($data['name'], $data['color'], $data['size']);
        return ['ok'=>true,'data'=>$data];
    }

    private function callHuggingFacePipeline(array $imagePaths): array {
    $hfToken = $this->cfg['hf_token'];
    // --- IMPROVEMENT: Use more powerful models by default ---
    $captionModel = $this->cfg['hf_caption_model'] ?: 'Salesforce/blip-image-captioning-large';
    $clipModel = $this->cfg['hf_clip_model'] ?: 'openai/clip-vit-large-patch14';
    $ocrKey = $this->cfg['use_ocr'] ? ($this->cfg['ocrspace_key'] ?? '') : '';

    // --- IMPROVEMENT: Process all images (up to 4) for more context ---
    $allCaptions = [];
    $allOcrText = [];
    $processedImages = array_slice($imagePaths, 0, 4);

    foreach ($processedImages as $path) {
        $captionText = $this->hfImageToText($captionModel, $path, $hfToken);
        if ($captionText) {
            $allCaptions[] = rtrim($captionText, '.');
        }
        if (!empty($ocrKey)) {
            $ocrResult = $this->ocrSpace($path, $ocrKey);
            if ($ocrResult) {
                $allOcrText[] = $ocrResult;
            }
        }
    }

    $caption = implode('. ', array_unique($allCaptions));
    $ocrText = implode("\n", array_unique($allOcrText));
    $first = $imagePaths[0]; // Still need a primary image for color/classification

    // Zero-shot categories với nhãn tiếng Việt chi tiết
    $category = null; $categoryScore = null;
    $labels = ['Giày thể thao','Giày boot','Sandal','Dép','Giày cao gót','Giày búp bê','Giày lười','Giày tây'];
        
        // Heuristic từ caption trước (ưu tiên tiếng Việt)
        $categoryMap = [
            'sandal' => 'Sandal',
            'dép' => 'Dép', 
            'slipper' => 'Dép',
            'flip flop' => 'Dép',
            'boot' => 'Giày boot',
            'sneaker' => 'Giày thể thao',
            'running' => 'Giày thể thao',
            'training' => 'Giày thể thao',
            'sport' => 'Giày thể thao',
            'heel' => 'Giày cao gót',
            'high heel' => 'Giày cao gót',
            'pump' => 'Giày búp bê',
            'flat' => 'Giày búp bê',
            'loafer' => 'Giày lười',
            'oxford' => 'Giày tây',
            'formal' => 'Giày tây'
        ];
        
        $text = strtolower($caption . ' ' . $ocrText);
        foreach ($categoryMap as $keyword => $cat) {
            if (str_contains($text, $keyword)) {
                $category = $cat;
                $categoryScore = 0.8;
                break;
            }
        }
        
        // CLIP backup nếu không tìm thấy
        if (empty($category) && $clipModel) {
            $zs = $this->hfZeroShotImageClassification($clipModel, $first, $labels, $hfToken);
            if ($zs && isset($zs['label'])) { $category = $zs['label']; $categoryScore = $zs['score']; }
        }

        // Dominant color (basic): Use average color heuristic
    [$colorName, $colorHex] = $this->extractDominantColor($first);
        
        // OCR + AI caption để tìm size
        $size = null; 
        // Tìm size từ OCR trước (35-46 là size giày phổ biến)
        if (preg_match('/\b(3[5-9]|4[0-6])\b/', $ocrText, $m)) { 
            $size = $m[1]; 
        }
        // Fallback từ caption nếu OCR không có
        if (!$size && preg_match('/size\s*(\d{2})/', $caption, $m)) {
            $size = $m[1];
        }
        
        // Detect brand & model code from OCR/caption
        $brand = $this->detectBrand($ocrText . ' ' . $caption);
        $modelCode = $this->detectModelCode($ocrText);

        // Post-process/normalize
    $color = $this->normalizeColor($colorName, $ocrText . ' ' . $caption);
    $name = $this->composeName($caption, $category, $color, $brand);
    $sku = $this->generateSkuSmart($name, $color, $size, $brand, $modelCode);
    $tags = $this->extractTags($caption, $ocrText, $category, $color, $size, $brand);

        $data = [
            'name' => $name,
            'category_id' => null,
            'color' => $color,
            'size' => $size,
            'description' => $caption ?: 'Sản phẩm giày được phân tích bởi AI',
            'tags' => $tags,
            'sku' => $sku,
            'confidence' => max((float)$categoryScore, 0.6)
        ];
        $data['duplicate'] = $this->checkDuplicateByHeuristics($data['name'], $data['color'], $data['size']);
        return ['ok'=>true,'data'=>$data];
    }

    private function hfImageToText(string $model, string $imagePath, string $token) {
        $url = 'https://api-inference.huggingface.co/models/' . rawurlencode($model);
        $img = file_get_contents($imagePath);
        if ($img === false) return '';
        
        $maxRetries = 2;
        $retryDelay = 1; // seconds

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/octet-stream',
                    'x-wait-for-model: true'
                ],
                CURLOPT_POSTFIELDS => $img,
                CURLOPT_TIMEOUT => $this->cfg['timeout'] ?? 15, // Increased timeout
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                // Log cURL error, maybe retry
                error_log("AISuggester cURL Error: $curlError");
                if ($attempt < $maxRetries) {
                    sleep($retryDelay);
                    continue;
                }
                return '';
            }

            $json = json_decode($resp, true);

            // Success case
            if ($httpCode === 200 && isset($json[0]['generated_text'])) {
                return trim($json[0]['generated_text']);
            }

            // Handle model loading error (503)
            if ($httpCode === 503 || (is_array($json) && isset($json['error']) && str_contains($json['error'], 'is currently loading'))) {
                error_log("AISuggester: Model $model is loading. Retrying...");
                if ($attempt < $maxRetries) {
                    sleep($retryDelay * ($attempt + 2)); // Exponential backoff
                    continue;
                }
                error_log("AISuggester: Model $model failed to load after $maxRetries retries.");
                return '';
            }
            
            // Other errors
            error_log("AISuggester HF Error: HTTP $httpCode - " . $resp);
            return '';
        }
        return '';
    }

    private function hfZeroShotImageClassification(string $model, string $imagePath, array $labels, string $token) {
        $url = 'https://api-inference.huggingface.co/models/' . rawurlencode($model);
        $img = file_get_contents($imagePath);
        if ($img === false) return null;
        $b64 = 'data:image/jpeg;base64,' . base64_encode($img);
        $body = json_encode(['inputs' => ['image' => $b64, 'text' => $labels]], JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'x-wait-for-model: true'
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $this->cfg['timeout'] ?? 12,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($resp, true);
        // Expected top scoring result
        if (is_array($json) && isset($json[0]['label'])) return $json[0];
        return null;
    }

    private function ocrSpace(string $imagePath, string $apiKey): string {
        $url = 'https://api.ocr.space/parse/image';
        $ch = curl_init($url);
        // Skip OCR for very large files in free tier (>4MB)
        if (@filesize($imagePath) > 4*1024*1024) return '';
        $post = [
            'apikey' => $apiKey ?: 'helloworld',
            'language' => 'eng',
            'isOverlayRequired' => 'false',
            'OCREngine' => 2,
            'scale' => 'true',
            'file' => new CURLFile($imagePath, mime_content_type($imagePath), basename($imagePath))
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_TIMEOUT => $this->cfg['timeout'] ?? 12,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($resp, true);
        $txt = '';
        if (isset($json['IsErroredOnProcessing']) && $json['IsErroredOnProcessing']) return '';
        if (!empty($json['ParsedResults'][0]['ParsedText'])) $txt = $json['ParsedResults'][0]['ParsedText'];
        return $txt;
    }

    private function extractDominantColor(string $imagePath): array {
        // If GD available, sample average color; else return nulls
        if (function_exists('imagecreatefromjpeg')) {
            $im = @imagecreatefromjpeg($imagePath);
            if (!$im && function_exists('imagecreatefrompng')) $im = @imagecreatefrompng($imagePath);
            if (!$im && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($imagePath);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im);
                $sample = 10; $r=$g=$b=0; $cnt=0;
                for ($y=0; $y<$h; $y+=max(1,intval($h/$sample))) {
                    for ($x=0; $x<$w; $x+=max(1,intval($w/$sample))) {
                        $rgb = imagecolorat($im, $x, $y);
                        $r += ($rgb >> 16) & 0xFF; $g += ($rgb >> 8) & 0xFF; $b += $rgb & 0xFF; $cnt++;
                    }
                }
                imagedestroy($im);
                if ($cnt>0) {
                    $r = intval($r/$cnt); $g = intval($g/$cnt); $b = intval($b/$cnt);
                    $hex = sprintf('#%02X%02X%02X', $r,$g,$b);
                    $name = $this->mapColorHexToName($r,$g,$b);
                    return [$name, $hex];
                }
            }
        }
        return [null, null];
    }

    private function normalizeColor(?string $colorName, string $context): ?string {
        $maps = [
            'black' => ['đen','black','blk','noir','schwarz'],
            'white' => ['trắng','white','wht','blanc','weiß'],
            'red' => ['đỏ','red','rouge','rot'],
            'blue' => ['xanh dương','blue','bleu','blau'],
            'green' => ['xanh lá','green','vert','grün'],
            'grey' => ['xám','grey','gray','gris','grau'],
            'brown' => ['nâu','brown','brun','braun']
        ];
        $text = strtolower(($colorName ?: '') . ' ' . $context);
        foreach ($maps as $norm => $keys) {
            foreach ($keys as $k) {
                if (str_contains($text, $k)) return ucfirst($norm);
            }
        }
        return $colorName ? ucfirst(strtolower($colorName)) : null;
    }

    private function composeName(string $caption, ?string $category, ?string $color, ?string $brand = null): string {
        // Tạo tên cụ thể và có nghĩa
        $parts = [];
        
        // Bắt đầu với category hoặc "Giày"
        $base = $category ?: 'Giày';
        $parts[] = $base;
        
        // Thêm đặc điểm từ caption (loại bỏ từ chung chung)
        if ($caption && $caption !== 'a shoe' && $caption !== 'shoe') {
            $filtered = $this->filterCaption($caption, $category);
            if ($filtered) $parts[] = $filtered;
        }
        
        // Thêm brand nếu có
        if ($brand) $parts[] = $brand;
        
        // Thêm màu cuối
        if ($color) $parts[] = 'màu ' . strtolower($color);
        
        $name = trim(implode(' ', $parts));
        return mb_substr($name, 0, 180);
    }
    
    private function filterCaption(string $caption, ?string $category): string {
        // Loại bỏ những từ chung chung, giữ lại đặc điểm cụ thể
        $caption = strtolower(trim($caption));
        $removeWords = ['a', 'the', 'shoe', 'shoes', 'pair', 'of', 'footwear', 'item'];
        $words = explode(' ', $caption);
        $filtered = [];
        
        foreach ($words as $word) {
            $word = trim($word, '.,!?');
            if (!in_array($word, $removeWords) && strlen($word) > 2) {
                // Thêm từ mô tả đặc biệt
                if (in_array($word, ['strap', 'heel', 'flat', 'leather', 'canvas', 'mesh', 'lace'])) {
                    $filtered[] = $word;
                }
            }
        }
        
        // Thêm mô tả giới tính và chức năng
        if (str_contains($caption, 'women') || str_contains($caption, 'female')) $filtered[] = 'nữ';
        if (str_contains($caption, 'men') || str_contains($caption, 'male')) $filtered[] = 'nam';
        if (str_contains($caption, 'beach') || str_contains($caption, 'summer')) $filtered[] = 'đi biển';
        if (str_contains($caption, 'casual')) $filtered[] = 'thường ngày';
        if (str_contains($caption, 'sport') || str_contains($caption, 'running')) $filtered[] = 'thể thao';
        
        return implode(' ', array_unique($filtered));
    }

    private function generateSkuSmart(string $name, ?string $color, ?string $size, ?string $brand = null, ?string $modelCode = null): string {
        // Build SKU from name initials + color + size + short rand
        $baseName = $brand ? ($brand . ' ' . $name) : $name;
        $words = preg_split('/\W+/', strtoupper($baseName));
        $init = '';
        foreach ($words as $w) { if ($w !== '') { $init .= substr($w,0,1); if (strlen($init) >= 4) break; } }
        $c2 = $color ? strtoupper(substr($color,0,2)) : 'NA';
        $s = $size ? $size : 'SZ';
        $mc = $modelCode ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $modelCode)) : null;
        $rand = substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
        return implode('-', array_filter([$init ?: 'PRD', $mc, $c2, $s, $rand]));
    }

    private function extractTags(string $caption, string $ocr, ?string $category, ?string $color, ?string $size, ?string $brand): array {
        $base = strtolower($caption . ' ' . $ocr);
        $out = [];
        
        // Tags theo giới tính
        if (str_contains($base, 'women') || str_contains($base, 'female') || str_contains($base, 'nữ')) $out[] = 'nữ';
        if (str_contains($base, 'men') || str_contains($base, 'male') || str_contains($base, 'nam')) $out[] = 'nam';
        if (str_contains($base, 'unisex') || str_contains($base, 'both')) $out[] = 'unisex';
        
        // Tags theo chức năng
        if (str_contains($base, 'sport') || str_contains($base, 'running') || str_contains($base, 'training')) $out[] = 'thể thao';
        if (str_contains($base, 'casual') || str_contains($base, 'daily') || str_contains($base, 'everyday')) $out[] = 'thường ngày';
        if (str_contains($base, 'formal') || str_contains($base, 'office') || str_contains($base, 'dress')) $out[] = 'công sở';
        if (str_contains($base, 'beach') || str_contains($base, 'summer') || str_contains($base, 'vacation')) $out[] = 'đi biển';
        if (str_contains($base, 'hiking') || str_contains($base, 'outdoor') || str_contains($base, 'trail')) $out[] = 'leo núi';
        
        // Tags theo chất liệu
        if (str_contains($base, 'leather') || str_contains($base, 'da')) $out[] = 'da';
        if (str_contains($base, 'canvas') || str_contains($base, 'vải')) $out[] = 'vải';
        if (str_contains($base, 'mesh') || str_contains($base, 'lưới')) $out[] = 'lưới';
        if (str_contains($base, 'rubber') || str_contains($base, 'cao su')) $out[] = 'cao su';
        
        // Tags theo đặc điểm
        if (str_contains($base, 'heel') || str_contains($base, 'gót')) $out[] = 'có gót';
        if (str_contains($base, 'flat') || str_contains($base, 'bằng')) $out[] = 'đế bằng';
        if (str_contains($base, 'strap') || str_contains($base, 'dây')) $out[] = 'có dây';
        if (str_contains($base, 'slip on') || str_contains($base, 'không dây')) $out[] = 'không dây';
        
        // Thêm category, color, brand, size làm tag
        if ($category) $out[] = strtolower($category);
        if ($color) $out[] = strtolower($color);
        if ($brand) $out[] = strtolower($brand);
        if ($size) $out[] = 'size-' . $size;
        
        // Lọc trùng và giới hạn số lượng
        $out = array_values(array_unique(array_filter($out)));
        return array_slice($out, 0, 8); // Tối đa 8 tags
    }

    private function detectBrand(string $text): ?string {
        $brands = ['Nike','Adidas','Puma','Reebok','New Balance','Converse','Vans','Asics','Skechers','Mizuno','Li-Ning'];
        foreach ($brands as $b) { if (stripos($text, $b) !== false) return $b; }
        return null;
    }

    private function detectModelCode(string $text): ?string {
        // Basic model code: alnum strings 4-10 chars with at least 1 letter+digit (e.g., AQ1234, M20324)
        if (preg_match('/\b(?=[A-Z0-9]{4,10}\b)(?=.*[A-Z])(?=.*\d)[A-Z0-9]+\b/i', $text, $m)) {
            return strtoupper($m[0]);
        }
        return null;
    }

    private function mapColorHexToName(int $r,int $g,int $b): ?string {
        // Simple palette mapping
        $palette = [
            'Black' => [0,0,0], 'White' => [255,255,255], 'Grey' => [128,128,128],
            'Red' => [200, 40, 40], 'Blue' => [40, 80, 200], 'Green' => [40, 160, 80],
            'Brown' => [120, 80, 40]
        ];
        $best = null; $bestD = PHP_INT_MAX;
        foreach ($palette as $name => [$pr,$pg,$pb]) {
            $d = ($r-$pr)**2 + ($g-$pg)**2 + ($b-$pb)**2;
            if ($d < $bestD) { $bestD = $d; $best = $name; }
        }
        return $best;
    }

    private function generateSku($color, $size) {
        $prefix = 'SKU';
        $rand = substr(strtoupper(bin2hex(random_bytes(3))), 0, 6);
        $parts = array_filter([$prefix, $color ? strtoupper(substr($color,0,2)) : null, $size]);
        return implode('-', $parts) . '-' . $rand;
    }

    private function checkDuplicateByHeuristics($name, $color, $size) {
        // Simple similarity search by name + attributes
        $sql = "SELECT p.product_id, p.name, v.variant_id, v.sku, v.color, v.size
                FROM products p
                LEFT JOIN product_variants v ON v.product_id = p.product_id
                WHERE p.name LIKE :kw
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $kw = '%' . ($name ?? '') . '%';
        $stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Fake similarity score
            $score = 0.90;
            if ($color && $row['color'] && strtolower($color) === strtolower($row['color'])) $score += 0.05;
            if ($size && $row['size'] && strtolower($size) === strtolower($row['size'])) $score += 0.05;
            // pick one image
            $img = null;
            $stmt2 = $this->db->prepare("SELECT file_path FROM product_images WHERE product_id = :pid ORDER BY is_primary DESC, image_id ASC LIMIT 1");
            $stmt2->bindValue(':pid', (int)$row['product_id'], PDO::PARAM_INT);
            $stmt2->execute();
            $imgRow = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($imgRow) $img = $imgRow['file_path'];
            return [
                'is_duplicate' => $score >= 0.95,
                'score' => $score,
                'matched' => $row + ['image' => $img]
            ];
        }
        return ['is_duplicate' => false, 'score' => 0.0, 'matched' => null];
    }
}
?>
