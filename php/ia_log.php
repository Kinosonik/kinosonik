<?php
declare(strict_types=1);
/**
 * Helper per escriure logs d'IA (JSON Lines)
 */
function ia_log(string $path, string $level, string $msg, array $ctx = []): void {
    $row = ['ts'=>gmdate('c'),'level'=>$level,'msg'=>$msg] + $ctx;
    file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
}
