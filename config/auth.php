<?php
require_once __DIR__ . '/jwt.php';

function requireAuth(): array {
    $user = getAuthUser();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Unauthorized. Please login.'
        ]);
        exit();
    }
    
    return $user;
}