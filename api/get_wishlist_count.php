<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/auth_user.php';
header("Content-Type: application/json");
require_once __DIR__ . '/../config/db.php';

// Hybrid Auth: JWT cookie first, fallback to query param
$user_id = getAuthenticatedUserId();

if (!$user_id) {
    $user_id = (int) ($_GET['user_id'] ?? 0) ?: null;
}

if (!$user_id) {
    echo json_encode(["status" => "success", "count" => 0]);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM favorites
        WHERE user_id = ? AND status = 'active'
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "count"  => (int) $result['count']
    ]);
} catch (Exception $e) {
    error_log("get_wishlist_count Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "count" => 0]);
}