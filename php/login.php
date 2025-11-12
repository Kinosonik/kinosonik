<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

$pdo = db();

/* ---------------- Helpers ---------------- */
if (!function_exists('push_login_modal_flash')) {
  function push_login_modal_flash(string $type, string $msg): void {
    $_SESSION['login_modal'] = ['open' => true, 'flash' => ['type' => $type, 'msg' => $msg]];
    ks_set_login_modal_cookie($_SESSION['login_modal']);
  }
}

if (!function_exists('redirect_index')) {
  function redirect_index(array $qs = []): never {
    redirect_to('index.php', $qs);
    exit;
  }
}

/* ---------------- Inputs ---------------- */
$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$email = $email ? mb_strtolower(trim($email), 'UTF-8') : $email;
$password = (string)($_POST['contrasenya'] ?? '');

/* ---------------- Return URL ---------------- */
$returnRaw  = (string)($_POST['return'] ?? '');
$returnUrl  = sanitize_return_url($returnRaw);
$withReturn = fn(array $p) => ($returnUrl !== '' ? $p + ['return' => $returnUrl] : $p);

/* -------- ✨ Throttle MILLORAT (BD en lloc de sessió) -------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$throttleKey = hash('sha256', ($email ?: 'anon') . '|' . $ip . '|' . substr($userAgent, 0, 100));
$now = time();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            throttle_key VARCHAR(64) PRIMARY KEY,
            fail_count INT NOT NULL DEFAULT 0,
            blocked_until INT NOT NULL DEFAULT 0,
            last_attempt INT NOT NULL DEFAULT 0,
            INDEX idx_blocked (blocked_until),
            INDEX idx_last_attempt (last_attempt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    error_log('login_attempts table creation failed: ' . $e->getMessage());
}

if (rand(1, 100) === 1) {
    try {
        $pdo->exec("DELETE FROM login_attempts WHERE blocked_until < " . ($now - 86400));
    } catch (PDOException $e) {
        error_log('login_attempts cleanup failed: ' . $e->getMessage());
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT fail_count, blocked_until 
        FROM login_attempts 
        WHERE throttle_key = ? AND blocked_until > ?
    ");
    $stmt->execute([$throttleKey, $now]);
    $blocked = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($blocked) {
        $waitSeconds = (int)$blocked['blocked_until'] - $now;
        try {
            audit_admin($pdo, 0, false, 'login_error', null, null, 'auth', 
                ['email' => (string)($email ?? ''), 'reason' => 'too_many_attempts', 'wait' => $waitSeconds,
                 'ip' => $ip, 'ua' => $userAgent],
                'error', 'throttled'
            );
        } catch (Throwable $e) { error_log('audit throttled: ' . $e->getMessage()); }
        
        push_login_modal_flash('danger', sprintf(
            'Massa intents. Torna-ho a provar d\'aquí %d minuts.',
            ceil($waitSeconds / 60)
        ));
        redirect_index($withReturn(['modal' => 'login', 'error' => 'too_many_attempts']));
    }
} catch (PDOException $e) {
    error_log('Throttle check failed: ' . $e->getMessage());
}

/* --- Funcions throttle millorades --- */
$throttle_fail = function() use ($pdo, $throttleKey, $now) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (throttle_key, fail_count, last_attempt, blocked_until)
            VALUES (?, 1, ?, 0)
            ON DUPLICATE KEY UPDATE
                fail_count = fail_count + 1,
                last_attempt = VALUES(last_attempt),
                blocked_until = CASE
                    WHEN fail_count + 1 >= 5 THEN VALUES(last_attempt) + (15*60)
                    ELSE blocked_until
                END
        ");
        $stmt->execute([$throttleKey, $now]);
    } catch (PDOException $e) {
        error_log('Throttle fail update: ' . $e->getMessage());
    }
};

$throttle_ok = function() use ($pdo, $throttleKey) {
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE throttle_key = ?");
        $stmt->execute([$throttleKey]);
    } catch (PDOException $e) {
        error_log('Throttle reset: ' . $e->getMessage());
    }
};

/* ---------------- Validació bàsica ---------------- */
if (!$email || $password === '') {
    try {
        audit_admin($pdo, 0, false, 'login_error', null, null, 'auth',
            ['email' => (string)($email ?? ''), 'reason' => 'missing_fields',
             'ip' => $ip, 'ua' => $userAgent],
            'error', 'missing email/password'
        );
    } catch (Throwable $e) { error_log('audit missing_fields: ' . $e->getMessage()); }

    $throttle_fail();
    push_login_modal_flash('danger', 'Falten camps obligatoris.');
    redirect_index($withReturn(['modal' => 'login', 'error' => 'missing_fields']));
}

