<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/php/security.php";
require_once dirname(__DIR__) . "/php/registration_lock.php";
app_start_session();
app_send_security_headers();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro encerrado - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <link rel="stylesheet" href="/css/base.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/base.css'); ?>">
    <link rel="stylesheet" href="/css/login.css">
    <link rel="stylesheet" href="/css/cadastro.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
</head>

<body>
    <div class="page">
        <div class="logo-wrapper">
            <img src="/img/logo.png" alt="Bolão da Copa">
        </div>

        <main class="login-card closed-card" aria-labelledby="closedTitle">
            <h1 id="closedTitle">O Bolão da Copa 2026 está encerrado</h1>
            <p class="closed-message">
                Mas não fique triste, ano que vem tem copa feminina e você já é nosso convidado.
                Acompanhe pelo Instagram.
            </p>
            <div class="closed-actions">
                <a class="closed-instagram" href="https://instagram.com/bolaodothiago" target="_blank" rel="noopener noreferrer">
                    instagram.com/bolaodothiago
                </a>
                <a class="closed-back" href="/regulamento.php">Ver regulamento</a>
            </div>
        </main>
    </div>
</body>
</html>
