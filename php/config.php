<?php
// php/config.php
declare(strict_types=1);

/* ---------- Subdirectori ---------- */
define('APP_SUBDIR', '/');  // Canvia-ho a '/' si vas a l'arrel

/* ---------- BASE_PATH ---------- */
if (!function_exists('base_path')) {
  function base_path(): string {
    $p = trim((string)APP_SUBDIR, '/');
    return $p === '' ? '/' : ('/' . $p . '/');
  }
}
if (!defined('BASE_PATH')) {
  define('BASE_PATH', base_path());
}

/* ---------- TZ de l’aplicació (UI) ---------- */
/* Mostrem hores en Europe/Madrid a la interfície. 
   Per DB/cron fem servir UTC (ja uses UTC_TIMESTAMP() en diversos llocs). */
if (!defined('APP_TZ')) {
  define('APP_TZ', 'Europe/Madrid');
}
date_default_timezone_set(APP_TZ);

/* ---------- Sessió ---------- */
$cookiePath = rtrim((string)BASE_PATH, '/') . '/';
if (PHP_SAPI !== 'cli') {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => $cookiePath,                  // ← coherent amb BASE_PATH
      'domain'   => 'riders.kinosonik.com',       // mantinc el teu domini
      'secure'   => true,                         // prod en HTTPS
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }
}

/* ---------- Secret local (únic i compartit) ---------- */
/* Carrega la configuració sensible des del fitxer centralitzat,
   amb export automàtic de variables al medi ambient (getenv/$_ENV). 
   Això evita inconsistències entre /html i /html-dev. */
define('KS_SECRET_EXPORT_ENV', true);
require_once '/var/config/secure/riders/secret.local.php';
if (is_array($cfg ?? null)) {
  foreach ($cfg as $k => $v) {
    $_ENV[$k] = (string)$v;
    $_SERVER[$k] = (string)$v;
    @putenv("$k=$v");
  }
}


/* ---------- Pàgina 500 i directori de logs ---------- */
if (!defined('KS_ERROR_PAGE')) {
  define('KS_ERROR_PAGE', __DIR__ . '/errors/500.html');
}
if (!defined('KS_SECURE_LOG_DIR')) {
  define('KS_SECURE_LOG_DIR', '/var/config/logs/riders');
}
require_once __DIR__ . '/flash.php';

/* ---------- i18n ---------- */
require_once __DIR__ . '/i18n.php';
if (isset($_GET['lang'])) { set_lang($_GET['lang']); }

/* ---------- Versió web ---------- */
$GLOBALS['versio_web'] = $GLOBALS['versio_web'] ?? '0.79B01';

/* ---------- Sanitització de return ---------- */
if (!function_exists('sanitize_return_url')) {
  function sanitize_return_url(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('#^https?://#i', $raw)) {
      $cur = preg_replace('/^www\./','', strtolower($_SERVER['HTTP_HOST'] ?? ''));
      $ret = preg_replace('/^www\./','', strtolower(parse_url($raw, PHP_URL_HOST) ?? ''));
      if ($ret !== $cur) return '';
    }
    $u = parse_url($raw);
    $path = $u['path'] ?? '';
    $qs   = $u['query'] ?? '';
    if (preg_match('#/(index\.php|php/(login|registre|verify_email|logout)\.php)$#i', $path)) return '';
    if ($qs && stripos($qs, 'modal=login') !== false) return '';
    return $raw;
  }
}

/* ---------- Composer autoload ---------- */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) { require_once $autoload; }

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Helper d’escape ---------- */
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------- URL helpers ---------- */
if (!function_exists('origin_url')) {
  function origin_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
  }
}
if (!function_exists('url')) {
  function url(string $path = ''): string {
    $path = ltrim($path, '/');
    return origin_url() . BASE_PATH . $path;
  }
}
if (!function_exists('asset')) {
  function asset(string $rel): string {
    $rel = ltrim($rel, '/');
    return BASE_PATH . $rel;
  }
}
if (!function_exists('absolute_url')) {
  function absolute_url(string $path): string {
    if (preg_match('#^https?://#i', $path)) return $path;
    if (substr($path, 0, 1) === '/')   return origin_url() . $path;
    return origin_url() . BASE_PATH . ltrim($path, '/');
  }
}

