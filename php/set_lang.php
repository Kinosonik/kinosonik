<?php
// php/set_lang.php — canvia l’idioma (sessió + cookie) i el persisteix si l’usuari està loguejat
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/i18n.php'; // normalize_lang(), set_lang()
require_once __DIR__ . '/db.php';
$pdo = db();
if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ── Entrada i normalització ───────────────────────────── */
$lang = normalize_lang((string)($_POST['lang'] ?? 'ca'));

/* ── Desa a sessió + cookie (helper unificat) ──────────── */
set_lang($lang);

/* ── Si hi ha sessió iniciada, persisteix a BD ─────────── */
if (!empty($_SESSION['loggedin']) && !empty($_SESSION['user_id'])) {
  try {
    // $pdo ja ve de preload.php (db.php)
    $st = $pdo->prepare("UPDATE Usuaris SET Idioma = :lang WHERE ID_Usuari = :id");
    $st->execute([':lang' => $lang, ':id' => (int)$_SESSION['user_id']]);
  } catch (Throwable $e) {
    error_log('set_lang persist error: ' . $e->getMessage());
    // No bloquegem el canvi d’idioma per un error de persistència
  }
}

/* ── Redirecció segura al referer (mateix origen) ──────── */
$fallback = (defined('BASE_PATH') ? BASE_PATH : '/') . 'index.php';
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$target = $fallback;

if ($ref !== '') {
  $refParts = @parse_url($ref) ?: [];
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  // Accepta el referer només si és del mateix origen
  if (($refParts['host'] ?? '') === $host && ($refParts['scheme'] ?? '') === $scheme) {
    $target = $ref;
  }
}

header('Location: ' . $target, true, 302);
exit;