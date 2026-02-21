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
 * - remove acentos/ç (Transliterator se disponível; fallback iconv)
 * - remove caracteres especiais (mantém apenas letras, números e espaços)
 * - normaliza espaços
 */
function normalize_nome(string $s): string {
    $s = trim($s);
    if ($s === "") return "";

    // Remove acentos/cedilha
    if (class_exists('Transliterator')) {
        // Any-Latin -> ASCII (remove diacríticos)
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII; [^\u0000-\u007F] remove', $s);
    } else {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tmp !== false && $tmp !== null) {
            $s = $tmp;
        }
    }

    // Remove tudo que não for letra/número/espaço
    $s = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $s) ?? $s;

    // Colapsa múltiplos espaços
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return trim($s);
}

$nomeRaw      = (string)($_POST["nome"] ?? "");
$sobrenomeRaw = (string)($_POST["sobrenome"] ?? "");

$nome      = normalize_nome($nomeRaw);
$sobrenome = normalize_nome($sobrenomeRaw);

$email    = trim((string)($_POST["email"] ?? ""));
$telefone = trim((string)($_POST["telefone"] ?? ""));
$cidade   = trim((string)($_POST["cidade"] ?? ""));
$estado   = strtoupper(trim((string)($_POST["estado"] ?? "")));
$senha    = (string)($_POST["senha"] ?? "");

// Campo atual do banco: "nome" deve receber Nome + Sobrenome
$nomeCompleto = trim($nome . " " . $sobrenome);
$nomeCompleto = preg_replace('/\s+/', ' ', $nomeCompleto) ?? $nomeCompleto;

if ($nome === "" || $sobrenome === "" || $email === "" || $telefone === "" || $cidade === "" || $estado === "" || $senha === "") {
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