<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

require_once dirname(__DIR__) . "/php/conexao.php";

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
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

if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    $sortCol = isset($_GET["sort"]) ? (string)$_GET["sort"] : "tipo_usuario";
    $sortOrder = isset($_GET["order"]) ? (string)$_GET["order"] : "desc";
    $mostrarInativos = isset($_GET["ativo"]) && $_GET["ativo"] === "all";

    $allowedCols = [
        "id",
        "nome",
        "email",
        "telefone",
        "cidade",
        "estado",
        "tipo_usuario",
        "ativo",
        "criado_em",
        "atualizado_em"
    ];

    if (!in_array($sortCol, $allowedCols, true)) {
        $sortCol = "tipo_usuario";
    }

    if ($sortOrder !== "asc" && $sortOrder !== "desc") {
        $sortOrder = "desc";
    }

    if ($sortCol === "tipo_usuario" && $sortOrder === "desc") {
        $orderBy = "CASE WHEN tipo_usuario='ADMIN' THEN 0 ELSE 1 END ASC, nome ASC";
    } else {
        $orderBy = "`" . $sortCol . "` " . strtoupper($sortOrder);
    }

    try {
        $sql = "
            SELECT id, nome, email, telefone, cidade, estado, tipo_usuario, ativo, criado_em, atualizado_em
            FROM usuarios
        ";

        if (!$mostrarInativos) {
            $sql .= " WHERE ativo = 1";
        }

        $sql .= " ORDER BY " . $orderBy;

        $stmt = $pdo->query($sql);
        $usuariosExport = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filename = "usuarios_" . date("Y-m-d_H-i") . ".csv";
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Pragma: no-cache");
        header("Expires: 0");

        $output = fopen("php://output", "w");
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ["ID", "Nome", "Email", "Telefone", "Cidade", "Estado", "Tipo", "Ativo", "Criado em", "Atualizado em"], ";");

        foreach ($usuariosExport as $u) {
            fputcsv($output, [
                (string)($u["id"] ?? ""),
                (string)($u["nome"] ?? ""),
                (string)($u["email"] ?? ""),
                (string)($u["telefone"] ?? ""),
                (string)($u["cidade"] ?? ""),
                (string)($u["estado"] ?? ""),
                (string)($u["tipo_usuario"] ?? ""),
                ((int)($u["ativo"] ?? 0) === 1 ? "ATIVO" : "INATIVO"),
                (string)($u["criado_em"] ?? ""),
                (string)($u["atualizado_em"] ?? ""),
            ], ";");
        }

        fclose($output);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Erro ao exportar usuários.";
        exit;
    }
}

// Get sort parameters
$sortCol = isset($_GET["sort"]) ? (string)$_GET["sort"] : "tipo_usuario";
$sortOrder = isset($_GET["order"]) ? (string)$_GET["order"] : "desc";
$mostrarInativos = isset($_GET["ativo"]) && $_GET["ativo"] === "all";

// Whitelist allowed sort columns for security
$allowedCols = [
    "id",
    "nome",
    "email",
    "telefone",
    "cidade",
    "estado",
    "tipo_usuario",
    "ativo",
    "criado_em",
    "atualizado_em"
];

if (!in_array($sortCol, $allowedCols, true)) {
    $sortCol = "tipo_usuario";
}

// Validate sort order
if ($sortOrder !== "asc" && $sortOrder !== "desc") {
    $sortOrder = "desc";
}

// Special case: sort by tipo_usuario should be DESC (admins first), then by name
if ($sortCol === "tipo_usuario" && $sortOrder === "desc") {
    $orderBy = "CASE WHEN tipo_usuario='ADMIN' THEN 0 ELSE 1 END ASC, nome ASC";
} else {
    $orderBy = "`" . $sortCol . "` " . strtoupper($sortOrder);
}

