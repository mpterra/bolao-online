<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REDEFINIR_SENHA.PHP — Processamento da nova senha
|--------------------------------------------------------------------------
| Fluxo:
|  1. Recebe token (POST) + nova_senha + confirmar_senha.
|  2. Valida token na tabela password_resets (existe + não expirado).
|  3. Valida e atualiza a senha do usuário.
|  4. Remove todos os tokens do usuário.
|  5. Redireciona para o login com flash de sucesso.
|--------------------------------------------------------------------------
*/

$debug = (getenv("APP_DEBUG") === "1");
ini_set("display_errors", $debug ? "1" : "0");
ini_set("display_startup_errors", $debug ? "1" : "0");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/conexao.php";

// ── Apenas POST ──────────────────────────────────────────
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: /index.php");
    exit;
}

// ── Helpers ──────────────────────────────────────────────
function redirect_redefine(string $msg, string $type, string $dest): never {
    $_SESSION["flash_reset"] = ["type" => $type, "msg" => $msg, "ts" => time()];
    header("Location: " . $dest);
    exit;
}

// ── Coleta dados ─────────────────────────────────────────
$token    = trim((string)($_POST["token"] ?? ""));
$nova     = (string)($_POST["nova_senha"] ?? "");
$confirma = (string)($_POST["confirmar_senha"] ?? "");

// Validação básica do token (64 chars hex)
if ($token === "" || !preg_match('/^[0-9a-f]{64}$/', $token)) {
    redirect_redefine(
        "Link inválido ou expirado. Solicite um novo link.",
        "error",
        "/esqueci_senha.php"
    );
}

// ── Valida senhas ────────────────────────────────────────
if (strlen($nova) < 8) {
    redirect_redefine(
        "A nova senha deve ter no mínimo 8 caracteres.",
        "error",
        "/redefinir_senha.php?token=" . urlencode($token)
    );
}

if ($nova !== $confirma) {
    redirect_redefine(
        "As senhas não coincidem. Tente novamente.",
        "error",
        "/redefinir_senha.php?token=" . urlencode($token)
    );
}

// ── Valida token no banco ────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT pr.id AS reset_id, pr.user_id, pr.expires_at
         FROM password_resets pr
         WHERE pr.token = ?
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
} catch (PDOException $e) {
    error_log("[redefinir_senha] DB error: " . $e->getMessage());
    redirect_redefine("Erro interno. Tente novamente.", "error", "/esqueci_senha.php");
}

if (!$reset) {
    redirect_redefine(
        "Link inválido ou já utilizado. Solicite um novo link.",
        "error",
        "/esqueci_senha.php"
    );
}

// Verifica expiração
if (strtotime($reset["expires_at"]) < time()) {
    // Remove token expirado
    try {
        $pdo->prepare("DELETE FROM password_resets WHERE id = ?")->execute([$reset["reset_id"]]);
    } catch (PDOException) { /* ignora */ }

    redirect_redefine(
        "Este link expirou. Solicite um novo link de redefinição.",
        "warn",
        "/esqueci_senha.php"
    );
}

// ── Atualiza senha ───────────────────────────────────────
$novaHash = password_hash($nova, PASSWORD_BCRYPT, ["cost" => 12]);

try {
    $pdo->beginTransaction();

    $upd = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
    $upd->execute([$novaHash, $reset["user_id"]]);

    // Remove todos os tokens deste usuário (incluindo o usado agora)
    $del = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $del->execute([$reset["user_id"]]);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("[redefinir_senha] DB error ao atualizar senha: " . $e->getMessage());
    redirect_redefine("Erro interno. Tente novamente.", "error", "/esqueci_senha.php");
}

// ── Redireciona para login com sucesso ───────────────────
$_SESSION["flash_login"] = [
    "type" => "ok",
    "msg"  => "Senha redefinida com sucesso! Faça login com sua nova senha.",
    "ts"   => time(),
];
header("Location: /index.php");
exit;
