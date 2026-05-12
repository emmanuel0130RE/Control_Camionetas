<?php
// config.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Carga credenciales desde una de estas fuentes, en este orden:
 * 1) Variables de entorno
 * 2) ./config.credentials.php   (este módulo de pruebas)
 * 3) ../config.credentials.php  (respaldo fuera de la carpeta)
 */
function load_db_config(): array
{
    $envHost = getenv('TOSTIREG_DB_HOST') ?: '';
    $envName = getenv('TOSTIREG_DB_NAME') ?: '';
    $envUser = getenv('TOSTIREG_DB_USER') ?: '';
    $envPass = getenv('TOSTIREG_DB_PASS') ?: '';

    if ($envHost !== '' && $envName !== '' && $envUser !== '') {
        return [
            'host'     => $envHost,
            'dbname'   => $envName,
            'username' => $envUser,
            'password' => $envPass,
        ];
    }

    $candidates = [
        // Para camionetas usamos primero las credenciales de esta carpeta,
        // así no toma por accidente la BD principal del sitio.
        __DIR__ . '/config.credentials.php',
        dirname(__DIR__) . '/config.credentials.php',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $config = require $candidate;
        if (!is_array($config)) {
            throw new RuntimeException('El archivo de credenciales no regresó un arreglo válido.');
        }

        $host = trim((string)($config['host'] ?? ''));
        $name = trim((string)($config['dbname'] ?? ''));
        $user = trim((string)($config['username'] ?? ''));

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('Faltan datos obligatorios en config.credentials.php');
        }

        return [
            'host'     => $host,
            'dbname'   => $name,
            'username' => $user,
            'password' => (string)($config['password'] ?? ''),
        ];
    }

    throw new RuntimeException(
        'No se encontraron credenciales de base de datos. ' .
        'Agrega config.credentials.php o define variables de entorno TOSTIREG_DB_*.'
    );
}

try {
    $db = load_db_config();

    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['dbname']),
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Seguridad para pruebas: este módulo debe trabajar en EmmaBd, no en la BD principal.
    $expectedDb = 'u650771697_EmmaBd';
    $currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($currentDb !== $expectedDb) {
        throw new RuntimeException(
            'Conexión incorrecta: camionetas está conectado a "' . $currentDb .
            '" y debe conectarse a "' . $expectedDb . '". Revisa config.credentials.php.'
        );
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Error fatal de conexión: ' . $e->getMessage());
}
