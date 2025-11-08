<?php
/**
 * Kinosonik Riders — tools/check_bootstrap.php
 * Valida que tots els entry points carreguen php/errors_bootstrap.php i php/config.php
 * al capdamunt, en l'ordre correcte, i amb el camí adequat.
 *
 * Ús:
 *   php tools/check_bootstrap.php [BASE_PATH]
 * Per defecte, BASE_PATH = directori pare d'aquest script.
 */

declare(strict_types=1);

// ===== Config bàsica =====
$BASE = $argv[1] ?? dirname(__DIR__);            // arrel del projecte, on hi ha /php
$PHP_DIR = $BASE . '/php';

// Directories a ignorar completament en l'escaneig
$IGNORE_DIRS = [
  '/php/', '/vendor/', '/node_modules/', '/.git/', '/assets/', '/img/', '/css/', '/js/', '/fonts/', '/storage/', '/.idea/', '/.vscode/',
];

// Fitxers NO-entry (helpers/partials) que no han de portar bootstrap/config
$NON_ENTRY_FILES = [
  'footer.php','head.php','navmenu.php','constants.php','db.php','flash.php','i18n.php','messages.php',
  'middleware.php','bootstrap_env.php','secret.php',
];

// Considerem NO-entry tots els que estiguin dins /php/
function isNonEntryByPath(string $path): bool {
  return (strpos($path, '/php/') !== false);
}

// ===== Utilitats =====
function rel(string $base, string $path): string {
  return ltrim(str_replace($base, '', $path), '/');
}

function isPhpFile(string $path): bool {
  return substr($path, -4) === '.php';
}

function shouldIgnoreDir(string $path, array $ignoreDirs): bool {
  foreach ($ignoreDirs as $frag) {
    if (strpos($path, $frag) !== false) return true;
  }
  return false;
}

// Profunditat des de BASE fins al directori del fitxer (per calcular dirname(__DIR__, N))
function depthFromBase(string $base, string $file): int {
  $dir = dirname($file);
  $rel = trim(str_replace($base, '', $dir), '/');
  if ($rel === '') return 0;
  return substr_count($rel, '/') + 1;
}

// Genera els require esperats segons profunditat
function expectedRequireLines(int $depth): array {
  if ($depth === 0) {
    return [
      "require_once __DIR__ . '/php/errors_bootstrap.php';",
      "require_once __DIR__ . '/php/config.php';",
    ];
  }
  $pref = "dirname(__DIR__, {$depth})";
  return [
    "require_once {$pref} . '/php/errors_bootstrap.php';",
    "require_once {$pref} . '/php/config.php';",
  ];
}

// Extreu les primeres N línies significatives (netejant comentaris en línia simples i línies buides)
function firstLines(string $file, int $max = 80): array {
  $lines = @file($file, FILE_IGNORE_NEW_LINES);
  if (!$lines) return [];
  $out = [];
  foreach ($lines as $ln) {
    // parem si passem el límit dur
    if (count($out) >= $max) break;
    $out[] = $ln;
  }
  return $out;
}

// Troba posicions bàsiques dins les primeres línies: require bootstrap/config, session_start, HTML
function scanTop(array $lines): array {
  $res = [
    'bootstrap_idx' => null,
    'config_idx'    => null,
    'session_idx'   => null,
    'html_idx'      => null,
  ];
  foreach ($lines as $i => $ln) {
    $s = trim($ln);
    if ($res['bootstrap_idx'] === null && preg_match('/require_once\s+.*errors_bootstrap\.php[\'"]\s*;/', $s)) {
      $res['bootstrap_idx'] = $i;
    }
    if ($res['config_idx'] === null && preg_match('/require_once\s+.*config\.php[\'"]\s*;/', $s)) {
      $res['config_idx'] = $i;
    }
    if ($res['session_idx'] === null && stripos($s, 'session_start(') !== false) {
      $res['session_idx'] = $i;
    }
    if ($res['html_idx'] === null && (stripos($s, '<!doctype') === 0 || stripos($s, '<html') === 0)) {
      $res['html_idx'] = $i;
    }
  }
  return $res;
}

// ===== Escaneig =====
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($BASE, FilesystemIterator::SKIP_DOTS));
$issues = [];
$total = 0;

echo "Kinosonik Riders — comprovació de bootstrap/config\n";
echo "Base: $BASE\n\n";

