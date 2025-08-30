<?php
class ProductVariant {
    private $conn;
    private $table = 'product_variants';
    private $imageTable = 'product_images';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function createVariant(array $data) {
        $sql = "INSERT INTO product_variants (product_id, sku, color, size, price, created_at, updated_at)
                VALUES (:product_id, :sku, :color, :size, :price, NOW(), NOW())";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':product_id', (int)$data['product_id'], PDO::PARAM_INT);
        $stmt->bindValue(':sku', trim($data['sku']));
        $stmt->bindValue(':color', $data['color'] ?? null);
        $stmt->bindValue(':size', $data['size'] ?? null);
        $stmt->bindValue(':price', (float)($data['price'] ?? 0));
        $stmt->execute();
        return (int)$this->conn->lastInsertId();
    }

    public function addImage($productId, $variantId, $filePath, $isPrimary = 0) {
        $sql = "INSERT INTO {$this->imageTable} (product_id, variant_id, file_path, is_primary, created_at)
                VALUES (:product_id, :variant_id, :file_path, :is_primary, NOW())";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
        $stmt->bindValue(':variant_id', $variantId ? (int)$variantId : null, $variantId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':file_path', $filePath);
        $stmt->bindValue(':is_primary', (int)$isPrimary, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$this->conn->lastInsertId();
    }
}
?>
