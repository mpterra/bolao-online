<?php
declare(strict_types=1);

$self = realpath(__FILE__) ?: __FILE__;
$candidates = [
    dirname(__DIR__, 2) . '/php/atualizar_cadastro.php',
    dirname(__DIR__, 3) . '/php/atualizar_cadastro.php',
    '/home2/mauri075/php/atualizar_cadastro.php',
];

foreach ($candidates as $target) {
    $resolved = realpath($target);
    if ($resolved === false || !is_file($resolved)) {
        continue;
    }

    if ($resolved === $self) {
        continue;
    }

    require_once $resolved;
    return;
}

http_response_code(500);
exit('Erro interno: arquivo de atualização cadastral não encontrado.');