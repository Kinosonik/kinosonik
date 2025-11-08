<?php
// php/rider.php — vista/descàrrega d’un rider (amb presigned R2)
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

use Aws\Exception\AwsException;

$pdo = db();

function deny(int $code, string $reason): never {
  http_response_code($code);
  error_log('[RIDER ' . $code . '] reason='.$reason
    .' ip='.($_SERVER['REMOTE_ADDR'] ?? '-')
    .' uri='.($_SERVER['REQUEST_URI'] ?? '-')
    .' ref='.($_SERVER['HTTP_REFERER'] ?? '-')
    .' ua='.($_SERVER['HTTP_USER_AGENT'] ?? '-'));
  exit;
}
function safe_filename(string $s): string {
  $s = str_replace(["\r","\n"], '', $s);
  $s = preg_replace('/[^A-Za-z0-9.\-_ ]+/', '_', $s);
  return $s !== '' ? $s : 'rider.pdf';
}
function audit_access(PDO $pdo, string $status, ?array $row, array $meta=[], ?string $err=null): void {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'rider_access',
      (int)($row['ID_Rider'] ?? 0),
      (string)($row['Rider_UID'] ?? ''),
      'rider',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) { error_log('audit rider_access failed: '.$e->getMessage()); }
}

/* --- Allow embedding from same-origin (visualitza.php) --- */
header_remove('X-Frame-Options');
header('X-Frame-Options: SAMEORIGIN', true);

// Substitueix CSP global per una d’específica
header_remove('Content-Security-Policy');
header('Content-Security-Policy: ' . implode('; ', [
  "base-uri 'self'",
  "form-action 'self'",
  "default-src 'self'",
  "img-src 'self' data: https:",
  "style-src 'self' 'unsafe-inline' https:",
  "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:",
  "font-src 'self' data: https:",
  "connect-src 'self' https:",
  "frame-ancestors 'self'",
  "upgrade-insecure-requests"
]));

$ref = (string)($_GET['ref'] ?? '');
if ($ref === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref)) {
  deny(400, 'bad_ref');
}

try {
  $stmt = $pdo->prepare("
    SELECT ID_Rider, ID_Usuari, Rider_UID, Nom_Arxiu, Object_Key, Estat_Segell, Referencia, rider_actualitzat
      FROM Riders
     WHERE Rider_UID = :uid
     LIMIT 1
  ");
  $stmt->execute([':uid' => $ref]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) deny(404, 'not_found');

  $estat     = strtolower((string)($row['Estat_Segell'] ?? ''));
  $objectKey = (string)($row['Object_Key'] ?? '');
  $ownerId   = (int)$row['ID_Usuari'];
  $refBand   = trim((string)($row['Referencia'] ?? ''));

  $me      = (int)($_SESSION['user_id'] ?? 0);
  $tipus   = (string)($_SESSION['tipus_usuari'] ?? '');
  $isAdmin = (strcasecmp($tipus, 'admin') === 0);

  // Si caducat → intenta redirigir a una versió VALIDADA més nova (mateix usuari + referència)
  if ($estat === 'caducat') {
    if ($refBand !== '') {
      $stn = $pdo->prepare("
        SELECT Rider_UID
          FROM Riders
         WHERE ID_Usuari = :uid AND Referencia = :refb AND Estat_Segell = 'validat'
         ORDER BY Data_Pujada DESC, ID_Rider DESC
         LIMIT 1
      ");
      $stn->execute([':uid' => $ownerId, ':refb' => $refBand]);
      $new = $stn->fetch(PDO::FETCH_ASSOC);
      if ($new && !empty($new['Rider_UID']) && strcasecmp($new['Rider_UID'], $ref) !== 0) {
        header('Location: ' . BASE_PATH . 'php/rider.php?ref=' . rawurlencode($new['Rider_UID']), true, 302);
        exit;
      }
    }
    // Propietari/admin poden veure’l malgrat caducat; públic no.
    if (!($isAdmin || $me === $ownerId)) {
      deny(410, 'expired_no_replacement');
    }
  }

  // Permisos: admin o propietari → sempre; públic → només VALIDAT
  $publicAllowed = ($estat === 'validat');
  $isOwner = ($me === $ownerId);
  $allowed = can_view_rider($sealState, $isOwner, $isAdmin);
  if (!$allowed) {
    audit_access($pdo, 'error', $row, ['public_allowed'=>$publicAllowed], 'forbidden');
    // 404 per no filtrar existència
    deny(404, 'forbidden');
  }

  if ($objectKey === '') deny(500, 'empty_object_key');

  $isDownload  = (isset($_GET['dl'])   && $_GET['dl']   === '1');
  $isView      = (isset($_GET['view']) && $_GET['view'] === '1');
  $disposition = $isDownload ? 'attachment' : 'inline';

  $safeName = safe_filename((string)($row['Nom_Arxiu'] ?? 'rider-'.$row['Rider_UID'].'.pdf'));

  $client = r2_client();
  $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
  if ($bucket === '') throw new RuntimeException('R2_BUCKET no configurat');

  if ($isView) {
    // Presigned + proxy via cURL (fallback a redirect)
    $cmd = $client->getCommand('GetObject', [
      'Bucket' => $bucket,
      'Key'    => $objectKey,
      'ResponseContentType' => 'application/pdf',
    ]);
    $request   = $client->createPresignedRequest($cmd, '+10 minutes');
    $signedUrl = (string)$request->getUri();

    if (!function_exists('curl_init')) {
      header('Location: ' . $signedUrl, true, 302);
      exit;
    }

    if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');
    while (ob_get_level() > 0) { @ob_end_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.$safeName.'"');
    header('Cache-Control: private, max-age=600');

    $ch = curl_init($signedUrl);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_TIMEOUT        => 120,
      CURLOPT_HTTPHEADER     => ['Accept: application/pdf'],
      CURLOPT_HEADERFUNCTION => function($ch, $line) {
        if (stripos($line, 'content-length:') === 0) header(trim($line));
        return strlen($line);
      },
      CURLOPT_WRITEFUNCTION  => function($ch, $data) {
        echo $data; @flush(); @ob_flush(); return strlen($data);
      },
    ]);
    $ok = curl_exec($ch);
    if ($ok === false) {
      error_log("rider.php: cURL stream error (".curl_errno($ch)."): ".curl_error($ch));
      header('Location: ' . $signedUrl, true, 302);
    }
    curl_close($ch);

    audit_access($pdo, 'success', $row, ['via'=>'proxy_view','public'=>$publicAllowed]);
    exit;
  }

  // Presigned redirect (download/inline)
  $cmd = $client->getCommand('GetObject', [
    'Bucket' => $bucket,
    'Key'    => $objectKey,
    'ResponseContentType'        => 'application/pdf',
    'ResponseContentDisposition' => $disposition . '; filename="' . $safeName . '"',
  ]);
  $request = $client->createPresignedRequest($cmd, '+10 minutes');

  audit_access($pdo, 'success', $row, ['via'=>'redirect','disposition'=>$disposition,'public'=>$publicAllowed]);
  header('Location: ' . (string)$request->getUri(), true, 302);
  exit;

} catch (AwsException $e) {
  error_log('rider.php AWS: ' . $e->getMessage());
  deny(502, 'aws_error');
} catch (Throwable $t) {
  error_log('rider.php: ' . $t->getMessage());
  deny(500, 'server_error');
}