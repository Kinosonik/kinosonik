<?php
declare(strict_types=1);

/**
 * php/secret.php
 * Carrega secrets des de /var/config/secure/riders/secret.local.php
 * amb comprovacions de seguretat i memòries internes.
 *
 * Ús:
 *   $SECRETS = require __DIR__ . '/secret.php';
 *   // o bé:
 *   // define('KS_SECRET_EXPORT_ENV', true); // ← abans del require, si vols exportar a $_ENV/putenv
 *   // $SECRETS = require __DIR__ . '/secret.php';
 */

// 1) Bloqueja accés directe via HTTP (només si es crida com a script)
if (PHP_SAPI !== 'cli') {
  $isDirect = isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__;
  if ($isDirect) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not Found";
    exit;
  }
}

if (!function_exists('ks_load_secret_cfg')) {
  /**
   * Carrega i valida el fitxer de secrets, amb caché al procés.
   * @return array<string,mixed>
   * @throws RuntimeException si falta o no és llegible o no retorna array
   */
  function ks_load_secret_cfg(): array {
    static $CACHE = null;
    if (is_array($CACHE)) return $CACHE;

    $secureSecret = '/var/config/secure/riders/secret.local.php';

    // Existència i llegibilitat
    if (!is_file($secureSecret)) {
      error_log('[KS-SECURITY] secret.local.php no trobat: ' . $secureSecret);
      throw new RuntimeException('Secret configuration missing (file not found).');
    }
    if (!is_readable($secureSecret)) {
      error_log('[KS-SECURITY] secret.local.php no llegible: ' . $secureSecret);
      throw new RuntimeException('Secret configuration missing (not readable).');
    }

    // Comprovació de permisos
    $st = @stat($secureSecret);
    if ($st !== false) {
      $mode = $st['mode'] & 0777;
      $uid  = (int)$st['uid'];
      $gid  = (int)$st['gid'];

      // www-data (Debian/Ubuntu)
      $wwwGid = 33; $wwwUid = 33;
      if (function_exists('posix_getgrnam')) {
        $gr = @posix_getgrnam('www-data'); if (is_array($gr) && isset($gr['gid'])) $wwwGid = (int)$gr['gid'];
      }
      if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam('www-data'); if (is_array($pw) && isset($pw['uid'])) $wwwUid = (int)$pw['uid'];
      }

      $worldOk = (($mode & 0007) === 0);

      $ok_new = ($uid === 0 /* root */ && $gid === $wwwGid && in_array($mode, [0400,0440,0600,0640], true) && $worldOk);
      $ok_old = ($uid === $wwwUid && $gid === $wwwGid && $mode === 0600 && $worldOk);

      if (!$ok_new && !$ok_old && !getenv('KS_QUIET_SECRET_PERMS')) {
        $debounceFile = sys_get_temp_dir() . '/ks_secret_warn.lock';
        $now  = time();
        $last = is_file($debounceFile) ? (int)@file_get_contents($debounceFile) : 0;
        if ($now - $last >= 600) {
          @file_put_contents($debounceFile, (string)$now);
          error_log(sprintf(
            '[KS-SECURITY] secret.local.php permisos inusuals: perm=%04o uid=%d gid=%d (acceptats: root:www-data 0400/0440/0600/0640 o www-data:www-data 0600; world=0)',
            $mode, $uid, $gid
          ));
        }
      }
    } else {
      if (!getenv('KS_QUIET_SECRET_PERMS')) {
        $debounceFile = sys_get_temp_dir() . '/ks_secret_warn.lock';
        $now  = time();
        $last = is_file($debounceFile) ? (int)@file_get_contents($debounceFile) : 0;
        if ($now - $last >= 600) {
          @file_put_contents($debounceFile, (string)$now);
          error_log('[KS-SECURITY] stat() ha fallat sobre secret.local.php');
        }
      }
    }

    // Carrega i valida que retorni array
    $cfg = require $secureSecret;
    if (!is_array($cfg)) {
      error_log('[KS-SECURITY] secret.local.php no retorna array');
      throw new RuntimeException('Secret configuration invalid (must return array).');
    }

    return $CACHE = $cfg;
  }
}

/**
 * (Opcional) Exporta claus al medi ambient si defineixes KS_SECRET_EXPORT_ENV=true
 * - Per cada entrada escalar string/int/bool, fa $_ENV[$k] i putenv("$k=$v")
 * - No sobreescriu claus ja presents a $_ENV/getenv()
 */
if (!function_exists('ks_secret_export_to_env')) {
  function ks_secret_export_to_env(array $cfg): void {
    foreach ($cfg as $k => $v) {
      if (!is_string($k)) continue;
      if (getenv($k) !== false || isset($_ENV[$k])) continue; // respecta valors ja definits
      if (is_scalar($v)) {
        $s = (string)$v;
        $_ENV[$k] = $s;
        // Si falla putenv, ho ignorem silenciosament
        @putenv($k . '=' . $s);
      }
    }
  }
}

// Executa la càrrega i retorna l’array (com abans)
$__secrets = ks_load_secret_cfg();

// Export a ENV si està activat (define abans del require)
if (defined('KS_SECRET_EXPORT_ENV') && KS_SECRET_EXPORT_ENV === true) {
  ks_secret_export_to_env($__secrets);
}

return $__secrets;