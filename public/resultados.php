<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

/**
 * ✅ HOSTGATOR:
 * conexao.php fora do public_html
 */
require_once "/home2/mauri075/php/conexao.php";

date_default_timezone_set('America/Sao_Paulo');

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        header("Location: /index.php");
        exit;
    }
}

function strh(?string $s): string {
    return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

function as_int_or_null($v): ?int {
    if ($v === null) return null;
    if (is_string($v)) $v = trim($v);
    if ($v === "" || $v === false) return null;
    if (!is_numeric($v)) return null;
    return (int)$v;
}

function dt_from_mysql(?string $dt): ?DateTimeImmutable {
    if (!$dt) return null;
    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
        if ($parsed instanceof DateTimeImmutable) return $parsed;

        return new DateTimeImmutable($dt, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Status automático (mesma lógica do admin):
 * - Se gols preenchidos (ambos NOT NULL) => ENCERRADO
 * - Senão:
 *    - agora < data_hora => AGENDADO
 *    - agora >= data_hora => EM_ANDAMENTO
 */
function compute_status_auto(?int $gc, ?int $gf, DateTimeImmutable $kickoff, DateTimeImmutable $now): string {
    if ($gc !== null && $gf !== null) return "ENCERRADO";
    return ($now < $kickoff) ? "AGENDADO" : "EM_ANDAMENTO";
}

/**
 * Resultado (1X2) a partir do placar real:
 * - 'H' casa, 'D' empate, 'A' fora
 */
function outcome_from_score(int $gc, int $gf): string {
    if ($gc > $gf) return 'H';
    if ($gc < $gf) return 'A';
    return 'D';
}

/**
 * Classifica palpite do usuário comparando com placar real.
 * Retorna:
 * - PENDENTE (sem resultado real)
 * - SEM_PALPITE
 * - PLACAR (acertou exato)
 * - RESULTADO (acertou 1X2)
 * - ERROU
 */
function classify_user_pick(?int $realGc, ?int $realGf, ?int $pickGc, ?int $pickGf): string {
    if ($realGc === null || $realGf === null) return "PENDENTE";
    if ($pickGc === null || $pickGf === null) return "SEM_PALPITE";
    if ($pickGc === $realGc && $pickGf === $realGf) return "PLACAR";

    $realOut = outcome_from_score($realGc, $realGf);
    $pickOut = outcome_from_score($pickGc, $pickGf);
    return ($realOut === $pickOut) ? "RESULTADO" : "ERROU";
}

/**
 * Normaliza nome do time -> slug de arquivo em /public/img/flags (seu padrão real).
 */
function flag_slug_from_name(string $nome): string {
    $s = trim($nome);
    if ($s === "") return "";

    $s = preg_replace('/\s+ou\s+.*/iu', '', $s) ?? $s;
    $s = preg_replace('/\s*\(.*?\)\s*/u', ' ', $s) ?? $s;
    $s = mb_strtolower($s, 'UTF-8');

    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== "") $s = $t;

    $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;
    return $s;
}

function flag_slug_aliases(string $slugBase): array {
    $m = [
        'reptcheca' => 'republicatcheca',
        'reptchecaouirlandaoudinamarca' => 'republicatcheca',
        'republicatchecaouirlandaoudinamarca' => 'republicatcheca',

        'coreiadosul' => 'coreiadosul',
        'coreiadonorte' => 'coreiadonorte',
        'estadosunidos' => 'estadosunidos',

        'mexico' => 'mexico',
        'africadosul' => 'africadosul',

        'cotedivoire' => 'costadomarfim',
    ];

    $out = [];
    if ($slugBase !== "") {
        $out[] = $slugBase;
        if (isset($m[$slugBase])) $out[] = $m[$slugBase];
    }
    return array_values(array_unique($out));
}

function flag_url_for_team(string $teamName, string $sigla): ?string {
    $baseDir = __DIR__ . "/img/flags"; // /public_html/img/flags (este arquivo está no public_html)
    $candidates = [];

    $slugByName = flag_slug_from_name($teamName);
    foreach (flag_slug_aliases($slugByName) as $s) {
        if ($s !== "") $candidates[] = $s;
    }

    $sig = trim($sigla);
    if ($sig !== "") {
        $sig = mb_strtolower($sig, "UTF-8");
        $sig = preg_replace('/[^a-z0-9]+/i', '', $sig) ?? $sig;
        if ($sig !== "") $candidates[] = $sig;
    }

    $candidates = array_values(array_unique($candidates));

    foreach ($candidates as $slug) {
        $fs = $baseDir . "/" . $slug . ".png";
        if (is_file($fs)) {
            return "/img/flags/" . $slug . ".png";
        }
    }
    return null;
}

require_login();

$usuarioId   = (int)($_SESSION["usuario_id"] ?? 0);
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Usuário";

$tipoSessao  = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin     = (mb_strtoupper($tipoSessao, "UTF-8") === "ADMIN");

$tz  = new DateTimeZone("America/Sao_Paulo");
$now = new DateTimeImmutable("now", $tz);

/* Logout */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_destroy();
    header("Location: /index.php");
    exit;
}

/* =========================================================
   Carregamento: edição ativa -> grupos -> jogos + palpite do usuário
   ========================================================= */
try {
    $edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
    if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

    $stG = $pdo->prepare("SELECT id, codigo, nome FROM grupos WHERE edicao_id = ? ORDER BY codigo ASC, id ASC");
    $stG->execute([$edicaoId]);
    $grupos = $stG->fetchAll(PDO::FETCH_ASSOC);

    $stJ = $pdo->prepare("
        SELECT
            j.id,
            j.fase,
            j.grupo_id,
            j.rodada,
            j.data_hora,
            j.status,
            j.gols_casa,
            j.gols_fora,
            j.codigo_fifa,

            g.codigo AS grupo_codigo,

            tc.nome  AS time_casa_nome,
            tc.sigla AS time_casa_sigla,

            tf.nome  AS time_fora_nome,
            tf.sigla AS time_fora_sigla,

            p.gols_casa AS palpite_casa,
            p.gols_fora AS palpite_fora
        FROM jogos j
        INNER JOIN grupos g ON g.id = j.grupo_id
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        LEFT JOIN palpites p
               ON p.jogo_id = j.id
              AND p.usuario_id = :uid
        WHERE j.edicao_id = :eid
          AND j.grupo_id IS NOT NULL
          AND (j.fase = 'GRUPOS' OR j.fase = 'GRUPO' OR j.fase = 'FASE_DE_GRUPOS' OR j.fase LIKE '%GRUP%')
        ORDER BY g.codigo ASC, j.data_hora ASC, j.id ASC
    ");
    $stJ->execute([
        ":uid" => $usuarioId,
        ":eid" => $edicaoId,
    ]);
    $jogos = $stJ->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar resultados.";
    exit;
}

$jogosPorGrupo = [];
foreach ($jogos as $row) {
    $gid = (int)$row["grupo_id"];
    if (!isset($jogosPorGrupo[$gid])) $jogosPorGrupo[$gid] = [];
    $jogosPorGrupo[$gid][] = $row;
}

$activeGroupId = 0;
if (isset($_GET["grupo_id"])) $activeGroupId = (int)$_GET["grupo_id"];
if ($activeGroupId <= 0 && count($grupos) > 0) $activeGroupId = (int)$grupos[0]["id"];

/* HEADER PADRÃO (partial) - arquivo em /public_html/partials */
require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <title>Bolão da Copa - Resultados</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/css/resultados.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/resultados.css'); ?>">
</head>
<body>

<div class="app-wrap">

    <?php
        render_app_header(
            $usuarioNome,
            $isAdmin,
            "resultados_publico",
            "Resultados • Real x Seu palpite",
            "/resultados.php?action=logout"
        );
    ?>

    <div class="app-shell">
        <aside class="app-menu">
            <div class="menu-title">Grupos</div>

            <div class="menu-list" id="group-menu">
                <?php foreach ($grupos as $g): ?>
                    <?php
                        $gid    = (int)$g["id"];
                        $codigo = (string)$g["codigo"];
                        $count  = isset($jogosPorGrupo[$gid]) ? count($jogosPorGrupo[$gid]) : 0;
                        $active = ($gid === $activeGroupId);
                    ?>
                    <a class="menu-link<?php echo $active ? " is-active" : ""; ?><?php echo $count > 0 ? "" : " is-disabled"; ?>"
                       href="#"
                       data-group-id="<?php echo $gid; ?>"
                       <?php echo $count > 0 ? "" : "aria-disabled='true' tabindex='-1'"; ?>
                       aria-label="Grupo <?php echo strh($codigo); ?>">
                        <span>Grupo <?php echo strh($codigo); ?></span>
                        <span class="badge<?php echo $count > 0 ? "" : " badge-muted"; ?>"><?php echo (int)$count; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="menu-actions">
                <div class="hint">
                    Aqui você confere o <strong>placar real</strong> e o <strong>seu palpite</strong>.
                    <br>
                    <small>Legenda: <strong>✔ Placar</strong>, <strong>✓ Resultado</strong>, <strong>— Pendente</strong>, <strong>× Errou</strong>.</small>
                </div>
            </div>
        </aside>

        <main class="app-content">

            <div class="content-head">
                <h1 class="content-h1">Resultados por grupo</h1>
                <p class="content-sub">
                    Mostra o placar real (quando existir) e o seu palpite para cada jogo.
                </p>
            </div>

            <?php foreach ($grupos as $g): ?>
                <?php
                    $gid      = (int)$g["id"];
                    $codigo   = (string)$g["codigo"];
                    $nome     = (string)($g["nome"] ?? "");
                    $isActive = ($gid === $activeGroupId);

                    $lista = $jogosPorGrupo[$gid] ?? [];
                ?>

                <section class="group-block<?php echo $isActive ? " is-active-group" : ""; ?>"
                         data-group-block="<?php echo $gid; ?>">

                    <div class="group-head">
                        <div class="group-line">
                            <div class="group-pill">Grupo <?php echo strh($codigo); ?></div>
                            <div class="group-count"><?php echo count($lista); ?> jogos</div>
                        </div>
                        <?php if (trim($nome) !== ""): ?>
                            <div class="group-name"><?php echo strh($nome); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="matches">
                        <?php if (count($lista) === 0): ?>
                            <div class="empty">Nenhum jogo cadastrado neste grupo.</div>
                        <?php endif; ?>

                        <?php foreach ($lista as $j): ?>
                            <?php
                                $jid    = (int)$j["id"];
                                $rodada = $j["rodada"] !== null ? (int)$j["rodada"] : null;

                                $kickoff = dt_from_mysql((string)$j["data_hora"]);
                                if (!$kickoff) $kickoff = new DateTimeImmutable("1970-01-01 00:00:00", $tz);

                                $realGc  = $j["gols_casa"] !== null ? (int)$j["gols_casa"] : null;
                                $realGf  = $j["gols_fora"] !== null ? (int)$j["gols_fora"] : null;

                                $pickGc  = $j["palpite_casa"] !== null ? (int)$j["palpite_casa"] : null;
                                $pickGf  = $j["palpite_fora"] !== null ? (int)$j["palpite_fora"] : null;

                                $autoStatus = compute_status_auto($realGc, $realGf, $kickoff, $now);
                                $pickClass  = classify_user_pick($realGc, $realGf, $pickGc, $pickGf);

                                $timeCasaNome = (string)$j["time_casa_nome"];
                                $timeForaNome = (string)$j["time_fora_nome"];
                                $siglaCasa    = (string)$j["time_casa_sigla"];
                                $siglaFora    = (string)$j["time_fora_sigla"];

                                $flagCasa = flag_url_for_team($timeCasaNome, $siglaCasa);
                                $flagFora = flag_url_for_team($timeForaNome, $siglaFora);

                                $whenTxt  = $kickoff->format("d/m/Y H:i");
                                $roundTxt = $rodada !== null ? ("Rodada " . $rodada) : "";

                                $realTxt = ($realGc === null || $realGf === null) ? "—" : ($realGc . " x " . $realGf);
                                $pickTxt = ($pickGc === null || $pickGf === null) ? "—" : ($pickGc . " x " . $pickGf);

                                $fifaTxt = isset($j["codigo_fifa"]) ? trim((string)$j["codigo_fifa"]) : "";
                            ?>
                            <article class="match-card" data-jogo-id="<?php echo $jid; ?>">
                                <div class="match-top">
                                    <div class="match-when">
                                        <span class="when"><?php echo strh($whenTxt); ?></span>
                                        <?php if ($roundTxt !== ""): ?>
                                            <span class="round"><?php echo strh($roundTxt); ?></span>
                                        <?php endif; ?>
                                        <?php if ($fifaTxt !== ""): ?>
                                            <span class="round">FIFA <?php echo strh($fifaTxt); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="badges">
                                        <span class="badge-status status-<?php echo strh($autoStatus); ?>"><?php echo strh($autoStatus); ?></span>
                                        <span class="badge-pick pick-<?php echo strh($pickClass); ?>"><?php echo strh($pickClass); ?></span>
                                    </div>
                                </div>

                                <div class="match-body">
                                    <div class="team team-home">
                                        <div class="team-name"><?php echo strh($timeCasaNome); ?></div>

                                        <div class="team-flag<?php echo $flagCasa ? "" : " no-flag"; ?>">
                                            <?php if ($flagCasa): ?>
                                                <img src="<?php echo strh($flagCasa); ?>" alt="<?php echo strh($siglaCasa); ?>">
                                            <?php endif; ?>
                                            <div class="team-badge"><?php echo strh($siglaCasa); ?></div>
                                        </div>
                                    </div>

                                    <div class="midbox">
                                        <div class="score-compare">
                                            <div class="score-col">
                                                <div class="score-label">Real</div>
                                                <div class="score-val"><?php echo strh($realTxt); ?></div>
                                            </div>

                                            <div class="score-sep"></div>

                                            <div class="score-col">
                                                <div class="score-label">Seu palpite</div>
                                                <div class="score-val"><?php echo strh($pickTxt); ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="team team-away">
                                        <div class="team-flag<?php echo $flagFora ? "" : " no-flag"; ?>">
                                            <?php if ($flagFora): ?>
                                                <img src="<?php echo strh($flagFora); ?>" alt="<?php echo strh($siglaFora); ?>">
                                            <?php endif; ?>
                                            <div class="team-badge"><?php echo strh($siglaFora); ?></div>
                                        </div>

                                        <div class="team-name"><?php echo strh($timeForaNome); ?></div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="toast" id="toast"></div>
        </main>
    </div>
</div>

<script id="resultados-config" type="application/json">
<?php
echo json_encode([
    "active_group_id" => $activeGroupId
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
</script>

<script src="/js/resultados.js"></script>
</body>
</html>