<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

$flash = null;
if (!empty($_SESSION["flash_reset"]) && is_array($_SESSION["flash_reset"])) {
    $flash = $_SESSION["flash_reset"];
    unset($_SESSION["flash_reset"]);
}

function h(?string $s): string {
    return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8");
}

$flashType = is_array($flash) ? (string)($flash["type"] ?? "") : "";
$flashMsg  = is_array($flash) ? (string)($flash["msg"]  ?? "") : "";

$allowed = ["error", "warn", "info", "ok"];
if (!in_array($flashType, $allowed, true)) $flashType = "";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Esqueci minha senha — Bolão do Thiago</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="stylesheet" href="/css/login.css">
</head>

<body
  data-flash-type="<?php echo h($flashType); ?>"
  data-flash-msg="<?php echo h($flashMsg); ?>"
>

<div class="toast-host" aria-live="polite" aria-atomic="true"></div>

<div class="page">

    <div class="logo-wrapper">
        <img src="/img/logo.png" alt="Bolão da Copa">
    </div>

    <div class="login-card">
        <h1>Esqueci minha senha</h1>

        <p class="reset-subtitle">
            Informe o e-mail cadastrado e enviaremos um link para você criar uma nova senha.
        </p>

        <form method="POST" action="/php/esqueci_senha.php" class="login-form" autocomplete="on">

            <div class="input-group">
                <input type="email" name="email" required autocomplete="email">
                <label>E-mail</label>
            </div>

            <button type="submit" class="btn-login">Enviar link</button>

            <p class="cadastro-link">
                Lembrou a senha?
                <a href="/index.php">Entrar</a>
            </p>

        </form>
    </div>

</div>

<script src="/js/index.js"></script>
</body>
</html>
