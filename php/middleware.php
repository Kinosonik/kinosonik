<?php
// php/middleware.php
declare(strict_types=1);

/* ---------- Request ID (per correlació) ---------- */
if (!function_exists('ks_request_id')) {
  function ks_request_id(): string {
    static $rid = null;
    if ($rid) return $rid;
    $data = random_bytes(16);                 // UUID v4 light
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return $rid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }
}

/**
 * Sessió:
 * - La configuració principal de cookies està a php/config.php
 * - Aquí només s'assegura que estigui oberta (només en entorn web)
 */
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

/* ---------- Utils d'error pages ---------- */
if (!function_exists('ks_is_dev_ip')) {
  function ks_is_dev_ip(): bool {
    $dev_ips = ['127.0.0.1', '::1', '192.168.', '10.0.'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach ($dev_ips as $p) { if (str_starts_with($ip, $p)) return true; }
    return false;
  }
}
if (!function_exists('ks_render_error')) {
  function ks_render_error(int $code, ?array $details = null): void {
    if (PHP_SAPI === 'cli') { // en CLI, no forcem capçaleres ni plantilles
      echo "ERROR $code\n";
      if ($details) { echo print_r($details, true); }
      return;
    }

    http_response_code($code);
    $isDev = ks_is_dev_ip();
    if ($isDev && $details) {
      echo "<pre style='color:#ff6;white-space:pre-wrap'>⚠️ ERROR $code\n";
      echo htmlspecialchars(print_r($details, true));
      echo "</pre>";
      return;
    }
    $file = __DIR__ . "/errors/$code.php";
    if (is_file($file)) { require $file; }
    else { echo "<h1>$code</h1>"; }
  }
}

if (!function_exists('redirect_index')) {
  function redirect_index(array $qs = [], int $code = 302): never {
    redirect_to('index.php', $qs, $code);
  }
}

/* ---------- Capçaleres de seguretat (només web i si no s'han enviat) ---------- */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

  // HSTS (només si HTTPS)
  if ($isHttps) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains; preload');
  }

  // Amaga la versió de PHP
  @ini_set('expose_php', '0');
  header_remove('X-Powered-By');

  // CSP bàsica i tolerant
  $csp = [
    "base-uri 'self'",
    "form-action 'self'",
    "default-src 'self'",
    "img-src 'self' data: https:",
    "style-src 'self' 'unsafe-inline' https:",
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:",
    "font-src 'self' data: https:",
    "connect-src 'self' https:",
    "frame-ancestors 'none'",
    "object-src 'none'",
    "upgrade-insecure-requests"
  ];
  header('Content-Security-Policy: ' . implode('; ', $csp));

  // Altres capçaleres útils
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  header('X-Frame-Options: DENY');
  header('X-Permitted-Cross-Domain-Policies: none');
  header('Cross-Origin-Opener-Policy: same-origin');
  header('Cross-Origin-Resource-Policy: same-origin');

  // Exposa l’ID per correlació amb logs/proxy i perquè l'usuari ens el pugui reportar
  header('X-Request-ID: ' . ks_request_id());
}

