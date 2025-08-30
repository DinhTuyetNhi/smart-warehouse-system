<?php
class Product {
    private $conn;
    private $table = 'products';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create(array $data) {
        $sql = "INSERT INTO products (category_id, name, description, created_at) VALUES (:category_id, :name, :description, NOW())";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':category_id', $data['category_id'] ?? null, $data['category_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':name', trim($data['name'] ?? ''));
        $stmt->bindValue(':description', $data['description'] ?? null);
        $stmt->execute();
        return (int)$this->conn->lastInsertId();
    }

    public function findSimilarByName($name, $limit = 5) {
        // Simple LIKE search as a fallback when AI unavailable
        $sql = "SELECT p.product_id, p.name, v.variant_id, v.sku, v.color, v.size
                FROM products p
                LEFT JOIN product_variants v ON v.product_id = p.product_id
                WHERE p.name LIKE :kw
                LIMIT :lim";
        $stmt = $this->conn->prepare($sql);
        $kw = '%' . trim($name) . '%';
        $stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
        $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