/* ---------- Cookies auxiliars ---------- */
if (!function_exists('cookie_path')) {
  function cookie_path(): string { return rtrim((string)BASE_PATH, '/') . '/'; }
}
if (!function_exists('ks_set_login_modal_cookie')) {
  function ks_set_login_modal_cookie(array $payload): void {
    @setcookie('login_modal', json_encode($payload), [
      'expires'  => time() + 120,
      'path'     => cookie_path(),
      'domain'   => $_SERVER['HTTP_HOST'] ?? '',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
  }
}
if (!function_exists('ks_clear_login_modal_cookie')) {
  function ks_clear_login_modal_cookie(): void {
    @setcookie('login_modal', '', [
      'expires'  => time() - 3600,
      'path'     => cookie_path(),
      'domain'   => $_SERVER['HTTP_HOST'] ?? '',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
  }
}

/* ---------- Redireccions ---------- */
if (!function_exists('redirect_index')) {
  function redirect_index(array $qs = []): never {
    $url = absolute_url('index.php');
    if ($qs) $url .= (strpos($url,'?')===false?'?':'&') . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
    if (!headers_sent()) { header('Location: ' . $url); exit; }
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . h($url) . '"></noscript>';
    exit;
  }
}
if (!function_exists('redirect_to')) {
  function redirect_to(string $path = 'index.php', array $qs = []): never {
    $url = absolute_url($path);
    if ($qs) $url .= (strpos($url,'?')===false?'?':'&') . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
    if (!headers_sent()) { header('Location: ' . $url); exit; }
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . h($url) . '"></noscript>';
    exit;
  }
}

/* ---------- Rols ---------- */
if (!function_exists('current_user_type')) {
  function current_user_type(): string {
    return (string)($_SESSION['tipus_usuari'] ?? '');
  }
}
if (!function_exists('is_admin')) {
  function is_admin(): bool {
    return strcasecmp(current_user_type(), 'admin') === 0;
  }
}
if (!function_exists('require_admin')) {
  function require_admin(): void {
    if (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') !== 0) {
      if (function_exists('ks_render_error')) {
        ks_render_error(403, ['reason' => 'admin_required']);
      } else {
        http_response_code(403);
        if (is_file(__DIR__ . '/errors/403.php')) {
          require __DIR__ . '/errors/403.php';
        } else {
          echo '403 — Accés denegat';
        }
      }
      exit;
    }
  }
}
if (!function_exists('redirect_flash')) {
  function redirect_flash(string $path = 'index.php', array $flash = []): never {
    if ($flash) { flash_set($flash['type'] ?? 'info', $flash['key'] ?? 'default', $flash['extra'] ?? []); }
    $url = absolute_url($path);
    if (!headers_sent()) { header('Location: ' . $url); exit; }
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . h($url) . '"></noscript>';
    exit;
  }
}

/* ---------- Error reporting ---------- */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
  // En CLI no intentem tocar capçaleres ni HTML
  if (PHP_SAPI === 'cli') {
    $line = "[PHP $errno] $errstr in $errfile:$errline";
    // Log
    ks_log($line);
    // Mostra a STDERR per facilitar el debug de comandes
    fwrite(STDERR, $line . PHP_EOL);
    // Retorna false per deixar que PHP segueixi el flux normal si cal
    return false;
  }
  ks_log("PHP $errno: $errstr in $errfile:$errline");
  return false;
}, E_ALL);

set_exception_handler(function($e) {
  try { $errId = bin2hex(random_bytes(4)); } catch (Throwable $t) {
    $errId = strtoupper(dechex(time() & 0xFFFF));
  }
  ks_log('EXCEPTION[' . $errId . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

  // 📌 Branca CLI: no enviem capçaleres ni HTML; escrivim a STDERR i sortim amb codi 1
  if (PHP_SAPI === 'cli') {
    $msg  = '[EXCEPTION ' . $errId . '] ' . $e->getMessage()
         . ' in ' . $e->getFile() . ':' . $e->getLine();
    fwrite(STDERR, $msg . PHP_EOL);
    // Opcional: mostrar trace breu
    if (function_exists('getenv') && getenv('KS_CLI_TRACE')) {
      fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
  }

  http_response_code(500);
  header('Content-Type: text/html; charset=utf-8');
  $page = @file_get_contents(KS_ERROR_PAGE);
  if ($page !== false) {
    $home = absolute_url('index.php');
    $page = str_replace(['{{ERROR_ID}}','{{HOME_URL}}'], [$errId, $home], $page);
    echo $page;
  } else {
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>';
    echo '<h1>Error intern</h1><p>Codi d’incidència: ' . htmlspecialchars($errId, ENT_QUOTES, 'UTF-8') . '</p>';
  }
  exit;
});

/* ---------- Logger ---------- */
if (!function_exists('ks_log')) {
  function ks_log(string $msg): void {
    $preferred = KS_SECURE_LOG_DIR;
    $dir = $preferred;

    $useSecure = false;
    if (is_dir($preferred)) {
      $useSecure = is_writable($preferred);
    } else {
      $parent = dirname($preferred);
      if (is_dir($parent) && is_writable($parent)) {
        @mkdir($preferred, 0700, true);
        $useSecure = is_dir($preferred) && is_writable($preferred);
      }
    }
    if (!$useSecure) {
      $dir = __DIR__ . '/logs';
      if (!is_dir($dir) && is_writable(dirname($dir))) {
        @mkdir($dir, 0755, true);
      }
    }

    $file = rtrim($dir, '/').'/php-error.log';
    // Logs en UTC per correlacionar amb DB/worker
    $line = '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND);
  }
}

if (!function_exists('ks_redirect')) {
  function ks_redirect(string $url, int $code = 302): never {
    if (!headers_sent()) {
      header('Location: ' . $url, true, $code);
      exit;
    }
    // fallback visual si ja s'ha enviat sortida
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
    exit;
  }
}