<?php
// config.php - versión demo CV sin credenciales MySQL.
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    // Base de datos local SQLite para la demo.
    // Se crea automáticamente en esta misma carpeta y NO toca la base real.
    $dbFile = __DIR__ . '/camionetas_cv_demo.sqlite';
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (Throwable $e) {
    http_response_code(500);
    die('Error fatal de conexión demo: ' . $e->getMessage());
}
