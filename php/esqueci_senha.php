<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ESQUECI_SENHA.PHP — Solicitação de redefinição de senha (SMTP via PHPMailer)
|--------------------------------------------------------------------------
| Fluxo:
|  1. Usuário informa o e-mail.
|  2. Se o e-mail existir na base, geramos um token seguro (64 hex chars),
|     salvamos na tabela password_resets com validade de 1 hora e enviamos
|     o link por e-mail via SMTP (Titan).
|  3. Sempre exibimos a mesma mensagem ao usuário (evita enumeração).
|--------------------------------------------------------------------------
*/

$debug = (getenv("APP_DEBUG") === "1");
ini_set("display_errors", $debug ? "1" : "0");
ini_set("display_startup_errors", $debug ? "1" : "0");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ── Apenas POST ──────────────────────────────────────────
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  header("Location: /esqueci_senha.php");
  exit;
}

require_once __DIR__ . "/conexao.php";
require_once __DIR__ . "/smtp_mailer.php";

// ── Helpers ──────────────────────────────────────────────
function redirect_reset(string $msg, string $type = "info", string $dest = "/esqueci_senha.php"): never {
    $_SESSION["flash_reset"] = ["type" => $type, "msg" => $msg, "ts" => time()];
    header("Location: " . $dest);
    exit;
}

function build_base_url(): string {
    $proto = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    $host  = $_SERVER["HTTP_HOST"] ?? "bolaodothiago.com.br";
    return $proto . "://" . $host;
}

$defaultMailConfig = [
  "host"       => "mail.bolaodothiago.com.br",
  "port"       => 465,
  "encryption" => "ssl",
  "username"   => "admin@bolaodothiago.com.br",
  "password"   => "Eng%3571Hawaii",
  "from_email" => "admin@bolaodothiago.com.br",
  "from_name"  => "Bolão do Thiago",
  "timeout"    => 20,
];

$mailConfigPath = __DIR__ . "/../config/mail.php";
$mailConfig = $defaultMailConfig;

if (is_file($mailConfigPath)) {
  $loadedMailConfig = require $mailConfigPath;
  if (is_array($loadedMailConfig)) {
    $mailConfig = array_merge($defaultMailConfig, $loadedMailConfig);
  }
} else {
  error_log("[esqueci_senha] Usando configuração SMTP embutida; arquivo ausente em: " . $mailConfigPath);
}

// ── Validação básica ─────────────────────────────────────
$email = trim((string)($_POST["email"] ?? ""));

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_reset("Informe um e-mail válido.", "error");
}

$email = mb_strtolower($email, "UTF-8");

// ── Busca usuário ────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE LOWER(email) = ? AND ativo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log("[esqueci_senha] DB error: " . $e->getMessage());
    redirect_reset("Erro interno. Tente novamente mais tarde.", "error");
}

$generic_msg = "Se o e-mail informado estiver cadastrado, você receberá em instantes um link para redefinir sua senha.";

if (!$user) {
    redirect_reset($generic_msg, "info");
}

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
    error_log("[esqueci_senha] DB error ao salvar token: " . $e->getMessage());
    redirect_reset("Erro interno. Tente novamente mais tarde.", "error");
}

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

smtp_send_mail(
  $mailConfig,
  $email,
  (string)($user["nome"] ?? ""),
  "Redefinição de senha — Bolão do Thiago",
  $htmlBody,
  "Acesse o link para redefinir sua senha: {$resetLink} (expira em 1 hora)"
);

redirect_reset($generic_msg, "info");
