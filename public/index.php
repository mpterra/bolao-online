<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

/**
 * Flash message vindo do auth.php:
 * $_SESSION["flash_login"] = ["type"=>"error|warn|info|ok","msg"=>"...","ts"=>time()];
 */
$flash = null;
if (!empty($_SESSION["flash_login"]) && is_array($_SESSION["flash_login"])) {
    $flash = $_SESSION["flash_login"];
    unset($_SESSION["flash_login"]); // consome uma vez
}

function h(?string $s): string {
    return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8");
}

$flashType = is_array($flash) ? (string)($flash["type"] ?? "") : "";
$flashMsg  = is_array($flash) ? (string)($flash["msg"] ?? "")  : "";

$allowed = ["error","warn","info","ok"];
if (!in_array($flashType, $allowed, true)) $flashType = "";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Bolão do Thiago</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <link rel="stylesheet" href="/bolao-da-copa/public/css/login.css">
</head>

<body
  data-flash-type="<?php echo h($flashType); ?>"
  data-flash-msg="<?php echo h($flashMsg); ?>"
>

<!-- Host do toast (fica no topo, dentro da mesma tela) -->
<div class="toast-host" aria-live="polite" aria-atomic="true"></div>

<div class="page">

    <div class="logo-wrapper">
        <img src="/bolao-da-copa/public/img/logo.png" alt="Bolão da Copa">
    </div>

    <div class="login-card">
        <h1>Bolão do Thiago</h1>

        <form method="POST" action="/bolao-da-copa/php/auth.php" class="login-form" autocomplete="on">

            <div class="input-group">
                <input type="text" name="usuario" required autocomplete="username">
                <label>Usuário</label>
            </div>

            <div class="input-group">
                <input type="password" name="senha" required autocomplete="current-password">
                <label>Senha</label>
            </div>

            <button type="submit" class="btn-login">Entrar</button>

            <p class="cadastro-link">
                Não tem conta?
                <a href="/bolao-da-copa/public/cadastro.php">Cadastre-se</a>
            </p>

        </form>
    </div>

</div>

<script src="/bolao-da-copa/public/js/index.js"></script>
</body>
</html>
