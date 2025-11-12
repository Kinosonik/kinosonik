<?php
/**
 * MAIL WORKER - Processador de cua de correus
 * Envia correus encuats amb PHPMailer i sistema de secrets centralitzat
 * 
 * @version 0.4-secrets
 */

// Ruta esperada: /var/www/html/php/cron/mail_worker.php


declare(strict_types=1);
date_default_timezone_set('Europe/Madrid');

// ═══════════════════════════════════════════════════════════════
// SECRETS I CONFIGURACIÓ
// ═══════════════════════════════════════════════════════════════

define('KS_SECRET_EXPORT_ENV', true);
$SECRETS = require dirname(__DIR__, 2) . '/php/secret.php';
require dirname(__DIR__, 2) . '/php/preload.php';
require dirname(__DIR__, 2) . '/php/db.php';

// ═══════════════════════════════════════════════════════════════
// DEPENDÈNCIES PHPMAILER (Debian o Composer)
// ═══════════════════════════════════════════════════════════════

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$autoloadPaths = [
    '/usr/share/php/libphp-phpmailer/autoload.php',  // Debian/Ubuntu
    dirname(__DIR__, 2) . '/vendor/autoload.php'     // Composer
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    error_log('[mail_worker] PHPMailer not found!');
    exit(1);
}

// ═══════════════════════════════════════════════════════════════
// CONFIGURACIÓ DEL WORKER
// ═══════════════════════════════════════════════════════════════

$pdo   = db();
$pid   = getmypid();
$host  = php_uname('n');
$ver   = 'mail-worker-0.4-secrets';
$logf  = '/var/config/logs/riders/mail_worker.log';
$lockf = '/tmp/mail_worker.lock';

$CONFIG = [
    'batch_size'    => 10,    // Correus per execució
    'max_retries'   => 3,     // Intents màxims
    'rate_limit_ms' => 200,   // Milisegons entre correus
    'cleanup_days'  => 30,    // Dies per mantenir correus antics
];

// ═══════════════════════════════════════════════════════════════
// LOGGING JSON ESTRUCTURAT
// ═══════════════════════════════════════════════════════════════

