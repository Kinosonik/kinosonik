<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ───────────── Logger no-op (manté compatibilitat amb crides existents) ───────────── */
function L($msg) { /* no-op en producció */ }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php'; // ✅ Auditoria

// PHPMailer i altres
use PHPMailer\PHPMailer\PHPMailer;
use setasign\Fpdi\Fpdi;
use Endroid\QrCode\Builder\Builder;

/* ───────────── AUTOLOAD COMPOSER ───────────── */
$autoload = __DIR__ . '/../vendor/autoload.php';

/* ───────────── Inits ───────────── */
@ini_set('display_errors', '0');
@ini_set('log_errors', '0'); // no persistim errors a fitxer

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ───────────── Helpers ───────────── */
function json_fail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_ok(array $data = []): never {
  echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Eliminació silenciosa de temporals
function silent_unlink(?string $p): void {
  if ($p && file_exists($p)) { @unlink($p); }
}

// Helper URL origen si no ve de preload.php
if (!function_exists('origin_url')) {
  function origin_url(): string {
    if (defined('BASE_URL') && BASE_URL) {
      $u = rtrim((string)BASE_URL, '/');
      return $u . '/';
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/';
  }
}

/** Helper per auditar i després respondre json_fail() */
function audit_and_fail(
  PDO $pdo,
  string $reason,
  int $httpCode,
  array $meta = [],
  ?int $userId = null,
  ?bool $isAdmin = null,
  ?int $riderId = null,
  ?string $riderUid = null,
  ?string $errorMsg = null
): never {
  try {
    audit_admin(
      $pdo,
      (int)($userId ?? 0),
      (bool)($isAdmin ?? false),
      'auto_publish_seal',
      $riderId,
      $riderUid,
      'riders',
      array_replace(['reason'=>$reason], $meta),
      'error',
      $errorMsg ?? $reason
    );
  } catch (Throwable $e) {
    error_log('audit auto_publish_seal error('.$reason.'): '.$e->getMessage());
  }
  json_fail($reason, $httpCode);
}

/* ───────────── LÒGICA PRINCIPAL ───────────── */
try {
  $bucket = null;
  $key = null;
  $bytes = 0;
  $sha256 = '';
  // method i CSRF (abans d’autoload, per ser ràpids)
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // No tenim $pdo encara; l’inicialitzem per auditar també això
    $pdo = db();
    audit_and_fail($pdo, 'bad_method', 405);
  }
  csrf_check_or_die();

  // DB i context d’usuari
  $pdo = db();

  $userId  = $_SESSION['user_id'] ?? null;
  $isAdmin = strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0;
  if (!$userId) {
    audit_and_fail($pdo, 'login_required', 401, [], null, null);
  }

  // Composer autoload
  if (!is_readable($autoload)) {
    audit_and_fail($pdo, 'autoload_missing', 500, [], (int)$userId, $isAdmin);
  }
  $__inc_ok = @include_once $autoload;
  if ($__inc_ok === false) {
    audit_and_fail($pdo, 'autoload_include_failed', 500, [], (int)$userId, $isAdmin);
  }

  /* ───────────── Inputs ───────────── */
  $csrf = $_POST['csrf'] ?? '';
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    audit_and_fail($pdo, 'csrf', 403, [], (int)$userId, $isAdmin);
  }

  $riderUid = trim((string)($_POST['rider_uid'] ?? ''));
  $riderId  = (int)($_POST['rider_id'] ?? 0);
  // Validació estricta d’UUID v1–v5
  if ($riderUid !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $riderUid)) {
    audit_and_fail($pdo, 'bad_uid', 422, [], (int)$userId, $isAdmin);
  }
  if ($riderUid === '' || $riderId <= 0) {
    audit_and_fail($pdo, 'missing_params', 400, [], (int)$userId, $isAdmin);
  }

  // Carrega rider + usuari
  $st = $pdo->prepare("
    SELECT r.ID_Rider, r.Rider_UID, r.ID_Usuari, r.Object_Key, r.Mida_Bytes, r.Hash_SHA256,
           r.Estat_Segell, r.Valoracio, r.Validacio_Manual_Solicitada,
           r.Descripcio, r.Referencia,
           u.Nom_Usuari, u.Cognoms_Usuari, u.Email_Usuari, u.Idioma
      FROM Riders r
      JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
     WHERE r.Rider_UID = :ruid AND r.ID_Rider = :rid
     LIMIT 1
  ");
  $st->execute([':ruid'=>$riderUid, ':rid'=>$riderId]);
  $R = $st->fetch(PDO::FETCH_ASSOC);
  if (!$R) {
    audit_and_fail($pdo, 'not_found', 404, ['rider_uid'=>$riderUid, 'rider_id'=>$riderId], (int)$userId, $isAdmin);
  }

  // Object_Key obligatori
if (empty($R['Object_Key'])) {
  audit_and_fail($pdo, 'object_key_missing', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
}

  // Permisos
  if (!$isAdmin && (int)$R['ID_Usuari'] !== (int)$userId) {
    audit_and_fail($pdo, 'forbidden', 403, ['owner_id'=>(int)$R['ID_Usuari']], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }

  // Condicions del botó
  $score = is_null($R['Valoracio']) ? null : (int)$R['Valoracio'];
  $estat = strtolower((string)($R['Estat_Segell'] ?? ''));
  $manualReq = (int)($R['Validacio_Manual_Solicitada'] ?? 0);

  if ($score === null || $score <= 80) {
    audit_and_fail($pdo, 'low_score', 400, ['score'=>$score], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }
  if (in_array($estat, ['validat','caducat'], true)) {
    audit_and_fail($pdo, 'already_final', 400, ['estat'=>$estat], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }
  if ($manualReq === 1) {
    audit_and_fail($pdo, 'tech_validation_pending', 400, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }

  // Evita segellar mentre hi ha una IA activa sobre el rider
  $chk = $pdo->prepare("SELECT COUNT(*) FROM ia_jobs WHERE rider_id = ? AND status IN ('queued','running')");
  $chk->execute([(int)$R['ID_Rider']]);
  if ((int)$chk->fetchColumn() > 0) {
    audit_and_fail($pdo, 'ai_job_active', 409, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }

  // Stack d’imatge mínim: com a mínim GD o bé Imagick
  if (!extension_loaded('gd') && !class_exists('Imagick')) {
    audit_and_fail($pdo, 'image_stack_missing', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }

  /* ───────────── Descarrega PDF de R2 ───────────── */
  try {
    $client = r2_client();
    $bucket = $_ENV['R2_BUCKET'] ?? getenv('R2_BUCKET') ?? '';
    if ($bucket === '') { throw new RuntimeException('R2_BUCKET missing'); }

    $key = (string)$R['Object_Key'];
    $tmpPdf = sys_get_temp_dir() . '/rider_' . $R['Rider_UID'] . '.pdf';

    $res = $client->getObject(['Bucket'=>$bucket, 'Key'=>$key]);
    $body = $res['Body'] ?? null;
    if (!$body) { throw new RuntimeException('R2 body empty'); }
    file_put_contents($tmpPdf, $body->getContents());
  } catch (Throwable $e) {
    audit_and_fail($pdo, 'r2_download', 500, ['bucket'=>$bucket ?? null, 'key'=>$key ?? null], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID'], $e->getMessage());
  }

  /* ───────────── Timestamp Europe/Madrid ───────────── */
  $TZ_EU   = new DateTimeZone('Europe/Madrid');
  $nowEU   = new DateTimeImmutable('now', $TZ_EU);
  $dateStr = $nowEU->format('m/Y');     // per al segell visual
  $nowSQL  = $nowEU->format('Y-m-d H:i:s'); // per persistir a BD

  /* ───────────── Dades del segell ───────────── */
  $idStr     = (string)(int)$R['ID_Rider'];
  // URL absolut i canònic a visualitza.php
  $absBase   = defined('BASE_URL') && BASE_URL ? rtrim((string)BASE_URL, '/') : origin_url();
  $basePath  = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
  $publicUrl = $absBase . $basePath . '/visualitza.php?ref=' . rawurlencode((string)$R['Rider_UID']);

  /* ───────────── QR a PNG ───────────── */
  $qrPngPath = sys_get_temp_dir() . '/qr_' . $R['Rider_UID'] . '.png';
  try {
    if (!class_exists(Builder::class)) { throw new RuntimeException('qr_lib_missing'); }
    $qr = Builder::create()->data($publicUrl)->size(300)->margin(0)->build();
    $qr->saveToFile($qrPngPath);
  } catch (Throwable $e) {
    audit_and_fail($pdo, 'qr_failed', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID'], $e->getMessage());
  }

  /* ───────────── Estampar segell ───────────── */
  /* ───────────── Estampar segell (PNG + text + QR, sense SVG) ───────────── */
$outPdf = sys_get_temp_dir() . '/rider_' . $R['Rider_UID'] . '_sealed.pdf';
try {
  if (!class_exists(Fpdi::class)) { throw new RuntimeException('fpdi_missing'); }

  $pdf = new Fpdi();
  $pageCount = $pdf->setSourceFile($tmpPdf);

  $tplId = $pdf->importPage(1);
  $size  = $pdf->getTemplateSize($tplId);
  $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
  $pdf->useTemplate($tplId);

  $pageW = $size['width'];

  // Config del segell
  $sealBgPath = realpath(__DIR__ . '/../img/seals/segell-validat-bg.png') ?: '';
  $designWpx  = 600; // base del disseny
  $designHpx  = 200;

  // Mida màxima per al segell a la pàgina (mm)
  $tplW = 65; 
  $tplH = 35;

  $sealPlaced = false;

  if ($sealBgPath && is_readable($sealBgPath)) {
    $info   = @getimagesize($sealBgPath);
    $pxW    = $info[0] ?? $designWpx;
    $pxH    = $info[1] ?? $designHpx;
    $aspect = $pxW / max(1,$pxH);

    // Escalat al contenidor (tplW x tplH)
    $sealW = $tplW;
    $sealH = $sealW / $aspect;
    if ($sealH > $tplH) { $sealH = $tplH; $sealW = $sealH * $aspect; }

    // Cantonada superior dreta
    $x = $pageW - 8 - $sealW;
    $y = 8;

    // 1) Fons del segell (PNG amb transparència)
    $pdf->Image($sealBgPath, $x, $y, $sealW, $sealH, 'PNG');

    // 2) QR – posicionat segons el disseny base (px → mm)
    $mmPerPx = $sealW / $designWpx; // mateixa escala en X i Y

    $qrWpx   = 150;  // ample del QR al disseny base
    $qrXpx   = 420;  // posició X del QR al disseny base
    $qrYpx   = 25;   // posició Y del QR al disseny base

    if (is_readable($qrPngPath)) {
      $pdf->Image(
        $qrPngPath,
        $x + $qrXpx * $mmPerPx,
        $y + $qrYpx * $mmPerPx,
        $qrWpx * $mmPerPx,
        $qrWpx * $mmPerPx,
        'PNG'
      );
    }

    // 3) Text – “ID:123-10/2025” a la posició del disseny base
    $textXpx = 30;   // posició X al disseny base
    $textYpx = 158;  // posició Y (baseline) al disseny base
    $pdf->SetFont('Helvetica','',8); // built-in, suficient per al nostre text ASCII
    $pdf->SetTextColor(0,0,0);
    $pdf->Text(
      $x + $textXpx * $mmPerPx,
      $y + $textYpx * $mmPerPx,
      "ID:{$idStr}-{$dateStr}"
    );

    $sealPlaced = true;
  }
  if (!$sealPlaced) {
    audit_and_fail($pdo, 'seal_png_missing', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
  }

  // Fallback: si per algun motiu no tenim fons, almenys posem el QR
  if (!$sealPlaced && is_readable($qrPngPath)) {
    $qrWmm = 25;
    $x = $pageW - 8 - $qrWmm;
    $y = 8 + 12;
    $pdf->Image($qrPngPath, $x, $y, $qrWmm, $qrWmm, 'PNG');
  }

  // Resta de pàgines
  for ($i = 2; $i <= $pageCount; $i++) {
    $tpl = $pdf->importPage($i);
    $sz  = $pdf->getTemplateSize($tpl);
    $pdf->AddPage($sz['orientation'], [$sz['width'], $sz['height']]);
    $pdf->useTemplate($tpl);
  }

  $pdf->Output($outPdf, 'F');

} catch (Throwable $e) {
  audit_and_fail($pdo, 'seal_process_failed', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID'], $e->getMessage());
}

  /* ───────────── Pujar a R2 + UPDATE BD ───────────── */
  try {
    $sha256 = hash_file('sha256', $outPdf) ?: null;
    // Assegura que el PDF de sortida és vàlid
    if (!is_file($outPdf) || filesize($outPdf) <= 0) {
      audit_and_fail($pdo, 'seal_output_empty', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
    }
    $bytes  = filesize($outPdf) ?: 0;

    $fh = fopen($outPdf, 'rb');
    try {
      $client->putObject([
        'Bucket'      => $bucket,
        'Key'         => $key,
        'Body'        => $fh,
        'ContentType' => 'application/pdf',
        'ContentLength' => $bytes,
      ]);
    } finally {
      if (is_resource($fh)) { fclose($fh); }
    }

    $upd = $pdo->prepare("
      UPDATE Riders
         SET Estat_Segell     = 'validat',
             Data_Publicacio  = UTC_TIMESTAMP(),
             Hash_SHA256      = :sha,
             Mida_Bytes       = :b,
             rider_actualitzat = NULL
       WHERE ID_Rider = :rid
       LIMIT 1
    ");
$upd->execute([
  ':sha' => $sha256,
  ':b'   => (int)$bytes,
  ':rid' => (int)$R['ID_Rider'],
]);
if ($upd->rowCount() === 0) {
  audit_and_fail($pdo, 'already_final', 409, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID']);
}

  } catch (Throwable $e) {
    audit_and_fail($pdo, 'r2_upload_failed', 500, ['bucket'=>$bucket ?? null, 'key'=>$key ?? null], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID'], $e->getMessage());
  }

  // UPDATE BD separat per poder distingir l’error
  try {
    /* ... UPDATE Riders tal com a dalt ... */
  } catch (Throwable $e) {
    audit_and_fail($pdo, 'db_update_failed', 500, [], (int)$userId, $isAdmin, (int)$R['ID_Rider'], (string)$R['Rider_UID'], $e->getMessage());
  }

  // Rellegeix Data_Publicacio exacta des de BD (coherència amb UTC_TIMESTAMP())
  $dpStmt = $pdo->prepare("SELECT Data_Publicacio FROM Riders WHERE ID_Rider = :id LIMIT 1");
  $dpStmt->execute([':id' => (int)$R['ID_Rider']]);
  $dataPublicacioDb = (string)($dpStmt->fetchColumn() ?: '');
  if ($dataPublicacioDb === '') {
    $dataPublicacioDb = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
  }

  /* ───────────── Traça a ia_runs (opcional però útil) ───────────── */
  $nowUTC = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
  try {
    $insRun = $pdo->prepare("
      INSERT INTO ia_runs
        (rider_id, job_uid, started_at, finished_at, status, score, bytes, chars, log_path, error_msg, summary_text, details_json)
      VALUES
        (:rid, :job, :st, :fin, 'ok', :score, :bytes, NULL, NULL, NULL, :sum, :det)
    ");
    $insRun->execute([
      ':rid'   => (int)$R['ID_Rider'],
      ':job'   => 'manual-seal',
      ':st'    => $nowUTC,
      ':fin'   => $nowUTC,
      ':score' => (int)$score,
      ':bytes' => (int)($bytes ?? 0),
      ':sum'   => 'Auto-segellat per usuari (score > 80).',
      ':det'   => json_encode(['action'=>'auto_publish_seal','sha256'=>$sha256,'public_url'=>$publicUrl], JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable $e) {
    // Best-effort: no bloqueja la publicació
  }

  /* ───────────── Email (best-effort, silenciat) ───────────── */
  try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet  = 'UTF-8';
    $mail->Host       = $_ENV['SMTP_HOST']      ?? 'smtp.mail.me.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER']      ?? '';
    $mail->Password   = $_ENV['SMTP_PASS']      ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

    $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
    $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
    $mail->setFrom($from, $fromName);
    if (!empty($_ENV['SMTP_USER'])) { $mail->Sender = $_ENV['SMTP_USER']; }
    $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $fromName);

    $mail->addAddress('rsendra@kinosonik.com');

    $mail->isHTML(true);
    $mail->Subject = 'Rider validat automàticament';
    $safeUser = h(($R['Nom_Usuari'] ?? '') . ' ' . ($R['Cognoms_Usuari'] ?? ''));
    $safeDesc = h((string)($R['Descripcio'] ?: ('RD'.$R['ID_Rider'])));
    $safeUrl  = h($publicUrl);

    $mail->Body = "<p>L’usuari <strong>{$safeUser}</strong> ha publicat un rider (\"<em>{$safeDesc}</em>\").</p>
                   <p>Enllaç públic: <a href=\"{$safeUrl}\">{$safeUrl}</a></p>";

    $mail->send();
  } catch (Throwable $e) {
    // silenciat en producció
  }

  /* ───────────── Neteja ───────────── */
  silent_unlink($qrPngPath);
  silent_unlink($outPdf);
  silent_unlink($tmpPdf);

  /* ───────────── AUDIT ÈXIT ───────────── */
  try {
    audit_admin(
      $pdo,
      (int)$userId,
      $isAdmin,
      'auto_publish_seal',
      (int)$R['ID_Rider'],
      (string)$R['Rider_UID'],
      'riders',
      [
        'bucket'    => $bucket,
        'key'       => $key,
        'bytes'     => (int)($bytes ?? 0),
        'sha256'    => (string)($sha256 ?? ''),
        'publicUrl' => $publicUrl,
      ],
      'success',
      null
    );
  } catch (Throwable $e) {
    error_log('audit auto_publish_seal success failed: '.$e->getMessage());
  }

  json_ok([
    'estat'            => 'validat',
    'data_publicacio'  => $dataPublicacioDb,
    'sha256'           => $sha256,
    'bytes'            => (int)$bytes,
  ]);

} catch (Throwable $e) {
  // última salvaguarda
  try {
    if (!isset($pdo) || !($pdo instanceof PDO)) { $pdo = db(); }
    $uid = $_SESSION['user_id'] ?? 0;
    $isA = strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0;
    audit_admin(
      $pdo,
      (int)$uid,
      $isA,
      'auto_publish_seal',
      isset($R['ID_Rider']) ? (int)$R['ID_Rider'] : null,
      isset($R['Rider_UID']) ? (string)$R['Rider_UID'] : null,
      'riders',
      ['reason'=>'internal_catch','error'=>$e->getMessage() ],
      'error',
      'internal'
    );
  } catch (Throwable $e2) {
    error_log('audit auto_publish_seal internal failed: '.$e2->getMessage());
  }
  json_fail('internal', 500);
}