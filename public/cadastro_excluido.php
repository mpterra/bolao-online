<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/security.php';
app_send_security_headers();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro excluído - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/login.css">
    <link rel="stylesheet" href="/css/cadastro.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
</head>
<body>
    <div class="page">
        <div class="logo-wrapper">
            <img src="/img/logo.png" alt="Bolão da Copa">
        </div>

        <main class="login-card closed-card" aria-labelledby="deletedTitle">
            <h1 id="deletedTitle">Seu cadastro foi excluído</h1>
            <p class="closed-message">
                Todos os seus dados foram excluídos do sistema.
                Tchau por enquanto, e você será bem-vindo de volta nos próximos bolões.
            </p>
            <div class="closed-actions">
                <a class="closed-instagram" href="/cadastro.php">Voltar quando quiser</a>
                <a class="closed-back" href="/index.php">Ir para o login</a>
            </div>
        </main>
    </div>
</body>
</html>
