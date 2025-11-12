<?php
// php/ia_demo.php — Anàlisi heurístic gratuït amb traça completa
declare(strict_types=1);

require_once __DIR__ . '/preload.php';
require_once __DIR__ . '/ai_utils.php';
require_once __DIR__ . '/ia_extract_heuristics.php';
require_once __DIR__ . '/ks_pdf.php';
require_once __DIR__ . '/audit.php';

set_time_limit(30);
header('Content-Type: application/json; charset=UTF-8');

// Helper per netejar i sortir
function cleanup_and_exit(array $response, int $code = 400, array $files = []): never {
    foreach ($files as $f) {
        if (file_exists($f)) @unlink($f);
    }
    http_response_code($code);
    echo json_encode($response);
    exit;
}

// Validació del mètode
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cleanup_and_exit(['error' => 'method_not_allowed'], 405);
}

// CSRF per usuaris loguejats
if (!empty($_SESSION['user_id'])) {
    if (empty($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        error_log("CSRF failed for user {$_SESSION['user_id']}");
        cleanup_and_exit(['error' => 'csrf_invalid'], 403);
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// 🧹 Elimina bloquejos antics del mateix IP (prevenció de stale locks)
foreach (glob(sys_get_temp_dir() . '/demo_rate_' . md5($ip) . '.{txt,lock}', GLOB_BRACE) as $old) {
    $age = time() - @filemtime($old);
    if ($age > 300) { // més de 5 minuts
        @unlink($old);
        error_log("[ia_demo] netejat stale lock: $old (age=$age)");
    }
}

// Rate limit amb file locking
$rateFile = sys_get_temp_dir() . '/demo_rate_' . md5($ip) . '.txt';
$now = time();
$lockFile = $rateFile . '.lock';
$lock = fopen($lockFile, 'c+');
if (!$lock) cleanup_and_exit(['error' => 'lock_failed'], 500);

// Si ja està bloquejat → sortim
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    cleanup_and_exit(['error' => 'rate_limit_locked'], 429);
}

// Neteja fitxers vells (> 1 dia)
foreach (glob(sys_get_temp_dir() . '/demo_rate_*.{txt,lock}', GLOB_BRACE) as $f) {
    if (filemtime($f) < time() - 86400) @unlink($f);
}

// --- A PARTIR D’AQUÍ SOTA EL LOCK ---
try {
    $entries = [];
    if (is_file($rateFile)) {
        foreach (explode("\n", file_get_contents($rateFile)) as $line) {
            $t = (int)trim($line);
            if ($t > 0 && ($now - $t) < 600) $entries[] = $t;
        }
    }
    if (count($entries) >= 5) {
        flock($lock, LOCK_UN);
        fclose($lock);
        cleanup_and_exit(['error' => 'too_many_attempts'], 429);
    }
    $entries[] = $now;
    file_put_contents($rateFile, implode("\n", $entries), LOCK_EX);
} finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
}

// ─── Cleanup segur per si falla abans del STEP21 ─────────────
$cutoff = time() - 300; // 5 minuts
$deleted = 0;
foreach (glob('/tmp/demo_ia_*.pdf') as $f) {
    if (filemtime($f) < $cutoff && @unlink($f)) $deleted++;
}
foreach (glob('/tmp/demo_rate_*.lock') as $f) {
    if (filemtime($f) < $cutoff && @unlink($f)) $deleted++;
}
if ($deleted > 0) {
    error_log("[ia_demo] cleanup auto: $deleted fitxers antics esborrats");
}


// Validació del fitxer
if (empty($_FILES['file']['tmp_name'])) {
    cleanup_and_exit(['error' => 'no_file'], 400);
}

$originalName = $_FILES['file']['name'] ?? 'unknown.pdf';
$name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
$name = substr($name, 0, 255);
if (empty($name) || $name === '_') $name = 'rider_' . uniqid() . '.pdf';

$tmp = sys_get_temp_dir() . '/demo_ia_' . uniqid('', true) . '.pdf';
if (!copy($_FILES['file']['tmp_name'], $tmp)) {
    cleanup_and_exit(['error' => 'file_copy_failed'], 500);
}

$tempFiles = [$tmp];
$size = filesize($tmp);
if ($size === false || $size > 20 * 1024 * 1024) {
    cleanup_and_exit(['error' => 'file_too_large'], 400, $tempFiles);
}

// Validació magic bytes
$fp = fopen($tmp, 'rb');
if (!$fp) cleanup_and_exit(['error' => 'file_read_failed'], 500, $tempFiles);
$header = fread($fp, 5);
fclose($fp);
if ($header !== '%PDF-') {
    cleanup_and_exit(['error' => 'invalid_file'], 400, $tempFiles);
}

$mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) ?: 'application/pdf';
if (!in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true)) {
    cleanup_and_exit(['error' => 'invalid_file'], 400, $tempFiles);
}