function log_json(string $lvl, string $event, array $data = []): void {
    global $pid, $host, $ver, $logf;
    
    $entry = array_merge([
        'ts'    => date('c'),
        'lvl'   => $lvl,
        'event' => $event,
        'pid'   => $pid,
        'host'  => $host,
        'ver'   => $ver,
    ], $data);
    
    @file_put_contents($logf, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    
    // Log crític també a error_log
    if ($lvl === 'error' || $lvl === 'fatal') {
        error_log("[mail_worker] $event: " . ($data['err'] ?? json_encode($data)));
    }
}

// ═══════════════════════════════════════════════════════════════
// LOCK EXCLUSIU (evita execucions simultànies)
// ═══════════════════════════════════════════════════════════════

$lock = @fopen($lockf, 'c+');
if (!$lock) {
    log_json('error', 'lock_open_fail', ['file' => $lockf]);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    log_json('info', 'already_running');
    fclose($lock);
    exit(0);
}

log_json('info', 'tick_start');

try {
    // ═══════════════════════════════════════════════════════════
    // CREAR TAULA SI NO EXISTEIX
    // ═══════════════════════════════════════════════════════════
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            status ENUM('pending','sent','error') DEFAULT 'pending',
            retries TINYINT UNSIGNED DEFAULT 0,
            error_msg TEXT NULL,
            INDEX idx_status (status),
            INDEX idx_status_retries (status, retries),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Índex únic per evitar duplicats (opcional, pot fallar si hi ha duplicats)
    try {
        $pdo->exec("ALTER TABLE mail_queue ADD UNIQUE KEY uniq_pending (to_email, status)");
    } catch (Throwable $e) {
        // Index ja existeix o hi ha duplicats, silent fail
    }
    
    // ═══════════════════════════════════════════════════════════
    // CLEAN-UP AUTOMÀTIC (correus antics >30 dies)
    // ═══════════════════════════════════════════════════════════
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM mail_queue 
            WHERE status IN ('sent', 'error') 
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$CONFIG['cleanup_days']]);
        
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            log_json('info', 'cleanup_completed', [
                'deleted' => $deleted,
                'older_than_days' => $CONFIG['cleanup_days']
            ]);
        }
    } catch (Throwable $e) {
        log_json('warn', 'cleanup_fail', ['err' => $e->getMessage()]);
    }
    
    // ═══════════════════════════════════════════════════════════
    // RECUPERAR CORREUS PENDENTS O AMB ERRORS (<3 intents)
    // ═══════════════════════════════════════════════════════════
    
    $stmt = $pdo->prepare("
        SELECT * FROM mail_queue
        WHERE status IN ('pending', 'error') 
        AND retries < ?
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->execute([$CONFIG['max_retries'], $CONFIG['batch_size']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$rows) {
        log_json('info', 'queue_empty');
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(0);
    }
    
    log_json('info', 'processing_batch', ['count' => count($rows)]);
    
    // ═══════════════════════════════════════════════════════════
    // PROCESSAR CADA CORREU
    // ═══════════════════════════════════════════════════════════
    
    $sentCount = 0;
    $errorCount = 0;
    
    foreach ($rows as $row) {
        $id      = (int)$row['id'];
        $email   = (string)$row['to_email'];
        $subject = (string)$row['subject'];
        $body    = (string)$row['body'];
        $retries = (int)$row['retries'];
        
        try {
            // ───────────────────────────────────────────────────
            // CONFIGURAR PHPMAILER
            // ───────────────────────────────────────────────────
            
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->CharSet       = 'UTF-8';
            $mail->Encoding      = 'quoted-printable';
            $mail->isHTML(true);
            $mail->SMTPAuth      = true;
            $mail->Timeout       = 20;
            $mail->SMTPKeepAlive = false;
            
            // Configuració SMTP des de secret.php
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.mail.me.com';
            $mail->Username   = $_ENV['SMTP_USER'] ?? '';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
            
            // Remitent
            $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? 'no-reply@kinosonik.com');
            $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
            
            $mail->setFrom($from, $fromName);
            
            if (!empty($_ENV['SMTP_USER'])) {
                $mail->Sender = $_ENV['SMTP_USER'];
            }
            
            $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $fromName);
            $mail->addAddress($email);
            
            // Contingut
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body); // Text pla per clients sense HTML
            
            // ───────────────────────────────────────────────────
            // ENVIAR
            // ───────────────────────────────────────────────────
            
            $mail->send();
            
            // Marcar com enviat
            $stmt = $pdo->prepare("
                UPDATE mail_queue 
                SET status = 'sent', 
                    sent_at = NOW(),
                    error_msg = NULL
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            $sentCount++;
            
            log_json('info', 'mail_sent', [
                'id'      => $id,
                'to'      => $email,
                'subject' => mb_substr($subject, 0, 50),
                'retry'   => $retries
            ]);
            
        } catch (Exception $e) {
            // ───────────────────────────────────────────────────
            // ERROR D'ENVIAMENT
            // ───────────────────────────────────────────────────
            
            $errorMsg = $e->getMessage();
            
            $stmt = $pdo->prepare("
                UPDATE mail_queue 
                SET status = 'error', 
                    retries = retries + 1,
                    error_msg = ?
                WHERE id = ?
            ");
            $stmt->execute([$errorMsg, $id]);
            
            $errorCount++;
            
            log_json('error', 'mail_fail', [
                'id'      => $id,
                'to'      => $email,
                'subject' => mb_substr($subject, 0, 50),
                'retry'   => $retries + 1,
                'err'     => $errorMsg,
                'code'    => $e->getCode()
            ]);
        }
        
        // Rate limiting: evita saturar SMTP
        usleep($CONFIG['rate_limit_ms'] * 1000);
    }
    
    // ═══════════════════════════════════════════════════════════
    // RESUM DE L'EXECUCIÓ
    // ═══════════════════════════════════════════════════════════
    
    log_json('info', 'batch_completed', [
        'processed' => count($rows),
        'sent'      => $sentCount,
        'errors'    => $errorCount
    ]);
    
} catch (PDOException $e) {
    log_json('fatal', 'database_error', [
        'err'  => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
} catch (Throwable $e) {
    log_json('fatal', 'unexpected_error', [
        'err'   => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
} finally {
    // Allibera el lock sempre
    flock($lock, LOCK_UN);
    fclose($lock);
    log_json('info', 'tick_end');
}