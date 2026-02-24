<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AUTH.PHP — LOGIN (BOLÃO DA COPA)
|--------------------------------------------------------------------------
| Melhorias (sem quebrar fluxo):
| - Sessão mais segura (cookie flags + modo estrito)
| - Regenera session_id após login (mitiga session fixation)
| - Rate-limit simples por IP (mitiga brute force básico)
| - Não expõe erros em produção (loga no error_log)
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);

// Em produção: não exibir erros na tela
$debug = (getenv("APP_DEBUG") === "1");
ini_set("display_errors", $debug ? "1" : "0");
ini_set("display_startup_errors", $debug ? "1" : "0");

// ------------------------------------------------------------
// Rotas (ajuste aqui se não estiver na raiz do domínio)
// Para HostGator na raiz: /index.php e /app.php
// ------------------------------------------------------------
$LOGIN_PATH = "/bolao-da-copa/public/index.php";
$APP_PATH   = "/bolao-da-copa/public/app.php";

// ------------------------------------------------------------
// Sessão segura (antes de session_start)
// ------------------------------------------------------------
$https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");

if (PHP_VERSION_ID >= 70300) {
    // PHP 7.3+: SameSite nativo
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => $https,   // só envia cookie em HTTPS
        "httponly" => true,     // JS não lê cookie
        "samesite" => "Lax",    // bom padrão para apps web
    ]);
} else {
    // Fallback (caso raro)
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_secure", $https ? "1" : "0");
    ini_set("session.cookie_samesite", "Lax");
}

ini_set("session.use_strict_mode", "1"); // evita aceitar IDs de sessão “inventados”
ini_set("session.use_only_cookies", "1");

session_start();

require_once __DIR__ . "/conexao.php";

function redirect_login_with_flash(string $msg, string $type = "error"): void {
    global $LOGIN_PATH;

    $_SESSION["flash_login"] = [
        "type" => $type, // error | warn | info | ok
        "msg"  => $msg,
        "ts"   => time(),
    ];
    header("Location: " . $LOGIN_PATH);
    exit;
}

function client_ip(): string {
    // Em shared hosting normalmente REMOTE_ADDR é o mais confiável.
    return isset($_SERVER["REMOTE_ADDR"]) ? (string)$_SERVER["REMOTE_ADDR"] : "0.0.0.0";
}

/**
 * Rate-limit simples por IP (na sessão).
 * Observação: é um “best-effort”, não substitui rate-limit por servidor/WAF.
 */
function rate_limit_guard(string $key, int $maxAttempts, int $windowSeconds, int $cooldownSeconds): void {
    $now = time();

    if (!isset($_SESSION["rl"]) || !is_array($_SESSION["rl"])) {
        $_SESSION["rl"] = [];
    }

    $bucket = $_SESSION["rl"][$key] ?? [
        "count" => 0,
        "start" => $now,
        "lock"  => 0,
    ];

    // Se está em cooldown
    if (!empty($bucket["lock"]) && $bucket["lock"] > $now) {
        redirect_login_with_flash("Muitas tentativas. Aguarde um pouco e tente novamente.", "warn");
    }

    // Reinicia janela
    if (($now - (int)$bucket["start"]) > $windowSeconds) {
        $bucket["count"] = 0;
        $bucket["start"] = $now;
        $bucket["lock"]  = 0;
    }

    // Incrementa tentativa
    $bucket["count"] = (int)$bucket["count"] + 1;

    // Estourou: trava por cooldown
    if ((int)$bucket["count"] > $maxAttempts) {
        $bucket["lock"] = $now + $cooldownSeconds;
        $_SESSION["rl"][$key] = $bucket;
        redirect_login_with_flash("Muitas tentativas. Aguarde um pouco e tente novamente.", "warn");
    }

    $_SESSION["rl"][$key] = $bucket;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: " . $LOGIN_PATH);
    exit;
}

$email = trim((string)($_POST["usuario"] ?? ""));
$senha = (string)($_POST["senha"] ?? "");

if ($email === "" || $senha === "") {
    redirect_login_with_flash("Informe e-mail e senha para continuar.", "warn");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_login_with_flash("Digite um e-mail válido.", "warn");
}

// Rate limit por IP (5 tentativas por 60s; cooldown 60s)
$ip = client_ip();
rate_limit_guard("login_ip_" . $ip, 5, 60, 60);

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

    // Mensagem genérica para não “enumerar” usuário
    if (!$u) {
        redirect_login_with_flash("E-mail ou senha inválidos.", "error");
    }

    if ((int)$u["ativo"] !== 1) {
        redirect_login_with_flash("Usuário inativo. Fale com o administrador.", "warn");
    }

    if (!password_verify($senha, (string)$u["senha_hash"])) {
        redirect_login_with_flash("E-mail ou senha inválidos.", "error");
    }

    // ✅ Mitiga session fixation
    session_regenerate_id(true);

    $_SESSION["usuario_id"]    = (int)$u["id"];
    $_SESSION["usuario_nome"]  = (string)$u["nome"];
    $_SESSION["usuario_email"] = (string)$u["email"];
    $_SESSION["tipo_usuario"]  = (string)$u["tipo_usuario"];

    // Limpa rate-limit na sessão após sucesso (opcional)
    unset($_SESSION["rl"]["login_ip_" . $ip]);

    header("Location: " . $APP_PATH);
    exit;

} catch (Throwable $e) {
    error_log("[AUTH] Erro no login: " . $e->getMessage());
    redirect_login_with_flash("Erro no login. Tente novamente.", "error");
}