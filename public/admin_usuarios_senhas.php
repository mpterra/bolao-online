<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/php/security.php";
app_start_session();
app_send_security_headers();

require_once dirname(__DIR__) . "/php/conexao.php";

function strh(?string $s): string
{
    return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

function format_datetime_br(?string $value): string
{
    if ($value === null || trim($value) === "") {
        return "—";
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date("d/m/Y H:i", $timestamp) : $value;
}

function admin_password_search_term(?string $value): string
{
    $search = trim((string)($value ?? ""));
    $search = preg_replace('/\s+/', ' ', $search) ?? $search;

    return mb_substr($search, 0, 120, 'UTF-8');
}

function admin_password_redirect(string $message, string $type, string $search = ''): never
{
    $_SESSION['admin_password_flash'] = [
        'type' => $type,
        'msg' => $message,
        'ts' => time(),
    ];

    $destination = '/admin_usuarios_senhas.php';
    if ($search !== '') {
        $destination .= '?q=' . rawurlencode($search);
    }

    header('Location: ' . $destination);
    exit;
}

function admin_password_fetch_metrics(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
            SUM(CASE WHEN UPPER(tipo_usuario) = 'ADMIN' THEN 1 ELSE 0 END) AS admins
        FROM usuarios
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total' => (int)($row['total'] ?? 0),
        'ativos' => (int)($row['ativos'] ?? 0),
        'admins' => (int)($row['admins'] ?? 0),
    ];
}

function admin_password_search_users(PDO $pdo, string $search, int $limit = 18): array
{
    if ($search === '') {
        return [
            'users' => [],
            'total' => 0,
            'limited' => false,
        ];
    }

    $like = '%' . $search . '%';
    $params = [];
    $where = '(u.nome LIKE ? OR u.email LIKE ? OR COALESCE(u.telefone, \'\') LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    if (ctype_digit($search)) {
        $where = '(u.id = ? OR ' . $where . ')';
        array_unshift($params, (int)$search);
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM usuarios u
        WHERE {$where}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            u.id,
            u.nome,
            u.email,
            u.telefone,
            u.cidade,
            u.estado,
            u.tipo_usuario,
            u.ativo,
            u.atualizado_em
        FROM usuarios u
        WHERE {$where}
        ORDER BY
            CASE WHEN UPPER(u.tipo_usuario) = 'ADMIN' THEN 0 ELSE 1 END ASC,
            u.ativo DESC,
            u.nome ASC,
            u.id ASC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'users' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'limited' => $total > $limit,
    ];
}

app_require_admin();

$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Admin";
$tipoSessao = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoSessao, "UTF-8") === "ADMIN");
$adminId = (int)($_SESSION['usuario_id'] ?? 0);

if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_destroy();
    header("Location: /index.php");
    exit;
}

$flash = null;
if (isset($_SESSION['admin_password_flash']) && is_array($_SESSION['admin_password_flash'])) {
    $flash = $_SESSION['admin_password_flash'];
    unset($_SESSION['admin_password_flash']);
}

$search = admin_password_search_term($_GET['q'] ?? '');
$formErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    app_require_csrf(false);

    $search = admin_password_search_term($_POST['q'] ?? '');
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newPassword = (string)($_POST['nova_senha'] ?? '');
    $confirmPassword = (string)($_POST['confirmar_nova_senha'] ?? '');

    if ($targetUserId <= 0) {
        $formErrors[] = 'Selecione um usuário válido.';
    }

    if (strlen($newPassword) < 8) {
        $formErrors[] = 'A nova senha deve ter no mínimo 8 caracteres.';
    }

    if ($newPassword !== $confirmPassword) {
        $formErrors[] = 'As senhas não coincidem.';
    }

    $passwordHash = null;
    if (count($formErrors) === 0) {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if (!is_string($passwordHash) || $passwordHash === '') {
            $formErrors[] = 'Não foi possível gerar a nova senha agora.';
        }
    }

    if (count($formErrors) === 0) {
        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare('
                SELECT id, nome, email
                FROM usuarios
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ');
            $userStmt->execute([$targetUserId]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $pdo->rollBack();
                admin_password_redirect('O usuário selecionado não foi encontrado.', 'error', $search);
            }

            $updateStmt = $pdo->prepare('
                UPDATE usuarios
                SET senha_hash = ?, atualizado_em = CURRENT_TIMESTAMP
                WHERE id = ?
                LIMIT 1
            ');
            $updateStmt->execute([$passwordHash, $targetUserId]);

            $deleteResetStmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?');
            $deleteResetStmt->execute([$targetUserId]);

            $pdo->commit();

            error_log(sprintf(
                '[admin_usuarios_senhas] Admin ID=%d atualizou a senha do usuario ID=%d.',
                $adminId,
                $targetUserId
            ));

            $targetName = trim((string)($targetUser['nome'] ?? ''));
            $targetEmail = trim((string)($targetUser['email'] ?? ''));
            $targetLabel = $targetName !== '' ? $targetName : ($targetEmail !== '' ? $targetEmail : ('ID ' . $targetUserId));

            admin_password_redirect(
                'Senha trocada com sucesso para ' . $targetLabel . '. Tokens pendentes de recuperação foram invalidados.',
                'ok',
                $search
            );
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[admin_usuarios_senhas] ' . $e->getMessage());
            admin_password_redirect('Não foi possível atualizar a senha agora.', 'error', $search);
        }
    }
}

