<?php
declare(strict_types=1);

// Llibreria d'accés a Cloudflare R2 via AWS S3 SDK (sense side-effects)
require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;

/** Llegeix la primera variable definida entre diverses claus (env/ENV/SERVER). */
if (!function_exists('env_first')) {
  function env_first(array $names, string $default = ''): string {
    foreach ($names as $n) {
      $v = getenv($n);
      if ($v !== false && $v !== '') return (string)$v;
      if (!empty($_ENV[$n]))    return (string)$_ENV[$n];
      if (!empty($_SERVER[$n])) return (string)$_SERVER[$n];
    }
    return $default;
  }
}


// r2.php — helpers bàsics R2

if (!function_exists('r2_public_url')) {
  function r2_public_url(string $key): ?string {
    // 1) Preferim base pública via CDN (config)
    $base = getenv('R2_PUBLIC_BASE') ?: (defined('R2_PUBLIC_BASE') ? R2_PUBLIC_BASE : '');
    if ($base) return rtrim($base,'/').'/'.ltrim($key,'/');

    // 2) Fallback S3 endpoint clàssic si tens variables
    $acct   = getenv('R2_ACCOUNT_ID');
    $bucket = getenv('R2_BUCKET');
    if ($acct && $bucket) {
      return "https://{$acct}.r2.cloudflarestorage.com/{$bucket}/".ltrim($key,'/');
    }
    return null;
  }
}

if (!function_exists('r2_presign_key')) {
  function r2_presign_key(string $key, int $ttl = 120): ?string {
    // Si no tens signatura S3, torna null i el codi farà servir r2_public_url
    return null;
  }
}


/** Endpoint R2: de R2_ENDPOINT o derivat de R2_ACCOUNT_ID. */
function r2_endpoint(): string {
  $endpoint = env_first(['R2_ENDPOINT']);
  if ($endpoint === '') {
    $account = env_first(['R2_ACCOUNT_ID']);
    if ($account !== '') $endpoint = "https://{$account}.r2.cloudflarestorage.com";
  }
  // Normalitza sense barra final
  return rtrim($endpoint, '/');
}

/** Nom del bucket (error si no està definit). */
function r2_bucket(): string {
  $bucket = env_first(['R2_BUCKET']);
  if ($bucket === '') {
    throw new RuntimeException('R2_BUCKET no definit al teu entorn/secrets.');
  }
  return $bucket;
}

/** Client S3 per a R2 (singleton). */
function r2_client(): S3Client {
  static $client = null;
  if ($client instanceof S3Client) return $client;

  $endpoint = r2_endpoint();
  $key = env_first(['R2_ACCESS_KEY','R2_ACCESS_KEY_ID']);
  $sec = env_first(['R2_SECRET_KEY','R2_SECRET_ACCESS_KEY']);

  if ($endpoint === '' || $key === '' || $sec === '') {
    throw new RuntimeException(
      'Config R2 incompleta: cal R2_ENDPOINT (o R2_ACCOUNT_ID) i claus (R2_ACCESS_KEY / R2_SECRET_KEY).'
    );
  }

  // Nota: R2 funciona amb 'region' => 'auto' i signatura v4
  $client = new S3Client([
    'version'                 => 'latest',
    'region'                  => 'auto',
    'endpoint'                => $endpoint,
    'credentials'             => ['key' => $key, 'secret' => $sec],
    'use_path_style_endpoint' => true,
    'signature_version'       => 'v4',
    // (Opcional) timeouts prudents:
    // 'http' => ['connect_timeout' => 5, 'timeout' => 20],
  ]);

  return $client;
}
/* ───────────────────────────
   Funció genèrica: r2_upload
   Pujar fitxer local a R2 i retornar metadades
─────────────────────────── */
if (!function_exists('r2_upload')) {
  /**
   * @param string $localPath  Ruta local del fitxer
   * @param string $remoteKey  Clau objecte dins el bucket
   * @param string $contentType MIME (per defecte application/pdf)
   * @return array {ok:bool, key:string, bytes:int, hash:string, public_url:string|null}
   * @throws RuntimeException si falla la pujada
   */
  function r2_upload(string $localPath, string $remoteKey, string $contentType = 'application/pdf'): array {
    if (!is_file($localPath)) {
      throw new RuntimeException("Fitxer inexistent: {$localPath}");
    }

    $size = filesize($localPath);
    if ($size === false || $size <= 0) {
      throw new RuntimeException("Fitxer buit o no llegible: {$localPath}");
    }

    // Calcular hash SHA-256
    $hash = @hash_file('sha256', $localPath);
    if (!is_string($hash) || strlen($hash) !== 64) {
      throw new RuntimeException("No s'ha pogut calcular el hash SHA-256 per {$localPath}");
    }

    // Obtenir client i bucket
    $client = r2_client();
    $bucket = r2_bucket();

    // Pujar fitxer
    $stream = fopen($localPath, 'rb');
    if ($stream === false) {
      throw new RuntimeException("No s’ha pogut obrir {$localPath}");
    }

    try {
      $client->putObject([
        'Bucket'      => $bucket,
        'Key'         => $remoteKey,
        'Body'        => $stream,
        'ContentType' => $contentType,
      ]);
    } catch (Throwable $e) {
      if (is_resource($stream)) fclose($stream);
      throw new RuntimeException("Error pujant a R2: " . $e->getMessage());
    }
    if (is_resource($stream)) fclose($stream);

    // URL pública si tens public access
    $endpoint = r2_endpoint();
    $publicUrl = "{$endpoint}/{$bucket}/{$remoteKey}";

    return [
      'ok'         => true,
      'key'        => $remoteKey,
      'bytes'      => (int)$size,
      'hash'       => $hash,
      'public_url' => $publicUrl,
    ];
  }
}
