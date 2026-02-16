<?php
declare(strict_types=1);

session_start();

// limpa dados da sessão
$_SESSION = [];

// remove cookie de sessão (se existir)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        (bool)$params["secure"],
        (bool)$params["httponly"]
    );
}

// encerra a sessão
session_destroy();

// volta pro login
header("Location: /bolao-da-copa/public/index.php");
exit;
