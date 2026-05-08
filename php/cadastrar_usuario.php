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
require_once __DIR__ . "/cadastro_mailer.php";
require_once __DIR__ . "/usuario_schema.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    // ✅ HostGator: páginas públicas ficam na raiz do public_html
    header("Location: /cadastro.php");
    exit;
}

$successRedirect = '/boas_vindas.php';

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

function normalize_data_nascimento(string $s): string {
    $s = trim($s);
    if ($s === "") {
        fail("Preencha a data de nascimento.");
    }

    $formats = [
        'd/m/Y' => '!d/m/Y',
        'Y-m-d' => '!Y-m-d',
    ];

    $birthDate = null;

    foreach ($formats as $expectedFormat => $parserFormat) {
        $candidate = DateTimeImmutable::createFromFormat($parserFormat, $s);
        if (!$candidate instanceof DateTimeImmutable) {
            continue;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        if ($candidate->format($expectedFormat) !== $s) {
            continue;
        }

        $birthDate = $candidate;
        break;
    }

    if (!$birthDate instanceof DateTimeImmutable) {
        fail("Data de nascimento inválida. Use DD/MM/AAAA.");
    }

    $today = new DateTimeImmutable('today');
    if ($birthDate > $today) {
        fail("Data de nascimento inválida.");
    }

    return $birthDate->format('Y-m-d');
}

function normalize_telefone(string $s): string {
    $digits = preg_replace('/\D+/', '', trim($s)) ?? '';

    if ((strlen($digits) === 12 || strlen($digits) === 13) && strncmp($digits, '55', 2) === 0) {
        $digits = substr($digits, 2);
    }

    if (!preg_match('/^\d{10,11}$/', $digits)) {
        fail("Telefone inválido. Informe DDD + número.");
    }

    $ddd = substr($digits, 0, 2);
    $numero = substr($digits, 2);

    if (strlen($numero) === 8) {
        return sprintf('(%s) %s-%s', $ddd, substr($numero, 0, 4), substr($numero, 4));
    }

    return sprintf('(%s) %s-%s', $ddd, substr($numero, 0, 5), substr($numero, 5));
}

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    exit($msg);
}

$nomeRaw      = (string)($_POST["nome"] ?? "");
$sobrenomeRaw = (string)($_POST["sobrenome"] ?? "");

$nome      = normalize_nome($nomeRaw);
$sobrenome = normalize_nome($sobrenomeRaw);

$dataNascimentoRaw = trim((string)($_POST["data_nascimento"] ?? ""));
$email             = trim((string)($_POST["email"] ?? ""));
$telefoneRaw       = trim((string)($_POST["telefone"] ?? ""));
$cidade            = trim((string)($_POST["cidade"] ?? ""));
$estado            = strtoupper(trim((string)($_POST["estado"] ?? "")));
$senha             = (string)($_POST["senha"] ?? "");
$confirmarSenha = (string)($_POST["confirmar_senha"] ?? "");

// Campo atual do banco: "nome" deve receber Nome + Sobrenome
$nomeCompleto = trim($nome . " " . $sobrenome);
$nomeCompleto = preg_replace('/\s+/', ' ', $nomeCompleto) ?? $nomeCompleto;
$nomeCompletoInformado = trim($nomeRaw . " " . $sobrenomeRaw);
$nomeCompletoInformado = preg_replace('/\s+/', ' ', $nomeCompletoInformado) ?? $nomeCompletoInformado;

if ($nome === "" || $sobrenome === "" || $dataNascimentoRaw === "" || $email === "" || $telefoneRaw === "" || $cidade === "" || $estado === "" || $senha === "" || $confirmarSenha === "") {
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
if (!isset($pdo) || !($pdo instanceof PDO) || !usuario_ensure_birth_date($pdo)) {
    fail("Erro interno: não foi possível preparar o campo de data de nascimento.", 500);
}

$dataNascimento = normalize_data_nascimento($dataNascimentoRaw);
$telefone = normalize_telefone($telefoneRaw);
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

    $sql = "INSERT INTO usuarios (nome, data_nascimento, email, telefone, cidade, estado, senha_hash, tipo_usuario, ativo)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'APOSTADOR', 1)";
    $insertParams = [$nomeCompleto, $dataNascimento, $email, $telefone, $cidade, $estado, $senhaHash];

    $ins = $pdo->prepare($sql);
    $ins->execute($insertParams);

    $newUserId = (int)$pdo->lastInsertId();

    $pdo->commit();

    $mailConfig = load_mail_config_for_register();
    if (!load_phpmailer_for_register()) {
        cadastro_mail_log('Falha ao carregar PHPMailer para envios pos-cadastro.');
        header("Location: {$successRedirect}");
        exit;
    }

    cadastro_mail_log('Iniciando envios de e-mail pos-cadastro. Usuario ID=' . $newUserId . ', email=' . $email);

    $notified = notify_new_user_register(
        $mailConfig,
        $newUserId,
        $nomeCompletoInformado !== '' ? $nomeCompletoInformado : $nomeCompleto,
        $email,
        $telefone
    );

    if (!$notified) {
        error_log('[cadastrar_usuario] Falha ao notificar novo cadastro por e-mail.');
    }

    $welcomed = send_welcome_email_to_new_user(
        $mailConfig,
        $email,
        $nomeCompletoInformado !== '' ? $nomeCompletoInformado : $nomeCompleto
    );

    if (!$welcomed) {
        error_log('[cadastrar_usuario] Falha ao enviar e-mail de boas-vindas.');
    }

    cadastro_mail_log('Final dos envios pos-cadastro. admin=' . ($notified ? 'ok' : 'falha') . ', welcome=' . ($welcomed ? 'ok' : 'falha'));

    header("Location: {$successRedirect}");
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