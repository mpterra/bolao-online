<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";

date_default_timezone_set('America/Sao_Paulo');

/*
|--------------------------------------------------------------------------
| mata_mata.php — ADMIN — Cadastro Manual Mata-mata
|--------------------------------------------------------------------------
| - ✅ Usa o HEADER PADRÃO (render_app_header) via public/partials/app_header.php
| - ✅ Botões exclusivos (Recarregar / Novo jogo) no MENU LATERAL ESQUERDO
| - FASE controlada por menu (valores fixos)
| - status sempre AGENDADO (não pede)
| - times via datalist no front (sem <select> lixo)
|
| Endpoints JSON:
|   GET  ?action=bootstrap
|   GET  ?action=list_games&edicao_id=..&fase=..
|   POST ?action=create
|   POST ?action=update
|   POST ?action=delete
|--------------------------------------------------------------------------
*/

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        // ✅ HostGator: sem /bolao-da-copa
        header("Location: /public/index.php");
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

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    $sess = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($sess) || $sent === '' || !hash_equals($sess, $sent)) {
        json_out(['ok' => false, 'error' => 'CSRF inválido. Recarregue a página.'], 400);
    }
}

function get_pdo(): PDO {
    // ✅ conexao.php já deve popular $pdo
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

    // ✅ Mantém compatibilidade: só tenta chamar se existir
    if (function_exists('conectar')) {
        $p = conectar();
        if ($p instanceof PDO) return $p;
    }
    if (function_exists('getConnection')) {
        $p = getConnection();
        if ($p instanceof PDO) return $p;
    }
    if (function_exists('get_pdo')) {
        $p = \get_pdo();
        if ($p instanceof PDO) return $p;
    }

    throw new RuntimeException("Não foi possível obter PDO. Verifique ../php/conexao.php.");
}

function trim_or_null($v): ?string {
    if (!is_string($v)) return null;
    $t = trim($v);
    return $t === '' ? null : $t;
}

function int_or_null($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return $v;
    if (is_string($v)) {
        $t = trim($v);
        if ($t !== '' && ctype_digit($t)) return (int)$t;
    }
    return null;
}

function to_mysql_datetime(?string $dtLocal): ?string {
    if ($dtLocal === null) return null;
    $dtLocal = trim($dtLocal);
    if ($dtLocal === '') return null;

    $dtLocal = str_replace('T', ' ', $dtLocal);

    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $dtLocal)) {
        return $dtLocal . ':00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $dtLocal)) {
        return $dtLocal;
    }
    return null;
}

function phases(): array {
    return [
        '16_DE_FINAL'     => '16 de final',
        'OITAVAS'         => 'Oitavas',
        'QUARTAS'         => 'Quartas',
        'SEMI'            => 'Semifinal',
        'TERCEIRO_LUGAR'  => '3º lugar',
        'FINAL'           => 'Final',
    ];
}

function normalize_phase(?string $fase): ?string {
    $fase = trim_or_null($fase);
    if ($fase === null) return null;
    $fase = strtoupper($fase);
    $all = phases();
    return array_key_exists($fase, $all) ? $fase : null;
}

function get_active_edicao_id(PDO $pdo): ?int {
    $stmt = $pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC, id DESC LIMIT 1");
    $id = $stmt ? $stmt->fetchColumn() : false;
    return ($id !== false && $id !== null) ? (int)$id : null;
}

/* =========================
   JSON API
   ========================= */

require_login();
require_admin();

