<?php
// php/request_human_validation.php — Sol·licita revisió humana d’un rider
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/middleware.php'; // db(), csrf_check_or_die(), is_post()
require_once __DIR__ . '/audit.php';

@ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$pdo = db();

/* Helpers JSON */
function jerr(int $code, string $error, array $extra = [], ?callable $aud = null, array $meta = []): never {
  if ($aud) { $aud('error', $meta + ['reason' => $error], $error); }
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $error] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function jok(array $data = [], ?callable $aud = null, array $meta = []): never {
  if ($aud) { $aud('success', $meta, null); }
  echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* Auditoria local */
$aud = function(string $status, array $meta = [], ?string $err = null) use ($pdo) {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'request_human_validation',
      isset($meta['rider_id']) ? (int)$meta['rider_id'] : null,
      isset($meta['rider_uid']) ? (string)$meta['rider_uid'] : null,
      'riders',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit request_human_validation failed: ' . $e->getMessage());
  }
};

/* Mètode + CSRF */
if (!is_post()) { jerr(405, 'method_not_allowed', [], $aud); }
csrf_check_or_die();

/* Auth */
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { jerr(401, 'auth_required', [], $aud); }
$isAdmin = (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0);

/* Inputs */
$riderId = isset($_POST['rider_id']) && ctype_digit((string)$_POST['rider_id']) ? (int)$_POST['rider_id'] : 0;
if ($riderId <= 0) { jerr(400, 'bad_id', [], $aud, ['rider_id' => $riderId]); }

/* Business rules */
$THROTTLE_HOURS = 12;  // temps mínim entre sol·licituds per rider

