<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";

/* =========================================================
   Helpers
   ========================================================= */

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        header("Location: /bolao-da-copa/public/index.php");
        exit;
    }
}

function strh(?string $s): string {
    return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8");
}

function session_int(string $key, int $default = 0): int {
    $v = $_SESSION[$key] ?? null;
    if ($v === null) return $default;
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

function session_str(string $key, string $default = ""): string {
    $v = $_SESSION[$key] ?? null;
    if ($v === null) return $default;
    return (string)$v;
}

function get_int_param(string $key): ?int {
    if (!isset($_GET[$key])) return null;
    $raw = (string)$_GET[$key];
    if ($raw === "") return null;
    if (!is_numeric($raw)) return null;
    $n = (int)$raw;
    return ($n > 0) ? $n : null;
}

function upper_utf8(string $s): string {
    return mb_strtoupper($s, "UTF-8");
}

function lower_utf8(string $s): string {
    return mb_strtolower($s, "UTF-8");
}

/* =========================================================
   Page
   ========================================================= */

require_login();

/**
 * ✅ Menu/topbar deve ser IDÊNTICO ao app.php:
 * - usa $_SESSION["usuario_nome"] (não "nome")
 * - logout via /public/app.php?action=logout
 * - links: Apostas / Ranking do Bolão / Admin (se ADMIN)
 */
$usuarioId   = session_int("usuario_id", 0);
$usuarioNome = session_str("usuario_nome", "Apostador");
$tipoUsuario = session_str("tipo_usuario", "");
$isAdmin     = (upper_utf8($tipoUsuario) === "ADMIN");

/**
 * Edição: default = maior id
 * Pode forçar: ?edicao_id=...
 */
$edicaoId = get_int_param("edicao_id"); // null = escolher default

try {
    // 1) Edições
    $stmtEd = $pdo->query("SELECT id, nome FROM edicoes ORDER BY id DESC");
    $edicoes = $stmtEd->fetchAll();

    // Default: maior id
    if ($edicaoId === null) {
        $edicaoId = isset($edicoes[0]["id"]) ? (int)$edicoes[0]["id"] : 0;
    }

    // Nome da edição
    $edicaoNome = "";
    foreach ($edicoes as $e) {
        if ((int)$e["id"] === (int)$edicaoId) {
            $edicaoNome = (string)($e["nome"] ?? "");
            break;
        }
    }

    // 2) Ranking (ordem ipsis litteris do banco)
    $sql = "
        SELECT
            r.posicao,
            r.usuario_id,
            u.nome AS usuario_nome,
            r.pontos,
            r.placares_acertados,
            r.resultados_acertados,
            r.pontos_primeira_fase,
            r.pontos_mata_mata,
            r.acertou_campeao,
            r.acertou_vice,
            r.acertou_terceiro,
            r.acertou_quarto,
            r.selecoes_classificadas,
            r.pontos_com_brasil,
            r.pontos_com_campeao,
            r.campeao_no_inicio,
            r.placar_na_final
        FROM ranking r
        INNER JOIN usuarios u ON u.id = r.usuario_id
        WHERE r.edicao_id = :edicao_id
        ORDER BY r.posicao ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":edicao_id" => (int)$edicaoId]);
    $rows = $stmt->fetchAll();

} catch (Throwable $e) {
    http_response_code(500);
    exit("Erro ao carregar ranking.");
}

$cfg = [
    "edicao" => ["id" => (int)$edicaoId, "nome" => (string)$edicaoNome],
    "user"   => ["id" => $usuarioId, "nome" => $usuarioNome, "tipo" => upper_utf8($tipoUsuario)],
    "counts" => ["total" => (int)count($rows)],
];

function build_row_title(array $r): string {
    $parts = [];

    $campeaoNoInicio = (string)($r["campeao_no_inicio"] ?? "");
    $placarFinal     = (string)($r["placar_na_final"] ?? "");

    if ($campeaoNoInicio !== "") $parts[] = "Campeão no início: " . $campeaoNoInicio;
    if ($placarFinal !== "")     $parts[] = "Placar na final: " . $placarFinal;

    return implode(" | ", $parts);
}

