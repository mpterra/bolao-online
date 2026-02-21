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
    <link rel="stylesheet" href="/bolao-da-copa/public/css/base.css">
    <link rel="stylesheet" href="/bolao-da-copa/public/css/login.css">
    <link rel="stylesheet" href="/bolao-da-copa/public/css/cadastro.css">

</head>

<body data-reg-success="<?php echo $sucesso ? '1' : '0'; ?>">

    <div class="page">

        <div class="logo-wrapper">
            <img src="/bolao-da-copa/public/img/logo.png" alt="Bolão da Copa">
        </div>

        <div class="login-card">
            <h1>Criar Conta</h1>

            <form method="POST" action="/bolao-da-copa/php/cadastrar_usuario.php" class="login-form" autocomplete="on">

                <!-- NOME -->
                <div class="input-group">
                    <input type="text" name="nome" required autocomplete="given-name">
                    <label>Nome</label>
                </div>

                <!-- SOBRENOME -->
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

                <!-- ESTADO COMO COMBO -->
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
                        <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"
                            stroke-linejoin="round" />
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

    <script src="/bolao-da-copa/public/js/cadastro.js"></script>
</body>

</html>


<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . "/conexao.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /bolao-da-copa/public/cadastro.php");
    exit;
}

/**
 * Normaliza string:
 * - remove acentos/ç (via iconv)
 * - remove caracteres especiais (mantém letras, números e espaços)
 * - normaliza espaços
 */
function normalize_nome(string $s): string {
    $s = trim($s);
    if ($s === "") return "";

    // UTF-8 -> ASCII removendo acentos/cedilha
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($ascii !== false && $ascii !== null) {
        $s = $ascii;
    }

    // remove tudo que não for letra/número/espaço
    $s = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $s) ?? $s;

    // colapsa múltiplos espaços
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return trim($s);
}

$nome       = normalize_nome((string)($_POST["nome"] ?? ""));
$sobrenome  = normalize_nome((string)($_POST["sobrenome"] ?? ""));
$email      = trim((string)($_POST["email"] ?? ""));
$telefone   = trim((string)($_POST["telefone"] ?? ""));
$cidade     = trim((string)($_POST["cidade"] ?? ""));
$estado     = strtoupper(trim((string)($_POST["estado"] ?? "")));
$senha      = (string)($_POST["senha"] ?? "");

// monta nome completo no campo atual "nome"
$nomeCompleto = trim($nome . " " . $sobrenome);
$nomeCompleto = preg_replace('/\s+/', ' ', $nomeCompleto) ?? $nomeCompleto;

if ($nomeCompleto === "" || $email === "" || $telefone === "" || $cidade === "" || $estado === "" || $senha === "") {
    exit("Preencha todos os campos.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Email inválido.");
}

if (strlen($estado) !== 2) {
    exit("UF inválida. Use 2 letras.");
}

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

try {
    // email é UNIQUE no schema (uk_usuarios_email)
    $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $check->execute([$email]);

    if ($check->fetch()) {
        exit("Já existe uma conta com esse email.");
    }

    // Schema real: senha_hash
    $sql = "INSERT INTO usuarios (nome, email, telefone, cidade, estado, senha_hash, tipo_usuario, ativo)
            VALUES (?, ?, ?, ?, ?, ?, 'APOSTADOR', 1)";

    $ins = $pdo->prepare($sql);
    $ins->execute([$nomeCompleto, $email, $telefone, $cidade, $estado, $senhaHash]);

    // volta pra tela de cadastro para abrir o modal e, ao OK, retornar ao login
    header("Location: /bolao-da-copa/public/cadastro.php?sucesso=1");
    exit;

} catch (Exception $e) {
    exit("Erro ao cadastrar: " . $e->getMessage());
}