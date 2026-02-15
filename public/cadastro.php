<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

$sucesso = (isset($_GET['sucesso']) && $_GET['sucesso'] === '1');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/bolao-da-copa/public/css/style.css">
</head>
<body data-reg-success="<?php echo $sucesso ? '1' : '0'; ?>">

<div class="page">

    <div class="logo-wrapper">
        <img src="/bolao-da-copa/public/img/logo.png" alt="Bolão da Copa">
    </div>

    <div class="login-card">
        <h1>Criar Conta</h1>

        <form method="POST" action="/bolao-da-copa/php/cadastrar_usuario.php" class="login-form" autocomplete="on">

            <div class="input-group">
                <input type="text" name="nome" required autocomplete="name">
                <label>Nome Completo</label>
            </div>

            <div class="input-group">
                <input type="email" name="email" required autocomplete="email">
                <label>Email</label>
            </div>

            <div class="input-group">
                <input type="text" name="telefone" required autocomplete="tel">
                <label>Telefone</label>
            </div>

            <div class="input-group">
                <input type="text" name="cidade" required autocomplete="address-level2">
                <label>Cidade</label>
            </div>

            <div class="input-group">
                <input type="text" name="estado" maxlength="2" required autocomplete="address-level1">
                <label>Estado (UF)</label>
            </div>

            <div class="input-group">
                <input type="password" name="senha" required autocomplete="new-password">
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

<!-- Modal sucesso -->
<div class="modal-overlay" id="modalSucesso" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-head">
            <div class="modal-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="modal-titles">
                <h2 id="modalTitle">Cadastro realizado com sucesso!</h2>
                <p>Sua conta foi criada. Clique em <b>OK</b> para voltar ao login.</p>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-modal-ok" id="btnOkCadastro">OK, voltar pro login →</button>
        </div>
    </div>
</div>

<script src="/bolao-da-copa/public/js/script.js"></script>
</body>
</html>
