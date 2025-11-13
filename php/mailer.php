<?php
// php/mailer.php — Helper centralitzat per enviament immediat de correus (amb secrets externs)
declare(strict_types=1);
$SECRETS = require '/var/config/secure/riders/secret.local.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ──────────────────────────────────────────────
   1. Carrega els secrets segurs
   ────────────────────────────────────────────── */
define('KS_SECRET_EXPORT_ENV', true); // Exporta a getenv()
$SECRETS = require __DIR__ . '/secret.php';

/* ──────────────────────────────────────────────
   2. Carrega PHPMailer (composer o manual)
   ────────────────────────────────────────────── */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
}

/* ──────────────────────────────────────────────
   3. Configuració — agafa dels secrets o posa defaults
   ────────────────────────────────────────────── */
define('MAIL_FROM', $SECRETS['SMTP_FROM'] ?? 'riders@kinosonik.com');
define('MAIL_FROM_NAME', $SECRETS['SMTP_FROM_NAME'] ?? 'Kinosonik Riders');
define('MAIL_HOST', $SECRETS['SMTP_HOST'] ?? 'localhost');
define('MAIL_PORT', (int)($SECRETS['SMTP_PORT'] ?? 25));
define('MAIL_USER', $SECRETS['SMTP_USER'] ?? '');
define('MAIL_PASS', $SECRETS['SMTP_PASS'] ?? '');
define('MAIL_SECURE', $SECRETS['SMTP_SECURE'] ?? 'tls');
define('MAIL_CHARSET', 'UTF-8');
define('MAIL_ADMIN_ALERT', $SECRETS['MAIL_ADMIN_ALERT'] ?? 'rsendra@kinosonik.com');


if (!defined('MAIL_CHARSET')) {
    define('MAIL_CHARSET', 'UTF-8');
}
if (!defined('MAIL_ADMIN_ALERT')) {
    define('MAIL_ADMIN_ALERT', getenv('MAIL_ADMIN_ALERT') ?: 'rsendra@kinosonik.com');
}



/* ──────────────────────────────────────────────
   4. Instància configurada
   ────────────────────────────────────────────── */
function ks_mailer(): PHPMailer {
    $mail = new PHPMailer(true);

    // 🔍 Mode debug SMTP (mostra conversa completa amb el servidor)
    $mail->SMTPDebug = 0;                   // 0=off, 1=min, 2=verbose
    $mail->Debugoutput = function($str, $level) {
        error_log("[mailer][debug] " . trim($str));
    };

    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = MAIL_USER !== '' && MAIL_PASS !== '';
    if ($mail->SMTPAuth) {
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
    }
    if (MAIL_SECURE !== '') {
        $mail->SMTPSecure = MAIL_SECURE;
    }
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = MAIL_CHARSET;
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->isHTML(true);
    return $mail;
}

/* ──────────────────────────────────────────────
   5. Enviament amb fallback + alerta
   ────────────────────────────────────────────── */
function ks_send_mail(
    string $toEmail,
    string $subject,
    string $htmlBody,
    ?string $plainText = null,
    ?string $toName = ''
): bool {
    try {
        $mail = ks_mailer();
        $mail->addAddress($toEmail, (string)$toName);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainText ?? strip_tags($htmlBody);
        $mail->send();
        error_log("[mailer] SMTP OK → $toEmail");
        return true;
    } catch (Exception $e) {
        $err = $e->getMessage();
        error_log("[mailer] SMTP failed for $toEmail: $err");

        // Fallback natiu
        $headers  = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
        $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=" . MAIL_CHARSET . "\r\n";

        $ok = @mail($toEmail, $subject, $htmlBody, $headers);

        // Envia alerta a l’administrador
        $alertBody = sprintf(
            "<p><b>Fallback de correu activat</b></p>
             <p><b>Data:</b> %s</p>
             <p><b>Destinatari:</b> %s</p>
             <p><b>Error SMTP:</b> %s</p>
             <p><b>Resultat fallback:</b> %s</p>",
            date('d/m/Y H:i'),
            htmlspecialchars($toEmail),
            htmlspecialchars($err),
            $ok ? 'OK' : 'FALLIT'
        );
        @mail(MAIL_ADMIN_ALERT, '[Kinosonik Riders] ALERTA: Fallback actiu', $alertBody, $headers);

        if ($ok) {
            error_log("[mailer] fallback mail() OK → $toEmail");
            return true;
        } else {
            error_log("[mailer] fallback mail() FAILED → $toEmail");
            return false;
        }
    }
}