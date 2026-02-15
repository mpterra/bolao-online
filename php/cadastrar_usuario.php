<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . "/conexao.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /bolao-da-copa/public/cadastro.php");
    exit;
}

$nome     = trim($_POST["nome"] ?? "");
$email    = trim($_POST["email"] ?? "");
$telefone = trim($_POST["telefone"] ?? "");
$cidade   = trim($_POST["cidade"] ?? "");
$estado   = strtoupper(trim($_POST["estado"] ?? ""));
$senha    = (string)($_POST["senha"] ?? "");

if ($nome === "" || $email === "" || $telefone === "" || $cidade === "" || $estado === "" || $senha === "") {
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
    $ins->execute([$nome, $email, $telefone, $cidade, $estado, $senhaHash]);

    // volta pra tela de cadastro para abrir o modal e, ao OK, retornar ao login
    header("Location: /bolao-da-copa/public/cadastro.php?sucesso=1");
    exit;

} catch (Exception $e) {
    exit("Erro ao cadastrar: " . $e->getMessage());
}
