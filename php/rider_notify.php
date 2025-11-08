<?php
// php/rider_notify.php — Notificacions per subscripcions de riders
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';

// ───────────── Composer (PHPMailer) ─────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
  @include_once $autoload;
}

// Helpers locals si no existeixen
if (!function_exists('origin_url')) {
  function origin_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
           || (($_SERVER['SERVER_PORT'] ?? null) == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
  }
}

if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/**
 * Envia notificacions per als subscriptors d’un rider.
 *
 * @param PDO    $pdo
 * @param int    $riderId  ID_Rider
 * @param string $event    'seal_expired' | 'redirect_changed' | 'rider_deleted'
 *
 * @return int   Nombre de correus intentats amb èxit
 */
function ks_notify_rider_subscribers(PDO $pdo, int $riderId, string $event): int {
  $allowedEvents = ['seal_expired','redirect_changed','rider_deleted'];
  if (!in_array($event, $allowedEvents, true)) {
    return 0;
  }

  if (!class_exists(PHPMailer::class)) {
    error_log('ks_notify_rider_subscribers: PHPMailer class not found');
    return 0;
  }

  // ── Rider principal ─────────────────────────────────────
  $qr = $pdo->prepare("
    SELECT r.ID_Rider, r.Rider_UID, r.Descripcio, r.Nom_Arxiu, r.Referencia,
           r.Estat_Segell, r.rider_actualitzat,
           u.Nom_Usuari AS owner_nom, u.Cognoms_Usuari AS owner_cognoms
      FROM Riders r
      JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
     WHERE r.ID_Rider = :id
     LIMIT 1
  ");
  $qr->execute([':id' => $riderId]);
  $rider = $qr->fetch(PDO::FETCH_ASSOC);

  if (!$rider) {
    // Si el rider ja no existeix (p.ex. event rider_deleted cridat massa tard), sortim en silenci
    return 0;
  }

  // ── Rider destí en cas de redirect_changed ──────────────
  $redirect = null;
  if ($event === 'redirect_changed' && !empty($rider['rider_actualitzat'])) {
    $qDest = $pdo->prepare("
      SELECT ID_Rider, Rider_UID, Descripcio, Nom_Arxiu
        FROM Riders
       WHERE ID_Rider = :id
       LIMIT 1
    ");
    $qDest->execute([':id' => (int)$rider['rider_actualitzat']]);
    $redirect = $qDest->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  // ── Subscriptors actius ─────────────────────────────────
  $qs = $pdo->prepare("
    SELECT rs.Usuari_ID,
           u.Email_Usuari, u.Nom_Usuari, u.Cognoms_Usuari, u.Idioma
      FROM Rider_Subscriptions rs
      JOIN Usuaris u ON u.ID_Usuari = rs.Usuari_ID
     WHERE rs.Rider_ID = :id
       AND rs.active = 1
  ");
  $qs->execute([':id' => $riderId]);
  $subs = $qs->fetchAll(PDO::FETCH_ASSOC);

  if (!$subs) {
    return 0;
  }

  // ── URLs públiques ──────────────────────────────────────
  $baseUrl  = defined('BASE_URL') && BASE_URL ? rtrim((string)BASE_URL, '/') : rtrim(origin_url(), '/');
  $basePath = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
  $riderUrl = $baseUrl . $basePath . '/visualitza.php?ref=' . rawurlencode((string)$rider['Rider_UID']);
  $redirectUrl = null;
  if ($redirect && !empty($redirect['Rider_UID'])) {
    $redirectUrl = $baseUrl . $basePath . '/visualitza.php?ref=' . rawurlencode((string)$redirect['Rider_UID']);
  }

  // ── Config SMTP comuna ──────────────────────────────────
  $host     = $_ENV['SMTP_HOST']      ?? 'smtp.mail.me.com';
  $user     = $_ENV['SMTP_USER']      ?? '';
  $pass     = $_ENV['SMTP_PASS']      ?? '';
  $port     = (int)($_ENV['SMTP_PORT'] ?? 587);
  $from     = $_ENV['SMTP_FROM']      ?? $user;
  $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
  $replyTo  = $_ENV['SMTP_REPLYTO']   ?? $from;

  $sent = 0;

  foreach ($subs as $sub) {
    $email = trim((string)$sub['Email_Usuari']);
    if ($email === '') {
      continue;
    }

    $lang = (string)($sub['Idioma'] ?? 'ca');
    if (!in_array($lang, ['ca','es','en'], true)) {
      $lang = 'ca';
    }

    [$subject, $bodyHtml, $bodyText] = ks_build_rider_notify_message(
      $lang,
      $event,
      $rider,
      $riderUrl,
      $redirectUrl
    );

    if ($subject === '' || $bodyHtml === '') {
      continue;
    }

    try {
      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->CharSet    = 'UTF-8';
      $mail->Host       = $host;
      $mail->SMTPAuth   = true;
      $mail->Username   = $user;
      $mail->Password   = $pass;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = $port;

      if ($from !== '') {
        $mail->setFrom($from, $fromName);
        $mail->addReplyTo($replyTo ?: $from, $fromName);
        if ($user !== '') {
          $mail->Sender = $user;
        }
      }

      $fullName = trim((string)(($sub['Nom_Usuari'] ?? '') . ' ' . ($sub['Cognoms_Usuari'] ?? '')));
      $mail->addAddress($email, $fullName);

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $bodyHtml;
      $mail->AltBody = $bodyText;

      $mail->send();
      $sent++;

      // Només té sentit guardar ts_last_notified si el rider continua existint
      if ($event !== 'rider_deleted') {
        $up = $pdo->prepare("
          UPDATE Rider_Subscriptions
             SET ts_last_notified = UTC_TIMESTAMP()
           WHERE Rider_ID = :rid AND Usuari_ID = :uid
        ");
        $up->execute([
          ':rid' => $riderId,
          ':uid' => (int)$sub['Usuari_ID'],
        ]);
      }

    } catch (Throwable $e) {
      error_log('ks_notify_rider_subscribers: mail error for rider '.$riderId.' to '.$email.': '.$e->getMessage());
      // Continuem amb la resta
    }
  }

  return $sent;
}

/**
 * Construeix subject + body (HTML i text) segons idioma i event.
 */
function ks_build_rider_notify_message(
  string $lang,
  string $event,
  array $rider,
  string $riderUrl,
  ?string $redirectUrl
): array {
  $title = trim((string)($rider['Descripcio'] ?? ''));
  if ($title === '') {
    $title = (string)($rider['Nom_Arxiu'] ?? '');
  }
  if ($title === '') {
    $title = 'RD' . (int)($rider['ID_Rider'] ?? 0);
  }

  $subject = '';
  $html = '';
  $text = '';

  if ($lang === 'es') {
    switch ($event) {
      case 'seal_expired':
        $subject = "Rider caducado: {$title}";
        $html = "<p>El rider <strong>".h($title)."</strong> ha pasado a estado <strong>caducado</strong>.</p>"
              . "<p>Puedes consultarlo aquí: <a href=\"".h($riderUrl)."\">".h($riderUrl)."</a></p>";
        $text = "El rider \"{$title}\" ha pasado a estado caducado.\n\nPuedes consultarlo aquí:\n{$riderUrl}\n";
        break;

      case 'redirect_changed':
        if ($redirectUrl) {
          $subject = "Nuevo rider vinculado a: {$title}";
          $html = "<p>El rider <strong>".h($title)."</strong> ahora redirige a una nueva versión.</p>"
                . "<p>Versión actual: <a href=\"".h($redirectUrl)."\">".h($redirectUrl)."</a></p>";
          $text = "El rider \"{$title}\" ahora redirige a una nueva versión.\n\nVersión actual:\n{$redirectUrl}\n";
        } else {
          $subject = "Redirección actualizada: {$title}";
          $html = "<p>La redirección asociada al rider <strong>".h($title)."</strong> se ha modificado o eliminado.</p>"
                . "<p>Puedes revisar el rider aquí: <a href=\"".h($riderUrl)."\">".h($riderUrl)."</a></p>";
          $text = "La redirección asociada al rider \"{$title}\" se ha modificado o eliminado.\n\nConsulta el rider:\n{$riderUrl}\n";
        }
        break;

      case 'rider_deleted':
        $subject = "Rider eliminado: {$title}";
        $html = "<p>El rider <strong>".h($title)."</strong> ha sido eliminado de Kinosonik Riders.</p>";
        $text = "El rider \"{$title}\" ha sido eliminado de Kinosonik Riders.\n";
        break;
    }

  } elseif ($lang === 'en') {
    switch ($event) {
      case 'seal_expired':
        $subject = "Rider expired: {$title}";
        $html = "<p>The rider <strong>".h($title)."</strong> has changed its status to <strong>expired</strong>.</p>"
              . "<p>You can review it here: <a href=\"".h($riderUrl)."\">".h($riderUrl)."</a></p>";
        $text = "The rider \"{$title}\" status changed to expired.\n\nReview it here:\n{$riderUrl}\n";
        break;

      case 'redirect_changed':
        if ($redirectUrl) {
          $subject = "New rider linked to: {$title}";
          $html = "<p>The rider <strong>".h($title)."</strong> now redirects to a new version.</p>"
                . "<p>Current version: <a href=\"".h($redirectUrl)."\">".h($redirectUrl)."</a></p>";
          $text = "The rider \"{$title}\" now redirects to a new version.\n\nCurrent version:\n{$redirectUrl}\n";
        } else {
          $subject = "Redirection updated: {$title}";
          $html = "<p>The redirection for rider <strong>".h($title)."</strong> has been changed or removed.</p>"
                . "<p>You can review the rider here: <a href=\"".h($riderUrl)."\">".h($riderUrl)."</a></p>";
          $text = "The redirection for rider \"{$title}\" has been changed or removed.\n\nReview the rider:\n{$riderUrl}\n";
        }
        break;

      case 'rider_deleted':
        $subject = "Rider deleted: {$title}";
        $html = "<p>The rider <strong>".h($title)."</strong> has been deleted from Kinosonik Riders.</p>";
        $text = "The rider \"{$title}\" has been deleted from Kinosonik Riders.\n";
        break;
    }

  } else { // ca per defecte
    switch ($event) {
      case 'seal_expired':
        $subject = "Rider caducat: {$title}";
        $html = "<p>El rider <strong>".h($title)."</strong> ha canviat l'estat del segell a <strong>caducat</strong>.</p>"
              . "<p>Pots revisar-lo aquí: <a href=\"".h($riderUrl)."\">".h($riderUrl)."</a></p>";
        $text = "El rider \"{$title}\" ha passat a estat caducat.\n\nPots revisar-lo aquí:\n{$riderUrl}\n";
        break;

      case 'redirect_changed':
        if ($redirectUrl) {
          $subject = "Nou rider vinculat a: {$title}";
          $html = "<p>El rider <strong>".h($title)."</strong> ara redirigeix a una nova versió.</p>"
                . "<p>Versió actual: <a href=\"".h($redirectUrl)."\">".h($redirectUrl)."</a></p>";
          $text = "El rider \"{$title}\" ara redirigeix a una nova versió.\n\nVersió actual:\n{$redirectUrl}\n";
        } else {
          $subject = "Redirecció actualitzada: {$title}";
          $html = "<p>S'ha modificat o eliminat la redirecció associada al rider <strong>".h($title)."</strong>.</p>"
                . "<p>Pots revisar el rider aquí: <a href=\"".h($riderUrl)."\">".h($riderUrl)."</a></p>";
          $text = "S'ha modificat o eliminat la redirecció associada al rider \"{$title}\".\n\nRevisa el rider:\n{$riderUrl}\n";
        }
        break;

      case 'rider_deleted':
        $subject = "Rider eliminat: {$title}";
        $html = "<p>El rider <strong>".h($title)."</strong> ha estat eliminat de Kinosonik Riders.</p>";
        $text = "El rider \"{$title}\" ha estat eliminat de Kinosonik Riders.\n";
        break;
    }
  }

  return [$subject, $html, $text];
}
