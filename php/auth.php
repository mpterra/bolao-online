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
| - ✅ Detecta automaticamente BASE PATH (localhost /bolao-da-copa/public vs host raiz /)
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);

// Em produção: não exibir erros na tela
$debug = (getenv("APP_DEBUG") === "1");
ini_set("display_errors", $debug ? "1" : "0");
ini_set("display_startup_errors", $debug ? "1" : "0");

// ------------------------------------------------------------
// Helpers de path
// ------------------------------------------------------------
function url_path_join(string $a, string $b): string {
    $a = rtrim($a, "/");
    $b = ltrim($b, "/");
    $out = $a === "" ? "/" . $b : $a . "/" . $b;
    // garante que começa com /
    if ($out === "") $out = "/";
    if ($out[0] !== "/") $out = "/" . $out;
    // remove // repetidos
    $out = preg_replace("#/+#", "/", $out) ?? $out;
    return $out;
}

/**
 * Descobre o prefixo do projeto via URL a partir do caminho do script atual.
 * Como auth.php fica em /php/auth.php:
 * - localhost: /bolao-da-copa/php/auth.php  -> prefixo: /bolao-da-copa
 * - host raiz: /php/auth.php                -> prefixo: (vazio) => "/"
 */
function detect_project_prefix_from_script(): string {
    $script = (string)($_SERVER["SCRIPT_NAME"] ?? "");
    if ($script === "") return "";

    $d1 = dirname($script);         // .../php
    $d2 = dirname($d1);             // ... (raiz do projeto) ou "/"
    $d2 = str_replace("\\", "/", $d2);

    if ($d2 === "/" || $d2 === "." || $d2 === "\\") return "";
    return $d2;
}

/**
 * Decide se a "public" é um diretório na URL ou se as páginas estão na raiz.
 * Baseado no filesystem:
 * - Dev: existe ../public/index.php e ../public/app.php => usa /<prefix>/public
 * - Deploy raiz: existe ../index.php e ../app.php => usa /<prefix> (sem /public)
 */
function detect_public_url_prefix(): string {
    $projectPrefix = detect_project_prefix_from_script(); // "" ou "/bolao-da-copa"

    $fsPublicIndex = __DIR__ . "/../public/index.php";
    $fsPublicApp   = __DIR__ . "/../public/app.php";

    $fsRootIndex = __DIR__ . "/../index.php";
    $fsRootApp   = __DIR__ . "/../app.php";

    $hasPublic = is_file($fsPublicIndex) && is_file($fsPublicApp);
    $hasRoot   = is_file($fsRootIndex) && is_file($fsRootApp);

    // Se existe /public "de verdade", prioriza ela.
    if ($hasPublic) {
        return url_path_join($projectPrefix, "public");
    }

    // Se não existe /public, assume páginas na raiz do projeto
    if ($hasRoot) {
        return $projectPrefix === "" ? "" : $projectPrefix;
    }

    // Fallback: tenta /public (comportamento antigo), senão raiz
    return url_path_join($projectPrefix, "public");
}

$PUBLIC_URL_PREFIX = detect_public_url_prefix();
$LOGIN_PATH = url_path_join($PUBLIC_URL_PREFIX, "index.php");
$APP_PATH   = url_path_join($PUBLIC_URL_PREFIX, "app.php");

// ------------------------------------------------------------
// Sessão segura (antes de session_start)
// ------------------------------------------------------------
$https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => $https,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
} else {
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_secure", $https ? "1" : "0");
    ini_set("session.cookie_samesite", "Lax");
}

ini_set("session.use_strict_mode", "1");
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
    return isset($_SERVER["REMOTE_ADDR"]) ? (string)$_SERVER["REMOTE_ADDR"] : "0.0.0.0";
}

/**
 * Rate-limit simples por IP (na sessão).
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

    if (!empty($bucket["lock"]) && (int)$bucket["lock"] > $now) {
        redirect_login_with_flash("Muitas tentativas. Aguarde um pouco e tente novamente.", "warn");
    }

    if (($now - (int)$bucket["start"]) > $windowSeconds) {
        $bucket["count"] = 0;
        $bucket["start"] = $now;
        $bucket["lock"]  = 0;
    }

    $bucket["count"] = (int)$bucket["count"] + 1;

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

    // ✅ Mitiga session fixation
    session_regenerate_id(true);

    $_SESSION["usuario_id"]    = (int)$u["id"];
    $_SESSION["usuario_nome"]  = (string)$u["nome"];
    $_SESSION["usuario_email"] = (string)$u["email"];
    $_SESSION["tipo_usuario"]  = (string)$u["tipo_usuario"];

    unset($_SESSION["rl"]["login_ip_" . $ip]);

    header("Location: " . $APP_PATH);
    exit;

} catch (Throwable $e) {
    error_log("[AUTH] Erro no login: " . $e->getMessage());
    redirect_login_with_flash("Erro no login. Tente novamente.", "error");
}