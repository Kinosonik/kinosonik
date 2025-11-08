<?php
// php/tools/test_mail.php
declare(strict_types=1);

// Carrega env, sessió, etc.
require_once __DIR__ . '/../config.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Helper ràpid
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Receptor de prova
$to = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_EMAIL);
if (!$to) {
  // per defecte envia’t-ho a tu si no passes ?to=
  $to = $_ENV['SMTP_USER'] ?? 'rsendra@kinosonik.com';
}

$mail = new PHPMailer(true);

try {
  // DEBUG: comenta aquesta línia un cop funcioni
  // $mail->SMTPDebug = 2; // Mostra diàleg SMTP (no en producció)

  $mail->isSMTP();
  $mail->Host       = $_ENV['SMTP_HOST'] ?? '';
  $mail->SMTPAuth   = true;
  $mail->Username   = $_ENV['SMTP_USER'] ?? '';
  $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

  // iCloud sol exigir que el From = compte/alias verificat → fem servir el USER
  $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
  $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';

  $mail->setFrom($from, $fromName);
  $mail->Sender = $_ENV['SMTP_USER'] ?? ''; // envelope/Return-Path
  // Opcional: on vols que responguin
  $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders');

  $mail->addAddress($to);

  $mail->isHTML(true);
  $mail->Subject = 'Prova SMTP Riders';
  $mail->Body    = '<p>Funciona 🎉</p>';

  if ($mail->send()) {
    echo "OK: missatge enviat a " . h($to);
  } else {
    // En teoria, si entra aquí, hi haurà ErrorInfo
    echo "ERROR: PHPMailer va retornar false<br>Detall: " . h($mail->ErrorInfo);
  }

} catch (Exception $e) {
  echo "EXCEPCIÓ: " . h($e->getMessage()) . "<br>Detall PHPMailer: " . h($mail->ErrorInfo);
}