// Extracció de text
try {
    $text = ks_pdf_to_text($tmp);
    if (empty($text)) {
        cleanup_and_exit(['error' => 'pdf_extract_failed'], 400, $tempFiles);
    }
    if (mb_strlen($text, 'UTF-8') < 50) {
        cleanup_and_exit(['error' => 'pdf_content_too_short'], 400, $tempFiles);
    }
} catch (Throwable $e) {
    error_log("[ia_demo] PDF extraction error: " . $e->getMessage());
    cleanup_and_exit(['error' => 'pdf_processing_failed'], 500, $tempFiles);
}

// Anàlisi heurístic
if (!function_exists('ia_extract_heuristics')) {
    cleanup_and_exit(['error' => 'service_unavailable'], 500, $tempFiles);
}

try {
    $result = ia_extract_heuristics($text, [
        'mode'      => 'demo',
        'source'    => 'anonymous',
        'file_name' => $name,
        'ip'        => $ip
    ]);
    error_log("[ia_demo] STEP17 després de heuristics");
    $score = max(0, min(100, (int)round($result['score'] ?? 0)));
} catch (Throwable $e) {
    error_log("[ia_demo] Heuristics error: " . $e->getMessage());
    cleanup_and_exit(['error' => 'analysis_failed'], 500, $tempFiles);
}

// Diccionari multillengua
$lang = in_array($_POST['lang'] ?? 'ca', ['ca', 'es', 'en'], true) ? $_POST['lang'] : 'ca';
$labels = [
    'ca' => ['bad' => 'Rider deficient', 'weak' => 'Rider feble', 'good' => 'Rider correcte'],
    'es' => ['bad' => 'Rider deficiente', 'weak' => 'Rider débil', 'good' => 'Rider correcto'],
    'en' => ['bad' => 'Poor rider', 'weak' => 'Weak rider', 'good' => 'Good rider'],
];
$label = $score <= 65 ? $labels[$lang]['bad'] : ($score <= 80 ? $labels[$lang]['weak'] : $labels[$lang]['good']);

// Logging
$hash = hash_file('sha256', $tmp);
$logEntry = json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $ip,
    'hash' => $hash,
    'score' => $score,
    'label' => $label,
    'lang' => $lang,
    'size' => $size,
    'text_len' => mb_strlen($text, 'UTF-8'),
]) . "\n";
$logFile = '/var/config/logs/riders/demo_ia.log';
if (is_writable(dirname($logFile))) {
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Neteja
foreach ($tempFiles as $f) @unlink($f);

// Audit
try {
    if (function_exists('audit_admin')) {
        audit_admin(db(), (int)($_SESSION['user_id'] ?? 0), false, 'ia_demo_run', null, null, 'public_demo', [
            'ip' => $ip, 'file' => $name, 'size' => $size, 'score' => $score, 'version' => 'demo-v2'
        ]);
    }
} catch (Throwable $e) {
    error_log("[ia_demo] Audit error: " . $e->getMessage());
}

// Resposta final
http_response_code(200);
echo json_encode([
    'score' => $score,
    'label' => $label,
    'lang'  => $lang,
    'version' => 'demo-v2'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
