<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/auth_user.php';
header("Content-Type: application/json");

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Hybrid Auth: JWT cookie first, fallback to request params
$user_id = getAuthenticatedUserId();

if (!$user_id) {
    if (in_array($method, ['POST', 'PUT'])) {
        $input   = json_decode(file_get_contents('php://input'), true);
        $user_id = (int) ($input['user_id'] ?? 0) ?: null;
    } else {
        $user_id = (int) ($_GET['user_id'] ?? 0) ?: null;
    }
}

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Please login to continue"]);
    exit;
}

$baseUrl = env('IMAGE_BASE_URL');

try {
    switch ($method) {

        case 'GET':
            $stmt = $conn->prepare("
                SELECT
                    c.id as cart_id,
                    p.id,
                    p.name,
                    p.slug,
                    p.price_per_kg as price,
                    p.image,
                    p.category,
                    p.stock,
                    c.quantity,
                    c.status,
                    c.last_added_at,
                    (c.quantity * p.price_per_kg) as subtotal
                FROM cart c
                INNER JOIN products p ON c.product_id = p.id
                WHERE c.session_id = ?
                AND c.status = 'active'
                ORDER BY c.last_added_at DESC
            ");
            $stmt->execute(['user_' . $user_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = 0;
            foreach ($items as &$item) {
                if (!empty($item['image'])) {
                    $images = explode(',', $item['image']);
                    $item['image'] = $baseUrl . trim($images[0]);
                } else {
                    $item['image'] = "https://placehold.co/200x200?text=No+Image";
                }
                $item['id']       = (int)   $item['id'];
                $item['cart_id']  = (int)   $item['cart_id'];
                $item['price']    = (float) $item['price'];
                $item['quantity'] = (float) $item['quantity'];
                $item['subtotal'] = (float) $item['subtotal'];
                $item['stock']    = (int)   $item['stock'];
                $total += $item['subtotal'];
            }
            unset($item);

            echo json_encode([
                "status"  => "success",
                "data"    => $items,
                "count"   => count($items),
                "total"   => round($total, 2),
                "user_id" => $user_id
            ]);
            break;

        case 'POST':
            $input      = json_decode(file_get_contents('php://input'), true);
            $product_id = (int)   ($input['product_id'] ?? 0);
            $quantity   = (float) ($input['quantity']   ?? 0.25);
            $last_added = $input['last_added_at'] ?? date('Y-m-d H:i:s');

            if ($product_id <= 0 || $quantity <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Invalid data"]);
                exit;
            }

            $stockCheck = $conn->prepare("SELECT stock, name FROM products WHERE id = ?");
            $stockCheck->execute([$product_id]);
            $product = $stockCheck->fetch();

            if (!$product) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Product not found"]);
                exit;
            }

            if ($product['stock'] < $quantity) {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "message" => "Insufficient stock. Only {$product['stock']} kg available"
                ]);
                exit;
            }

            $session_id = 'user_' . $user_id;

            $checkStmt = $conn->prepare("
                SELECT id, quantity FROM cart
                WHERE session_id = ? AND product_id = ? AND status = 'active'
            ");
            $checkStmt->execute([$session_id, $product_id]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                $newQty = $existing['quantity'] + $quantity;

                if ($newQty > $product['stock']) {
                    http_response_code(400);
                    echo json_encode([
                        "status"  => "error",
                        "message" => "Cannot add more. Total would exceed available stock"
                    ]);
                    exit;
                }

                $conn->prepare("
                    UPDATE cart SET quantity = ?, last_added_at = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$newQty, $last_added, $existing['id']]);

                echo json_encode([
                    "status"   => "success",
                    "message"  => "Cart updated successfully",
                    "action"   => "updated",
                    "quantity" => $newQty,
                    "user_id"  => $user_id
                ]);
            } else {
                $conn->prepare("
                    INSERT INTO cart (session_id, product_id, quantity, status, last_added_at, created_at, updated_at)
                    VALUES (?, ?, ?, 'active', ?, NOW(), NOW())
                ")->execute([$session_id, $product_id, $quantity, $last_added]);

                echo json_encode([
                    "status"   => "success",
                    "message"  => "Added to cart successfully",
                    "action"   => "added",
                    "cart_id"  => $conn->lastInsertId(),
                    "user_id"  => $user_id
                ]);
            }
            break;

        case 'PUT':
            $input      = json_decode(file_get_contents('php://input'), true);
            $product_id = (int)   ($input['product_id'] ?? 0);
            $quantity   = (float) ($input['quantity']   ?? 0);
            $last_added = $input['last_added_at'] ?? date('Y-m-d H:i:s');

            if ($quantity <= 0) {
                $conn->prepare("
                    DELETE FROM cart
                    WHERE session_id = ? AND product_id = ? AND status = 'active'
                ")->execute(['user_' . $user_id, $product_id]);

                echo json_encode(["status" => "success", "message" => "Item removed"]);
                exit;
            }

            $stockCheck = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $stockCheck->execute([$product_id]);
            $product = $stockCheck->fetch();

            if ($quantity > $product['stock']) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Quantity exceeds available stock"]);
                exit;
            }

            $conn->prepare("
                UPDATE cart SET quantity = ?, last_added_at = ?, updated_at = NOW()
                WHERE session_id = ? AND product_id = ? AND status = 'active'
            ")->execute([$quantity, $last_added, 'user_' . $user_id, $product_id]);

            echo json_encode(["status" => "success", "message" => "Quantity updated"]);
            break;

        case 'DELETE':
            $product_id = (int) ($_GET['product_id'] ?? 0);

            $conn->prepare("
                DELETE FROM cart
                WHERE session_id = ? AND product_id = ? AND status = 'active'
            ")->execute(['user_' . $user_id, $product_id]);

            echo json_encode(["status" => "success", "message" => "Removed from cart"]);
            break;

        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    error_log("Cart API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "An error occurred. Please try again."]);
}