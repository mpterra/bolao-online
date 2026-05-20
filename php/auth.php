<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AUTH.PHP - LOGIN
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/security.php";
app_start_session();
app_send_security_headers();

require_once __DIR__ . "/conexao.php";

$LOGIN_PATH = "/index.php";
$APP_PATH   = "/app.php";

function redirect_login_with_flash(string $msg, string $type = "error"): void
{
    global $LOGIN_PATH;

    $_SESSION["flash_login"] = [
        "type" => $type,
        "msg"  => $msg,
        "ts"   => time(),
    ];

    header("Location: " . $LOGIN_PATH);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: " . $LOGIN_PATH);
    exit;
}

app_require_csrf(false);

$email = mb_strtolower(trim((string)($_POST["usuario"] ?? "")), "UTF-8");
$senha = (string)($_POST["senha"] ?? "");

if ($email === "" || $senha === "") {
    redirect_login_with_flash("Informe e-mail e senha para continuar.", "warn");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_login_with_flash("Digite um e-mail valido.", "warn");
}

$ip = app_client_ip();
$loginIpKey = app_rate_limit_key("login_ip", $ip);
$loginUserKey = app_rate_limit_key("login_user", $email);
$loginMaxAttempts = 5;
$loginWindowSeconds = 600;
$loginCooldownSeconds = 900;

if (app_rate_limit_is_locked($pdo, $loginIpKey) || app_rate_limit_is_locked($pdo, $loginUserKey)) {
    redirect_login_with_flash("Muitas tentativas. Aguarde um pouco e tente novamente.", "warn");
}

$invalidLoginMessage = static function (int $remaining): string {
    if ($remaining <= 0) {
        return "E-mail ou senha invalidos. Limite de tentativas atingido. Aguarde um pouco e tente novamente.";
    }

    $plural = $remaining === 1 ? "tentativa" : "tentativas";
    return "E-mail ou senha invalidos. Voce ainda tem {$remaining} {$plural} antes do bloqueio temporario.";
};

$registerLoginFailure = function () use ($pdo, $loginIpKey, $loginUserKey, $loginMaxAttempts, $loginWindowSeconds, $loginCooldownSeconds): array {
    $ipStatus = app_rate_limit_register_failure($pdo, $loginIpKey, $loginMaxAttempts, $loginWindowSeconds, $loginCooldownSeconds);
    $userStatus = app_rate_limit_register_failure($pdo, $loginUserKey, $loginMaxAttempts, $loginWindowSeconds, $loginCooldownSeconds);

    return [
        "remaining" => min((int)($ipStatus["remaining"] ?? 0), (int)($userStatus["remaining"] ?? 0)),
        "locked" => !empty($ipStatus["locked"]) || !empty($userStatus["locked"]),
    ];
};

try {
    $stmt = $pdo->prepare("
        SELECT id, nome, email, senha_hash, tipo_usuario, ativo
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        $status = $registerLoginFailure();
        redirect_login_with_flash($invalidLoginMessage((int)$status["remaining"]), !empty($status["locked"]) ? "warn" : "error");
    }

    if ((int)$u["ativo"] !== 1) {
        $registerLoginFailure();
        redirect_login_with_flash("Usuario inativo. Fale com o administrador.", "warn");
    }

    if (!password_verify($senha, (string)$u["senha_hash"])) {
        $status = $registerLoginFailure();
        redirect_login_with_flash($invalidLoginMessage((int)$status["remaining"]), !empty($status["locked"]) ? "warn" : "error");
    }

    session_regenerate_id(true);

    $_SESSION["usuario_id"]    = (int)$u["id"];
    $_SESSION["usuario_nome"]  = (string)$u["nome"];
    $_SESSION["usuario_email"] = (string)$u["email"];
    $_SESSION["tipo_usuario"]  = (string)$u["tipo_usuario"];

    app_rate_limit_clear($pdo, $loginIpKey);
    app_rate_limit_clear($pdo, $loginUserKey);

    header("Location: " . $APP_PATH);
    exit;
} catch (Throwable $e) {
    error_log("[AUTH] Erro no login: " . $e->getMessage());
    redirect_login_with_flash("Erro no login. Tente novamente.", "error");
}
