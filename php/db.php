<?php
declare(strict_types=1);

if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $SECRETS = require '/var/config/secure/riders/secret.local.php';

    $DB_HOST = $SECRETS['DB_HOST'] ?? 'localhost';
    $DB_NAME = $SECRETS['DB_NAME'] ?? '';
    $DB_USER = $SECRETS['DB_USER'] ?? '';
    $DB_PASS = $SECRETS['DB_PASS'] ?? '';
    $charset = $SECRETS['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$charset}";
    $opt = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $opt);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+00:00'");

    // 🔍 Traça de depuració
    if (function_exists('ks_log')) {
      ks_log('[debug-db] Using DB: ' . ($DB_NAME ?: '(undefined)'));
    } else {
      error_log('[debug-db] Using DB: ' . ($DB_NAME ?: '(undefined)'));
    }

    return $pdo;
  }
}
