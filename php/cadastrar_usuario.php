<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

function cadastro_mail_log(string $message): void {
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/cadastro-mail.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    error_log('[cadastrar_usuario] ' . trim($line));
}

function load_mail_config_for_register(): array {
    $defaultMailConfig = [
        "host"       => "mail.bolaodothiago.com.br",
        "port"       => 465,
        "encryption" => "ssl",
        "auto_relax_tls" => true,
        "verify_peer" => true,
        "verify_peer_name" => true,
        "allow_self_signed" => false,
        "username"   => "admin@bolaodothiago.com.br",
        "password"   => "Eng%3571Hawaii",
        "from_email" => "admin@bolaodothiago.com.br",
        "from_name"  => "Bolão do Thiago",
        "timeout"    => 20,
    ];

    $mailConfigPaths = [
        __DIR__ . "/../config/mail.php",
        __DIR__ . "/../../config/mail.php",
        __DIR__ . "/../../../config/mail.php",
    ];

    foreach ($mailConfigPaths as $mailConfigPath) {
        if (!is_file($mailConfigPath)) {
            continue;
        }

        $loadedMailConfig = require $mailConfigPath;
        if (is_array($loadedMailConfig)) {
            return array_merge($defaultMailConfig, $loadedMailConfig);
        }
    }

    return $defaultMailConfig;
}

function load_phpmailer_for_register(): bool {
    $possibleBases = [
        __DIR__ . "/PHPMailer/src",
        __DIR__ . "/../PHPMailer/src",
        __DIR__ . "/../../php/PHPMailer/src",
        __DIR__ . "/../../../php/PHPMailer/src",
    ];

    foreach ($possibleBases as $base) {
        $exceptionFile = $base . "/Exception.php";
        $phpMailerFile = $base . "/PHPMailer.php";
        $smtpFile      = $base . "/SMTP.php";

        if (is_file($exceptionFile) && is_file($phpMailerFile) && is_file($smtpFile)) {
            require_once $exceptionFile;
            require_once $phpMailerFile;
            require_once $smtpFile;
            cadastro_mail_log("PHPMailer carregado de: " . $base);
            return true;
        }
    }

    cadastro_mail_log("PHPMailer nao encontrado nos caminhos esperados.");
    return false;
}

function send_email_phpmailer(
    array $cfg,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): bool {
    try {
        $host       = trim((string)($cfg["host"] ?? ""));
        $port       = (int)($cfg["port"] ?? 0);
        $encryption = strtolower(trim((string)($cfg["encryption"] ?? "ssl")));
        $username   = trim((string)($cfg["username"] ?? ""));
        $password   = (string)($cfg["password"] ?? "");
        $fromEmail  = trim((string)($cfg["from_email"] ?? $username));
        $fromName   = trim((string)($cfg["from_name"] ?? "Bolao do Thiago"));
        $timeout    = (int)($cfg["timeout"] ?? 20);

        if ($host === "" || $port <= 0 || $username === "" || $password === "" || $fromEmail === "") {
            cadastro_mail_log("Configuracao SMTP incompleta para PHPMailer.");
            return false;
        }

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->Port       = $port;
        $mail->Timeout    = $timeout;
        $mail->SMTPDebug  = 0;

        if ($encryption === "ssl") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === "tls") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet  = "UTF-8";
        $mail->Encoding = "base64";

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->Sender = $fromEmail;

        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        cadastro_mail_log("Email enviado via PHPMailer para {$toEmail}.");
        return true;
    } catch (Throwable $e) {
        cadastro_mail_log("Erro PHPMailer para {$toEmail}: " . $e->getMessage());

        if (isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== "") {
            cadastro_mail_log("PHPMailer ErrorInfo para {$toEmail}: " . $mail->ErrorInfo);
        }

        return false;
    }
}

function send_admin_alert_with_retry(
    array $mailConfig,
    string $recipientEmail,
    string $recipientName,
    string $htmlBody,
    string $textBody
): bool {
    $primarySubject = "Alô Alô, um Moisés se cadastrou";
    $fallbackSubject = "Alo Alo, um Moises se cadastrou";

    $sent = send_email_phpmailer($mailConfig, $recipientEmail, $recipientName, $primarySubject, $htmlBody, $textBody);
    if ($sent) {
        return true;
    }

    cadastro_mail_log("Retry alerta admin para {$recipientEmail} com assunto ASCII.");

    return send_email_phpmailer($mailConfig, $recipientEmail, $recipientName, $fallbackSubject, $htmlBody, $textBody);
}