/* ---------- Helpers genèrics ---------- */
if (!function_exists('is_post')) {
  function is_post(): bool { return (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'); }
}

/* ---------- Redirect helpers ---------- */
if (!function_exists('redirect_to')) {
  /**
   * Redirigeix a un path intern (respectant BASE_PATH) amb querystring opcional.
   * Exemple: redirect_to('espai.php', ['seccio'=>'dades'])
   */
  function redirect_to(string $path, array $qs = [], int $code = 302): never {
    $base = defined('BASE_PATH') ? (string)BASE_PATH : '/';
    $url  = rtrim($base, '/') . '/' . ltrim($path, '/');
    if ($qs) { $url .= '?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986); }
    header('Location: ' . $url, true, $code);
    exit;
  }
}

/* ---------- Sanitització del 'return' (same-origin + BASE_PATH) ---------- */
if (!function_exists('sanitize_return_url')) {
  /**
   * Accepta només rutes del mateix origen i sota BASE_PATH.
   * Retorna '' si no és segura.
   */
  function sanitize_return_url(?string $raw): string {
    $raw = (string)($raw ?? '');
    if ($raw === '') return '';
    $base = defined('BASE_PATH') ? (string)BASE_PATH : '/';
    // Evita esquema extern o protocol-relative
    if (preg_match('#^\s*(?:[a-z][a-z0-9+\-.]*:)?//#i', $raw)) return '';
    // Normalitza a path
    $p = parse_url($raw, PHP_URL_PATH) ?? '';
    $q = parse_url($raw, PHP_URL_QUERY) ?? '';
    if ($p === '') return '';
    // Ha d’estar dins BASE_PATH
    $base = rtrim($base, '/') . '/';
    $pNorm = '/' . ltrim($p, '/');
    if (strpos($pNorm, $base) !== 0) return '';
    $out = $pNorm;
    if ($q !== '' && is_string($q)) $out .= '?' . $q;
    return $out;
  }
}

/* ---------- CSRF ---------- */
if (PHP_SAPI !== 'cli' && empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (!function_exists('csrf_token')) {
  function csrf_token(): string { return $_SESSION['csrf'] ?? ''; }
}
if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars((string)csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
  }
}
function csrf_check_or_die(): void {
  if (PHP_SAPI === 'cli') return; // en CLI, no apliquem CSRF

  if (session_status() === PHP_SESSION_NONE) { @session_start(); }
  $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
  if ($ok) return;

  // ——— AUDIT: CSRF invàlid (no bloqueja si falla l'auditoria)
  try {
    if (!function_exists('db')) { require_once __DIR__ . '/db.php'; }
    require_once __DIR__ . '/audit.php';
    $pdo = db();

    $seccio = $_GET['seccio'] ?? ($_POST['seccio'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? ''));
    $context = (is_string($seccio) && $seccio !== '') ? $seccio : 'global';
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'csrf_invalid',
      null,
      null,
      $context,
      [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'    => $_SERVER['REQUEST_URI'] ?? '',
      ],
      'error',
      'csrf token mismatch'
    );
  } catch (Throwable $e) {
    error_log('audit csrf_invalid failed: ' . $e->getMessage());
  }

  http_response_code(403);
  exit;
}

/* ---------- Rols ---------- */
if (!function_exists('ks_role')) {
  function ks_role(): string {
    return strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
  }
}

/**
 * Retorna tots els rols actius a sessió: rol base + extres de User_Roles.
 * Admin sempre present si el rol base/extra és 'admin'.
 */
if (!function_exists('ks_roles')) {
  function ks_roles(): array {
    $base   = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
    $extras = array_map('strtolower', (array)($_SESSION['roles_extra'] ?? []));
    // Evita buits i dupes, preservant el rol base
    $all = array_values(array_filter(array_unique(array_merge($extras, $base ? [$base] : []))));
    return $all;
  }
}

/** Comprovació de rols (admin passa sempre) */
if (!function_exists('ks_has_role')) {
  function ks_has_role(string ...$want): bool {
    $have = ks_roles();
    if (in_array('admin', $have, true)) return true;
    foreach ($want as $w) {
      if (in_array(strtolower($w), $have, true)) return true;
    }
    return false;
  }
}

/** Compat: antiga API booleana */
if (!function_exists('ks_is')) {
  function ks_is(string ...$roles): bool {
    return ks_has_role(...$roles);
  }
}

/** Require amb render unificat i pas lliure d’admin via ks_has_role() */
if (!function_exists('ks_require_role')) {
  function ks_require_role(string ...$roles): void {
    if (ks_has_role(...$roles)) return;
    http_response_code(403);
    if (function_exists('ks_render_error')) { ks_render_error(403); }
    exit;
  }
}

/* Conveniència específica (legible a vistes/partials) */
if (!function_exists('ks_is_admin'))     { function ks_is_admin(): bool     { return ks_has_role('admin'); } }
if (!function_exists('ks_is_tecnic'))    { function ks_is_tecnic(): bool    { return ks_has_role('tecnic'); } }
if (!function_exists('ks_is_sala'))      { function ks_is_sala(): bool      { return ks_has_role('sala'); } }
if (!function_exists('ks_is_productor')) { function ks_is_productor(): bool { return ks_has_role('productor'); } }


/* ---------- Control global d’errors ---------- */
if (PHP_SAPI !== 'cli') {
  // Fatal errors → 500 (detall a LAN)
  register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
      ks_render_error(500, ks_is_dev_ip() ? $err : null);
    }
  });

  // Si algun codi ja està establert, mostra plantilla
  $code = http_response_code();
  if (in_array($code, [403,404,500], true)) {
    ks_render_error($code);
    exit;
  }
}