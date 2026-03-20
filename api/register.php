<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// ----------------------------------------------------------------
// reCAPTCHA Enterprise Verification
// ----------------------------------------------------------------
function verifyRecaptcha(string $token, string $expectedAction): array {
    $secretKey = env('RECAPTCHA_SECRET_KEY', '');

    // DEBUG - remove after fixing
    error_log("=== reCAPTCHA Debug ===");
    error_log("Secret key loaded: " . (empty($secretKey) ? "EMPTY - NOT SET!" : "OK (length: " . strlen($secretKey) . ")"));
    error_log("Token received: " . (empty($token) ? "EMPTY!" : substr($token, 0, 30) . "..."));

    $url = "https://www.google.com/recaptcha/api/siteverify?secret={$secretKey}&response={$token}";
    
    $response = file_get_contents($url);

    error_log("Google response: " . $response);

    if (!$response) {
        return ["success" => false, "message" => "Failed to contact reCAPTCHA server"];
    }

    $data = json_decode($response, true);

    // Return error-codes in response temporarily
    if (!($data['success'] ?? false)) {
        return [
            "success"     => false,
            "message"     => "reCAPTCHA verification failed",
            "debug"       => [
                "error_codes"  => $data['error-codes'] ?? [],
                "secret_empty" => empty($secretKey),
                "token_length" => strlen($token),
                "google_said"  => $data
            ]
        ];
    }

    if (($data['score'] ?? 0) < 0.5) {
        return ["success" => false, "message" => "Suspicious behavior detected", "score" => $data['score']];
    }

    return ["success" => true, "score" => $data['score'] ?? 0];
}
// ----------------------------------------------------------------
// Read Input
// ----------------------------------------------------------------
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['name'], $data['email'], $data['phone'], $data['password'], $data['captcha_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit();
}

// Verify Captcha
$captcha = verifyRecaptcha($data['captcha_token'], "signup");
if (!$captcha["success"]) {
    echo json_encode([
        "status"  => "error",
        "message" => $captcha["message"],
        "debug"   => $captcha["debug"] ?? null  // ← add this
    ]);
    exit();
}

// Sanitize inputs
$name     = trim($data['name']);
$email    = trim($data['email']);
$phone    = trim($data['phone']);
$password = $data['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
    exit();
}

if (!preg_match('/^\d{10,15}$/', $phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid phone']);
    exit();
}

if (strlen($password) < 8) {
    echo json_encode(['status' => 'error', 'message' => 'Password too short']);
    exit();
}

$password_hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $stmt = $conn->prepare("
        INSERT INTO customers (name, email, phone, password_hash)
        VALUES (:name, :email, :phone, :password_hash)
    ");
    $stmt->bindParam(':name',          $name);
    $stmt->bindParam(':email',         $email);
    $stmt->bindParam(':phone',         $phone);
    $stmt->bindParam(':password_hash', $password_hash);

    if ($stmt->execute()) {
        echo json_encode([
            "status"        => "success",
            "user"          => [
                "id"    => $conn->lastInsertId(),
                "name"  => $name,
                "email" => $email,
                "phone" => $phone
            ],
            "captcha_score" => $captcha["score"]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Registration failed. Please try again."]);
    }
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(["status" => "error", "message" => "Email or phone already exists"]);
    } else {
        error_log("Register API DB Error: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "An error occurred. Please try again."]);
    }
}

$conn = null;