function notify_new_user_register(array $mailConfig, int $newUserId, string $nomeCompleto, string $email, string $telefone): bool {
    $recipients = [
        ["email" => "thiagopterra@gmail.com", "name" => "Thiago"],
        ["email" => "mauriciopterra@gmail.com", "name" => "Mauricio"],
    ];

    $nomeSafe = htmlspecialchars($nomeCompleto, ENT_QUOTES, 'UTF-8');
    $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $telefoneSafe = htmlspecialchars($telefone, ENT_QUOTES, 'UTF-8');

    $htmlBody = ""
        . "<h2>Novo usuário cadastrado</h2>"
        . "<p><strong>Nome completo:</strong> {$nomeSafe}</p>"
        . "<p><strong>ID:</strong> {$newUserId}</p>"
        . "<p><strong>Email:</strong> {$emailSafe}</p>"
        . "<p><strong>Telefone:</strong> {$telefoneSafe}</p>";

    $textBody = ""
        . "Novo usuário cadastrado\n\n"
        . "Nome completo: {$nomeCompleto}\n"
        . "ID: {$newUserId}\n"
        . "Email: {$email}\n"
        . "Telefone: {$telefone}\n";

    $allSent = true;

    foreach ($recipients as $recipient) {
        $recipientEmail = (string)$recipient["email"];
        $recipientName = (string)$recipient["name"];

        $sent = send_admin_alert_with_retry(
            $mailConfig,
            $recipientEmail,
            $recipientName,
            $htmlBody,
            $textBody
        );

        if (!$sent) {
            $allSent = false;
            cadastro_mail_log("Falha ao enviar alerta admin para {$recipientEmail}.");
        } else {
            cadastro_mail_log("Alerta admin enviado para {$recipientEmail}.");
        }
    }

    return $allSent;
}

function send_welcome_email_to_new_user(array $mailConfig, string $toEmail, string $nomeCompleto): bool {
    $toName = $nomeCompleto;
    $subject = "Bem-vindo ao Bolao do Thiago! Sua resenha acaba de ganhar reforco";

    $nomeSafe = htmlspecialchars($nomeCompleto, ENT_QUOTES, 'UTF-8');

    $htmlBody = ""
        . "<h2>Fala, {$nomeSafe}! 🎉</h2>"
        . "<p>Seu cadastro foi confirmado e agora voce ja pode entrar no <strong>Bolao do Thiago</strong>.</p>"
        . "<p>Prepare o palpite, afie a intuicao e venha brigar pelo topo do ranking.</p>"
        . "<p>Regra nao escrita do bolao: quem acerta, comemora. Quem erra, diz que foi estrategico.</p>"
        . "<p>Boa sorte e divirta-se! ⚽🏆</p>";

    $textBody = ""
        . "Fala, {$nomeCompleto}!\n\n"
        . "Seu cadastro foi confirmado e agora voce ja pode entrar no Bolao do Thiago.\n"
        . "Prepare o palpite, afie a intuicao e venha brigar pelo topo do ranking.\n\n"
        . "Regra nao escrita do bolao: quem acerta, comemora. Quem erra, diz que foi estrategico.\n\n"
        . "Boa sorte e divirta-se!";

    $sent = send_email_phpmailer($mailConfig, $toEmail, $toName, $subject, $htmlBody, $textBody);

    if (!$sent) {
        cadastro_mail_log("Falha ao enviar boas-vindas para {$toEmail}.");
    } else {
        cadastro_mail_log("Boas-vindas enviada para {$toEmail}.");
    }

    return $sent;
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
$nomeCompletoInformado = trim($nomeRaw . " " . $sobrenomeRaw);
$nomeCompletoInformado = preg_replace('/\s+/', ' ', $nomeCompletoInformado) ?? $nomeCompletoInformado;

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

    $newUserId = (int)$pdo->lastInsertId();

    $pdo->commit();

    $mailConfig = load_mail_config_for_register();
    if (!load_phpmailer_for_register()) {
        cadastro_mail_log('Falha ao carregar PHPMailer para envios pos-cadastro.');
        header("Location: /cadastro.php?sucesso=1");
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