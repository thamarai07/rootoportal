<?php
require_once __DIR__ . '/jwt.php';

function getAuthenticatedUserId(): ?int {
    // DEBUG — remove after fixing
    error_log("=== Auth Debug ===");
    error_log("Cookies received: " . json_encode($_COOKIE));
    error_log("auth_token exists: " . (isset($_COOKIE['auth_token']) ? 'YES' : 'NO'));
    error_log("Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'none'));

    $authUser = getAuthUser();
    if ($authUser && isset($authUser['user_id'])) {
        error_log("User ID from JWT: " . $authUser['user_id']);
        return (int) $authUser['user_id'];
    }

    error_log("No valid JWT found");
    return null;
}