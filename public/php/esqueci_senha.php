<?php
declare(strict_types=1);

$localTarget = dirname(__DIR__, 2) . '/php/esqueci_senha.php';
$hostgatorTarget = '/home2/mauri075/php/esqueci_senha.php';

if (is_file($localTarget)) {
	require_once $localTarget;
	return;
}

require_once $hostgatorTarget;
