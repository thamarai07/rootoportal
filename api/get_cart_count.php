<?php
/**
 * Get Cart Count API - USER-SPECIFIC
 * Returns the count of ACTIVE cart items for a specific user
 */

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/auth_user.php'; 
header("Content-Type: application/json");


require_once __DIR__ . '/../config/db.php';


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed", "count" => 0]);
    exit;
}


$user_id = getAuthenticatedUserId();

if (!$user_id) {
    echo json_encode(["status" => "success", "count" => 0]);
    exit;
}


try {
    $session_id = 'user_' . $user_id;

    $stmt = $conn->prepare("
        SELECT COUNT(*) as count,
               COALESCE(SUM(c.quantity * p.price_per_kg), 0) as total_value
        FROM cart c
        LEFT JOIN products p ON c.product_id = p.id
        WHERE c.session_id = ? AND c.status = 'active'
    ");
    $stmt->execute([$session_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"      => "success",
        "count"       => (int) $result['count'],
        "total_value" => round((float) $result['total_value'], 2)
    ]);

} catch (Exception $e) {
    error_log("get_cart_count Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "count" => 0]);
} catch (PDOException $e) {
    // Database errors
    error_log("❌ Database error in get_cart_count: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error",
        "count" => 0
    ]);
    
}
?>