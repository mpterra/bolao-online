<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ✅ HostGator: pasta /php fica fora do public_html, mas ESTE arquivo também está em /php.
// Então conexao.php é "vizinho" (mesma pasta).
require_once __DIR__ . "/conexao.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    // ✅ HostGator: páginas públicas ficam na raiz do public_html
    header("Location: /cadastro.php");
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

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    exit($msg);
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
$confirmarSenha = (string)($_POST["confirmar_senha"] ?? "");

// Campo atual do banco: "nome" deve receber Nome + Sobrenome
$nomeCompleto = trim($nome . " " . $sobrenome);
$nomeCompleto = preg_replace('/\s+/', ' ', $nomeCompleto) ?? $nomeCompleto;

if ($nome === "" || $sobrenome === "" || $email === "" || $telefone === "" || $cidade === "" || $estado === "" || $senha === "" || $confirmarSenha === "") {
    fail("Preencha todos os campos.");
}

if ($senha !== $confirmarSenha) {
    fail("As senhas não coincidem.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail("Email inválido.");
}

if (strlen($estado) !== 2) {
    fail("UF inválida. Use 2 letras.");
}

// Higiene mínima (sem “inventar” regra de negócio)
$email = mb_strtolower($email, 'UTF-8');
$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

try {
    // garante que $pdo existe e está ok
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        fail("Erro interno: conexão indisponível.", 500);
    }

    // transação (consistência + evita condição de corrida em checagem/insert)
    $pdo->beginTransaction();

    // email é UNIQUE no schema (uk_usuarios_email)
    $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $check->execute([$email]);

    if ($check->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        fail("Já existe uma conta com esse email.", 409);
    }

    $sql = "INSERT INTO usuarios (nome, email, telefone, cidade, estado, senha_hash, tipo_usuario, ativo)
            VALUES (?, ?, ?, ?, ?, ?, 'APOSTADOR', 1)";

    $ins = $pdo->prepare($sql);
    $ins->execute([$nomeCompleto, $email, $telefone, $cidade, $estado, $senhaHash]);

    $pdo->commit();

    // ✅ HostGator: volta pra /cadastro.php
    header("Location: /cadastro.php?sucesso=1");
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Trata violação de UNIQUE (se mesmo assim ocorrer corrida)
    $errInfo = (isset($ins) && $ins instanceof PDOStatement) ? $ins->errorInfo() : null;
    $sqlState = is_array($errInfo) ? ($errInfo[0] ?? "") : "";

    if ($e instanceof PDOException) {
        // MySQL: 23000 = integrity constraint violation (inclui UNIQUE)
        if ($sqlState === "23000") {
            fail("Já existe uma conta com esse email.", 409);
        }
    }

    fail("Erro ao cadastrar: " . $e->getMessage(), 500);
}