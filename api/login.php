<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/jwt.php';  // ← jwt functions come from here
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// ----------------------------------------------------------------
// DELETE THESE — they're already in config/jwt.php
// ----------------------------------------------------------------
// function base64UrlEncode(string $data): string { ... }  ← DELETE
// function generateJWT(array $payload, string $secret): string { ... }  ← DELETE

// ----------------------------------------------------------------
// reCAPTCHA Verification
// ----------------------------------------------------------------
function verifyRecaptcha(string $token, string $expectedAction): array {
    $secretKey = env('RECAPTCHA_SECRET_KEY', '');

    $response = file_get_contents(
        "https://www.google.com/recaptcha/api/siteverify?secret={$secretKey}&response={$token}"
    );

    if (!$response) {
        return ["success" => false, "message" => "Failed to contact reCAPTCHA server"];
    }

    $data = json_decode($response, true);

    if (!($data['success'] ?? false)) {
        return ["success" => false, "message" => "reCAPTCHA verification failed"];
    }

    if (($data['score'] ?? 0) < 0.5) {
        return ["success" => false, "message" => "Suspicious behavior detected"];
    }

    return ["success" => true, "score" => $data['score'] ?? 0];
}

// ----------------------------------------------------------------
// Read Input
// ----------------------------------------------------------------
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['email'], $data['password'], $data['captcha_token'])) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit();
}

$email    = trim($data['email']);
$password = $data['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Invalid email"]);
    exit();
}

// Captcha Check
$captcha = verifyRecaptcha($data['captcha_token'], "login");
if (!$captcha["success"]) {
    echo json_encode(["status" => "error", "message" => $captcha["message"]]);
    exit();
}

// Fetch User
try {
    $stmt = $conn->prepare("
        SELECT id, name, email, phone, password_hash
        FROM customers WHERE email = :email
    ");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(["status" => "error", "message" => "Invalid email or password"]);
        exit();
    }

    // Generate JWT & set cookie
    $payload = [
        "user_id" => $user["id"],
        "email"   => $user["email"],
        "name"    => $user["name"],
        "iat"     => time(),
        "exp"     => time() + (7 * 24 * 60 * 60)
    ];

    $jwt = generateJWT($payload);  // ← no need to pass $secret, jwt.php handles it
    setAuthCookie($jwt);           // ← sets HttpOnly cookie

    echo json_encode([
        "status" => "success",
        "user"   => [
            "id"    => $user["id"],
            "name"  => $user["name"],
            "email" => $user["email"],
            "phone" => $user["phone"]
        ]
    ]);

} catch (PDOException $e) {
    error_log("Login API DB Error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "An error occurred. Please try again."]);
}

$conn = null;