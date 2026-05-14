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

function format_datetime_br(?string $value): string {
    if ($value === null || trim($value) === "") {
        return "";
    }

    $timestamp = strtotime($value);

    return $timestamp !== false ? date("d/m/Y H:i", $timestamp) : $value;
}

function resolve_users_listing_state(array $query): array {
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

    $sortCol = isset($query["sort"]) ? (string)$query["sort"] : "id";
    $sortOrder = isset($query["order"]) ? (string)$query["order"] : "asc";
    $ativoParam = isset($query["ativo"]) ? (string)$query["ativo"] : "all";

    if (!in_array($sortCol, $allowedCols, true)) {
        $sortCol = "id";
    }

    if ($sortOrder !== "asc" && $sortOrder !== "desc") {
        $sortOrder = "asc";
    }

    return [
        "sort" => $sortCol,
        "order" => $sortOrder,
        "somente_ativos" => in_array($ativoParam, ["1", "active", "ativos"], true),
    ];
}

function build_users_order_by(string $sortCol, string $sortOrder): string {
    if ($sortCol === "tipo_usuario" && $sortOrder === "desc") {
        return "CASE WHEN tipo_usuario='ADMIN' THEN 0 ELSE 1 END ASC, nome ASC";
    }

    return "`" . $sortCol . "` " . strtoupper($sortOrder);
}

function fetch_users(PDO $pdo, bool $somenteAtivos, string $orderBy): array {
    $sql = "
        SELECT id, nome, email, telefone, cidade, estado, tipo_usuario, ativo, criado_em, atualizado_em
        FROM usuarios
    ";

    if ($somenteAtivos) {
        $sql .= " WHERE ativo = 1";
    }

    $sql .= " ORDER BY " . $orderBy;

    $stmt = $pdo->query($sql);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function build_users_list_query(array $state, array $overrides = []): string {
    $params = [
        "sort" => (string)$state["sort"],
        "order" => (string)$state["order"],
    ];

    if (!empty($state["somente_ativos"])) {
        $params["ativo"] = "1";
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === "") {
            unset($params[$key]);
            continue;
        }

        $params[$key] = (string)$value;
    }

    return http_build_query($params);
}

function next_sort_order(string $column, array $state): string {
    return ($state["sort"] === $column && $state["order"] === "asc") ? "desc" : "asc";
}

function sort_indicator(string $column, array $state): string {
    if ($state["sort"] !== $column) {
        return "↕";
    }

    return $state["order"] === "asc" ? "↑" : "↓";
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

$listState = resolve_users_listing_state($_GET);
$sortCol = (string)$listState["sort"];
$sortOrder = (string)$listState["order"];
$somenteAtivos = (bool)$listState["somente_ativos"];
$orderBy = build_users_order_by($sortCol, $sortOrder);

try {
    $usuarios = fetch_users($pdo, $somenteAtivos, $orderBy);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar usuários.";
    exit;
}

if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    $filename = "usuarios_" . date("Y-m-d_H-i") . ".csv";
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $output = fopen("php://output", "w");
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ["ID", "Nome", "Email", "Telefone", "Cidade", "Estado", "Tipo", "Ativo", "Criado em", "Atualizado em"], ";");

    foreach ($usuarios as $u) {
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
}

$totalUsuarios = count($usuarios);
$totalAdmins = 0;
$totalAtivos = 0;

foreach ($usuarios as $usuarioResumo) {
    $tipoResumo = isset($usuarioResumo["tipo_usuario"]) ? (string)$usuarioResumo["tipo_usuario"] : "";
    $ativoResumo = (int)($usuarioResumo["ativo"] ?? 0);

    if (mb_strtoupper($tipoResumo, "UTF-8") === "ADMIN") {
        $totalAdmins++;
    }

    if ($ativoResumo === 1) {
        $totalAtivos++;
    }
}

$totalInativos = $totalUsuarios - $totalAtivos;

