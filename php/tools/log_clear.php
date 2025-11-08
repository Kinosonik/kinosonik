<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/preload.php';

// Carrega secrets i clau
$secrets = require dirname(__DIR__) . '/secret.php';
$authKey = $secrets['KS_LOG_VIEW_KEY'] ?? '';
$k = $_POST['k'] ?? $_GET['k'] ?? '';
if ($authKey === '' || $k !== $authKey) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Fitxer a netejar
$logFile = '/var/config/logs/riders/php-error.log';

if (!is_writable($logFile)) {
    http_response_code(500);
    echo "Log no writable: $logFile";
    exit;
}

// Neteja el contingut (no elimina el fitxer)
file_put_contents($logFile, '');
ks_log('LOG_CLEAN: Log buidat manualment a ' . date('c'));

header('Content-Type: text/plain; charset=UTF-8');
echo "OK";