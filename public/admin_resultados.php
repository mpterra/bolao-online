<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

// ✅ HostGator: arquivo em /public_html => sobe 1 nível para /home2/mauri075 e entra em /php
require_once dirname(__DIR__) . "/php/conexao.php";

date_default_timezone_set('America/Sao_Paulo');

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

function as_int_or_null($v): ?int {
    if ($v === null) return null;
    if (is_string($v)) $v = trim($v);
    if ($v === "" || $v === false) return null;
    if (!is_numeric($v)) return null;
    return (int)$v;
}

function json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Status automático por horário do jogo:
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
 * Normaliza nome do time -> slug de arquivo em /public/img/flags (SEU PADRÃO REAL).
 * Regra:
 * - pega só a 1ª opção antes de " ou "
 * - remove parênteses
 * - lower
 * - remove acentos (iconv)
 * - remove tudo que não seja [a-z0-9]
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

/**
 * Algumas strings do banco/seed nunca vão bater com o filename “bonitinho”.
 * Aqui é onde você resolve a vida sem gambiarra no HTML/CSS.
 */
function flag_slug_aliases(string $slugBase): array {
    $m = [
        // abreviações/formatos comuns
        'reptcheca' => 'republicatcheca',
        'reptchecaouirlandaoudinamarca' => 'republicatcheca',
        'republicatchecaouirlandaoudinamarca' => 'republicatcheca',

        'coreiadosul' => 'coreiadosul',
        'coreiadonorte' => 'coreiadonorte',
        'estadosunidos' => 'estadosunidos',

        // variações que costumam aparecer
        'mexico' => 'mexico',
        'africadosul' => 'africadosul',

        // “costa do marfim” às vezes vem “cote d’ivoire”
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
    $baseDir = __DIR__ . "/img/flags"; // /public_html/img/flags
    $candidates = [];

    $slugByName = flag_slug_from_name($teamName);
    foreach (flag_slug_aliases($slugByName) as $s) {
        if ($s !== "") $candidates[] = $s;
    }

    // fallback: se alguém nomeou arquivo por sigla (não é seu caso hoje, mas não atrapalha)
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
            // ✅ HostGator: público na raiz
            return "/img/flags/" . $slug . ".png";
        }
    }
    return null;
}

require_login();
require_admin();

