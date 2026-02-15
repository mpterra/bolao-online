<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/bolao-da-copa/public/css/style.css">
</head>
<body>

<div class="page">

    <div class="login-card">
        <h1>Criar Conta</h1>

        <form method="POST" action="/bolao-da-copa/php/cadastrar_usuario.php" class="login-form">

            <div class="input-group">
                <input type="text" name="nome" required>
                <label>Nome Completo</label>
            </div>

            <div class="input-group">
                <input type="email" name="email" required>
                <label>Email</label>
            </div>

            <div class="input-group">
                <input type="text" name="telefone" required>
                <label>Telefone</label>
            </div>

            <div class="input-group">
                <input type="text" name="cidade" required>
                <label>Cidade</label>
            </div>

            <div class="input-group">
                <input type="text" name="estado" maxlength="2" required>
                <label>Estado (UF)</label>
            </div>

            <div class="input-group">
                <input type="password" name="senha" required>
                <label>Senha</label>
            </div>

            <button type="submit" class="btn-login">Cadastrar</button>

            <p class="cadastro-link">
                Já tem conta?
                <a href="/bolao-da-copa/public/index.php">Entrar</a>
            </p>

        </form>
    </div>

</div>

<script src="/bolao-da-copa/public/js/script.js"></script>
</body>
</html>