foreach ($rii as $fileinfo) {
  $path = $fileinfo->getPathname();
  if (!$fileinfo->isFile()) continue;
  if (!isPhpFile($path)) continue;
  if (shouldIgnoreDir($path, $IGNORE_DIRS)) continue;

  $total++;
  $relPath = rel($BASE, $path);
  $filename = basename($path);

  $isNonEntry = isNonEntryByPath($path) || in_array($filename, $NON_ENTRY_FILES, true);
  $depth = depthFromBase($BASE, $path);
  $expected = expectedRequireLines($depth);

  $lines = firstLines($path, 80);
  $scan  = scanTop($lines);

  // Heurística: entry point si NO és non-entry i no està dins /php/
  $isEntry = !$isNonEntry;

  if ($isEntry) {
    // Comprovacions
    $missingBootstrap = ($scan['bootstrap_idx'] === null);
    $missingConfig    = ($scan['config_idx'] === null);
    $wrongOrder       = (!$missingBootstrap && !$missingConfig && $scan['bootstrap_idx'] > $scan['config_idx']);
    $tooLate          = false;

    // massa tard si apareix després de session_start o HTML
    if (!$missingBootstrap) {
      if ($scan['session_idx'] !== null && $scan['bootstrap_idx'] > $scan['session_idx']) $tooLate = true;
      if ($scan['html_idx'] !== null && $scan['bootstrap_idx'] > $scan['html_idx'])       $tooLate = true;
      if ($scan['bootstrap_idx'] > 20) $tooLate = true; // heurística conservadora
    }

    // Validació bàsica del camí (no perfecte, però útil)
    $bootstrapLine = $scan['bootstrap_idx'] !== null ? trim($lines[$scan['bootstrap_idx']]) : '';
    $configLine    = $scan['config_idx']    !== null ? trim($lines[$scan['config_idx']])    : '';
    $expectedBoot  = $expected[0];
    $expectedConf  = $expected[1];

    $pathSuspicious = false;
    if (!$missingBootstrap && strpos($bootstrapLine, $expectedBoot) === false) $pathSuspicious = true;
    if (!$missingConfig    && strpos($configLine,    $expectedConf) === false) $pathSuspicious = true;

    if ($missingBootstrap || $missingConfig || $wrongOrder || $tooLate || $pathSuspicious) {
      $issues[] = [
        'file' => $relPath,
        'type' => 'ENTRY',
        'problems' => array_values(array_filter([
          $missingBootstrap ? 'MISSING_BOOTSTRAP' : null,
          $missingConfig    ? 'MISSING_CONFIG'    : null,
          $wrongOrder       ? 'WRONG_ORDER'       : null,
          $tooLate          ? 'LATE_INCLUDE'      : null,
          $pathSuspicious   ? 'SUSPICIOUS_PATH'   : null,
        ])),
        'suggest' => $expected,
      ];
    }
  } else {
    // No entry: alerta si INCORRECTAMENT inclou bootstrap/config
    $hasBootstrap = ($scan['bootstrap_idx'] !== null);
    $hasConfig    = ($scan['config_idx']    !== null);
    if ($hasBootstrap || $hasConfig) {
      $issues[] = [
        'file' => $relPath,
        'type' => 'NON_ENTRY',
        'problems' => array_values(array_filter([
          $hasBootstrap ? 'UNNECESSARY_BOOTSTRAP' : null,
          $hasConfig    ? 'UNNECESSARY_CONFIG'    : null,
        ])),
        'suggest' => ['(no incloure cap require aquí)'],
      ];
    }
  }
}

// ===== Informe =====
if (empty($issues)) {
  echo "✅ Tot correcte: no s'han detectat problemes als $total fitxers analitzats.\n";
  exit(0);
}

echo "⚠️  Problemes detectats (" . count($issues) . " fitxers):\n\n";
foreach ($issues as $it) {
  echo "• " . $it['file'] . " [" . $it['type'] . "]\n";
  echo "  - Problemes: " . implode(', ', $it['problems']) . "\n";
  if (!empty($it['suggest'])) {
    echo "  - Suggerit al capdamunt del fitxer:\n";
    foreach ($it['suggest'] as $s) {
      echo "      $s\n";
    }
  }
  echo "\n";
}

exit(1);