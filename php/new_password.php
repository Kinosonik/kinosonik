<?php
// php/new_password.php — Formulari i processament de reset de contrasenya
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

$pdo = db();

header('Content-Type: text/html; charset=utf-8');

/* Helpers */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* Inputs bàsics */
$token   = (string)($_GET['token'] ?? '');
$errors  = [];
$success = "";

/* AUDIT: vista del formulari (GET) — no guardem token */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'password_reset_view',
      null,
      null,
      'account',
      ['has_token' => $token !== '' ? 1 : 0],
      'success',
      null
    );
  } catch (Throwable $e) { error_log('audit password_reset_view failed: ' . $e->getMessage()); }
}

/* ───────────────── Throttle bàsic per POST ───────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $_SESSION['pwreset_thr'] = $_SESSION['pwreset_thr'] ?? ['n'=>0, 't'=>time()];
  $thr = &$_SESSION['pwreset_thr'];
  // finestra 10 min / 20 intents
  if (time() - (int)$thr['t'] > 600) { $thr = ['n'=>0, 't'=>time()]; }
  if ($thr['n'] >= 20) {
    $errors[] = "Massa intents. Torna-ho a provar més tard.";
  }
}

/* Processament */
if (($_SERVER["REQUEST_METHOD"] ?? 'GET') === "POST" && empty($errors)) {
  // CSRF només en POST
  csrf_check_or_die();

  $token     = (string)($_POST['token'] ?? '');
  $password  = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');

  // Validacions bàsiques
  if ($token === '' || !preg_match('/^[A-Za-z0-9._~-]{24,128}$/', $token)) {
    $errors[] = "Token invàlid.";
  }
  if (mb_strlen($password, 'UTF-8') < 6) {
    $errors[] = "La contrasenya ha de tenir mínim 6 caràcters.";
  }
  if ($password !== $password2) {
    $errors[] = "Les contrasenyes no coincideixen.";
  }

  $userIdForAudit = 0;

  if (empty($errors)) {
    try {
      // Validar token i caducitat en UTC des de la BD
      $stmt = $pdo->prepare("
        SELECT ID_Usuari
          FROM Usuaris
         WHERE reset_token = :t
           AND reset_expires > UTC_TIMESTAMP()
         LIMIT 1
      ");
      $stmt->execute([':t' => $token]);
      $usuari = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$usuari) {
        $errors[] = "Token invàlid o caducat.";
      } else {
        $userIdForAudit = (int)$usuari['ID_Usuari'];

        // Actualitzar contrasenya i netejar token/caducitat
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("
          UPDATE Usuaris
             SET Password_Hash = :h,
                 reset_token   = NULL,
                 reset_expires = NULL,
                 Ultim_Acces_Usuari = UTC_TIMESTAMP()
           WHERE ID_Usuari = :id
           LIMIT 1
        ");
        $upd->execute([':h' => $hash, ':id' => $userIdForAudit]);

        $success = "Contrasenya actualitzada correctament. Ja pots iniciar sessió.";

        // AUDIT èxit
        try {
          audit_admin(
            $pdo,
            (int)$userIdForAudit,
            false,
            'password_reset_update',
            null,
            null,
            'account',
            ['result' => 'updated'],
            'success',
            null
          );
        } catch (Throwable $e) { error_log('audit password_reset_update success failed: '.$e->getMessage()); }
      }

    } catch (Throwable $e) {
      error_log('new_password exception: ' . $e->getMessage());
      $errors[] = "Error intern. Torna-ho a provar més tard.";
    }
  }

  // AUDIT error si hi ha errors (no loguem contrasenya ni token literal)
  if (!empty($errors)) {
    try {
      audit_admin(
        $pdo,
        (int)$userIdForAudit,
        false,
        'password_reset_update',
        null,
        null,
        'account',
        ['result' => 'error', 'reasons' => array_map(fn($s)=>mb_substr((string)$s,0,120), $errors)],
        'error',
        'password reset failed'
      );
    } catch (Throwable $e) { error_log('audit password_reset_update error failed: '.$e->getMessage()); }
  }

  // increment throttle POST
  $_SESSION['pwreset_thr']['n'] = (int)($_SESSION['pwreset_thr']['n'] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <title>Restablir Contrasenya</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap bàsic (coherent amb la resta del lloc) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
  <div class="card shadow p-4" style="max-width: 420px; width:100%;">
    <h5 class="card-title text-center mb-3">Nova contrasenya</h5>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" role="alert">
        <?php foreach ($errors as $err): ?>
          <p class="mb-0"><?= h($err) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success" role="alert"><?= h($success) ?></div>
      <div class="text-center">
        <a class="btn btn-outline-primary" href="<?= h(BASE_PATH) ?>index.php?modal=login">Inicia sessió</a>
      </div>
    <?php else: ?>
      <form method="POST" class="mt-2" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="mb-3">
          <label for="password" class="form-label">Nova contrasenya</label>
          <input type="password" class="form-control" id="password" name="password" required minlength="6" autocomplete="new-password">
        </div>

        <div class="mb-3">
          <label for="password2" class="form-label">Repeteix la contrasenya</label>
          <input type="password" class="form-control" id="password2" name="password2" required minlength="6" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-100">Canviar contrasenya</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>