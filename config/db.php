<?php
/**
 * Database Configuration
 * Reads credentials from .env – no hardcoded values here.
 */

require_once __DIR__ . '/env.php';

$dbHost = env(key: 'DB_HOST', default: 'localhost');
$dbName = env(key: 'DB_NAME', default: 'vfsportal');
$dbUser = env(key: 'DB_USER', default: 'root');
$dbPass = env(key: 'DB_PASS', default: '');
$dbPort = env(key: 'DB_PORT', default: '3306');

try {
    $conn = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());

    if ($isDevMode) {
        // Only show details in development
        die(json_encode([
            'status'  => 'error',
            'message' => 'Database connection failed: ' . $e->getMessage()
        ]));
    }

    // In production – safe generic message only
    header('Content-Type: application/json');
    die(json_encode([
        'status'  => 'error',
        'message' => 'Service temporarily unavailable. Please try again later.'
    ]));
}
