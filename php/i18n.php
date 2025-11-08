<?php
// php/i18n.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
/** Normalitza el codi d’idioma a un dels permesos */
function normalize_lang(string $lang): string {
  $lang = strtolower(trim($lang));
  return in_array($lang, ['ca','es','en'], true) ? $lang : 'ca';
}

/** Idioma actual (sessió > cookie > 'ca') */
function current_lang(): string {
  if (!empty($_SESSION['lang'])) {
    return normalize_lang((string)$_SESSION['lang']);
  }
  if (!empty($_COOKIE['lang'])) {
    return normalize_lang((string)$_COOKIE['lang']);
  }
  return 'ca';
}

/** Desa idioma a sessió + cookie (path coherent amb BASE_PATH si existeix) */
function set_lang(string $lang): void {
  $lang = normalize_lang($lang);
  $_SESSION['lang'] = $lang;

  // Path dinàmic: si BASE_PATH està definit, l'usem; sinó '/'
  $path = defined('BASE_PATH') ? (rtrim((string)BASE_PATH, '/') . '/') : '/';

  // Domini host-only per evitar sorpreses amb www/subdominis
  $domain = $_SERVER['HTTP_HOST'] ?? '';

  // Respecta entorns amb proxy/CDN
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

  @setcookie('lang', $lang, [
    'expires'  => time() + 60*60*24*365, // 1 any
    'path'     => $path,                 // "/riders/" o "/"
    'domain'   => $domain,               // host-only
    'secure'   => $secure,
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}

/** Carrega el paquet d’idioma */
function i18n_load(string $lang): array {
  $base = __DIR__ . '/../lang';
  $file = $base . '/' . $lang . '.php';
  if (!is_file($file)) { $file = $base . '/ca.php'; }
  $arr = include $file;
  return is_array($arr) ? $arr : [];
}

/** Traducció amb placeholders senzills {nom} */
$GLOBALS['__L'] = i18n_load(current_lang());

function t(string $key, array $vars = []): string {
  $val = $GLOBALS['__L'][$key] ?? $key;
  if ($vars) {
    foreach ($vars as $k => $v) {
      $val = str_replace('{' . $k . '}', (string)$v, $val);
    }
  }
  return $val;
}

function __(string $key, array $vars = []): string {
  return t($key, $vars);
}