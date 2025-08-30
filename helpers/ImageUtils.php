<?php
/**
 * ImageUtils: Validate and process product images
 * - Validate extensions, size, count, resolution, aspect ratio, filename safety
 * - Resize and compress
 * - Strip EXIF by re-encoding
 */
class ImageUtils {
    const ALLOWED_EXT = ['jpg','jpeg','png','webp'];
    const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5MB
    const MIN_DIM = 800; // px
    const MIN_COUNT = 2;
    const MAX_COUNT = 4;

    public static function sanitizeFileName($name) {
        $name = preg_replace('/\s+/', '_', $name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
        return $name;
    }

    public static function hasSpecialChars($name) {
        // Allow only letters, numbers, dot, dash, underscore
        return preg_match('/[^A-Za-z0-9._-]/', $name) === 1;
    }

    public static function validateUploadBatch(array $files): array {
        // $files is the typical $_FILES['images'] array
        $errors = [];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
            $errors[] = 'Số lượng ảnh sản phẩm phải từ 2 đến 4';
        }

        for ($i = 0; $i < $count; $i++) {
            $name = $files['name'][$i] ?? '';
            $size = $files['size'][$i] ?? 0;
            $tmp  = $files['tmp_name'][$i] ?? '';
            $err  = $files['error'][$i] ?? UPLOAD_ERR_OK;

            if ($err !== UPLOAD_ERR_OK) {
                $errors[] = "Lỗi upload ảnh #" . ($i+1);
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXT)) {
                $errors[] = 'Ảnh sản phẩm phải ở định dạng JPG, PNG, JPEG hoặc WEBP';
            }

            if ($size > self::MAX_SIZE_BYTES) {
                $errors[] = 'Dung lượng ảnh tối đa 5MB';
            }

            if (self::hasSpecialChars($name)) {
                $errors[] = 'Tên file ảnh không được chứa ký tự đặc biệt';
            }

            if (is_uploaded_file($tmp)) {
                $dim = @getimagesize($tmp);
                if (!$dim) {
                    $errors[] = 'Không thể đọc kích thước ảnh';
                } else {
                    [$w, $h] = $dim;
                    if ($w < self::MIN_DIM || $h < self::MIN_DIM) {
                        $errors[] = 'Kích thước ảnh tối thiểu 800×800 px';
                    }
                    $ratio = $w > 0 && $h > 0 ? max($w,$h)/min($w,$h) : 0; // 1.0 for square
                    // Accept ~1:1 or 4:5 (ratio 1.25). Allow small tolerance
                    if (!self::ratioOk($w,$h)) {
                        $errors[] = 'Tỷ lệ ảnh phải xấp xỉ 1:1 hoặc 4:5';
                    }
                }
            }
        }

        return $errors;
    }

    public static function ratioOk(int $w, int $h): bool {
        if ($w <= 0 || $h <= 0) return false;
        $r1 = $w / $h; // width to height
        $r2 = $h / $w; // height to width
        $approx = function($r, $target, $tol = 0.06) { // 6% tolerance
            return abs($r - $target) <= $tol;
        };
        // 1:1 ~ 1.0, 4:5 is 0.8 (w/h) or 1.25 (h/w)
        return $approx($r1, 1.0) || $approx($r1, 0.8) || $approx($r2, 1.25);
    }

    public static function ensureDir($path) {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }

    public static function processAndSave($tmpPath, $destPath, $extPreferred = null): array {
        if (!function_exists('imagecreatetruecolor')) {
            // Fallback: copy original to destination without EXIF stripping
            $destPath = self::withExtension($destPath, $extPreferred ?: pathinfo($destPath, PATHINFO_EXTENSION) ?: 'jpg');
            if (@copy($tmpPath, $destPath)) {
                $dim = @getimagesize($destPath);
                $w = $dim[0] ?? null; $h = $dim[1] ?? null;
                return [true, 'OK_NOGD', $destPath, $w, $h];
            }
            return [false, 'Máy chủ thiếu thư viện GD để xử lý ảnh', null, null, null];
        }
        // Returns [success(bool), msg, outPath, width, height]
        $info = @getimagesize($tmpPath);
        if (!$info) return [false, 'Ảnh không hợp lệ', null, null, null];
        [$w,$h,$type] = $info;

        switch ($type) {
            case IMAGETYPE_JPEG:
                $src = @imagecreatefromjpeg($tmpPath);
                $ext = 'jpg';
                break;
            case IMAGETYPE_PNG:
                $src = @imagecreatefrompng($tmpPath);
                $ext = 'png';
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $src = @imagecreatefromwebp($tmpPath);
                    $ext = 'webp';
                } else {
                    // Fallback: try as jpeg
                    $src = @imagecreatefromjpeg($tmpPath);
                    $ext = 'jpg';
                }
                break;
            default:
                return [false, 'Định dạng ảnh không hỗ trợ', null, null, null];
        }
        if (!$src) return [false, 'Không thể đọc ảnh', null, null, null];

        // Resize if larger than 1600 on the longer side
        $maxSide = 1600;
        $scale = max($w,$h) > $maxSide ? $maxSide / max($w,$h) : 1.0;
        $newW = (int)round($w * $scale);
        $newH = (int)round($h * $scale);
        $dst = imagecreatetruecolor($newW, $newH);

        // Keep transparency for PNG/WebP
        if (in_array($ext, ['png','webp'])) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0,0,0,0, $newW,$newH, $w,$h);

        // Decide output ext
        $outExt = $extPreferred ?: $ext;
        $destPath = self::withExtension($destPath, $outExt);

        // Save with compression
        $ok = false;
        if ($outExt === 'jpg' || $outExt === 'jpeg') {
            $ok = imagejpeg($dst, $destPath, 82); // quality 82
        } elseif ($outExt === 'png') {
            $ok = imagepng($dst, $destPath, 6); // 0-9
        } elseif ($outExt === 'webp' && function_exists('imagewebp')) {
            $ok = imagewebp($dst, $destPath, 82);
        } else {
            $ok = imagejpeg($dst, $destPath, 82);
        }

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) return [false, 'Không thể lưu ảnh xử lý', null, null, null];
        return [true, 'OK', $destPath, $newW, $newH];
    }

    private static function withExtension($path, $ext) {
        $ext = strtolower($ext);
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $path) . '.' . $ext;
    }
}
?>