try {
  $pdo->beginTransaction();

  // Carrega rider bloquejant la fila
  $st = $pdo->prepare("
    SELECT ID_Rider, ID_Usuari, Rider_UID,
           Estat_Segell, Valoracio, Validacio_Manual_Solicitada, Validacio_Manual_Data
      FROM Riders
     WHERE ID_Rider = :rid
     LIMIT 1
     FOR UPDATE
  ");
  $st->execute([':rid' => $riderId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) {
    $pdo->rollBack();
    jerr(404, 'not_found', [], $aud, ['rider_id' => $riderId]);
  }

  $ownerId   = (int)$r['ID_Usuari'];
  $riderUid  = (string)($r['Rider_UID'] ?? '');
  $estat     = strtolower(trim((string)($r['Estat_Segell'] ?? '')));
  $valoracio = $r['Valoracio'];
  $already   = (int)($r['Validacio_Manual_Solicitada'] ?? 0) === 1;
  $lastReq   = (string)($r['Validacio_Manual_Data'] ?? '');

  // Permisos: propietari o admin
  if (!$isAdmin && $ownerId !== $userId) {
    $pdo->rollBack();
    jerr(403, 'forbidden', [], $aud, ['rider_id' => $riderId, 'owner_id' => $ownerId, 'actor_id' => $userId]);
  }

  // Disponible només si hi ha valoració i no és final (validat/caducat)
  if (is_null($valoracio) || in_array($estat, ['validat','caducat'], true)) {
    $pdo->rollBack();
    jerr(422, 'unavailable', [], $aud, ['rider_id' => $riderId, 'status' => $estat, 'score' => $valoracio]);
  }

  // Throttling: si n'hi ha una de recent (< THROTTLE_HOURS)
  
  if ($lastReq !== '' && $lastReq !== '0000-00-00 00:00:00') {
    try {
      $last = new DateTimeImmutable($lastReq, new DateTimeZone('UTC'));
      $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
      $diffH = (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 3600);
      if ($diffH < $THROTTLE_HOURS) {
        $nextAllowed = $last->modify("+{$THROTTLE_HOURS} hours");
        $nextLocal = $nextAllowed->setTimezone(new DateTimeZone('Europe/Madrid'))->format('d/m/Y H:i');
        $pdo->rollBack();
        jerr(429, 'throttled', ['next_allowed' => $nextLocal], $aud, [
          'rider_id' => $riderId,
          'last'     => $lastReq,
          'throttle_hours' => $THROTTLE_HOURS
        ]);
      }
    } catch (Throwable $e) {
      // si falla el parseig, no apliquem throttling
    }
  }
  
  // Idempotent: si ja estava marcada, retornem OK amb la data existent
  if ($already) {
    $whenLocal = null;
    if ($lastReq !== '' && $lastReq !== '0000-00-00 00:00:00') {
      try {
        $whenLocal = (new DateTimeImmutable($lastReq, new DateTimeZone('UTC')))
          ->setTimezone(new DateTimeZone('Europe/Madrid'))
          ->format('d/m/Y H:i');
      } catch (Throwable $e) {}
    }
    $pdo->commit();
    jok([
      'already' => true,
      'requested_at' => $whenLocal,
    ], $aud, ['rider_id' => $riderId, 'rider_uid' => $riderUid, 'idempotent' => true]);
  }

  // Marca sol·licitud (UTC a BD)
  $upd = $pdo->prepare("
    UPDATE Riders
       SET Validacio_Manual_Solicitada = 1,
           Validacio_Manual_Data = UTC_TIMESTAMP()
     WHERE ID_Rider = :rid
     LIMIT 1
  ");
  $upd->execute([':rid' => $riderId]);

  // Recarrega data exacta per respondre
  $q = $pdo->prepare("SELECT Validacio_Manual_Data FROM Riders WHERE ID_Rider = :rid LIMIT 1");
  $q->execute([':rid' => $riderId]);
  $utcWhen = (string)($q->fetchColumn() ?: '');

  $pdo->commit();

  // Format humà (Europe/Madrid)
  $whenLocal = null;
  if ($utcWhen !== '' && $utcWhen !== '0000-00-00 00:00:00') {
    try {
      $whenLocal = (new DateTimeImmutable($utcWhen, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Madrid'))
        ->format('d/m/Y H:i');
    } catch (Throwable $e) {}
  }

  // EMAIL best-effort (silenciat si no hi ha Composer/PHPMailer)
  try {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_readable($autoload) && (@include_once $autoload) !== false) {
      if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet  = 'UTF-8';
        $mail->Host       = $_ENV['SMTP_HOST']      ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER']      ?? '';
        $mail->Password   = $_ENV['SMTP_PASS']      ?? '';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
        $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
        $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
        if ($from !== '') $mail->setFrom($from, $fromName);
        if (!empty($_ENV['SMTP_USER'])) { $mail->Sender = $_ENV['SMTP_USER']; }
        if (!empty($_ENV['SMTP_REPLYTO'])) { $mail->addReplyTo($_ENV['SMTP_REPLYTO'], $fromName); }

        $mail->addAddress($_ENV['VALIDATION_NOTIFY_TO'] ?? 'rsendra@kinosonik.com');
        $mail->isHTML(true);
        $mail->Subject = 'Sol·licitud de validació humana';
        $safeWhen = htmlspecialchars((string)$whenLocal ?: '—', ENT_QUOTES, 'UTF-8');
        $safeId   = (int)$riderId;
        $safeUID  = htmlspecialchars($riderUid, ENT_QUOTES, 'UTF-8');
        $mail->Body = "<p>S’ha sol·licitat una <strong>validació humana</strong> pel rider RD{$safeId} ({$safeUID}).</p>
                       <p>Data sol·licitud: {$safeWhen}</p>";
        $mail->send();
      }
    }
  } catch (Throwable $e) {
    // silenciat
  }

  jok([
    'requested_at' => $whenLocal,
    'throttle_hours' => $THROTTLE_HOURS
  ], $aud, ['rider_id' => $riderId, 'rider_uid' => $riderUid]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('request_human_validation error: ' . $e->getMessage());
  jerr(500, 'server_error', [], $aud, ['rider_id' => $riderId ?? null]);
}