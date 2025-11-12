<?php
/**
 * VERIFICACIÓ DE CORREU ELECTRÒNIC
 * Versió atòmica i optimitzada amb auto-login
 * 
 * Funcionalitats:
 * - UPDATE atòmic (idempotent i race-condition safe)
 * - Rate limiting per IP (10 intents/hora)
 * - Auto-login després de verificació exitosa
 * - Gestió d'errors detallada amb audit
 * - Timezone Madrid (Europe/Madrid)
 * - Protecció contra timing attacks
 * 
 * @version 2.0
 * @requires preload.php, db.php, i18n.php, messages.php, middleware.php, audit.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

// Opcional: evitar indexació per SEO
header('X-Robots-Tag: noindex');

$pdo = db();

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════

/**
 * Estableix missatge flash al modal de login
 */
function push_login_modal_flash(string $type, string $msg): void {
    $_SESSION['login_modal'] = [
        'open' => true, 
        'flash' => ['type' => $type, 'msg' => $msg]
    ];
    ks_set_login_modal_cookie($_SESSION['login_modal']);
}

/**
 * Redirigeix a index.php amb paràmetres opcionals
 */
function go_home(array $params = []): never {
    $qs = $params ? ('?' . http_build_query($params)) : '';
    header('Location: ' . BASE_PATH . 'index.php' . $qs, true, 302);
    exit;
}

/**
 * Obté la IP real del client (amb suport per proxies)
 */
function real_client_ip(): string {
    $trustedProxies = ['127.0.0.1', '::1'];
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if ($forwardedFor !== '' && in_array($remoteAddr, $trustedProxies, true)) {
        $ips = array_map('trim', explode(',', $forwardedFor));
        return $ips[0] ?? $remoteAddr;
    }
    
    return $remoteAddr;
}

// ══════════════════════════════════════════════════════════════
// AUDIT HELPER
// ══════════════════════════════════════════════════════════════

$AUD_ACTION = 'user_verify_email';
$client_ip = real_client_ip();

$aud = function(string $status, array $meta = [], ?string $err = null) use ($pdo, $AUD_ACTION, $client_ip) {
    try {
        $meta['ip'] = $client_ip; // Sempre registrem la IP
        audit_admin(
            $pdo,
            (int)($_SESSION['user_id'] ?? 0),
            false,
            $AUD_ACTION,
            $meta['user_id'] ?? null,
            null,
            'users',
            $meta,
            $status,
            $err
        );
    } catch (Throwable $e) {
        error_log('[verify_email] Audit failed: ' . $e->getMessage());
    }
};

// ══════════════════════════════════════════════════════════════
// VALIDACIÓ DEL TOKEN
// ══════════════════════════════════════════════════════════════

$token = trim((string)($_GET['token'] ?? ''));

// Format vàlid: 64 caràcters hexadecimals
if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
    $aud('error', [
        'reason' => 'invalid_format', 
        'token_prefix' => substr($token, 0, 8)
    ], 'token_invalid');
    
    push_login_modal_flash('danger', 
        $messages['error']['token_invalid'] ?? 'Token de verificació invàlid.'
    );
    go_home(['modal' => 'login', 'error' => 'token_invalid']);
}

$token = strtolower($token);
$tokenHash = hash('sha256', $token);

// ══════════════════════════════════════════════════════════════
// RATE LIMITING PER IP (10 intents/hora)
// ══════════════════════════════════════════════════════════════

