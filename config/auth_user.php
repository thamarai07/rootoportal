<?php
require_once __DIR__ . '/jwt.php';

/**
 * Returns authenticated user_id from JWT cookie.
 * Falls back to query param user_id ONLY in dev mode.
 * In production, always requires valid JWT.
 */
function getAuthenticatedUserId(): ?int {
    // Try JWT cookie first (secure)
    $authUser = getAuthUser();
    if ($authUser && isset($authUser['user_id'])) {
        return (int) $authUser['user_id'];
    }

    // Never fall back to query param in production
    return null;
}