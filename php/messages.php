<?php
// php/messages.php — carrega missatges segons idioma actiu i exposa $messages + helper msg_text()
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
  session_start();
}

// 1) Idioma — coherent amb i18n.php
require_once __DIR__ . '/i18n.php';
$lang = current_lang();
$allowed = ['ca', 'es', 'en'];
if (!in_array($lang, $allowed, true)) {
  $lang = 'ca';
}

// 2) Ruta dels diccionaris (ex: /lang/messages.ca.php)
$path = __DIR__ . '/../lang/messages.' . $lang . '.php';
if (!is_file($path) && $lang !== 'ca') {
  // fallback si falta el fitxer
  $path = __DIR__ . '/../lang/messages.ca.php';
}

// 3) Inicialitza estructura segura
$messages = ['success' => [], 'error' => []];

// 4) Carrega el diccionari tolerant dos estils:
//    a) El fitxer FA `return ['success'=>..., 'error'=>...]`
//    b) El fitxer DEFINEIX `$messages['success']=...; $messages['error']=...;`
if (is_file($path)) {
  $__dict = require $path;

  if (is_array($__dict) && (isset($__dict['success']) || isset($__dict['error']))) {
    // Estil (a): el fitxer retorna un array
    $messages = array_merge($messages, $__dict);
  } elseif (isset($messages) && is_array($messages) &&
            (isset($messages['success']) || isset($messages['error']))) {
    // Estil (b): ja està poblada
  } else {
    // Si el fitxer no retorna res vàlid
    $messages = ['success' => [], 'error' => []];
  }
}

// 5) Helper per obtenir text d’una clau amb fallback
if (!function_exists('msg_text')) {
  function msg_text(string $type, string $key): string {
    /** @var array $messages */
    global $messages;
    $bucket = ($type === 'error') ? 'error' : 'success';
    $dict = $messages[$bucket] ?? [];
    if (isset($dict[$key]) && is_string($dict[$key])) {
      return $dict[$key];
    }
    return (string)($dict['default'] ?? '');
  }
}

// 6) Sanitització QS (opcional, per a ús a la UI)
$qs_success = isset($_GET['success']) ? preg_replace('/[^a-z0-9_:-]/i', '', (string)$_GET['success']) : '';
$qs_error   = isset($_GET['error'])   ? preg_replace('/[^a-z0-9_:-]/i', '', (string)$_GET['error'])   : '';
$qs_modal   = isset($_GET['modal'])   ? preg_replace('/[^a-z0-9_:-]/i', '', (string)$_GET['modal'])   : '';