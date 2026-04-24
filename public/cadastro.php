<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sucesso = (isset($_GET['sucesso']) && $_GET['sucesso'] === '1');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/login.css">
    <link rel="stylesheet" href="/css/cadastro.css">
</head>

<body data-reg-success="<?php echo $sucesso ? '1' : '0'; ?>">

    <div class="page">

        <div class="logo-wrapper">
            <img src="/img/logo.png" alt="Bolão da Copa">
        </div>

        <div class="login-card">
            <h1>Criar Conta</h1>

            <form method="POST" action="/php/cadastrar_usuario.php" class="login-form" autocomplete="on">

                <div class="input-group">
                    <input type="text" name="nome" required autocomplete="given-name">
                    <label>Nome</label>
                </div>

                <div class="input-group">
                    <input type="text" name="sobrenome" required autocomplete="family-name">
                    <label>Sobrenome</label>
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
                    <select name="estado" required>
                        <option value="" disabled selected hidden></option>
                        <option value="AC">AC</option>
                        <option value="AL">AL</option>
                        <option value="AP">AP</option>
                        <option value="AM">AM</option>
                        <option value="BA">BA</option>
                        <option value="CE">CE</option>
                        <option value="DF">DF</option>
                        <option value="ES">ES</option>
                        <option value="GO">GO</option>
                        <option value="MA">MA</option>
                        <option value="MT">MT</option>
                        <option value="MS">MS</option>
                        <option value="MG">MG</option>
                        <option value="PA">PA</option>
                        <option value="PB">PB</option>
                        <option value="PR">PR</option>
                        <option value="PE">PE</option>
                        <option value="PI">PI</option>
                        <option value="RJ">RJ</option>
                        <option value="RN">RN</option>
                        <option value="RS">RS</option>
                        <option value="RO">RO</option>
                        <option value="RR">RR</option>
                        <option value="SC">SC</option>
                        <option value="SP">SP</option>
                        <option value="SE">SE</option>
                        <option value="TO">TO</option>
                    </select>
                    <label>Estado (UF)</label>
                </div>

                <div class="input-group">
                    <input type="password" id="senha" name="senha" required autocomplete="new-password">
                    <label>Senha</label>
                    <button type="button" class="password-toggle" data-toggle-password="senha" aria-label="Mostrar senha" aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M2 12s3.8-6 10-6 10 6 10 6-3.8 6-10 6-10-6-10-6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M10.6 6.2a10.7 10.7 0 0 1 1.4-.2c6.2 0 10 6 10 6a18 18 0 0 1-3.4 4.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M6.8 8.8C4.2 10.7 2 12 2 12s3.8 6 10 6c1.4 0 2.6-.3 3.8-.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M9.9 9.9A3 3 0 0 0 14 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                <div class="input-group">
                    <input type="password" id="confirmar_senha" name="confirmar_senha" required autocomplete="new-password">
                    <label>Confirmar senha</label>
                    <button type="button" class="password-toggle" data-toggle-password="confirmar_senha" aria-label="Mostrar confirmação de senha" aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M2 12s3.8-6 10-6 10 6 10 6-3.8 6-10 6-10-6-10-6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M10.6 6.2a10.7 10.7 0 0 1 1.4-.2c6.2 0 10 6 10 6a18 18 0 0 1-3.4 4.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M6.8 8.8C4.2 10.7 2 12 2 12s3.8 6 10 6c1.4 0 2.6-.3 3.8-.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M9.9 9.9A3 3 0 0 0 14 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                <button type="submit" class="btn-login">Cadastrar</button>

                <p class="cadastro-link">
                    Já tem conta?
                    <a href="/index.php">Entrar</a>
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

    <script src="/js/cadastro.js"></script>
</body>
</html>