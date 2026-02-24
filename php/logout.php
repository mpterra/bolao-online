<?php
declare(strict_types=1);

// ------------------------------------------------------------
// Sessão segura (antes de session_start) — mesmo padrão do auth.php
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

// limpa dados da sessão
$_SESSION = [];

// remove cookie de sessão (se existir)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            "expires"  => time() - 42000,
            "path"     => $params["path"] ?? "/",
            "domain"   => $params["domain"] ?? "",
            "secure"   => (bool)($params["secure"] ?? $https),
            "httponly" => (bool)($params["httponly"] ?? true),
            "samesite" => $params["samesite"] ?? "Lax",
        ]);
    } else {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"] ?? "/",
            $params["domain"] ?? "",
            (bool)($params["secure"] ?? $https),
            (bool)($params["httponly"] ?? true)
        );
    }
}

// encerra a sessão
session_destroy();

// ✅ HostGator: volta pro login na raiz do public_html
header("Location: /index.php");
exit;