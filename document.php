<?php
// document.php — Serveix/obre un document per id amb control d’accés (R2 presign + proxy view)
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/r2.php';

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

$docId = (int)($_GET['id'] ?? 0);
if ($docId <= 0) { http_response_code(400); exit('bad_request'); }

function deny(int $code, string $reason): never {
  http_response_code($code);
  error_log('[DOC '.$code.'] reason='.$reason
    .' ip='.($_SERVER['REMOTE_ADDR'] ?? '-')
    .' uri='.($_SERVER['REQUEST_URI'] ?? '-'));
  exit;
}
function safe_filename(string $s): string {
  $s = str_replace(["\r","\n"], '', $s);
  $s = preg_replace('/[^A-Za-z0-9.\-_ ]+/', '_', $s);
  return $s !== '' ? $s : 'document.pdf';
}

/* --- Permetre <iframe> same-origin per visualitzar PDFs --- */
header_remove('X-Frame-Options');
header('X-Frame-Options: SAMEORIGIN', true);
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

/* ── Autorització: document vinculat a una actuació del teu event ──────── */
// ── Autorització (event owner OR doc owner OR admin)
$sqlAuth = <<<SQL
SELECT e.owner_user_id AS event_owner, d.owner_user_id AS doc_owner
FROM Documents d
LEFT JOIN Stage_Day_Acts a ON (a.rider_orig_doc_id = d.id OR a.final_doc_id = d.id)
LEFT JOIN Stage_Days    sd ON sd.id = a.stage_day_id
LEFT JOIN Event_Stages  s  ON s.id = sd.stage_id
LEFT JOIN Events        e  ON e.id = s.event_id
WHERE d.id = :id
LIMIT 1
SQL;

$st = $pdo->prepare($sqlAuth);
$st->execute([':id'=>$docId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row && !$isAdmin) { deny(404,'not_found'); }

$eventOwner = (int)($row['event_owner'] ?? 0);
$docOwner   = (int)($row['doc_owner']   ?? 0);

if (!$isAdmin && $uid !== $eventOwner && $uid !== $docOwner) { deny(403,'forbidden'); }


/* ── Carrega del document ──────────────────────────────────────────────── */
$cols = $pdo->query("SHOW COLUMNS FROM Documents")->fetchAll(PDO::FETCH_COLUMN,0);
$has = fn(string $c)=>in_array($c,$cols,true);

$sel = [];
foreach (['title','filename','mime','bytes','r2_key','url','storage_path','local_path','size_bytes','mime_type'] as $c) {
  if ($has($c)) $sel[] = $c;
}
$fields = $sel ? implode(',', $sel) : '*';

$q = $pdo->prepare("SELECT $fields FROM Documents WHERE id=:id");
$q->execute([':id'=>$docId]);
$doc = $q->fetch(PDO::FETCH_ASSOC);
if (!$doc) deny(404, 'not_found');

$fnParam = (string)($_GET['fn'] ?? '');
$fn = safe_filename($fnParam !== '' ? $fnParam : ($doc['title'] ?? $doc['filename'] ?? ('document_'.$docId.'.pdf')));
$mime = (string)($doc['mime'] ?? ($doc['mime_type'] ?? 'application/pdf'));
$isDownload = isset($_GET['dl']) && $_GET['dl'] === '1';
$isView     = isset($_GET['view']) && $_GET['view'] === '1';
$disposition = $isDownload ? 'attachment' : 'inline';
$isPdf = stripos($mime,'pdf') !== false
      || preg_match('/\.pdf$/i', (($doc['filename'] ?? '').' '.($doc['title'] ?? '').' '.($doc['r2_key'] ?? '')));
if (!$isPdf) { deny(415, 'unsupported_media'); }


/* 1) URL directa si existeix */
if (!empty($doc['url'])) {
  header('Location: '.$doc['url'], true, 302);
  exit;
}

/* 2) R2 via SDK: proxy per defecte (inline). Redirigeix només si dl=1 o redir=1 */
if (!empty($doc['r2_key'])) {
  try {
    $client = r2_client();
    $bucket = r2_bucket();
    $cmd = $client->getCommand('GetObject', [
      'Bucket' => $bucket,
      'Key'    => (string)$doc['r2_key'],
      'ResponseContentType'        => $mime,
      'ResponseContentDisposition' => $disposition.'; filename="'.$fn.'"',
    ]);
    $request   = $client->createPresignedRequest($cmd, '+10 minutes');
    $signedUrl = (string)$request->getUri();

    // Proxy per defecte. Redir només si descarrega o forces redir.
    $forceRedir = isset($_GET['redir']) && $_GET['redir'] === '1';
    $proxy = !$forceRedir && !$isDownload;

    if ($proxy && function_exists('curl_init')) {
      if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
      @ini_set('zlib.output_compression', '0');
      @ini_set('output_buffering', 'off');
      while (ob_get_level() > 0) { @ob_end_clean(); }

      header('Content-Type: '.$mime);
      header('Content-Disposition: inline; filename="'.$fn.'"');
      header('Cache-Control: private, max-age=600');
      header('X-Content-Type-Options: nosniff');

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
        error_log("document.php proxy cURL error (".curl_errno($ch)."): ".curl_error($ch));
        header('Cache-Control: private, max-age=600');
        header('Location: '.$signedUrl, true, 302);
      }
      curl_close($ch);
      exit;
    }

    // Fallback proxy sense cURL
    if ($proxy) {
      header('Content-Type: '.$mime);
      header('Content-Disposition: inline; filename="'.$fn.'"');
      header('Cache-Control: private, max-age=600');
      header('X-Content-Type-Options: nosniff');
      header('X-Accel-Buffering: no');

      $ctx = stream_context_create([
        'http' => [
          'method'  => 'GET',
          'header'  => "Accept: application/pdf\r\n",
          'timeout' => 120,
        ],
        'ssl' => [
          'verify_peer'      => true,
          'verify_peer_name' => true,
        ]
      ]);
      $fp = @fopen($signedUrl, 'rb', false, $ctx);
      if ($fp) {
        while (!feof($fp)) {
          $buf = fread($fp, 8192);
          if ($buf === false) break;
          echo $buf;
          @flush(); @ob_flush();
        }
        fclose($fp);
        exit;
      }
      error_log('document.php stream fopen fallit; faig redirect');
      header('Cache-Control: private, max-age=600');
      header('Location: '.$signedUrl, true, 302);
      exit;
    }

    // Aquí només si vols redirigir (dl=1 o redir=1)
    header('Cache-Control: private, max-age=600');
    header('Location: '.$signedUrl, true, 302);
    exit;

  } catch (Throwable $e) {
    $u = r2_public_url((string)($doc['r2_key'] ?? ''));
    if ($u) { header('Location: '.$u, true, 302); exit; }
    error_log('document.php R2: '.$e->getMessage());
  }
}


/* 3) Path local com a última opció */
$path = $doc['storage_path'] ?? ($doc['local_path'] ?? null);
if ($path && is_file($path)) {
  $size = (int)($doc['size_bytes'] ?? ($doc['bytes'] ?? @filesize($path) ?: 0));
  header('Content-Type: '.$mime);
  if ($size > 0) header('Content-Length: '.$size);
  header('Content-Disposition: '.$disposition.'; filename="'.$fn.'"');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

deny(404, 'file_not_found');