$columns = [
    ["key" => "id", "label" => "ID", "class" => "users-col-id"],
    ["key" => "nome", "label" => "Nome", "class" => "users-col-nome"],
    ["key" => "email", "label" => "Email", "class" => "users-col-email"],
    ["key" => "telefone", "label" => "Telefone", "class" => "users-col-telefone"],
    ["key" => "cidade", "label" => "Cidade", "class" => "users-col-cidade"],
    ["key" => "estado", "label" => "Estado", "class" => "users-col-estado"],
    ["key" => "tipo_usuario", "label" => "Tipo", "class" => "users-col-tipo"],
    ["key" => "ativo", "label" => "Ativo", "class" => "users-col-ativo"],
    ["key" => "criado_em", "label" => "Criado em", "class" => "users-col-criado"],
    ["key" => "atualizado_em", "label" => "Atualizado em", "class" => "users-col-atualizado"],
];

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
        .users-page-wrap {
            width: min(1580px, 100%) !important;
        }

        .users-page-content .content-head {
            margin-bottom: 12px;
        }

        .users-page-content .content-h1 {
            font-size: clamp(1.56rem, 3vw, 2.38rem) !important;
            line-height: 1.18 !important;
            margin-bottom: 4px;
        }

        .users-page-content .content-sub {
            font-size: 0.86rem;
            line-height: 1.4;
            color: rgba(255, 255, 255, 0.72);
        }

        .users-page-shell {
            grid-template-columns: 240px minmax(0, 1fr);
        }

        .users-page-content {
            min-width: 0;
            overflow: visible;
        }

        .users-layout {
            display: grid;
            gap: 14px;
            min-width: 0;
        }

        .users-toolbar-card,
        .users-data-card {
            min-width: 0;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            background:
                radial-gradient(900px 320px at 18% 10%, rgba(16, 208, 138, 0.12), transparent 58%),
                radial-gradient(760px 300px at 82% 18%, rgba(247, 201, 72, 0.10), transparent 58%),
                var(--card-grad);
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.26);
        }

        .users-toolbar-card {
            padding: 14px 16px;
            display: grid;
            gap: 12px;
        }

        .users-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .users-metric {
            min-width: 0;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.05);
            display: grid;
            gap: 4px;
        }

        .users-metric span {
            display: block;
            margin-bottom: 0;
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.66rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .users-metric strong {
            display: block;
            font-size: 1.08rem;
            line-height: 1;
            color: #ffffff;
        }

        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
            align-items: center;
        }

        .stats-info {
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.84rem;
            line-height: 1.4;
        }

        .toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .btn-toggle-filter {
            padding: 8px 14px;
            min-height: 38px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.86rem;
            transition: background-color 0.2s, border-color 0.2s, transform 0.2s;
        }

        .btn-toggle-filter:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.34);
            transform: translateY(-1px);
        }

        .btn-toggle-filter.active {
            background: rgba(0, 200, 122, 0.28);
            border-color: rgba(0, 200, 122, 0.48);
            color: rgba(100, 255, 180, 1);
        }

        .users-data-card {
            padding: 14px 16px 16px;
            overflow: visible;
        }

        .users-data-head {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .users-data-head h2 {
            margin: 0;
            font-size: 0.96rem;
            color: rgba(255, 255, 255, 0.95);
        }

        .users-data-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
            color: rgba(255, 255, 255, 0.66);
            font-size: 0.8rem;
        }

        .users-data-meta span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: rgba(255, 255, 255, 0.04);
        }

        .users-grid-viewport {
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            overscroll-behavior-x: contain;
            -webkit-overflow-scrolling: touch;
            scrollbar-gutter: stable both-edges;
            padding: 2px 0 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.03);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
            scrollbar-width: auto;
            scrollbar-color: rgba(255, 255, 255, 0.28) rgba(255, 255, 255, 0.08);
        }

        .users-grid-viewport:focus-visible {
            outline: 2px solid rgba(70, 220, 255, 0.42);
            outline-offset: 2px;
        }

        .users-grid-viewport::-webkit-scrollbar {
            height: 16px;
        }

        .users-grid-viewport::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.24);
            border-radius: 999px;
            border: 3px solid transparent;
            background-clip: padding-box;
        }

        .users-grid-viewport::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.06);
            border-radius: 999px;
        }

        .users-board {
            width: 1842px;
            min-width: 1842px;
            display: grid;
            gap: 10px;
            padding: 10px;
        }

        .users-board-row {
            display: grid;
            grid-template-columns: 72px 360px 320px 170px 200px 90px 150px 120px 180px 180px;
            min-width: 1842px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.02);
        }

        .users-board-data:nth-child(odd) {
            background: rgba(255, 255, 255, 0.035);
        }

        .users-board-data:hover {
            background: rgba(255, 255, 255, 0.055);
        }

        .users-cell {
            min-width: 0;
            padding: 12px 14px;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            line-height: 1.35;
            font-size: 0.92rem;
        }

        .users-board-row .users-cell:last-child {
            border-right: 0;
        }

        .users-cell-head {
            background: linear-gradient(180deg, rgba(140, 120, 255, 0.28) 0%, rgba(70, 220, 255, 0.18) 100%);
            padding-top: 14px;
            padding-bottom: 14px;
        }

        .users-sort-link {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            color: inherit;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .users-sort-link:hover {
            color: #ffffff;
        }

        .users-sort-link.is-active {
            color: rgba(255, 255, 255, 1);
        }

        .users-sort-indicator {
            opacity: 0.75;
            font-size: 0.95rem;
        }

        .users-cell--mono {
            font-variant-numeric: tabular-nums;
        }

        .users-cell--wrap {
            align-items: flex-start;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .users-cell--strong {
            font-weight: 700;
            color: #ffffff;
        }

        .users-cell--center {
            justify-content: center;
            text-align: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge-admin {
            background: rgba(140, 120, 255, 0.3);
            color: rgba(215, 180, 255, 1);
            border: 1px solid rgba(140, 120, 255, 0.48);
        }

        .badge-apostador {
            background: rgba(100, 100, 120, 0.2);
            color: rgba(190, 190, 205, 1);
            border: 1px solid rgba(100, 100, 120, 0.38);
        }

        .badge-ativo {
            background: rgba(0, 200, 122, 0.2);
            color: rgba(100, 255, 180, 1);
            border: 1px solid rgba(0, 200, 122, 0.4);
        }

        .badge-inativo {
            background: rgba(200, 80, 80, 0.2);
            color: rgba(255, 160, 160, 1);
            border: 1px solid rgba(200, 80, 80, 0.4);
        }

        .users-empty-state {
            padding: 46px 24px;
            text-align: center;
            color: rgba(255, 255, 255, 0.66);
            border: 1px dashed rgba(255, 255, 255, 0.16);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.03);
        }

        @media (max-width: 920px) {
            .users-page-shell {
                grid-template-columns: 1fr;
            }

            .users-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .users-toolbar-card,
            .users-data-card {
                padding: 14px;
            }

            .filter-controls {
                align-items: flex-start;
            }

            .stats-info {
                width: 100%;
            }

            .toolbar-actions {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .users-data-head {
                align-items: flex-start;
            }

            .users-data-meta {
                justify-content: flex-start;
            }
        }

        @media (max-width: 640px) {
            .users-page-content .content-h1 {
                font-size: 1.26rem !important;
            }

            .users-page-content .content-sub {
                font-size: 0.78rem;
            }

            .users-summary {
                gap: 8px;
            }

            .users-metric {
                padding: 9px 10px;
            }

            .users-metric strong {
                font-size: 0.96rem;
            }

            .users-metric span {
                font-size: 0.6rem;
            }

            .filter-controls {
                gap: 8px;
            }

            .btn-toggle-filter {
                width: 100%;
                min-height: 36px;
                font-size: 0.82rem;
            }

            .users-grid-viewport {
                border-radius: 12px;
            }

            .toolbar-actions {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .users-data-meta {
                width: 100%;
                gap: 6px;
                font-size: 0.74rem;
            }

            .users-data-meta span {
                width: 100%;
                justify-content: center;
                padding: 6px 8px;
            }

            .users-board {
                gap: 8px;
                padding: 6px;
            }
        }
    </style>
</head>
<body>

<div class="app-wrap users-page-wrap">

    <?php
        render_app_header(
            $usuarioNome,
            $isAdmin,
            "admin",
            "Admin • Cadastro de Usuários",
            "/admin_usuarios_cadastro.php?action=logout"
        );
    ?>

    <div class="app-shell users-page-shell">
        <aside class="app-menu">
            <div class="menu-title">Ações</div>

            <div class="menu-actions menu-actions-tight">
                <a class="btn-receipt" href="/admin.php">
                    Exportar apostas por usuário
                </a>
                <a class="btn-receipt" href="/admin_usuarios_cadastro.php" aria-current="page">
                    Lista de usuários
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

        <main class="app-content users-page-content">
            <div class="content-head">
                <h1 class="content-h1">Cadastro de Usuários</h1>
                <p class="content-sub">Listagem administrativa com ordenação e exportação.</p>
            </div>

            <div class="users-layout">
                <section class="users-toolbar-card" aria-label="Resumo e filtros da lista de usuários">
                    <div class="users-summary">
                        <div class="users-metric">
                            <span>Total exibido</span>
                            <strong><?php echo $totalUsuarios; ?></strong>
                        </div>
                        <div class="users-metric">
                            <span>Admins</span>
                            <strong><?php echo $totalAdmins; ?></strong>
                        </div>
                        <div class="users-metric">
                            <span>Ativos</span>
                            <strong><?php echo $totalAtivos; ?></strong>
                        </div>
                        <div class="users-metric">
                            <span>Inativos</span>
                            <strong><?php echo $totalInativos; ?></strong>
                        </div>
                    </div>

                    <div class="filter-controls">
                        <span class="stats-info">
                            <?php echo $somenteAtivos
                                ? "Mostrando somente ativos."
                                : "Mostrando todos os usuários."; ?>
                        </span>
                        <div class="toolbar-actions">
                            <a class="btn-toggle-filter <?php echo $somenteAtivos ? "active" : ""; ?>"
                               href="?<?php echo strh(build_users_list_query($listState, ["ativo" => $somenteAtivos ? null : "1"])); ?>">
                                <?php echo $somenteAtivos ? "Mostrar todos" : "Somente ativos"; ?>
                            </a>
                            <a class="btn-toggle-filter"
                               href="?<?php echo strh(build_users_list_query($listState, ["export" => "csv"])); ?>">
                                Exportar CSV
                            </a>
                        </div>
                    </div>
                </section>

                <section class="users-data-card">
                    <div class="users-data-head">
                        <h2>Lista de usuários</h2>
                        <div class="users-data-meta">
                            <span><?php echo $totalUsuarios; ?> registro<?php echo $totalUsuarios !== 1 ? "s" : ""; ?></span>
                            <span>Deslize para ver mais colunas</span>
                        </div>
                    </div>

                    <?php if (!empty($usuarios)): ?>
                        <div class="users-grid-viewport" tabindex="0" aria-label="Grade com os dados cadastrais dos usuários">
                            <div class="users-board" role="table" aria-label="Lista de usuários cadastrados">
                                <div class="users-board-row users-board-header" role="row">
                                    <?php foreach ($columns as $column): ?>
                                        <?php
                                            $sortQuery = build_users_list_query($listState, [
                                                "sort" => $column["key"],
                                                "order" => next_sort_order((string)$column["key"], $listState),
                                            ]);
                                            $isSortActive = ($sortCol === $column["key"]);
                                        ?>
                                        <div class="users-cell users-cell-head <?php echo strh((string)$column["class"]); ?>" role="columnheader">
                                            <a class="users-sort-link <?php echo $isSortActive ? "is-active" : ""; ?>"
                                               href="?<?php echo strh($sortQuery); ?>">
                                                <span><?php echo strh((string)$column["label"]); ?></span>
                                                <span class="users-sort-indicator"><?php echo strh(sort_indicator((string)$column["key"], $listState)); ?></span>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php foreach ($usuarios as $u): ?>
                                    <?php
                                        $uid = (int)($u["id"] ?? 0);
                                        $nome = (string)($u["nome"] ?? "");
                                        $email = (string)($u["email"] ?? "");
                                        $telefone = (string)($u["telefone"] ?? "");
                                        $cidade = (string)($u["cidade"] ?? "");
                                        $estado = (string)($u["estado"] ?? "");
                                        $tipo = (string)($u["tipo_usuario"] ?? "");
                                        $ativo = (int)($u["ativo"] ?? 0);
                                        $criado = isset($u["criado_em"]) ? (string)$u["criado_em"] : null;
                                        $atualizado = isset($u["atualizado_em"]) ? (string)$u["atualizado_em"] : null;

                                        $tipoUpper = mb_strtoupper($tipo, "UTF-8");
                                        $tipoBadge = ($tipoUpper === "ADMIN") ? "badge-admin" : "badge-apostador";
                                        $tipoLabel = ($tipoUpper === "ADMIN") ? "ADMIN" : "APOSTADOR";
                                        $ativoBadge = ($ativo === 1) ? "badge-ativo" : "badge-inativo";
                                        $ativoText = ($ativo === 1) ? "Ativo" : "Inativo";
                                    ?>
                                    <div class="users-board-row users-board-data" role="row">
                                        <div class="users-cell users-cell--mono users-cell--center" role="cell"><?php echo $uid; ?></div>
                                        <div class="users-cell users-cell--wrap users-cell--strong" role="cell"><?php echo strh($nome); ?></div>
                                        <div class="users-cell users-cell--wrap" role="cell"><?php echo strh($email); ?></div>
                                        <div class="users-cell users-cell--mono" role="cell"><?php echo strh($telefone); ?></div>
                                        <div class="users-cell users-cell--wrap" role="cell"><?php echo strh($cidade); ?></div>
                                        <div class="users-cell users-cell--center" role="cell"><?php echo strh($estado); ?></div>
                                        <div class="users-cell users-cell--center" role="cell">
                                            <span class="badge <?php echo $tipoBadge; ?>"><?php echo strh($tipoLabel); ?></span>
                                        </div>
                                        <div class="users-cell users-cell--center" role="cell">
                                            <span class="badge <?php echo $ativoBadge; ?>"><?php echo $ativoText; ?></span>
                                        </div>
                                        <div class="users-cell users-cell--mono" role="cell"><?php echo strh(format_datetime_br($criado)); ?></div>
                                        <div class="users-cell users-cell--mono" role="cell"><?php echo strh(format_datetime_br($atualizado)); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="users-empty-state">
                            Nenhum usuário encontrado com o filtro atual.
                        </div>
                    <?php endif; ?>
                </section>
            </div>

        </main>
    </div>
</div>

</body>
</html>