function build_row_class(int $uid, int $meId, int $pos): string {
    $c = "rk-row";
    if ($uid === $meId) $c .= " rk-me";
    if ($pos === 1) $c .= " rk-top1";
    if ($pos === 2) $c .= " rk-top2";
    if ($pos === 3) $c .= " rk-top3";
    return $c;
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Ranking — Bolão da Copa</title>

  <!-- Base global do projeto -->
  <link rel="stylesheet" href="/bolao-da-copa/public/css/base.css?v=1">

  <!-- Página ranking -->
  <link rel="stylesheet" href="/bolao-da-copa/public/css/ranking.css?v=1">
</head>
<body>

<div class="app-wrap">

  <!-- ✅ HEADER: MESMÍSSIMO DO app.php -->
  <header class="app-header">
    <div class="app-brand">
      <img src="/bolao-da-copa/public/img/logo.png" alt="Bolão" onerror="this.style.display='none'">
      <div class="app-title">
        <strong>Bolão da Copa</strong>
        <span>Ranking<?= ($edicaoNome !== "" ? " • " . strh($edicaoNome) : "") ?></span>
      </div>
    </div>

    <nav class="app-topnav" aria-label="Menu principal">
      <a class="topnav-link" href="/bolao-da-copa/public/app.php">Apostas</a>
      <a class="topnav-link is-active" href="/bolao-da-copa/public/ranking.php">Ranking do Bolão</a>
      <?php if ($isAdmin): ?>
        <a class="topnav-link is-admin" href="/bolao-da-copa/public/admin.php">Admin</a>
      <?php endif; ?>
    </nav>

    <div class="app-actions">
      <div class="user-chip" title="<?= strh($usuarioNome); ?>">
        <span class="dot"></span>
        <span class="user-chip-name"><?= strh($usuarioNome); ?></span>
      </div>
      <a class="btn-logout" href="/bolao-da-copa/public/app.php?action=logout">Sair</a>
    </div>
  </header>

  <!-- CONTEÚDO -->
  <section class="app-shell rk-shell-onecol">
    <div class="app-content">

      <div class="content-head rk-head">
        <div>
          <div class="content-h1">Ranking</div>
        </div>

        <div class="rk-edicao-box">
          <form method="get" action="ranking.php" class="rk-edicao-form">
            <label class="rk-label" for="edicao_id">Edição</label>
            <select class="rk-select" id="edicao_id" name="edicao_id">
              <?php foreach ($edicoes as $e): ?>
                <?php
                  $idEd = (int)$e["id"];
                  $nmEd = (string)($e["nome"] ?? ("Edição " . (string)$idEd));
                ?>
                <option value="<?= $idEd ?>" <?= ($idEd === (int)$edicaoId ? "selected" : "") ?>>
                  <?= strh($nmEd) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="rk-btn" type="submit">Abrir</button>
          </form>
        </div>
      </div>

      <div class="rk-toolbar">
        <div class="rk-search">
          <label class="rk-label" for="rkSearch">Buscar</label>
          <input id="rkSearch" class="rk-input" type="search" placeholder="Digite o nome do usuário…" autocomplete="off">
          <button id="rkClear" class="rk-btn rk-btn-ghost" type="button">Limpar</button>
        </div>

        <div class="rk-stats">
          <span class="badge badge-muted">
            Participantes: <span id="rkCount"><?= (int)count($rows) ?></span>
          </span>
          <span class="badge badge-muted">
            Edição: <?= strh($edicaoNome !== "" ? $edicaoNome : ("#" . (string)$edicaoId)) ?>
          </span>
        </div>
      </div>

      <div class="rk-tablewrap" role="region" aria-label="Tabela de Ranking">
        <table class="rk-table" id="rkTable">
          <thead>
            <tr>
              <th class="c-pos">#</th>
              <th class="c-user">Usuário</th>
              <th class="c-pts">Pontos</th>
              <th class="c-small">Placares</th>
              <th class="c-small">Resultados</th>
              <th class="c-small">1ª fase</th>
              <th class="c-small">Mata-mata</th>
              <th class="c-small">🏆</th>
              <th class="c-small">🥈</th>
              <th class="c-small">🥉</th>
              <th class="c-small">4º</th>
            </tr>
          </thead>

          <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $uid  = (int)($r["usuario_id"] ?? 0);
              $nome = (string)($r["usuario_nome"] ?? "");

              $pos    = (int)($r["posicao"] ?? 0);
              $pontos = (int)($r["pontos"] ?? 0);
              $plac   = (int)($r["placares_acertados"] ?? 0);
              $resu   = (int)($r["resultados_acertados"] ?? 0);
              $pf     = (int)($r["pontos_primeira_fase"] ?? 0);
              $mm     = (int)($r["pontos_mata_mata"] ?? 0);

              $title    = build_row_title($r);
              $rowClass = build_row_class($uid, $usuarioId, $pos);
              $dataName = lower_utf8($nome);
            ?>
            <tr class="<?= strh($rowClass) ?>"
                data-name="<?= strh($dataName) ?>"
                data-user-id="<?= $uid ?>"
                <?= ($title !== "" ? 'title="'.strh($title).'"' : "") ?>>
              <td data-label="#" class="c-pos"><span class="rk-rank"><?= $pos ?></span></td>

              <td data-label="Usuário" class="c-user">
                <div class="rk-user">
                  <span class="rk-user-name"><?= strh($nome) ?></span>
                  <?php if ($uid === $usuarioId): ?>
                    <span class="badge rk-badge-me">você</span>
                  <?php endif; ?>
                </div>
              </td>

              <td data-label="Pontos" class="c-pts"><span class="rk-points"><?= $pontos ?></span></td>
              <td data-label="Placares" class="c-small"><?= $plac ?></td>
              <td data-label="Resultados" class="c-small"><?= $resu ?></td>
              <td data-label="1ª fase" class="c-small"><?= $pf ?></td>
              <td data-label="Mata-mata" class="c-small"><?= $mm ?></td>

              <td data-label="🏆" class="c-small"><?= ((int)($r["acertou_campeao"] ?? 0) ? "✔" : "") ?></td>
              <td data-label="🥈" class="c-small"><?= ((int)($r["acertou_vice"] ?? 0) ? "✔" : "") ?></td>
              <td data-label="🥉" class="c-small"><?= ((int)($r["acertou_terceiro"] ?? 0) ? "✔" : "") ?></td>
              <td data-label="4º" class="c-small"><?= ((int)($r["acertou_quarto"] ?? 0) ? "✔" : "") ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php if (count($rows) === 0): ?>
          <div class="rk-empty">
            Nenhum ranking encontrado para esta edição.
          </div>
        <?php endif; ?>
      </div>

      <div class="rk-foot text-muted">
        Dica: use a busca para filtrar por nome. A ordem exibida é a do banco (posicao).
      </div>

    </div>
  </section>

</div>

<script type="application/json" id="app-config"><?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/bolao-da-copa/public/js/ranking.js?v=1"></script>
</body>
</html>