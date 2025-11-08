<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/preload.php';

$when = date('c');

// 1) escrit via el teu logger
ks_log("VISOR-TEST: ping $when");

// 2) un warning “controlat”
trigger_error("VISOR-TEST WARNING a $when", E_USER_WARNING);

// 3) línia directa al mateix fitxer de log (per si hi hagués cap problema amb els handlers)
@error_log("[MANUAL $when] prova directa des de make_noise.php");

// resposta HTTP
header('Content-Type: text/plain; charset=UTF-8');
echo "ok $when\n";