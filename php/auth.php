<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . "/conexao.php";

function redirect_login_with_flash(string $msg, string $type = "error"): void {
    $_SESSION["flash_login"] = [
        "type" => $type, // error | warn | info | ok
        "msg"  => $msg,
        "ts"   => time(),
    ];
    header("Location: /bolao-da-copa/public/index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /bolao-da-copa/public/index.php");
    exit;
}

$email = trim($_POST["usuario"] ?? "");
$senha = (string)($_POST["senha"] ?? "");

if ($email === "" || $senha === "") {
    redirect_login_with_flash("Informe e-mail e senha para continuar.", "warn");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_login_with_flash("Digite um e-mail válido.", "warn");
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
        redirect_login_with_flash("E-mail ou senha inválidos.", "error");
    }

    if ((int)$u["ativo"] !== 1) {
        redirect_login_with_flash("Usuário inativo. Fale com o administrador.", "warn");
    }

    if (!password_verify($senha, (string)$u["senha_hash"])) {
        redirect_login_with_flash("E-mail ou senha inválidos.", "error");
    }

    $_SESSION["usuario_id"] = (int)$u["id"];
    $_SESSION["usuario_nome"] = (string)$u["nome"];
    $_SESSION["usuario_email"] = (string)$u["email"];
    $_SESSION["tipo_usuario"] = (string)$u["tipo_usuario"];

    header("Location: /bolao-da-copa/public/app.php");
    exit;

} catch (Exception $e) {
    redirect_login_with_flash("Erro no login. Tente novamente.", "error");
}