$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Admin";
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
   Endpoint JSON: salvar placar real (ADMIN)
   - NÃO recebe status do client
   - status é calculado automaticamente
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_GET["action"]) && $_GET["action"] === "save") {
    try {
        $raw  = file_get_contents("php://input");
        $data = json_decode($raw ?: "{}", true);
        if (!is_array($data)) json_out(["ok" => false, "error" => "JSON inválido."], 400);

        $jogoId = as_int_or_null($data["jogo_id"] ?? null);
        $gc     = as_int_or_null($data["gols_casa"] ?? null);
        $gf     = as_int_or_null($data["gols_fora"] ?? null);

        if (!$jogoId || $jogoId <= 0) json_out(["ok" => false, "error" => "jogo_id inválido."], 400);

        foreach (["gols_casa" => $gc, "gols_fora" => $gf] as $k => $v) {
            if ($v !== null && ($v < 0 || $v > 30)) {
                json_out(["ok" => false, "error" => "{$k} deve estar entre 0 e 30 (ou vazio)."], 400);
            }
        }

        if (($gc === null) !== ($gf === null)) {
            json_out(["ok" => false, "error" => "Informe os dois placares (casa e fora) ou deixe ambos vazios."], 400);
        }

        $st = $pdo->prepare("SELECT id, data_hora FROM jogos WHERE id = ? LIMIT 1");
        $st->execute([$jogoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(["ok" => false, "error" => "Jogo não encontrado."], 404);

        $kickoff = new DateTimeImmutable((string)$row["data_hora"], $tz);
        $status  = compute_status_auto($gc, $gf, $kickoff, $now);

        $upd = $pdo->prepare("
            UPDATE jogos
               SET gols_casa = :gc,
                   gols_fora = :gf,
                   status    = :st
             WHERE id = :id
             LIMIT 1
        ");
        $upd->bindValue(":gc", $gc, $gc === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $upd->bindValue(":gf", $gf, $gf === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $upd->bindValue(":st", $status, PDO::PARAM_STR);
        $upd->bindValue(":id", $jogoId, PDO::PARAM_INT);
        $upd->execute();

        json_out([
            "ok" => true,
            "jogo_id" => $jogoId,
            "gols_casa" => $gc,
            "gols_fora" => $gf,
            "status" => $status
        ]);
    } catch (Throwable $e) {
        json_out(["ok" => false, "error" => "Erro ao salvar resultado."], 500);
    }
}

/* =========================================================
   Carregamento (layout por grupos, estilo app.php)
   ========================================================= */
try {
    $ed = $pdo->query("SELECT id, nome, ano, ativo FROM edicoes ORDER BY ativo DESC, ano DESC, id DESC");
    $edicoes = $ed->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar edições.";
    exit;
}

$edicaoId = isset($_GET["edicao_id"]) ? (int)$_GET["edicao_id"] : 0;
if ($edicaoId <= 0) {
    foreach ($edicoes as $e) {
        if ((int)$e["ativo"] === 1) { $edicaoId = (int)$e["id"]; break; }
    }
    if ($edicaoId <= 0 && count($edicoes) > 0) $edicaoId = (int)$edicoes[0]["id"];
}

try {
    $g = $pdo->prepare("SELECT id, codigo, nome FROM grupos WHERE edicao_id = ? ORDER BY codigo ASC, id ASC");
    $g->execute([$edicaoId]);
    $grupos = $g->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar grupos.";
    exit;
}

try {
    $j = $pdo->prepare("
        SELECT
            j.id,
            j.fase,
            j.grupo_id,
            j.rodada,
            j.data_hora,
            j.status,
            j.gols_casa,
            j.gols_fora,

            g.codigo AS grupo_codigo,

            tc.id AS time_casa_id,
            tc.nome AS time_casa_nome,
            tc.sigla AS time_casa_sigla,

            tf.id AS time_fora_id,
            tf.nome AS time_fora_nome,
            tf.sigla AS time_fora_sigla
        FROM jogos j
        LEFT JOIN grupos g ON g.id = j.grupo_id
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        WHERE j.edicao_id = ?
          AND j.grupo_id IS NOT NULL
        ORDER BY g.codigo ASC, j.data_hora ASC, j.id ASC
    ");
    $j->execute([$edicaoId]);
    $jogos = $j->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar jogos.";
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

// include do header único
require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <title>Bolão da Copa - Admin Resultados</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/css/admin_resultados.css?v=<?php echo filemtime(__DIR__ . '/css/admin_resultados.css'); ?>">
</head>
<body data-page="admin_resultados">

<div class="app-wrap">

    <?php
        render_app_header(
            $usuarioNome,
            $isAdmin,
            "resultados",
            "Admin • Resultados (placar real)",
            "/admin_resultados.php?action=logout"
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
                    <a class="menu-link<?php echo $active ? " is-active" : ""; ?>"
                       href="#"
                       data-group-id="<?php echo $gid; ?>"
                       aria-label="Grupo <?php echo strh($codigo); ?>">
                        <span>Grupo <?php echo strh($codigo); ?></span>
                        <span class="badge badge-muted"><?php echo $count; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="app-content">

            <div class="content-head">
                <h1 class="content-h1">Resultados reais por grupo</h1>
                <p class="content-sub">
                    Preencha os dois placares para encerrar o jogo automaticamente. Deixe vazio para não encerrar.
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
                        <?php if ($nome !== ""): ?>
                            <div class="group-name"><?php echo strh($nome); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="matches">
                        <?php if (count($lista) === 0): ?>
                            <div class="group-rank-empty">Nenhum jogo cadastrado neste grupo.</div>
                        <?php endif; ?>

                        <?php foreach ($lista as $j): ?>
                            <?php
                                $jid    = (int)$j["id"];
                                $rodada = $j["rodada"] !== null ? (int)$j["rodada"] : null;

                                $kickoff = new DateTimeImmutable((string)$j["data_hora"], $tz);
                                $gc      = $j["gols_casa"] !== null ? (int)$j["gols_casa"] : null;
                                $gf      = $j["gols_fora"] !== null ? (int)$j["gols_fora"] : null;

                                $autoStatus = compute_status_auto($gc, $gf, $kickoff, $now);

                                $timeCasaNome = (string)$j["time_casa_nome"];
                                $timeForaNome = (string)$j["time_fora_nome"];
                                $siglaCasa    = (string)$j["time_casa_sigla"];
                                $siglaFora    = (string)$j["time_fora_sigla"];

                                $flagCasa = flag_url_for_team($timeCasaNome, $siglaCasa);
                                $flagFora = flag_url_for_team($timeForaNome, $siglaFora);

                                $whenTxt  = $kickoff->format("d/m/Y H:i");
                                $roundTxt = $rodada !== null ? ("Rodada " . $rodada) : "";
                            ?>
                            <article class="match-card" data-jogo-row="<?php echo $jid; ?>">
                                <div class="match-top">
                                    <div class="match-when">
                                        <span class="when"><?php echo strh($whenTxt); ?></span>
                                        <?php if ($roundTxt !== ""): ?>
                                            <span class="round"><?php echo strh($roundTxt); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="match-status status-<?php echo strh($autoStatus); ?>">
                                        <?php echo strh($autoStatus); ?>
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

                                    <div class="scorebox">
                                        <input class="score js-score"
                                               type="number"
                                               inputmode="numeric"
                                               min="0" max="30" step="1"
                                               data-field="gols_casa"
                                               value="<?php echo $gc === null ? "" : (string)$gc; ?>"
                                               placeholder="-" />

                                        <span class="x">x</span>

                                        <input class="score js-score"
                                               type="number"
                                               inputmode="numeric"
                                               min="0" max="30" step="1"
                                               data-field="gols_fora"
                                               value="<?php echo $gf === null ? "" : (string)$gf; ?>"
                                               placeholder="-" />
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

                                <div class="match-actions">
                                    <button type="button"
                                            class="btn-save-one js-save-real"
                                            data-jogo-id="<?php echo $jid; ?>">
                                        Salvar resultado
                                    </button>

                                    <span class="save-state js-row-msg" style="display:none;"></span>
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

<script id="admin-resultados-config" type="application/json">
<?php
echo json_encode([
    "edicao_id" => $edicaoId,
    "active_group_id" => $activeGroupId
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
</script>

<script src="/js/admin_resultados.js"></script>
</body>
</html>