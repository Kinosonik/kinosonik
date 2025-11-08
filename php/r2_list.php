<?php
// php/r2_list.php — Llista objectes del bucket R2 (ús d’ADMIN)
// Mostra text/plain; protegeix via sessió/rol i audita l’accés.
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/audit.php';

// ── Protecció: només ADMIN
if (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') !== 0) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "403 — Forbidden\n";
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');

// (Opcional) Força logging, però no mostris errors per pantalla
@ini_set('display_errors','0');
@ini_set('log_errors','1');

// Helpers
function humanBytes(int $b): string {
  $u = ['B','KB','MB','GB','TB']; $i=0; $v=(float)$b;
  while ($v>=1024 && $i<count($u)-1) { $v/=1024; $i++; }
  return number_format($v, $i===0?0:1) . ' ' . $u[$i];
}
function safe_prefix(?string $p): string {
  $p = trim((string)$p);
  // Admet només caràcters segurs i limita longitud
  $p = preg_replace('#[^A-Za-z0-9/_\.\-]#', '', $p) ?? '';
  return mb_substr($p, 0, 200, 'UTF-8');
}

use Aws\Exception\AwsException;

$bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
if ($bucket === '') {
  echo "❌ Falta R2_BUCKET\n";
  exit(1);
}

$prefix = safe_prefix($_GET['prefix'] ?? '');

try {
  $pdo = function_exists('db') ? db() : null;
  if ($pdo) {
    // Audit: consulta del llistat (no loguem resultats)
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      true,
      'r2_list',
      null,
      null,
      'admin',
      ['prefix' => $prefix !== '' ? $prefix : null],
      'success',
      null
    );
  }
} catch (Throwable $e) {
  error_log('audit r2_list failed: ' . $e->getMessage());
}

try {
  $s3 = r2_client();

  echo "Bucket: {$bucket}" . ($prefix !== '' ? " | Prefix: {$prefix}" : '') . "\n";
  echo "Hora: " . gmdate('c') . " (UTC)\n\n";

  $token = null; $count = 0; $pages = 0;
  do {
    $args = ['Bucket' => $bucket, 'MaxKeys' => 1000];
    if ($prefix !== '') $args['Prefix'] = $prefix;
    if ($token) $args['ContinuationToken'] = $token;

    $res = $s3->listObjectsV2($args);
    $pages++;

    if (!empty($res['Contents'])) {
      foreach ($res['Contents'] as $obj) {
        $k  = (string)($obj['Key'] ?? '');
        $s  = (int)($obj['Size'] ?? 0);
        $lm = '-';
        if (isset($obj['LastModified'])) {
          $lmVal = $obj['LastModified'];
          if ($lmVal instanceof DateTimeInterface)      $lm = $lmVal->setTimezone(new DateTimeZone('UTC'))->format('c');
          elseif (is_string($lmVal) && $lmVal !== '')   $lm = gmdate('c', strtotime($lmVal));
        }
        echo "- {$k}\n  • Size: " . humanBytes($s) . " ({$s} B)\n  • LastModified: {$lm}\n";
        $count++;
      }
    }

    $token = !empty($res['IsTruncated']) ? (string)$res['NextContinuationToken'] : null;

    // Tall de seguretat: evita dumps massa grans (configurable)
    if ($pages >= 200) {  // ~200k objectes teòrics
      echo "\n(Interromput per límit de pàgines: {$pages})\n";
      break;
    }
  } while ($token);

  if ($count === 0) {
    echo "(no hi ha objectes)\n";
  }
  echo "\nTotal objectes llistats: {$count}\nFI\n";

} catch (AwsException $e) {
  $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
  echo "❌ AWS: {$msg}\n";
  exit(1);
} catch (Throwable $e) {
  echo "❌ Error llistant bucket: " . $e->getMessage() . "\n";
  exit(1);
}