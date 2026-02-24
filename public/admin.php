<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

// ✅ HostGator: arquivo em /public_html => sobe 1 nível para /home2/mauri075 e entra em /php
require_once dirname(__DIR__) . "/php/conexao.php";

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        // ✅ HostGator: páginas públicas na raiz do public_html
        header("Location: /index.php");
        exit;
    }
}

function require_admin(): void {
    $tipo = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
    if (mb_strtoupper($tipo, "UTF-8") !== "ADMIN") {
        http_response_code(403);
        echo "Acesso negado.";
        exit;
    }
}

function strh(?string $s): string {
    return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

require_login();
require_admin();

$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Admin";
$tipoSessao = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoSessao, "UTF-8") === "ADMIN");

/* Logout */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_destroy();
    header("Location: /index.php");
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT id, nome, email, tipo_usuario, ativo
        FROM usuarios
        WHERE ativo = 1
        ORDER BY (tipo_usuario='ADMIN') DESC, nome ASC, id ASC
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar usuários.";
    exit;
}

require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <title>Bolão da Copa - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>

<div class="app-wrap">

    <?php
        render_app_header(
            $usuarioNome,
            $isAdmin,
            "admin",
            "Admin • Exportar apostas",
            "/admin.php?action=logout"
        );
    ?>

    <div class="app-shell">
        <aside class="app-menu">
            <div class="menu-title">Ações</div>

            <div class="menu-actions menu-actions-tight">
                <a class="btn-receipt" href="/php/export_apostas_todas_zip.php">
                    Baixar todas apostas
                </a>
                <a class="btn-atualizar-resultados" href="/admin_resultados.php">
                    Atualizar resultados
                </a>
                <a class="btn-mata-mata" href="/mata_mata.php">
                    Atualizar mata-mata
                </a>
            </div>
        </aside>

        <main class="app-content">
            <div class="content-head">
                <h1 class="content-h1">Exportar apostas por usuário</h1>
                <p class="content-sub">Clique em um usuário para baixar o arquivo.</p>
            </div>

            <div class="admin-card">
                <h2>Usuários ativos</h2>
                <p>Colunas no Excel:
                    <strong>Grupo</strong>,
                    <strong>Data/Hora</strong>,
                    <strong>Time casa</strong>,
                    <strong>Placar casa</strong>,
                    <strong>Time visitante</strong>,
                    <strong>Placar visitante</strong>.
                </p>

                <div class="admin-grid">
                    <?php foreach ($usuarios as $u): ?>
                        <?php
                            $uid = (int)$u["id"];
                            $nome = (string)$u["nome"];
                            $tipo = (string)$u["tipo_usuario"];
                        ?>
                        <a class="admin-user-btn"
                           href="/php/export_apostas_excel.php?usuario_id=<?php echo $uid; ?>">
                            <span><?php echo strh($nome); ?></span>
                            <span class="small"><?php echo strh($tipo); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="/js/admin.js"></script>
</body>
</html>