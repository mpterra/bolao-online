<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ESQUECI_SENHA.PHP — Solicitação de redefinição de senha (SMTP compartilhado)
|--------------------------------------------------------------------------
| Fluxo:
|  1. Usuário informa o e-mail.
|  2. Se o e-mail existir na base, geramos um token seguro (64 hex chars),
|     salvamos na tabela password_resets com validade de 1 hora e enviamos
|     o link por e-mail via SMTP.
|  3. Se o e-mail não existir, retornamos feedback ao usuário sem tentar envio.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/security.php";
app_start_session();
app_send_security_headers();
require_once __DIR__ . "/smtp_mailer.php";

// ── Apenas POST ──────────────────────────────────────────
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: /esqueci_senha.php");
    exit;
}

app_require_csrf(false);

require_once __DIR__ . "/conexao.php";

// ── Helpers ──────────────────────────────────────────────
function redirect_reset(string $msg, string $type = "info", string $dest = "/esqueci_senha.php"): never {
    $_SESSION["flash_reset"] = ["type" => $type, "msg" => $msg, "ts" => time()];
    header("Location: " . $dest);
    exit;
}

function reset_log(string $requestId, string $message): void {
    $line = sprintf("[%s] <%s> %s\n", date('Y-m-d H:i:s'), $requestId, $message);
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/reset-mail.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    error_log('[esqueci_senha] ' . $line);
}

function build_base_url(): string {
    $proto = app_is_https() ? "https" : "http";
    $host  = $_SERVER["HTTP_HOST"] ?? "bolaodothiago.com.br";
    return $proto . "://" . $host;
}

function send_reset_email(
    array $cfg,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $requestId
): bool {
    $sent = smtp_send_mail($cfg, $toEmail, $toName, $subject, $htmlBody, $textBody);

    if ($sent) {
        $host = trim((string)($cfg["host"] ?? ""));
        $port = (int)($cfg["port"] ?? 0);
        $encryption = strtolower(trim((string)($cfg["encryption"] ?? "ssl")));
        reset_log(
            $requestId,
            "Email enviado com sucesso via SMTP em {$host}:{$port} ({$encryption}) para {$toEmail}."
        );
        return true;
    }

    $smtpError = trim(smtp_get_last_error());
    if ($smtpError !== '') {
        reset_log($requestId, "Erro SMTP: " . $smtpError);
    } else {
        reset_log($requestId, "Erro SMTP sem detalhe adicional.");
    }

    try {
        reset_log($requestId, "Config SMTP usada: " . json_encode([
            'host' => (string)($cfg['host'] ?? ''),
            'port' => (int)($cfg['port'] ?? 0),
            'encryption' => (string)($cfg['encryption'] ?? ''),
            'from_email' => (string)($cfg['from_email'] ?? ''),
            'from_name' => (string)($cfg['from_name'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        reset_log($requestId, "Falha ao serializar configuracao SMTP.");
    }

    return false;
}

function delete_reset_token(PDO $pdo, int $userId, string $requestId): void
{
    if ($userId <= 0) {
        return;
    }

    try {
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
        reset_log($requestId, "Token removido apos falha no envio.");
    } catch (Throwable $e) {
        reset_log($requestId, "Falha ao remover token apos erro de envio: " . $e->getMessage());
    }
}

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

$mailConfig = $defaultMailConfig;
$requestId = bin2hex(random_bytes(4));

reset_log($requestId, "Inicio do fluxo de esqueci_senha.");

foreach ($mailConfigPaths as $mailConfigPath) {
    if (is_file($mailConfigPath)) {
        $loadedMailConfig = require $mailConfigPath;

        if (is_array($loadedMailConfig)) {
            $mailConfig = array_merge($defaultMailConfig, $loadedMailConfig);
            reset_log($requestId, "Config SMTP carregada de: " . $mailConfigPath);
            break;
        }
    }
}

if ($mailConfig === $defaultMailConfig) {
    reset_log($requestId, "Usando configuração SMTP embutida.");
}

// ── Validação básica ─────────────────────────────────────
$email = trim((string)($_POST["email"] ?? ""));

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reset_log($requestId, "Email invalido no POST.");
    redirect_reset("Informe um e-mail válido.", "error");
}

$email = mb_strtolower($email, "UTF-8");
reset_log($requestId, "Email recebido: " . $email);

$resetIpKey = app_rate_limit_key("password_reset_ip", app_client_ip());
$resetEmailKey = app_rate_limit_key("password_reset_email", $email);

if (app_rate_limit_is_locked($pdo, $resetIpKey) || app_rate_limit_is_locked($pdo, $resetEmailKey)) {
    reset_log($requestId, "Solicitacao bloqueada por rate limit.");
    redirect_reset("Muitas solicitacoes. Aguarde um pouco e tente novamente.", "warn");
}

// ── Busca usuário ────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE LOWER(email) = ? AND ativo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    reset_log($requestId, "DB error ao buscar usuario: " . $e->getMessage());
    redirect_reset("Erro interno. Tente novamente mais tarde.", "error");
}

$success_msg = "Se o e-mail informado estiver cadastrado, você receberá em instantes um link para redefinir sua senha.";
$not_found_msg = "Email nao cadastrado.";

if (!$user) {
    app_rate_limit_register_failure($pdo, $resetIpKey, 5, 900, 1800);
    app_rate_limit_register_failure($pdo, $resetEmailKey, 5, 900, 1800);
    reset_log($requestId, "Usuario nao encontrado ou inativo.");
    redirect_reset($not_found_msg, "warn");
}

reset_log($requestId, "Usuario encontrado. ID=" . (string)$user["id"]);

// ── Gera token ───────────────────────────────────────────
$token     = bin2hex(random_bytes(32));
$expiresAt = date("Y-m-d H:i:s", time() + 3600);

// ── Persiste token ───────────────────────────────────────
try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user["id"]]);

    $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
        ->execute([$user["id"], $token, $expiresAt]);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    reset_log($requestId, "DB error ao salvar token: " . $e->getMessage());
    redirect_reset("Erro interno. Tente novamente mais tarde.", "error");
}