/* ---------------- ✨ Procés LOGIN ---------------- */
try {
    $stmt = $pdo->prepare("
        SELECT ID_Usuari, Nom_Usuari, Cognoms_Usuari, Email_Usuari, Password_Hash,
               Email_Verificat, Tipus_Usuari, COALESCE(Idioma,'ca') AS Idioma
        FROM Usuaris
        WHERE Email_Usuari = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $dummyHash = '$2y$10$' . str_repeat('a', 53);
    $hashToVerify = $user ? (string)$user['Password_Hash'] : $dummyHash;
    $passwordValid = password_verify($password, $hashToVerify);

    if (!$user || !$passwordValid) {
        try {
            audit_admin($pdo, 0, false, 'login_error', null, null, 'auth',
                ['email' => $email, 'reason' => 'invalid_credentials',
                 'ip' => $ip, 'ua' => $userAgent],
                'error', 'email or password incorrect'
            );
        } catch (Throwable $e) { error_log('audit invalid_credentials: ' . $e->getMessage()); }

        $throttle_fail();
        push_login_modal_flash('danger', 'Email o contrasenya incorrectes.');
        redirect_index($withReturn(['modal' => 'login', 'error' => 'invalid_credentials']));
    }

    if ((int)$user['Email_Verificat'] !== 1) {
        try {
            audit_admin($pdo, (int)$user['ID_Usuari'], 
                (strcasecmp((string)$user['Tipus_Usuari'], 'admin') === 0),
                'login_error', null, null, 'auth',
                ['email' => $email, 'reason' => 'email_not_verified',
                 'ip' => $ip, 'ua' => $userAgent],
                'error', 'email not verified'
            );
        } catch (Throwable $e) { error_log('audit unverified: ' . $e->getMessage()); }

        push_login_modal_flash('warning', 'Verifica el teu correu abans d’iniciar sessió.');
        redirect_index($withReturn(['modal'=>'login','error'=>'email_not_verified','email'=>$email]));
    }

    if (password_needs_rehash($hashToVerify, PASSWORD_DEFAULT)) {
        try {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE Usuaris SET Password_Hash = ? WHERE ID_Usuari = ?");
            $upd->execute([$newHash, (int)$user['ID_Usuari']]);
            error_log("Password rehashed for user: {$user['ID_Usuari']}");
        } catch (PDOException $e) {
            error_log('Password rehash failed: ' . $e->getMessage());
        }
    }

    $throttle_ok();
    if (function_exists('session_regenerate_after_login')) {
        session_regenerate_after_login();
    } else {
        session_regenerate_id(true);
    }

    $_SESSION['loggedin']      = true;
    $_SESSION['user_id']       = (int)$user['ID_Usuari'];
    $_SESSION['user_name']     = (string)$user['Nom_Usuari'];
    $_SESSION['user_surnames'] = (string)$user['Cognoms_Usuari'];
    $_SESSION['user_email']    = (string)$user['Email_Usuari'];
    $_SESSION['tipus_usuari']  = (string)$user['Tipus_Usuari'];

    $idioma = strtolower((string)$user['Idioma']);
    $_SESSION['lang'] = in_array($idioma, ['ca','es','en'], true) ? $idioma : 'ca';
    if (function_exists('set_lang')) { set_lang($_SESSION['lang']); }

    try {
        $role = strtolower((string)$user['Tipus_Usuari']);
        $ins = $pdo->prepare('
            INSERT INTO User_Roles (user_id, role) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ');
        $ins->execute([(int)$user['ID_Usuari'], $role]);

        $st = $pdo->prepare('SELECT role FROM User_Roles WHERE user_id = ?');
        $st->execute([(int)$user['ID_Usuari']]);
        $_SESSION['roles_extra'] = array_values(
            array_unique(array_map('strtolower', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'role')))
        );
    } catch (PDOException $e) {
        error_log('roles_extra bootstrap: ' . $e->getMessage());
        $_SESSION['roles_extra'] = [$role ?? 'tecnic'];
    }

    try {
        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM Riders WHERE ID_Usuari = ?');
        $stCnt->execute([(int)$user['ID_Usuari']]);
        $_SESSION['has_my_riders'] = ((int)$stCnt->fetchColumn() > 0) ? 1 : 0;
    } catch (PDOException $e) {
        $_SESSION['has_my_riders'] = 0;
    }

    try {
        $upd = $pdo->prepare("UPDATE Usuaris SET Ultim_Acces_Usuari = UTC_TIMESTAMP() WHERE ID_Usuari = ?");
        $upd->execute([(int)$user['ID_Usuari']]);
    } catch (PDOException $e) {
        error_log('Update Ultim_Acces failed: ' . $e->getMessage());
    }

    try {
        audit_admin($pdo, (int)$user['ID_Usuari'],
            (strcasecmp((string)$user['Tipus_Usuari'], 'admin') === 0),
            'login_success', null, null, 'auth',
            ['email' => $email, 'method' => 'password', 'ip' => $ip, 'ua' => $userAgent],
            'success', null
        );
    } catch (Throwable $e) { error_log('audit login_success: ' . $e->getMessage()); }

    $roleBase = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));

    if ($returnUrl !== '' && 
    str_starts_with($returnUrl, '/') && 
    !str_starts_with($returnUrl, '//')) {
        $isRestricted = (
            str_contains($returnUrl, 'espai.php') ||
            preg_match('#/(?:admin|secure)/#i', $returnUrl)
        );
        if (!($roleBase === 'sala' && $isRestricted)) {
            header('Location: ' . $returnUrl);
            exit;
        }
    }

    if (ks_has_role('admin')) {
        redirect_to('espai.php', ['seccio' => 'admin_riders']);
    } elseif (ks_has_role('productor')) {
        redirect_to('espai.php', ['seccio' => 'produccio']);
    } elseif (ks_has_role('tecnic')) {
        redirect_to('espai.php', ['seccio' => 'riders']);
    } elseif (ks_has_role('sala')) {
        redirect_to('rider_vistos.php');
    } else {
        redirect_to('index.php');
    }
    exit;

} catch (Throwable $e) {
    error_log('Login exception: ' . $e->getMessage());
    try {
        audit_admin($pdo, 0, false, 'login_error', null, null, 'auth',
            ['email' => (string)($email ?? ''), 'reason' => 'exception',
             'ip' => $ip, 'ua' => $userAgent],
            'error', 'server exception: ' . $e->getMessage()
        );
    } catch (Throwable $e2) { error_log('audit exception: ' . $e2->getMessage()); }

    push_login_modal_flash('danger', 'Error intern del servidor.');
    redirect_index($withReturn(['modal' => 'login', 'error' => 'server_error']));
}
