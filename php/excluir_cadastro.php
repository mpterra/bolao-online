<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
app_start_session();
app_send_security_headers();

require_once __DIR__ . '/conexao.php';

function redirect_profile_delete_with_flash(string $message, string $type = 'error'): never {
    $_SESSION['flash_profile'] = [
        'type' => $type,
        'msg' => $message,
        'ts' => time(),
    ];

    header('Location: /meus_dados.php');
    exit;
}

function self_delete_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return (bool)$cache[$table];
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ');
    $stmt->execute([$table]);

    $cache[$table] = (bool)$stmt->fetchColumn();
    return (bool)$cache[$table];
}

function self_delete_exec_if_table(PDO $pdo, string $table, string $sql, array $params): void {
    if (!self_delete_table_exists($pdo, $table)) {
        return;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function self_delete_destroy_session(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /meus_dados.php');
    exit;
}

app_require_csrf(false);

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    header('Location: /index.php');
    exit;
}

$senhaAtual = (string)($_POST['senha_atual'] ?? '');
if ($senhaAtual === '') {
    redirect_profile_delete_with_flash('Informe sua senha para confirmar o descadastro.', 'warn');
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    redirect_profile_delete_with_flash('Nao foi possivel acessar o banco de dados agora.', 'error');
}

$rateKey = app_rate_limit_key('self_delete', (string)$usuarioId);
if (app_rate_limit_is_locked($pdo, $rateKey)) {
    redirect_profile_delete_with_flash('Muitas tentativas incorretas. Aguarde alguns minutos e tente novamente.', 'warn');
}

try {
    $stmt = $pdo->prepare('
        SELECT id, senha_hash
        FROM usuarios
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        self_delete_destroy_session();
        header('Location: /index.php');
        exit;
    }

    $senhaHash = (string)($usuario['senha_hash'] ?? '');
    if ($senhaHash === '' || !password_verify($senhaAtual, $senhaHash)) {
        app_rate_limit_register_failure($pdo, $rateKey, 5, 15 * 60, 10 * 60);
        redirect_profile_delete_with_flash('Senha incorreta. Seu cadastro nao foi excluido.', 'warn');
    }

    app_rate_limit_clear($pdo, $rateKey);

    $pdo->beginTransaction();

    $lockStmt = $pdo->prepare('
        SELECT id
        FROM usuarios
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ');
    $lockStmt->execute([$usuarioId]);

    if (!$lockStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        self_delete_destroy_session();
        header('Location: /index.php');
        exit;
    }

    self_delete_exec_if_table(
        $pdo,
        'pontos_palpite',
        'DELETE pp FROM pontos_palpite pp INNER JOIN palpites p ON p.id = pp.palpite_id WHERE p.usuario_id = ?',
        [$usuarioId]
    );
    self_delete_exec_if_table($pdo, 'password_resets', 'DELETE FROM password_resets WHERE user_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'bet_update_notification_items', 'DELETE FROM bet_update_notification_items WHERE usuario_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'bet_update_notifications', 'DELETE FROM bet_update_notifications WHERE usuario_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'ranking', 'DELETE FROM ranking WHERE usuario_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'palpite_top4', 'DELETE FROM palpite_top4 WHERE usuario_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'palpite_campeao', 'DELETE FROM palpite_campeao WHERE usuario_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'palpite_grupo_classificacao', 'DELETE FROM palpite_grupo_classificacao WHERE usuario_id = ?', [$usuarioId]);
    self_delete_exec_if_table($pdo, 'palpites', 'DELETE FROM palpites WHERE usuario_id = ?', [$usuarioId]);

    $deleteUser = $pdo->prepare('DELETE FROM usuarios WHERE id = ? LIMIT 1');
    $deleteUser->execute([$usuarioId]);

    if ($deleteUser->rowCount() !== 1) {
        throw new RuntimeException('Cadastro nao removido.');
    }

    $pdo->commit();

    self_delete_destroy_session();
    header('Location: /cadastro_excluido.php');
    exit;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[excluir_cadastro] ' . $e->getMessage());
    redirect_profile_delete_with_flash('Nao foi possivel excluir seu cadastro agora.', 'error');
}
