<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        header("Location: /bolao-da-copa/public/index.php");
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

/* Logout (opcional) */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_destroy();
    header("Location: /bolao-da-copa/public/index.php");
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <title>Bolão da Copa - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/bolao-da-copa/public/css/admin.css">
</head>
<body>

<div class="app-wrap">
    <header class="app-header">
        <div class="app-brand">
            <img src="/bolao-da-copa/public/img/logo.png" alt="Bolão" onerror="this.style.display='none'">
            <div class="app-title">
                <strong>Bolão da Copa</strong>
                <span>Admin • Exportar apostas</span>
            </div>
        </div>

        <div class="app-actions">
            <div class="user-chip" title="<?php echo strh($usuarioNome); ?>">
                <span class="dot"></span>
                <span class="user-chip-name"><?php echo strh($usuarioNome); ?></span>
            </div>

            <a class="btn-logout" href="/bolao-da-copa/public/admin.php?action=logout">Sair</a>
        </div>
    </header>

    <div class="app-shell">
        <aside class="app-menu">
            <div class="menu-title">Ações</div>

            <div class="menu-actions" style="margin-top:0;border-top:0;padding-top:0;">
                
                <a class="btn-receipt"
                   href="/bolao-da-copa/public/app.php"
                   style="display:block;text-align:center;text-decoration:none;">
                    Voltar para Palpites
                </a>

                <a class="btn-receipt"
                   href="/bolao-da-copa/php/export_apostas_todas_zip.php"
                   style="display:block;text-align:center;text-decoration:none;margin-top:10px;">
                    Baixar TODAS as apostas (ZIP)
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
                           href="/bolao-da-copa/php/export_apostas_excel.php?usuario_id=<?php echo $uid; ?>">
                            <span><?php echo strh($nome); ?></span>
                            <span class="small"><?php echo strh($tipo); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="/bolao-da-copa/public/js/admin.js"></script>
</body>
</html>
