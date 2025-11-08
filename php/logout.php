<?php
// php/logout.php — Tancament de sessió segur
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php'; // ✅ per registrar auditoria

/* Només accepta POST */
if (!function_exists('is_post') || !is_post()) {
  http_response_code(405); // Method Not Allowed
  exit;
}

/* CSRF obligatori (ja valida el token internament) */
if (function_exists('csrf_check_or_die')) {
  csrf_check_or_die();
} else {
  // Fallback minimalista per si no hi és (evitem dependències trencades)
  $csrf = $_POST['csrf'] ?? '';
  if (!is_string($csrf) || $csrf === '' || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    http_response_code(403);
    exit;
  }
}

/* Sessió activa garantida */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ─────────────────── Auditoria (abans de buidar sessió) ─────────────────── */
try {
  // Preparem dades d'usuari actuals abans de netejar la sessió
  $uid     = (int)($_SESSION['user_id'] ?? 0);
  $isAdmin = (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0);

  // Obtenim PDO via db() si existeix (no sempre cal, però sovint el projecte ja el té)
  if (!function_exists('db')) {
    require_once __DIR__ . '/db.php';
  }
  $pdo = db();

  audit_admin(
    $pdo,
    $uid,
    $isAdmin,
    'logout_success',
    null,
    null,
    'auth',
    [],          // meta opcional (buit)
    'success',
    null
  );
} catch (Throwable $e) {
  // No trenquis mai el logout per culpa de l’auditoria
  error_log('audit logout_success failed: ' . $e->getMessage());
}

/* Anti-fixació: canvia l'ID abans de destruir */
@session_regenerate_id(true);

/* Buida estat de sessió */
$_SESSION = [];
@session_unset();

/* Esborra la cookie de sessió amb els paràmetres actuals + un fallback a "/" */
if (ini_get('session.use_cookies')) {
  $params   = session_get_cookie_params();
  $sessName = session_name();

  // Esborrat amb els mateixos paràmetres que la sessió
  @setcookie(
    $sessName,
    '',
    [
      'expires'  => time() - 42000,
      'path'     => $params['path']     ?? '/',
      'domain'   => $params['domain']   ?? '',
      'secure'   => (bool)($params['secure'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')),
      'httponly' => (bool)($params['httponly'] ?? true),
      // SameSite: si es definia per ini, PHP 8.3 el respecta; no el forcem aquí.
    ]
  );

  // Fallback addicional a "/" per si el path de la sessió era massa específic
  if (($params['path'] ?? '/') !== '/') {
    @setcookie(
      $sessName,
      '',
      [
        'expires'  => time() - 42000,
        'path'     => '/',
        'domain'   => $params['domain']   ?? '',
        'secure'   => (bool)($params['secure'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')),
        'httponly' => (bool)($params['httponly'] ?? true),
      ]
    );
  }
}

/* Destrueix la sessió al servidor */
@session_destroy();

/* Redirecció a l'índex de l’app */
if (function_exists('redirect_index')) {
  redirect_index();
  exit;
}

// Fallback ultra simple si no existeix redirect_index()
$host   = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = defined('APP_SUBDIR') ? (string)APP_SUBDIR : '/';
$target = rtrim($base, '/') . '/';
header("Location: {$scheme}://{$host}{$target}", true, 303);
exit;