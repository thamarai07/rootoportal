<?php
require_once __DIR__ . '/env.php';

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function generateJWT(array $payload): string {
    $secret  = env('JWT_SECRET', 'change-this-secret');
    $header  = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = base64UrlEncode(json_encode($payload));
    $sig     = base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}

function validateJWT(string $token): ?array {
    $secret = env('JWT_SECRET', 'change-this-secret');
    $parts  = explode('.', $token);

    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;

    $validSig = base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));
    if (!hash_equals($validSig, $sig)) return null;

    $data = json_decode(base64UrlDecode($payload), true);

    // Check expiry
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function setAuthCookie(string $token): void {
    $isProduction = env('APP_ENV', 'production') === 'production';
    
    setcookie('auth_token', $token, [
        'expires'  => time() + (7 * 24 * 60 * 60), // 7 days
        'path'     => '/',
        'domain'   => $isProduction ? '.rooto.in' : '',
        'secure'   => $isProduction,
        'httponly' => true,
        'samesite' => $isProduction ? 'None' : 'Lax'
    ]);
}

function clearAuthCookie(): void {
    setcookie('auth_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '.rooto.in',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
}

function getAuthUser(): ?array {
    $token = $_COOKIE['auth_token'] ?? null;
    if (!$token) return null;
    return validateJWT($token);
}