try {
    $sql = "
        SELECT id, nome, email, telefone, cidade, estado, tipo_usuario, ativo, criado_em, atualizado_em
        FROM usuarios
    ";
    
    if (!$mostrarInativos) {
        $sql .= " WHERE ativo = 1";
    }
    
    $sql .= " ORDER BY " . $orderBy;
    
    $stmt = $pdo->query($sql);
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
    <title>Bolão da Copa - Cadastro de Usuários</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/css/admin.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
    <style>
        .table-usuarios {
            width: 100%;
            min-width: 1320px;
            table-layout: auto;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 0;
        }

        .table-usuarios thead {
            position: sticky;
            top: 0;
            background: linear-gradient(180deg, rgba(140, 120, 255, 0.3) 0%, rgba(70, 220, 255, 0.2) 100%);
            backdrop-filter: blur(10px);
            z-index: 10;
        }

        .table-usuarios thead th:first-child {
            border-top-left-radius: 14px;
        }

        .table-usuarios thead th:last-child {
            border-top-right-radius: 14px;
        }

        .table-usuarios th {
            padding: 11px 10px;
            text-align: left;
            font-weight: 600;
            color: #ffffff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 0.84rem;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
            vertical-align: middle;
            white-space: nowrap;
        }

        .table-usuarios th:hover {
            background-color: rgba(255, 255, 255, 0.08);
        }

        .table-usuarios th.sortable::after {
            content: " ↕";
            opacity: 0.5;
        }

        .table-usuarios th.sort-asc::after {
            content: " ↑";
            opacity: 1;
        }

        .table-usuarios th.sort-desc::after {
            content: " ↓";
            opacity: 1;
        }

        .table-usuarios td {
            padding: 11px 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.92rem;
            line-height: 1.25;
            color: rgba(255, 255, 255, 0.9);
            vertical-align: middle;
        }

        .col-id,
        .col-estado,
        .col-tipo,
        .col-ativo {
            white-space: nowrap;
        }

        .col-nome {
            min-width: 320px;
            max-width: 420px;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: normal;
            line-height: 1.2;
            font-weight: 700;
        }

        .col-email,
        .col-telefone,
        .col-cidade,
        .col-estado,
        .col-criado,
        .col-atualizado {
            white-space: nowrap;
        }

        .col-email {
            min-width: 250px;
        }

        .col-telefone {
            min-width: 150px;
        }

        .col-cidade {
            min-width: 150px;
        }

        .col-criado,
        .col-atualizado {
            min-width: 170px;
        }

        .table-usuarios td.col-email,
        .table-usuarios td.col-telefone,
        .table-usuarios td.col-cidade,
        .table-usuarios td.col-criado,
        .table-usuarios td.col-atualizado {
            overflow-wrap: anywhere;
        }

        .table-usuarios tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.04);
        }

        .table-usuarios tbody tr:nth-child(even) {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .table-usuarios tbody td:first-child,
        .table-usuarios thead th:first-child {
            padding-left: 14px;
        }

        .table-usuarios tbody td:last-child,
        .table-usuarios thead th:last-child {
            padding-right: 14px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .badge-admin {
            background: rgba(140, 120, 255, 0.3);
            color: rgba(200, 150, 255, 1);
            border: 1px solid rgba(140, 120, 255, 0.5);
        }

        .badge-apostador {
            background: rgba(100, 100, 120, 0.2);
            color: rgba(180, 180, 200, 1);
            border: 1px solid rgba(100, 100, 120, 0.4);
        }

        .badge-ativo {
            background: rgba(0, 200, 122, 0.2);
            color: rgba(100, 255, 180, 1);
            border: 1px solid rgba(0, 200, 122, 0.4);
        }

        .badge-inativo {
            background: rgba(200, 80, 80, 0.2);
            color: rgba(255, 150, 150, 1);
            border: 1px solid rgba(200, 80, 80, 0.4);
        }

        .table-wrapper {
            overflow-x: auto;
            margin-top: 16px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.03);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .filter-controls {
            display: flex;
            gap: 12px;
            margin: 20px 0;
            align-items: center;
        }

        .btn-toggle-filter {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-toggle-filter:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .btn-toggle-filter.active {
            background: rgba(0, 200, 122, 0.3);
            border-color: rgba(0, 200, 122, 0.5);
            color: rgba(100, 255, 180, 1);
        }

        .stats-info {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.95rem;
        }

        .toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .col-id { width: 60px; }
        .col-email { width: 250px; }
        .col-telefone { width: 150px; }
        .col-cidade { width: 150px; }
        .col-estado { width: 70px; }
        .col-tipo { width: 120px; }
        .col-ativo { width: 80px; }
        .col-criado { width: 170px; }
        .col-atualizado { width: 170px; }

        @media (max-width: 1200px) {
            .table-usuarios {
                min-width: 1180px;
            }

            .table-usuarios {
                font-size: 0.9rem;
            }

            .table-usuarios td,
            .table-usuarios th {
                padding: 8px 6px;
            }
        }

        @media (max-width: 768px) {
            .table-usuarios {
                min-width: 1040px;
            }

            .table-wrapper {
                border-radius: 12px;
            }

            .table-usuarios {
                font-size: 0.86rem;
            }

            .col-nome {
                min-width: 280px;
                max-width: 340px;
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
            "Admin • Cadastro de Usuários",
            "/admin_usuarios_cadastro.php?action=logout"
        );
    ?>

    <div class="app-shell">
        <aside class="app-menu">
            <div class="menu-title">Ações</div>

            <div class="menu-actions menu-actions-tight">
                <a class="btn-receipt" href="/php/export_participantes_excel.php">
                    Lista de participantes
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
            </div>
        </aside>

        <main class="app-content">
            <div class="content-head">
                <h1 class="content-h1">Cadastro de Usuários</h1>
                <p class="content-sub">Todos os dados cadastrais dos usuários do sistema. Clique nos cabeçalhos para ordenar.</p>
            </div>

            <div class="admin-card">
                <div class="filter-controls">
                    <span class="stats-info">
                        Total: <strong><?php echo count($usuarios); ?></strong> usuário<?php echo count($usuarios) !== 1 ? "s" : ""; ?>
                    </span>
                    <div class="toolbar-actions">
                        <?php
                            $filterParam = $mostrarInativos ? "" : "&ativo=all";
                            $filterText = $mostrarInativos ? "Ocultar inativos" : "Mostrar inativos";
                            $filterClass = $mostrarInativos ? "active" : "";
                            $baseQuery = "sort=" . urlencode($sortCol) . "&order=" . urlencode($sortOrder) . $filterParam;
                        ?>
                        <a class="btn-toggle-filter <?php echo $filterClass; ?>"
                           href="?<?php echo $baseQuery; ?>">
                            <?php echo $filterText; ?>
                        </a>
                        <a class="btn-toggle-filter"
                           href="?<?php echo $baseQuery; ?>&export=csv">
                            Exportar CSV
                        </a>
                    </div>
                </div>

                <div style="margin: 0 2px 10px; color: rgba(255,255,255,0.58); font-size: 0.84rem; line-height: 1.4;">
                    A tabela usa rolagem horizontal quando necessário. Nome, email e cidade ficam visíveis sem truncamento; os demais campos mantêm largura estável para leitura rápida.
                </div>

                <div class="table-wrapper">
                    <table class="table-usuarios" aria-label="Tabela de usuários cadastrados">
                        <colgroup>
                            <col class="col-id" />
                            <col class="col-nome" />
                            <col class="col-email" />
                            <col class="col-telefone" />
                            <col class="col-cidade" />
                            <col class="col-estado" />
                            <col class="col-tipo" />
                            <col class="col-ativo" />
                            <col class="col-criado" />
                            <col class="col-atualizado" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="col-id sortable <?php echo ($sortCol === "id" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=id&order=" . (($sortCol === "id" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        ID
                                    </a>
                                </th>
                                <th class="col-nome sortable <?php echo ($sortCol === "nome" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=nome&order=" . (($sortCol === "nome" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Nome
                                    </a>
                                </th>
                                <th class="col-email sortable <?php echo ($sortCol === "email" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=email&order=" . (($sortCol === "email" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Email
                                    </a>
                                </th>
                                <th class="col-telefone sortable <?php echo ($sortCol === "telefone" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=telefone&order=" . (($sortCol === "telefone" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Telefone
                                    </a>
                                </th>
                                <th class="col-cidade sortable <?php echo ($sortCol === "cidade" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=cidade&order=" . (($sortCol === "cidade" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Cidade
                                    </a>
                                </th>
                                <th class="col-estado sortable <?php echo ($sortCol === "estado" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=estado&order=" . (($sortCol === "estado" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Estado
                                    </a>
                                </th>
                                <th class="col-tipo sortable <?php echo ($sortCol === "tipo_usuario" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=tipo_usuario&order=" . (($sortCol === "tipo_usuario" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Tipo
                                    </a>
                                </th>
                                <th class="col-ativo sortable <?php echo ($sortCol === "ativo" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=ativo&order=" . (($sortCol === "ativo" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Ativo
                                    </a>
                                </th>
                                <th class="col-criado sortable <?php echo ($sortCol === "criado_em" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=criado_em&order=" . (($sortCol === "criado_em" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Criado em
                                    </a>
                                </th>
                                <th class="col-atualizado sortable <?php echo ($sortCol === "atualizado_em" ? "sort-" . $sortOrder : ""); ?>">
                                    <a href="?<?php echo "sort=atualizado_em&order=" . (($sortCol === "atualizado_em" && $sortOrder === "asc") ? "desc" : "asc"); ?><?php echo $mostrarInativos ? "&ativo=all" : ""; ?>" style="color: inherit; text-decoration: none;">
                                        Atualizado em
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php
                                    $uid = (int)$u["id"];
                                    $nome = (string)$u["nome"];
                                    $email = (string)$u["email"];
                                    $telefone = (string)$u["telefone"];
                                    $cidade = (string)$u["cidade"];
                                    $estado = (string)$u["estado"];
                                    $tipo = (string)$u["tipo_usuario"];
                                    $ativo = (int)$u["ativo"];
                                    $criado = (string)$u["criado_em"];
                                    $atualizado = (string)$u["atualizado_em"];

                                    $tipoBadge = (mb_strtoupper($tipo, "UTF-8") === "ADMIN") ? "badge-admin" : "badge-apostador";
                                    $ativoBadge = $ativo ? "badge-ativo" : "badge-inativo";
                                    $ativoText = $ativo ? "Ativo" : "Inativo";

                                    // Format timestamps
                                    $criadoFormatado = date('d/m/Y H:i', strtotime($criado));
                                    $atualizadoFormatado = date('d/m/Y H:i', strtotime($atualizado));
                                ?>
                                <tr>
                                    <td class="col-id"><?php echo $uid; ?></td>
                                    <td class="col-nome"><?php echo strh($nome); ?></td>
                                    <td class="col-email"><?php echo strh($email); ?></td>
                                    <td class="col-telefone"><?php echo strh($telefone); ?></td>
                                    <td class="col-cidade"><?php echo strh($cidade); ?></td>
                                    <td class="col-estado"><?php echo strh($estado); ?></td>
                                    <td class="col-tipo">
                                        <span class="badge <?php echo $tipoBadge; ?>">
                                            <?php echo strh($tipo); ?>
                                        </span>
                                    </td>
                                    <td class="col-ativo">
                                        <span class="badge <?php echo $ativoBadge; ?>">
                                            <?php echo $ativoText; ?>
                                        </span>
                                    </td>
                                    <td class="col-criado"><?php echo strh($criadoFormatado); ?></td>
                                    <td class="col-atualizado"><?php echo strh($atualizadoFormatado); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (empty($usuarios)): ?>
                    <div style="padding: 40px 20px; text-align: center; color: rgba(255,255,255,0.6);">
                        <p>Nenhum usuário encontrado.</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

</body>
</html>
