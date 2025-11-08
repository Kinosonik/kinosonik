<?php
declare(strict_types=1);

// No facis new PDO aquí a nivell de fitxer.
// Centralitza la connexió dins de db() i reutilitza-la via static.

if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
      return $pdo;
    }

    // Carrega config (secret.php ja omple $_ENV)
    require_once __DIR__ . '/config.php';

    $DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
    $DB_NAME = $_ENV['DB_NAME'] ?? '';
    $DB_USER = $_ENV['DB_USER'] ?? '';
    $DB_PASS = $_ENV['DB_PASS'] ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$charset}";
    $opt = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $opt);
    // Sessió de connexió coherent
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
  }
}