$pdo = get_pdo();
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action !== '') {
    try {
        if ($action === 'bootstrap') {
            $edicoes = [];
            $stmtE = $pdo->query("SELECT id, nome, ano, ativo FROM edicoes ORDER BY ano DESC, id DESC");
            while ($r = $stmtE->fetch(PDO::FETCH_ASSOC)) {
                $edicoes[] = [
                    'id' => (int)$r['id'],
                    'nome' => (string)$r['nome'],
                    'ano' => (int)$r['ano'],
                    'ativo' => (int)$r['ativo'],
                ];
            }

            $times = [];
            $stmtT = $pdo->query("SELECT id, nome, sigla FROM times ORDER BY nome ASC");
            while ($r = $stmtT->fetch(PDO::FETCH_ASSOC)) {
                $times[] = [
                    'id' => (int)$r['id'],
                    'nome' => (string)$r['nome'],
                    'sigla' => (string)$r['sigla'],
                    'label' => (string)$r['nome'] . " (" . (string)$r['sigla'] . ")",
                ];
            }

            $edicaoDefault = get_active_edicao_id($pdo);
            if ($edicaoDefault === null && count($edicoes) > 0) $edicaoDefault = (int)$edicoes[0]['id'];

            $ph = phases();
            $phList = [];
            foreach ($ph as $k => $v) {
                $phList[] = ['code' => $k, 'label' => $v];
            }

            json_out([
                'ok' => true,
                'csrf_token' => csrf_token(),
                'edicao_default' => $edicaoDefault,
                'edicoes' => $edicoes,
                'times' => $times,
                'phases' => $phList,
            ]);
        }

        if ($action === 'list_games') {
            $edicaoId = int_or_null($_GET['edicao_id'] ?? null);
            $fase = normalize_phase($_GET['fase'] ?? null);

            if ($edicaoId === null) json_out(['ok' => false, 'error' => 'edicao_id inválido.'], 400);
            if ($fase === null) json_out(['ok' => false, 'error' => 'fase inválida.'], 400);

            $stmt = $pdo->prepare("
                SELECT
                  j.id,
                  j.fase,
                  j.data_hora,
                  j.codigo_fifa,
                  j.status,
                  j.time_casa_id,
                  j.time_fora_id,
                  tc.nome AS time_casa_nome,
                  tc.sigla AS time_casa_sigla,
                  tf.nome AS time_fora_nome,
                  tf.sigla AS time_fora_sigla,
                  j.zebra_time_id,
                  (SELECT COUNT(*) FROM palpites p WHERE p.jogo_id = j.id) AS total_palpites
                FROM jogos j
                JOIN times tc ON tc.id = j.time_casa_id
                JOIN times tf ON tf.id = j.time_fora_id
                WHERE j.edicao_id = ?
                  AND j.grupo_id IS NULL
                  AND j.fase = ?
                ORDER BY j.data_hora ASC, j.id ASC
            ");
            $stmt->execute([$edicaoId, $fase]);

            $rows = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $zebra = 'NONE';
                if (!empty($r['zebra_time_id'])) {
                    $zid = (int)$r['zebra_time_id'];
                    if ($zid === (int)$r['time_casa_id']) $zebra = 'CASA';
                    else if ($zid === (int)$r['time_fora_id']) $zebra = 'FORA';
                }

                $tp = (int)$r['total_palpites'];

                $rows[] = [
                    'id' => (int)$r['id'],
                    'fase' => (string)$r['fase'],
                    'data_hora' => (string)$r['data_hora'],
                    'codigo_fifa' => $r['codigo_fifa'] !== null ? (string)$r['codigo_fifa'] : null,
                    'status' => (string)$r['status'],
                    'time_casa_id' => (int)$r['time_casa_id'],
                    'time_fora_id' => (int)$r['time_fora_id'],
                    'time_casa_nome' => (string)$r['time_casa_nome'],
                    'time_casa_sigla' => (string)$r['time_casa_sigla'],
                    'time_fora_nome' => (string)$r['time_fora_nome'],
                    'time_fora_sigla' => (string)$r['time_fora_sigla'],
                    'zebra' => $zebra,
                    'total_palpites' => $tp,
                    'has_palpites' => ($tp > 0),
                ];
            }

            json_out(['ok' => true, 'games' => $rows]);
        }

        if ($action === 'create') {
            require_csrf();

            $edicaoId = int_or_null($_POST['edicao_id'] ?? null);
            $fase = normalize_phase($_POST['fase'] ?? null);
            $dataHora = to_mysql_datetime(trim_or_null($_POST['data_hora'] ?? null));
            $timeCasaId = int_or_null($_POST['time_casa_id'] ?? null);
            $timeForaId = int_or_null($_POST['time_fora_id'] ?? null);
            $codigoFifa = trim_or_null($_POST['codigo_fifa'] ?? null);
            $zebra = trim_or_null($_POST['zebra'] ?? null);

            if ($edicaoId === null) json_out(['ok' => false, 'error' => 'Edição inválida.'], 400);
            if ($fase === null) json_out(['ok' => false, 'error' => 'Fase inválida.'], 400);
            if ($dataHora === null) json_out(['ok' => false, 'error' => 'Data/hora inválida.'], 400);
            if ($timeCasaId === null || $timeForaId === null) json_out(['ok' => false, 'error' => 'Selecione os dois times.'], 400);
            if ($timeCasaId === $timeForaId) json_out(['ok' => false, 'error' => 'Times precisam ser diferentes.'], 400);

            $zebraTimeId = null;
            if ($zebra === 'CASA') $zebraTimeId = $timeCasaId;
            else if ($zebra === 'FORA') $zebraTimeId = $timeForaId;

            if ($codigoFifa !== null) {
                $codigoFifa = trim($codigoFifa);
                if ($codigoFifa === '') $codigoFifa = null;
                if ($codigoFifa !== null && mb_strlen($codigoFifa, 'UTF-8') > 20) {
                    json_out(['ok' => false, 'error' => 'Código FIFA muito longo (máx 20).'], 400);
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO jogos (
                  edicao_id, fase, grupo_id, rodada, data_hora,
                  time_casa_id, time_fora_id,
                  codigo_fifa, zebra_time_id, status
                ) VALUES (
                  ?, ?, NULL, NULL, ?,
                  ?, ?,
                  ?, ?, 'AGENDADO'
                )
            ");
            $stmt->execute([
                $edicaoId, $fase, $dataHora,
                $timeCasaId, $timeForaId,
                $codigoFifa, $zebraTimeId
            ]);

            json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        }

        if ($action === 'update') {
            require_csrf();

            $id = int_or_null($_POST['id'] ?? null);
            $edicaoId = int_or_null($_POST['edicao_id'] ?? null);
            $dataHora = to_mysql_datetime(trim_or_null($_POST['data_hora'] ?? null));
            $timeCasaId = int_or_null($_POST['time_casa_id'] ?? null);
            $timeForaId = int_or_null($_POST['time_fora_id'] ?? null);
            $codigoFifa = trim_or_null($_POST['codigo_fifa'] ?? null);
            $zebra = trim_or_null($_POST['zebra'] ?? null);

            if ($id === null) json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
            if ($edicaoId === null) json_out(['ok' => false, 'error' => 'Edição inválida.'], 400);
            if ($dataHora === null) json_out(['ok' => false, 'error' => 'Data/hora inválida.'], 400);

            if ($codigoFifa !== null) {
                $codigoFifa = trim($codigoFifa);
                if ($codigoFifa === '') $codigoFifa = null;
                if ($codigoFifa !== null && mb_strlen($codigoFifa, 'UTF-8') > 20) {
                    json_out(['ok' => false, 'error' => 'Código FIFA muito longo (máx 20).'], 400);
                }
            }

            $stmtG = $pdo->prepare("
                SELECT id, fase, time_casa_id, time_fora_id
                FROM jogos
                WHERE id = ? AND edicao_id = ? AND grupo_id IS NULL
            ");
            $stmtG->execute([$id, $edicaoId]);
            $g = $stmtG->fetch(PDO::FETCH_ASSOC);
            if (!$g) json_out(['ok' => false, 'error' => 'Jogo não encontrado.'], 404);

            $stmtC = $pdo->prepare("SELECT COUNT(*) FROM palpites WHERE jogo_id = ?");
            $stmtC->execute([$id]);
            $hasPalpites = ((int)$stmtC->fetchColumn() > 0);

            $tc = (int)$g['time_casa_id'];
            $tf = (int)$g['time_fora_id'];

            if ($hasPalpites) {
                $zebraTimeId = null;
                if ($zebra === 'CASA') $zebraTimeId = $tc;
                else if ($zebra === 'FORA') $zebraTimeId = $tf;

                $stmtU = $pdo->prepare("
                    UPDATE jogos
                    SET data_hora = ?, codigo_fifa = ?, zebra_time_id = ?, status = 'AGENDADO'
                    WHERE id = ? AND edicao_id = ? AND grupo_id IS NULL
                ");
                $stmtU->execute([$dataHora, $codigoFifa, $zebraTimeId, $id, $edicaoId]);

                json_out(['ok' => true, 'locked_times' => true]);
            }

            if ($timeCasaId === null || $timeForaId === null) json_out(['ok' => false, 'error' => 'Selecione os dois times.'], 400);
            if ($timeCasaId === $timeForaId) json_out(['ok' => false, 'error' => 'Times precisam ser diferentes.'], 400);

            $zebraTimeId = null;
            if ($zebra === 'CASA') $zebraTimeId = $timeCasaId;
            else if ($zebra === 'FORA') $zebraTimeId = $timeForaId;

            $stmtU = $pdo->prepare("
                UPDATE jogos
                SET data_hora = ?, time_casa_id = ?, time_fora_id = ?, codigo_fifa = ?, zebra_time_id = ?, status = 'AGENDADO'
                WHERE id = ? AND edicao_id = ? AND grupo_id IS NULL
            ");
            $stmtU->execute([$dataHora, $timeCasaId, $timeForaId, $codigoFifa, $zebraTimeId, $id, $edicaoId]);

            json_out(['ok' => true, 'locked_times' => false]);
        }

        if ($action === 'delete') {
            require_csrf();

            $id = int_or_null($_POST['id'] ?? null);
            $edicaoId = int_or_null($_POST['edicao_id'] ?? null);

            if ($id === null) json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
            if ($edicaoId === null) json_out(['ok' => false, 'error' => 'Edição inválida.'], 400);

            $stmt = $pdo->prepare("
                DELETE FROM jogos
                WHERE id = ? AND edicao_id = ? AND grupo_id IS NULL
                  AND NOT EXISTS (SELECT 1 FROM palpites p WHERE p.jogo_id = jogos.id)
            ");
            $stmt->execute([$id, $edicaoId]);

            if ($stmt->rowCount() === 0) {
                json_out(['ok' => false, 'error' => 'Não foi possível excluir (jogo não existe ou já possui palpites).'], 400);
            }

            json_out(['ok' => true]);
        }

        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    } catch (PDOException $e) {
        $msg = $e->getMessage();

        if (stripos($msg, 'uk_jogos_codigo_fifa') !== false) {
            json_out(['ok' => false, 'error' => 'Código FIFA já existe nesta edição.'], 400);
        }
        if (stripos($msg, 'uk_jogo_unico') !== false) {
            json_out(['ok' => false, 'error' => 'Jogo duplicado (mesma edição/data/hora e mesmos times).'], 400);
        }
        if (stripos($msg, 'chk_jogos_times_diferentes') !== false) {
            json_out(['ok' => false, 'error' => 'Times precisam ser diferentes.'], 400);
        }
        if (stripos($msg, 'chk_jogo_zebra_valida') !== false) {
            json_out(['ok' => false, 'error' => 'Zebra inválida (precisa ser um dos times do jogo).'], 400);
        }

        json_out(['ok' => false, 'error' => 'Erro no banco: ' . $msg], 500);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Erro: ' . $e->getMessage()], 500);
    }
}

/* =========================
   Page (HTML)
   ========================= */

$csrf = csrf_token();

// ✅ HostGator: sem /bolao-da-copa + ✅ filemtime NÃO pode estar dentro de string PHP
$cssHref = "/css/mata_mata.css?v=" . @filemtime(__DIR__ . "/css/mata_mata.css");
$jsSrc   = "/js/mata_mata.js?v=" . @filemtime(__DIR__ . "/js/mata_mata.js");

$usuarioNome = '';
if (isset($_SESSION['usuario_nome']) && is_string($_SESSION['usuario_nome'])) $usuarioNome = $_SESSION['usuario_nome'];
else if (isset($_SESSION['nome']) && is_string($_SESSION['nome'])) $usuarioNome = $_SESSION['nome'];
else $usuarioNome = 'Admin';

$tipo = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipo, "UTF-8") === "ADMIN");

/* Logout (padrão deste projeto) */
$logoutHref = "/mata_mata.php?action=logout";

/* HEADER PADRÃO (partial) */
require_once __DIR__ . "/partials/app_header.php";

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
  <title>Cadastrar Mata-Mata — Admin</title>
  <link rel="stylesheet" href="<?php echo strh($cssHref); ?>">
</head>
<body>

  <div class="app-wrap">
    <?php render_app_header($usuarioNome, $isAdmin, "mata_mata", "Admin • Cadastrar Mata-Mata", $logoutHref); ?>

    <div class="app-shell">
      <!-- MENU LATERAL (ações exclusivas da tela) -->
      <aside class="app-menu">
        <div class="menu-title">Cadastrar mata-mata</div>

        <div class="menu-actions menu-actions-tight">
          <button class="btn-save-all" id="btnNew" type="button">+ Novo jogo</button>
          <button class="btn-receipt" id="btnReload" type="button">Recarregar</button>

          <div class="hint">
            Use o topo para escolher a <b>fase</b>. Dentro da fase, cadastre os jogos.
            <br><br>
            Status é sempre <b>AGENDADO</b>. Se existir palpite, não dá pra trocar os times.
          </div>
        </div>
      </aside>

      <!-- CONTEÚDO -->
      <section class="app-content">
        <div class="content-head">
          <div class="content-h1">Mata-mata — Cadastro manual</div>
          <div class="content-sub">Selecione a edição e a fase, depois crie/edite os jogos.</div>
        </div>

        <div class="mm-card-head">
          <div class="mm-head-left">
            <div class="mm-field mm-field-small">
              <label for="edicao">Edição</label>
              <select id="edicao"></select>
            </div>

            <div class="mm-phasebar" id="phaseBar" aria-label="Fases do mata-mata"></div>
          </div>

          <div class="mm-right">
            <span class="mm-pill" id="pillPhase">Fase: —</span>
            <span class="mm-pill" id="pillCount">0 jogo(s)</span>
          </div>
        </div>

        <div class="mm-card-body">
          <div id="listArea"></div>
        </div>
      </section>
    </div>
  </div>

  <!-- Modal -->
  <div class="mm-modal-backdrop" id="modalBackdrop" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="mm-modal">
      <div class="mm-modal-head">
        <strong id="modalTitle">Novo jogo</strong>

        <div class="mm-modal-actions">
          <button class="mm-btn" id="btnClose" type="button">Fechar</button>
          <button class="mm-btn mm-btn-primary" id="btnSave" type="button">Salvar</button>
        </div>
      </div>

      <div class="mm-modal-body">
        <div class="mm-modal-sub" id="modalPhaseLine"></div>

        <div class="mm-grid">
          <div class="mm-field">
            <label for="m_data">Data/Hora</label>
            <input id="m_data" type="datetime-local" />
            <div class="mm-hint">Horário local (America/Sao_Paulo).</div>
          </div>

          <div class="mm-field">
            <label for="m_codigo">Código FIFA (opcional)</label>
            <input id="m_codigo" type="text" maxlength="20" placeholder="Ex.: 400001234" />
          </div>

          <div class="mm-field">
            <label for="m_casa_label">Time da casa</label>
            <input id="m_casa_label" type="text" list="timesList" placeholder="Digite para buscar..." autocomplete="off" />
            <input id="m_casa_id" type="hidden" />
            <div class="mm-hint" id="hintCasa"></div>
          </div>

          <div class="mm-field">
            <label for="m_fora_label">Time de fora</label>
            <input id="m_fora_label" type="text" list="timesList" placeholder="Digite para buscar..." autocomplete="off" />
            <input id="m_fora_id" type="hidden" />
            <div class="mm-hint" id="hintFora"></div>
          </div>

          <div class="mm-field">
            <label for="m_zebra">Zebra</label>
            <select id="m_zebra">
              <option value="NONE">Nenhuma</option>
              <option value="CASA">Casa</option>
              <option value="FORA">Fora</option>
            </select>
            <div class="mm-hint">Opcional. Se definida, precisa ser um dos times do jogo.</div>
          </div>

          <div class="mm-field">
            <label>Regras</label>
            <div class="mm-hint">
              • Status é sempre <b>AGENDADO</b> (não existe campo).<br>
              • Se já existirem palpites, você <b>não pode</b> trocar os times.
            </div>
          </div>
        </div>

        <datalist id="timesList"></datalist>

        <div class="mm-modal-footer">
          <div class="mm-hint" id="modalInfo"></div>
          <button class="mm-btn mm-btn-danger" id="btnDelete" type="button" style="display:none;">Excluir</button>
        </div>
      </div>
    </div>
  </div>

  <div class="mm-toast" id="toast"></div>

  <script type="application/json" id="mm-config"><?php
    echo json_encode([
      'csrf_token' => $csrf,
      'endpoints' => [
        'bootstrap'  => '/mata_mata.php?action=bootstrap',
        'list_games' => '/mata_mata.php?action=list_games',
        'create'     => '/mata_mata.php?action=create',
        'update'     => '/mata_mata.php?action=update',
        'delete'     => '/mata_mata.php?action=delete',
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  ?></script>

  <script src="<?php echo strh($jsSrc); ?>"></script>
</body>
</html>