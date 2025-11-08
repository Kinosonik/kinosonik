<?php
// php/reset_password.php — Formulari i processament de reset de contrasenya
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// require_once __DIR__ . '/db.php'; // ❌ Duplicat: db() ja ve via preload.php
require_once __DIR__ . '/i18n.php';       // t()/__()
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

$pdo = db();
header('Content-Type: text/html; charset=utf-8');

/* Helpers locals */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Valida i retorna l'usuari a partir d’un token de reset.
 * A BD hi guardem el HASH (sha256) del token, no el token en clar.
 * Fem la comprovació de caducitat a SQL (UTC) per evitar problemes de timezone a PHP.
 */
function get_user_by_reset_token(PDO $pdo, string $rawToken): ?array {
  if ($rawToken === '' || strlen($rawToken) < 10) return null;
  $hash = hash('sha256', $rawToken);

  $sql = "
    SELECT ID_Usuari
      FROM Usuaris
     WHERE Password_Reset_Token_Hash = :h
       AND Password_Reset_Expira > UTC_TIMESTAMP()
     LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':h' => $hash]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  return $u ?: null;
}

/* Auditoria compacta */
function audit_pw_reset(PDO $pdo, string $phase, string $status, ?int $userId = null, ?string $err = null, array $meta = []): void {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),                              // pot ser 0 (anònim)
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'password_reset',
      null,
      null,
      'account',
      ['phase' => $phase, 'target_user_id' => $userId] + $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit password_reset failed: ' . $e->getMessage());
  }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ------------------------- GET: mostra formulari ------------------------- */
if ($method === 'GET') {
  $token = (string)($_GET['token'] ?? '');
  $u = get_user_by_reset_token($pdo, $token);

  if (!$u) {
    audit_pw_reset($pdo, 'view', 'error', null, 'token_invalid');
    redirect_index(['modal' => 'login', 'error' => 'token_invalid']);
  }

  audit_pw_reset($pdo, 'view', 'success', (int)$u['ID_Usuari']);
  ?>
  <!doctype html>
  <html lang="<?= h($_SESSION['lang'] ?? 'ca') ?>" data-bs-theme="dark">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= h(t('reset.title_page', ['app' => 'Riders'])) ?></title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-body-tertiary">
      <div class="container" style="max-width:520px;">
        <div class="py-5 text-center">
          <h1 class="h3 mb-3 fw-normal"><?= h(t('reset.heading')) ?></h1>
          <p class="text-secondary small"><?= h(t('reset.intro')) ?></p>
        </div>

        <div class="card shadow-sm">
          <div class="card-body">
            <form class="needs-validation" method="POST" novalidate>
              <?= csrf_field() ?>
              <input type="hidden" name="token" value="<?= h($token) ?>">

              <div class="mb-3">
                <label for="pwd1" class="form-label"><?= h(t('reset.new_password_label')) ?></label>
                <input
                  type="password"
                  class="form-control"
                  id="pwd1"
                  name="password"
                  pattern="^(?=.*[A-Za-z])(?=.*\d).{8,}$"
                  required
                  autocomplete="new-password"
                >
                <div class="form-text"><?= h(t('reset.new_password_help')) ?></div>
                <div class="invalid-feedback"><?= h(t('reset.new_password_invalid')) ?></div>
              </div>

              <div class="mb-3">
                <label for="pwd2" class="form-label"><?= h(t('reset.confirm_label')) ?></label>
                <input
                  type="password"
                  class="form-control"
                  id="pwd2"
                  name="password2"
                  required
                  autocomplete="new-password"
                >
                <div class="invalid-feedback"><?= h(t('reset.confirm_invalid')) ?></div>
              </div>

              <div class="d-grid gap-2">
                <button class="btn btn-primary" type="submit"><?= h(t('reset.btn_update')) ?></button>
                <a href="<?= h(BASE_PATH . 'index.php') ?>" class="btn btn-outline-secondary"><?= h(t('reset.btn_cancel')) ?></a>
              </div>
            </form>
          </div>
        </div>

        <p class="text-center text-secondary small mt-3 mb-5">
          <?= h(str_replace('%Y', date('Y'), t('reset.footer'))) ?>
        </p>
      </div>

      <script>
      (() => {
        'use strict';
        const form = document.querySelector('.needs-validation');
        form.addEventListener('submit', e => {
          const p1 = document.getElementById('pwd1');
          const p2 = document.getElementById('pwd2');
          p2.setCustomValidity('');
          if (p1.value !== p2.value) { p2.setCustomValidity('<?= addslashes(t('reset.client_mismatch')) ?>'); }
          if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
          form.classList.add('was-validated');
        }, false);
      })();
      </script>
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    </body>
  </html>
  <?php
  exit;
}

/* ------------------------- POST: aplica canvi ------------------------- */
csrf_check_or_die();

$token = (string)($_POST['token'] ?? '');
$u = get_user_by_reset_token($pdo, $token);
if (!$u) {
  audit_pw_reset($pdo, 'update', 'error', null, 'token_invalid');
  redirect_index(['modal' => 'login', 'error' => 'token_invalid']);
}

// Validacions contrasenya
$p1 = (string)($_POST['password']  ?? '');
$p2 = (string)($_POST['password2'] ?? '');
if ($p1 === '' || $p2 === '' || $p1 !== $p2) {
  audit_pw_reset($pdo, 'update', 'error', (int)$u['ID_Usuari'], 'password_mismatch');
  redirect_index(['modal' => 'login', 'error' => 'password_mismatch']);
}
if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $p1)) {
  audit_pw_reset($pdo, 'update', 'error', (int)$u['ID_Usuari'], 'weak_password');
  redirect_index(['modal' => 'login', 'error' => 'weak_password']);
}

// Hash i update (Argon2id si disponible)
$hash = defined('PASSWORD_ARGON2ID') ? password_hash($p1, PASSWORD_ARGON2ID)
                                     : password_hash($p1, PASSWORD_DEFAULT);

try {
  $upd = $pdo->prepare("
    UPDATE Usuaris
       SET Password_Hash = :ph,
           Password_Reset_Token_Hash = NULL,
           Password_Reset_Expira     = NULL
     WHERE ID_Usuari = :id
     LIMIT 1
  ");
  $upd->execute([':ph' => $hash, ':id' => (int)$u['ID_Usuari']]);

  audit_pw_reset($pdo, 'update', 'success', (int)$u['ID_Usuari'], null, ['reset_done' => true]);

  // Tornem a l’index i obrim el modal de login amb missatge d’èxit
  redirect_index(['modal' => 'login', 'success' => 'reset_ok']);

} catch (Throwable $e) {
  error_log('reset_password error: ' . $e->getMessage());
  audit_pw_reset($pdo, 'update', 'error', (int)$u['ID_Usuari'], 'server_error');
  redirect_index(['modal' => 'login', 'error' => 'reset_failed']);
}