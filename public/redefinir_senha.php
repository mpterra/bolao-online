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

// Token vem da query string
$token = trim((string)($_GET["token"] ?? ""));

// Valida formato do token (64 chars hex) antes de renderizar o formulário
$tokenValido = preg_match('/^[0-9a-f]{64}$/', $token) === 1;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Redefinir senha — Bolão do Thiago</title>
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
        <h1>Nova senha</h1>

        <?php if (!$tokenValido): ?>
            <p class="reset-subtitle reset-subtitle--error">
                Link inválido ou expirado. Solicite um novo link de redefinição.
            </p>
            <p class="cadastro-link" style="margin-top:16px;">
                <a href="/esqueci_senha.php">Solicitar novo link</a>
            </p>
        <?php else: ?>

        <p class="reset-subtitle">
            Crie uma nova senha para a sua conta. Mínimo de 8 caracteres.
        </p>

        <form method="POST" action="/php/redefinir_senha.php" class="login-form" autocomplete="off">

            <!-- Token oculto -->
            <input type="hidden" name="token" value="<?php echo h($token); ?>">

            <div class="input-group">
                <input type="password" name="nova_senha" id="nova_senha"
                       required minlength="8" autocomplete="new-password">
                <label>Nova senha</label>
            </div>

            <div class="input-group">
                <input type="password" name="confirmar_senha" id="confirmar_senha"
                       required minlength="8" autocomplete="new-password">
                <label>Confirmar nova senha</label>
            </div>

            <button type="submit" class="btn-login">Salvar nova senha</button>

            <p class="cadastro-link">
                Lembrou a senha?
                <a href="/index.php">Entrar</a>
            </p>

        </form>

        <?php endif; ?>
    </div>

</div>

<script src="/js/redefinir_senha.js"></script>
</body>
</html>
