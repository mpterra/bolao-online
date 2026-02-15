<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /bolao-da-copa/public/index.php");
    exit;
}

$nome     = trim($_POST["nome"]);
$email    = trim($_POST["email"]);
$telefone = trim($_POST["telefone"]);
$cidade   = trim($_POST["cidade"]);
$estado   = strtoupper(trim($_POST["estado"]));
$senha    = $_POST["senha"];

if (strlen($estado) !== 2) {
    exit("Estado inválido.");
}

$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

try {

    $sql = "INSERT INTO usuarios 
            (nome, email, telefone, cidade, estado, senha_hash, tipo_usuario)
            VALUES 
            (:nome, :email, :telefone, :cidade, :estado, :senha_hash, 'APOSTADOR')";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":nome"       => $nome,
        ":email"      => $email,
        ":telefone"   => $telefone,
        ":cidade"     => $cidade,
        ":estado"     => $estado,
        ":senha_hash" => $senha_hash
    ]);

    header("Location: /bolao-da-copa/public/index.php?cadastro=sucesso");
    exit;

} catch (PDOException $e) {

    if ($e->errorInfo[1] == 1062) {
        exit("Email já cadastrado.");
    }

    exit("Erro ao cadastrar usuário.");
}