try {
    $metrics = admin_password_fetch_metrics($pdo);
    $searchResults = admin_password_search_users($pdo, $search);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar a gestão de usuários.";
    exit;
}

$users = $searchResults['users'];
$resultsCount = count($users);
$totalMatches = (int)$searchResults['total'];
$resultsLimited = !empty($searchResults['limited']);
$totalInactive = max(0, (int)$metrics['total'] - (int)$metrics['ativos']);

require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <title>Bolão da Copa - Gestão de Usuários</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/css/admin.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
    <style>
        .password-page-shell {
            grid-template-columns: 240px minmax(0, 1fr);
        }

        .password-page-content {
            min-width: 0;
            overflow: visible;
        }

        .password-page-content .content-head {
            margin-bottom: 10px;
        }

        .password-page-content .content-h1 {
            font-size: clamp(1.36rem, 2.35vw, 1.98rem) !important;
            line-height: 1.14 !important;
            margin-bottom: 3px;
        }

        .password-page-content .content-sub {
            font-size: 0.8rem;
            line-height: 1.35;
            color: rgba(255, 255, 255, 0.72);
        }

        .password-layout {
            display: grid;
            gap: 12px;
            min-width: 0;
        }

        .password-toolbar-card,
        .password-results-card {
            display: grid;
            gap: 12px;
        }

        .password-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .password-metric {
            min-width: 0;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.05);
            display: grid;
            gap: 3px;
        }

        .password-metric span {
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.62rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .password-metric strong {
            color: #ffffff;
            font-size: 1rem;
            line-height: 1;
        }

        .password-search-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: end;
        }

        .password-search-form .comunicado-field {
            margin: 0;
        }

        .password-search-action,
        .password-clear-action {
            width: auto;
            min-width: 138px;
            max-width: none;
            margin: 0;
        }

        .password-clear-action {
            text-decoration: none;
        }

        .password-toolbar-note {
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.78rem;
            line-height: 1.4;
        }

        .password-results-head {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: space-between;
            align-items: flex-start;
        }

        .password-results-head h2 {
            margin: 0;
        }

        .password-results-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            color: rgba(255, 255, 255, 0.66);
            font-size: 0.74rem;
        }

        .password-results-meta span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 8px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: rgba(255, 255, 255, 0.04);
        }

        .password-results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 12px;
        }

        .password-user-card {
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03)),
                rgba(4, 12, 16, 0.45);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
            padding: 14px;
            display: grid;
            gap: 12px;
        }

        .password-user-head {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
            align-items: flex-start;
        }

        .password-user-title h3 {
            margin: 0 0 4px;
            font-size: 1rem;
            line-height: 1.2;
            color: rgba(255, 255, 255, 0.96);
        }

        .password-user-title p {
            margin: 0;
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.82rem;
            overflow-wrap: anywhere;
        }

        .password-user-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .password-user-meta {
            margin: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .password-user-meta div {
            min-width: 0;
            padding: 9px 10px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
        }

        .password-user-meta dt {
            margin: 0 0 4px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.64rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .password-user-meta dd {
            margin: 0;
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.85rem;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .password-reset-form {
            gap: 10px;
        }

        .password-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .password-reset-note {
            margin: 0;
            color: rgba(255, 255, 255, 0.66);
            font-size: 0.74rem;
            line-height: 1.4;
        }

        .password-submit {
            max-width: none;
        }

        .password-empty-state {
            padding: 40px 22px;
            text-align: center;
            color: rgba(255, 255, 255, 0.68);
            border: 1px dashed rgba(255, 255, 255, 0.16);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.03);
        }

        @media (max-width: 920px) {
            .password-page-shell {
                grid-template-columns: 1fr;
            }

            .password-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .password-search-form {
                grid-template-columns: 1fr;
            }

            .password-search-action,
            .password-clear-action {
                width: 100%;
            }

            .password-results-meta {
                width: 100%;
            }
        }

        @media (max-width: 640px) {
            .password-summary {
                grid-template-columns: 1fr;
            }

            .password-form-grid,
            .password-user-meta {
                grid-template-columns: 1fr;
            }

            .password-page-content .content-h1 {
                font-size: 1.14rem !important;
            }

            .password-page-content .content-sub {
                font-size: 0.72rem;
            }
        }
    </style>
</head>
<body>

<div class="app-wrap">

    <?php
        render_app_header(
            $usuarioNome,
            $isAdmin,
            "admin",
            "Admin • Gestão de Usuários",
            "/admin_usuarios_senhas.php?action=logout"
        );
    ?>

    <div class="app-shell password-page-shell">
        <aside class="app-menu">
            <div class="menu-title">Ações</div>

            <div class="menu-actions menu-actions-tight">
                <a class="btn-receipt" href="/admin.php">
                    Exportar apostas por usuário
                </a>
                <a class="btn-receipt" href="/admin_usuarios_cadastro.php">
                    Lista de usuários
                </a>
                <a class="btn-receipt" href="/admin_usuarios_senhas.php" aria-current="page">
                    Gerenciar senhas
                </a>
                <a class="btn-receipt" href="/php/export_apostas_todas_zip.php">
                    Baixar todas apostas
                </a>
                <a class="btn-atualizar-resultados" href="/admin_resultados.php">
                    Atualizar resultados
                </a>
                <a class="btn-mata-mata" href="/mata_mata.php">
                    Atualizar mata-mata
                </a>
                <a class="btn-receipt" href="/admin_comunicados.php">
                    Enviar comunicado
                </a>
            </div>
        </aside>

        <main class="app-content password-page-content">
            <div class="content-head">
                <h1 class="content-h1">Gestão de usuários</h1>
                <p class="content-sub">Pesquise por nome, e-mail, telefone ou ID e defina uma nova senha manualmente.</p>
            </div>

            <div class="password-layout">
                <section class="admin-card password-toolbar-card" aria-label="Resumo e pesquisa de usuários">
                    <div class="password-summary">
                        <div class="password-metric">
                            <span>Total de usuários</span>
                            <strong><?php echo (int)$metrics['total']; ?></strong>
                        </div>
                        <div class="password-metric">
                            <span>Admins</span>
                            <strong><?php echo (int)$metrics['admins']; ?></strong>
                        </div>
                        <div class="password-metric">
                            <span>Ativos</span>
                            <strong><?php echo (int)$metrics['ativos']; ?></strong>
                        </div>
                        <div class="password-metric">
                            <span>Inativos</span>
                            <strong><?php echo $totalInactive; ?></strong>
                        </div>
                    </div>

                    <?php if (count($formErrors) > 0): ?>
                        <div class="comunicado-alert comunicado-alert-error" role="alert">
                            <?php foreach ($formErrors as $error): ?>
                                <p><?php echo strh($error); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (is_array($flash)): ?>
                        <?php
                            $flashType = (string)($flash['type'] ?? '');
                            $flashClass = 'comunicado-alert-warn';
                            if ($flashType === 'ok') {
                                $flashClass = 'comunicado-alert-success';
                            } elseif ($flashType === 'error') {
                                $flashClass = 'comunicado-alert-error';
                            }
                        ?>
                        <div class="comunicado-alert <?php echo $flashClass; ?>" role="status">
                            <p><?php echo strh((string)($flash['msg'] ?? '')); ?></p>
                        </div>
                    <?php endif; ?>

                    <form class="comunicado-form password-search-form" method="get" action="/admin_usuarios_senhas.php">
                        <label class="comunicado-field">
                            <span>Pesquisar usuário</span>
                            <input
                                type="search"
                                name="q"
                                value="<?php echo strh($search); ?>"
                                placeholder="Nome, e-mail, telefone ou ID"
                                autocomplete="off"
                            >
                        </label>

                        <button class="btn-atualizar-resultados password-search-action" type="submit">
                            Pesquisar
                        </button>

                        <a class="btn-receipt password-clear-action" href="/admin_usuarios_senhas.php">
                            Limpar
                        </a>
                    </form>

                    <p class="password-toolbar-note">
                        A troca é imediata. Ao salvar uma nova senha, qualquer token pendente de recuperação desse usuário será apagado.
                    </p>
                </section>

                <section class="admin-card password-results-card">
                    <div class="password-results-head">
                        <h2>Resultado da pesquisa</h2>
                        <div class="password-results-meta">
                            <?php if ($search !== ''): ?>
                                <span>Busca: <?php echo strh($search); ?></span>
                                <span><?php echo $resultsCount; ?> de <?php echo $totalMatches; ?> resultado<?php echo $totalMatches !== 1 ? 's' : ''; ?></span>
                            <?php else: ?>
                                <span>Digite um termo para procurar um usuário</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($resultsLimited): ?>
                        <div class="comunicado-alert comunicado-alert-warn" role="status">
                            <p>Mostrando os primeiros <?php echo $resultsCount; ?> resultados. Refine a busca para encontrar mais rápido.</p>
                        </div>
                    <?php endif; ?>

                    <?php if ($search === ''): ?>
                        <div class="password-empty-state">
                            Pesquise por nome, e-mail, telefone ou ID para liberar a troca manual de senha.
                        </div>
                    <?php elseif ($resultsCount === 0): ?>
                        <div class="password-empty-state">
                            Nenhum usuário encontrado com o termo informado.
                        </div>
                    <?php else: ?>
                        <div class="password-results-grid">
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $userId = (int)($user['id'] ?? 0);
                                    $userName = (string)($user['nome'] ?? '');
                                    $userEmail = (string)($user['email'] ?? '');
                                    $userPhone = (string)($user['telefone'] ?? '');
                                    $userCity = (string)($user['cidade'] ?? '');
                                    $userState = (string)($user['estado'] ?? '');
                                    $userType = mb_strtoupper((string)($user['tipo_usuario'] ?? ''), 'UTF-8');
                                    $userActive = (int)($user['ativo'] ?? 0) === 1;
                                ?>
                                <article class="password-user-card">
                                    <div class="password-user-head">
                                        <div class="password-user-title">
                                            <h3><?php echo strh($userName !== '' ? $userName : ('Usuário #' . $userId)); ?></h3>
                                            <p><?php echo strh($userEmail !== '' ? $userEmail : 'Sem e-mail cadastrado'); ?></p>
                                        </div>

                                        <div class="password-user-badges">
                                            <span class="badge <?php echo $userType === 'ADMIN' ? 'badge-admin' : 'badge-apostador'; ?>">
                                                <?php echo $userType === 'ADMIN' ? 'ADMIN' : 'APOSTADOR'; ?>
                                            </span>
                                            <span class="badge <?php echo $userActive ? 'badge-ativo' : 'badge-inativo'; ?>">
                                                <?php echo $userActive ? 'Ativo' : 'Inativo'; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <dl class="password-user-meta">
                                        <div>
                                            <dt>ID</dt>
                                            <dd><?php echo $userId; ?></dd>
                                        </div>
                                        <div>
                                            <dt>Telefone</dt>
                                            <dd><?php echo strh($userPhone !== '' ? $userPhone : '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Cidade / Estado</dt>
                                            <dd><?php echo strh(trim($userCity . ($userState !== '' ? ' / ' . $userState : '')) ?: '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Atualizado em</dt>
                                            <dd><?php echo strh(format_datetime_br(isset($user['atualizado_em']) ? (string)$user['atualizado_em'] : null)); ?></dd>
                                        </div>
                                    </dl>

                                    <form class="comunicado-form password-reset-form" method="post" action="/admin_usuarios_senhas.php">
                                        <?php echo app_csrf_field(); ?>
                                        <input type="hidden" name="q" value="<?php echo strh($search); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">

                                        <div class="password-form-grid">
                                            <label class="comunicado-field">
                                                <span>Nova senha</span>
                                                <input
                                                    type="password"
                                                    name="nova_senha"
                                                    minlength="8"
                                                    required
                                                    autocomplete="new-password"
                                                >
                                            </label>

                                            <label class="comunicado-field">
                                                <span>Confirmar nova senha</span>
                                                <input
                                                    type="password"
                                                    name="confirmar_nova_senha"
                                                    minlength="8"
                                                    required
                                                    autocomplete="new-password"
                                                >
                                            </label>
                                        </div>

                                        <p class="password-reset-note">
                                            A nova senha substitui a atual imediatamente. O usuário precisará entrar com essa senha na próxima autenticação.
                                        </p>

                                        <button class="btn-atualizar-resultados comunicado-submit password-submit" type="submit">
                                            Salvar nova senha
                                        </button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</div>

<script src="/js/admin.js"></script>
</body>
</html>
