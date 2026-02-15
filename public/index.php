<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Caminho absoluto para evitar erro -->
    <link rel="stylesheet" href="/bolao-da-copa/public/css/style.css">
</head>

<body>

    <div class="page">

        <div class="logo-wrapper">
            <img src="/bolao-da-copa/public/img/logo.png" alt="Bolão da Copa">
        </div>

        <div class="login-card">
            <h1>Bolão da Copa</h1>

            <form method="POST" action="/bolao-da-copa/php/auth.php" class="login-form">

                <div class="input-group">
                    <input type="text" name="usuario" required>
                    <label>Usuário</label>
                </div>

                <div class="input-group">
                    <input type="password" name="senha" required>
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

    <script src="/bolao-da-copa/public/js/script.js"></script>
</body>

</html>