<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . "/conexao.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /bolao-da-copa/public/index.php");
    exit;
}

$email = trim($_POST["usuario"] ?? "");
$senha = (string)($_POST["senha"] ?? "");

if ($email === "" || $senha === "") {
    exit("Informe email e senha.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Email inválido.");
}

try {
    // Schema real: senha_hash + ativo
    $stmt = $pdo->prepare("
        SELECT id, nome, email, senha_hash, tipo_usuario, ativo
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u) {
        exit("Email ou senha inválidos.");
    }

    if ((int)$u["ativo"] !== 1) {
        exit("Usuário inativo. Fale com o administrador.");
    }

    if (!password_verify($senha, $u["senha_hash"])) {
        exit("Email ou senha inválidos.");
    }

    $_SESSION["usuario_id"] = (int)$u["id"];
    $_SESSION["usuario_nome"] = $u["nome"];
    $_SESSION["usuario_email"] = $u["email"];
    $_SESSION["tipo_usuario"] = $u["tipo_usuario"];

    // Como você disse: index é só login.
    // Então aqui você redireciona para a primeira tela REAL pós-login do seu sistema.
    // Se você ainda não tem, aponte para uma landing simples (ex: /public/home.php).
    header("Location: /bolao-da-copa/public/home.php");
    exit;

} catch (Exception $e) {
    exit("Erro no login: " . $e->getMessage());
}