reset_log($requestId, "Token salvo com sucesso.");

// ── Monta e-mail ─────────────────────────────────────────
$baseUrl   = build_base_url();
$resetLink = $baseUrl . "/redefinir_senha.php?token=" . urlencode($token);
$nomeUser  = htmlspecialchars((string)($user["nome"] ?? ""), ENT_QUOTES, "UTF-8");

$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;background:#071a1f;color:#ffffff;margin:0;padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:32px auto;background:#0c2a30;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.5);">
    <tr>
      <td style="background:linear-gradient(90deg,#00c27a,#f7c948);padding:4px 0;"></td>
    </tr>
    <tr>
      <td style="padding:32px 32px 24px;">
        <h2 style="margin:0 0 8px;font-size:20px;color:#f7c948;">Bolão do Thiago 🏆</h2>
        <p style="margin:0 0 20px;font-size:15px;color:#cfeff2;">Olá, <strong>{$nomeUser}</strong>!</p>
        <p style="margin:0 0 16px;font-size:14px;color:#cfeff2;line-height:1.6;">
          Recebemos uma solicitação para redefinir a senha da sua conta.
          Clique no botão abaixo para criar uma nova senha.<br>
          <em style="font-size:12px;color:rgba(207,239,242,.65);">O link expira em <strong>1 hora</strong>.</em>
        </p>
        <p style="text-align:center;margin:24px 0;">
          <a href="{$resetLink}"
             style="display:inline-block;padding:13px 32px;background:linear-gradient(90deg,#00c27a,#f7c948);color:#062027;font-weight:900;font-size:15px;text-decoration:none;border-radius:14px;">
            Redefinir minha senha
          </a>
        </p>
        <p style="font-size:12px;color:rgba(207,239,242,.55);word-break:break-all;">
          Ou copie e cole este link no seu navegador:<br>
          <a href="{$resetLink}" style="color:#10d08a;">{$resetLink}</a>
        </p>
        <hr style="border:none;border-top:1px solid rgba(255,255,255,.12);margin:24px 0;">
        <p style="font-size:12px;color:rgba(207,239,242,.45);margin:0;">
          Se você não solicitou a redefinição de senha, ignore este e-mail.
          Sua senha permanece a mesma.
        </p>
      </td>
    </tr>
    <tr>
      <td style="background:linear-gradient(90deg,#00c27a,#f7c948);padding:4px 0;"></td>
    </tr>
  </table>
</body>
</html>
HTML;

$textBody = "Olá, " . (string)($user["nome"] ?? "") . "!\n\n"
    . "Recebemos uma solicitação para redefinir a senha da sua conta.\n\n"
    . "Acesse o link abaixo para criar uma nova senha:\n"
    . $resetLink . "\n\n"
    . "O link expira em 1 hora.\n\n"
    . "Se você não solicitou a redefinição de senha, ignore este e-mail.";

$sent = send_reset_email(
    $mailConfig,
    $email,
    (string)($user["nome"] ?? ""),
    "Redefinição de senha — Bolão do Thiago",
    $htmlBody,
    $textBody,
    $requestId
);

if ($sent) {
    app_rate_limit_clear($pdo, $resetIpKey);
    app_rate_limit_clear($pdo, $resetEmailKey);
    reset_log($requestId, "Fluxo finalizado com sucesso.");
    redirect_reset($success_msg, "info");
} else {
    reset_log($requestId, "Fluxo finalizado sem envio.");
    delete_reset_token($pdo, (int)$user["id"], $requestId);
    redirect_reset("Nao foi possivel enviar o e-mail agora. Tente novamente em alguns minutos.", "error");
}