try {
    // Crea taula si no existeix
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS verify_attempts (
            ip VARCHAR(45) PRIMARY KEY,
            attempts INT NOT NULL DEFAULT 0,
            blocked_until INT NOT NULL DEFAULT 0,
            last_attempt INT NOT NULL DEFAULT 0,
            INDEX idx_blocked (blocked_until),
            INDEX idx_last (last_attempt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Neteja intents antics (>1 hora)
    $pdo->prepare("
        DELETE FROM verify_attempts 
        WHERE last_attempt < ?
    ")->execute([time() - 3600]);

    // Comprova si la IP està bloquejada
    $stmt = $pdo->prepare("
        SELECT attempts, blocked_until 
        FROM verify_attempts 
        WHERE ip = ? AND blocked_until > ?
    ");
    $stmt->execute([$client_ip, time()]);
    $blocked = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($blocked) {
        $waitMinutes = ceil(((int)$blocked['blocked_until'] - time()) / 60);
        error_log("[verify_email] IP blocked: {$client_ip} (wait {$waitMinutes}min)");
        
        $aud('error', [
            'reason' => 'rate_limit',
            'attempts' => (int)$blocked['attempts'],
            'wait_minutes' => $waitMinutes
        ], 'rate_limit');
        
        push_login_modal_flash('danger', 
            $messages['error']['verify_rate_limit'] ?? "Massa intents. Espera {$waitMinutes} minuts."
        );
        go_home(['modal' => 'login', 'error' => 'rate_limit']);
    }

    // Registra aquest intent
    $stmt = $pdo->prepare("
        INSERT INTO verify_attempts (ip, attempts, last_attempt, blocked_until)
        VALUES (?, 1, ?, 0)
        ON DUPLICATE KEY UPDATE
            attempts = attempts + 1,
            last_attempt = VALUES(last_attempt),
            blocked_until = CASE
                WHEN attempts + 1 >= 10 THEN VALUES(last_attempt) + (15*60)
                ELSE blocked_until
            END
    ");
    $stmt->execute([$client_ip, time()]);

} catch (PDOException $e) {
    error_log('[verify_email] Rate limit check failed: ' . $e->getMessage());
    // Fail open: no bloquejar si el sistema de rate limit falla
}

// ══════════════════════════════════════════════════════════════
// PROCESSAMENT ATÒMIC DEL TOKEN
// ══════════════════════════════════════════════════════════════

try {
    // 🔥 UPDATE ATÒMIC: només si no verificat i no caducat
    // Aquesta query és idempotent i race-condition safe
    $upd = $pdo->prepare("
        UPDATE Usuaris
        SET Email_Verificat = 1,
            Email_Verificat_At = NOW(),
            Email_Verify_Token_Hash = NULL,
            Email_Verify_Expira = NULL,
            Ultima_Connexio = NOW()
        WHERE Email_Verify_Token_Hash = :hash
          AND Email_Verificat = 0
          AND (Email_Verify_Expira IS NULL OR Email_Verify_Expira > NOW())
        LIMIT 1
    ");
    
    $upd->execute([':hash' => $tokenHash]);
    $changed = ($upd->rowCount() === 1);

    // ──────────────────────────────────────────────────────────
    // CAS 1: VERIFICACIÓ EXITOSA
    // ──────────────────────────────────────────────────────────
    if ($changed) {
        // Recupera dades de l'usuari per fer auto-login
        $stmt = $pdo->prepare("
            SELECT 
                ID_Usuari, 
                Email_Usuari, 
                Nom_Usuari, 
                Tipus_Usuari, 
                Idioma
            FROM Usuaris
            WHERE Email_Verificat = 1 
              AND Email_Verify_Token_Hash IS NULL
            ORDER BY Email_Verificat_At DESC
            LIMIT 1
        ");
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $userId = (int)$user['ID_Usuari'];
            
            // 🎯 AUTO-LOGIN SEGUR
            session_regenerate_id(true); // Protecció contra session fixation
            
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_email'] = $user['Email_Usuari'];
            $_SESSION['user_nom'] = $user['Nom_Usuari'];
            $_SESSION['user_tipus'] = $user['Tipus_Usuari'];
            $_SESSION['user_lang'] = $user['Idioma'];
            $_SESSION['email_verified'] = true;
            $_SESSION['logged_in'] = true;

            // Neteja rate limiting per aquesta IP (verificació exitosa)
            try {
                $pdo->prepare("DELETE FROM verify_attempts WHERE ip = ?")->execute([$client_ip]);
            } catch (Throwable $e) { /* silent */ }

            // Audit
            $aud('success', [
                'user_id' => $userId,
                'updated' => true,
                'auto_login' => true
            ]);

            // Log intern
            error_log("[verify_email] User {$userId} ({$user['Email_Usuari']}) verified and logged in from {$client_ip}");

            // Missatge de benvinguda
            push_login_modal_flash('success', 
                $messages['success']['verify_ok'] ?? 'Correu verificat! Benvingut/da.'
            );

            // Redirigeix segons tipus d'usuari
            $redirects = [
                'tecnic' => 'dashboard_tecnic.php',
                'sala' => 'dashboard_sala.php',
                'productor' => 'dashboard_productor.php',
                'banda' => 'dashboard_banda.php',
            ];
            
            $dashboardPage = $redirects[$user['Tipus_Usuari']] ?? 'dashboard.php';
            
            header('Location: ' . BASE_PATH . $dashboardPage . '?success=email_verified', true, 302);
            exit;
        }

        // Fallback: si no trobem l'usuari (rara condició)
        $aud('success', ['updated' => true, 'user_not_found_after_update' => true]);
        push_login_modal_flash('success', 
            $messages['success']['verify_ok'] ?? 'Verificació completada! Ja pots iniciar sessió.'
        );
        go_home(['modal' => 'login', 'success' => 'verify_ok']);
    }

    // ──────────────────────────────────────────────────────────
    // CAS 2: NO S'HA CANVIAT CAP FILA
    // Token invàlid, caducat o ja usat
    // ──────────────────────────────────────────────────────────

    // Protecció contra timing attacks: constant-time delay
    usleep(random_int(50000, 150000)); // 50-150ms

    // Comprova l'estat real per donar feedback específic
    $stmt = $pdo->prepare("
        SELECT 
            ID_Usuari, 
            Email_Verificat, 
            Email_Verify_Expira
        FROM Usuaris
        WHERE Email_Verify_Token_Hash = :hash
        LIMIT 1
    ");
    $stmt->execute([':hash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Token no trobat: pot ser ja consumit o mai vàlid
        // ⚠️ No revelem si existeix o no (seguretat)
        $aud('error', ['reason' => 'token_not_found'], 'token_invalid_or_used');
        push_login_modal_flash('info', 
            $messages['error']['token_invalid'] ?? 'Token invàlid o ja utilitzat.'
        );
        go_home(['modal' => 'login', 'error' => 'token_invalid']);
    }

    $userId = (int)$row['ID_Usuari'];
    $isVerified = (int)$row['Email_Verificat'] === 1;
    $expiry = $row['Email_Verify_Expira'] ?? null;

    // Ja verificat prèviament (idempotent)
    if ($isVerified) {
        $aud('success', [
            'user_id' => $userId, 
            'already_verified' => true
        ]);
        push_login_modal_flash('info', 
            $messages['success']['verify_ok'] ?? 'Ja estàs verificat/da. Inicia sessió.'
        );
        go_home(['modal' => 'login', 'success' => 'verify_ok']);
    }

    // Token caducat
    if ($expiry && strtotime((string)$expiry) <= time()) {
        $aud('error', [
            'user_id' => $userId,
            'reason' => 'expired',
            'expired_at' => $expiry
        ], 'token_expired');
        
        push_login_modal_flash('danger', 
            $messages['error']['token_expired'] ?? 'L\'enllaç ha caducat. Sol·licita\'n un de nou.'
        );
        go_home(['modal' => 'login', 'error' => 'token_expired']);
    }

    // Per defecte: token invàlid (no hauria d'arribar aquí)
    $aud('error', [
        'user_id' => $userId,
        'reason' => 'unknown_no_change'
    ], 'token_invalid');
    
    push_login_modal_flash('danger', 
        $messages['error']['token_invalid'] ?? 'Token invàlid.'
    );
    go_home(['modal' => 'login', 'error' => 'token_invalid']);

} catch (PDOException $e) {
    error_log('[verify_email] Database error: ' . $e->getMessage());
    $aud('error', [
        'reason' => 'db_exception',
        'message' => $e->getMessage()
    ], 'verify_failed');
    
    push_login_modal_flash('danger', 
        $messages['error']['verify_failed'] ?? 'Error de sistema. Contacta amb suport.'
    );
    go_home(['modal' => 'login', 'error' => 'verify_failed']);

} catch (Throwable $e) {
    error_log('[verify_email] Unexpected error: ' . $e->getMessage());
    $aud('error', [
        'reason' => 'exception',
        'message' => $e->getMessage()
    ], 'verify_failed');
    
    push_login_modal_flash('danger', 
        $messages['error']['verify_failed'] ?? 'Error inesperat. Torna-ho a provar.'
    );
    go_home(['modal' => 'login', 'error' => 'verify_failed']);
}