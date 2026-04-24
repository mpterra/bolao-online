<?php
declare(strict_types=1);

$self = realpath(__FILE__) ?: __FILE__;
$candidates = [
	dirname(__DIR__, 2) . '/php/redefinir_senha.php',
	dirname(__DIR__, 3) . '/php/redefinir_senha.php',
	'/home2/mauri075/php/redefinir_senha.php',
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
exit('Erro interno: arquivo de redefinição não encontrado.');
