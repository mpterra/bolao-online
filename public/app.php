<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

/*
|--------------------------------------------------------------------------
| ✅ AUTO-BASE (SEM FICAR TROCANDO LINKS ENTRE LOCAL/HOST)
|--------------------------------------------------------------------------
| Suporta dois layouts:
| 1) app.php dentro de /public  (ex: /bolao-da-copa/public/app.php)
| 2) app.php na raiz           (ex: /app.php) com assets em /public/*
|--------------------------------------------------------------------------
*/
$SCRIPT_DIR = str_replace('\\', '/', (string)dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$WEB_BASE = rtrim($SCRIPT_DIR, '/');
if ($WEB_BASE === '/') $WEB_BASE = '';

$IS_ROOT_LAYOUT = is_dir(__DIR__ . '/public') && is_file(__DIR__ . '/public/css/app.css');

// assets (css/js/img) podem estar:
// - no mesmo diretório do app.php (layout /public)
// - dentro de /public (layout raiz)
$ASSET_FS_BASE  = $IS_ROOT_LAYOUT ? (__DIR__ . '/public') : __DIR__;
$ASSET_WEB_BASE = $IS_ROOT_LAYOUT
	? (($WEB_BASE === '' ? '' : $WEB_BASE) . '/public')
	: ($WEB_BASE === '' ? '' : $WEB_BASE);

// páginas públicas (index/app/campeao) ficam:
// - no mesmo diretório do app.php
$PAGE_WEB_BASE = ($WEB_BASE === '' ? '' : $WEB_BASE);

// pasta /php fica:
// - irmã de /public (layout /public -> /bolao-da-copa/php)
// - dentro da raiz (layout raiz -> /php)
if (preg_match('#/public$#', $WEB_BASE)) {
	$PHP_WEB_BASE = preg_replace('#/public$#', '', $WEB_BASE) . '/php';
} else {
	$PHP_WEB_BASE = ($WEB_BASE === '' ? '' : $WEB_BASE) . '/php';
}

// require conexao.php (fs) em ambos os layouts
$CONEXAO_PATH_1 = __DIR__ . "/../php/conexao.php";
$CONEXAO_PATH_2 = __DIR__ . "/php/conexao.php";
if (is_file($CONEXAO_PATH_1)) {
	require_once $CONEXAO_PATH_1;
} else {
	require_once $CONEXAO_PATH_2;
}

/*
|--------------------------------------------------------------------------
| APP.PHP - BOLÃO DA COPA (APOSTAS)
|--------------------------------------------------------------------------
| - Tela única para palpites na fase de grupos
| - Menu de grupos FILTRA (não rola a página)
| - Persistência em `palpites` (UPSERT por usuario_id + jogo_id)
| - Endpoint JSON no próprio arquivo (action=save)
| - Seleção livre de 1º/2º/3º de cada grupo (palpite_grupo_classificacao)
| - Botão "Quem será o campeão" no fim do menu
|--------------------------------------------------------------------------
|
| ✅ REGRA DE BLOQUEIO (ATUALIZADA — DIA LÓGICO)
| - Jogos passados (data_hora <= agora): sempre bloqueados.
| - “Dia lógico”:
|     * Jogos entre 00:00 e 04:59 pertencem ao DIA ANTERIOR.
|     * Jogos a partir de 05:00 pertencem ao mesmo dia do calendário.
| - Trava por “dia lógico”:
|     * 1h antes do PRIMEIRO jogo do dia lógico, bloqueia TODOS os jogos
|       daquele dia lógico (incluindo os da madrugada 00:00–04:59 do dia seguinte).
| - Jogos futuros (outros dias lógicos): liberados até seu dia lógico travar.
|--------------------------------------------------------------------------
*/

// ✅ Timezone oficial da aplicação (evita UTC “comendo” seu horário)
date_default_timezone_set('America/Sao_Paulo');

function json_response(array $data, int $code = 200): void {
	http_response_code($code);
	header("Content-Type: application/json; charset=utf-8");
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function require_login(string $loginUrl): void {
	if (empty($_SESSION["usuario_id"])) {
		header("Location: " . $loginUrl);
		exit;
	}
}

function strh(?string $s): string {
	return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

function fmt_datahora(string $dt): string {
	$ts = strtotime($dt);
	if ($ts === false) return $dt;
	return date("d/m H:i", $ts);
}

function fmt_hm(DateTimeInterface $dt): string {
	return $dt->format('H:i');
}

/**
 * Normaliza nome do time -> nome do arquivo da bandeira:
 * - minúsculas
 * - remove acentos
 * - remove espaços e caracteres especiais
 * - se tiver " OU " pega apenas a primeira opção
 * Ex.: "África do Sul" -> "africadosul"
 */
function flag_slug(string $nome): string {
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
 * Retorna URL pública da bandeira se existir no disco, senão null.
 * Pasta web: {ASSET_WEB_BASE}/img/flags/{slug}.png
 * Pasta fs : {ASSET_FS_BASE}/img/flags/{slug}.png
 */
function flag_url(string $teamName, string $assetFsBase, string $assetWebBase): ?string {
	$slug = flag_slug($teamName);
	if ($slug === "") return null;

	$fsPath = rtrim($assetFsBase, "/") . "/img/flags/" . $slug . ".png";
	if (is_file($fsPath)) {
		$webBase = ($assetWebBase === "" ? "" : $assetWebBase);
		return $webBase . "/img/flags/" . $slug . ".png";
	}
	return null;
}

/**
 * Converte string DATETIME do MySQL -> DateTimeImmutable no timezone oficial.
 * Retorna null se não conseguir interpretar.
 */
function dt_from_mysql(?string $dt): ?DateTimeImmutable {
	if (!$dt) return null;
	try {
		$tz = new DateTimeZone('America/Sao_Paulo');
		$parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
		if ($parsed instanceof DateTimeImmutable) return $parsed;

		$parsed2 = new DateTimeImmutable($dt, $tz);
		return $parsed2;
	} catch (Throwable $e) {
		return null;
	}
}

/**
 * “Dia lógico” de apostas:
 * - 00:00 a 04:59 pertencem ao dia anterior
 * - 05:00 em diante pertencem ao mesmo dia
 * Retorna string Y-m-d no timezone da aplicação.
 */
function logical_bet_day(DateTimeImmutable $dt): string {
	$hour = (int)$dt->format('H');
	if ($hour >= 0 && $hour < 5) {
		return $dt->sub(new DateInterval('P1D'))->format('Y-m-d');
	}
	return $dt->format('Y-m-d');
}

/**
 * Calcula o instante de bloqueio de um “dia lógico” específico:
 * - 1h antes do PRIMEIRO jogo do dia lógico
 * - “primeiro jogo do dia lógico” é o MIN(data_hora) considerando:
 *     * jogos no próprio dia com hora >= 05:00
 *     * jogos na madrugada do dia seguinte (00:00–04:59), que “pertencem” ao dia
 *
 * Retorna null se não existir jogo naquele dia lógico.
 *
 * ✅ FIX CRÍTICO:
 * Alguns ambientes com PDO MySQL não aceitam o MESMO placeholder nomeado repetido na query.
 * Por isso usamos :day1 e :day2 (evita SQLSTATE[HY093]).
 */
function compute_lock_for_logical_day(PDO $pdo, string $dayYmd): ?DateTimeImmutable {
	$sql = "
		SELECT MIN(j.data_hora)
		FROM jogos j
		INNER JOIN edicoes e ON e.id = j.edicao_id AND e.ativo = 1
		WHERE j.grupo_id IS NOT NULL
		  AND (j.fase = 'GRUPOS' OR j.fase = 'GRUPO' OR j.fase = 'FASE_DE_GRUPOS' OR j.fase LIKE '%GRUP%')
		  AND (
				(DATE(j.data_hora) = :day1 AND TIME(j.data_hora) >= '05:00:00')
			 OR (DATE(j.data_hora) = DATE_ADD(:day2, INTERVAL 1 DAY) AND TIME(j.data_hora) < '05:00:00')
		  )
	";
	$st = $pdo->prepare($sql);
	$st->execute([
		":day1" => $dayYmd,
		":day2" => $dayYmd,
	]);
	$minDt = $st->fetchColumn();

	$first = dt_from_mysql(is_string($minDt) ? $minDt : null);
	if (!$first) return null;

	return $first->sub(new DateInterval('PT1H'));
}

/**
 * Resolve (com cache) o lockAt para um dia lógico.
 */
function get_lock_for_logical_day(PDO $pdo, array &$cache, string $logicalDayYmd): ?DateTimeImmutable {
	if (array_key_exists($logicalDayYmd, $cache)) {
		$val = $cache[$logicalDayYmd];
		return ($val instanceof DateTimeImmutable) ? $val : null;
	}
	$lockAt = compute_lock_for_logical_day($pdo, $logicalDayYmd);
	$cache[$logicalDayYmd] = $lockAt;
	return $lockAt;
}

/**
 * Decide se um jogo está bloqueado e por quê, seguindo as regras (dia lógico).
 */
function lock_reason_for_game(
	?DateTimeImmutable $gameDt,
	DateTimeImmutable $now,
	PDO $pdo,
	array &$lockCache
): ?string {
	if (!$gameDt) {
		return "Data/hora inválida do jogo.";
	}

	if ($gameDt <= $now) {
		return "Jogo já iniciado/encerrado.";
	}

	$logicalDay = logical_bet_day($gameDt);
	$lockAt = get_lock_for_logical_day($pdo, $lockCache, $logicalDay);

	if ($lockAt instanceof DateTimeImmutable) {
		if ($now >= $lockAt) {
			return "Apostas do dia bloqueadas desde " . fmt_hm($lockAt) . ".";
		}
	}

	return null;
}

/*
|--------------------------------------------------------------------------
| URLs padrão (sem hardcode /bolao-da-copa/...)
|--------------------------------------------------------------------------
*/
$LOGIN_URL   = ($PAGE_WEB_BASE === "" ? "" : $PAGE_WEB_BASE) . "/index.php";
$APP_URL     = ($PAGE_WEB_BASE === "" ? "" : $PAGE_WEB_BASE) . "/app.php";
$CHAMP_URL   = ($PAGE_WEB_BASE === "" ? "" : $PAGE_WEB_BASE) . "/campeao.php";
$LOGOUT_URL  = $APP_URL . "?action=logout";

$SAVE_GAMES_URL      = $APP_URL . "?action=save";
$SAVE_GROUP_RANK_URL = $APP_URL . "?action=save_group_rank";
$RECIBO_URL          = ($PHP_WEB_BASE === "" ? "" : $PHP_WEB_BASE) . "/recibo.php?action=pdf";

require_login($LOGIN_URL);

$usuarioId   = (int)($_SESSION["usuario_id"] ?? 0);
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";

$tipoUsuario = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoUsuario, "UTF-8") === "ADMIN");

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$nowLogicalDay = logical_bet_day($now);

// cache global por request (dia lógico -> lockAt|null)
$lockCache = [];

/* ---------------------------
   Logout
--------------------------- */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
	session_destroy();
	header("Location: " . $LOGIN_URL);
	exit;
}

/* ---------------------------
   API: salvar palpites jogos (JSON)
--------------------------- */
if (isset($_GET["action"]) && $_GET["action"] === "save") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		json_response(["ok" => false, "message" => "Método inválido."], 405);
	}

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);

	if (!is_array($payload)) {
		json_response(["ok" => false, "message" => "JSON inválido."], 400);
	}

	$items = $payload["items"] ?? null;
	if (!is_array($items) || count($items) === 0) {
		json_response(["ok" => false, "message" => "Nada para salvar."], 400);
	}

	$normalized = [];
	foreach ($items as $it) {
		$jogoId = isset($it["jogo_id"]) ? (int)$it["jogo_id"] : 0;

		$gc = $it["gols_casa"] ?? null;
		$gf = $it["gols_fora"] ?? null;

		$gc = ($gc === "" || $gc === null) ? null : (int)$gc;
		$gf = ($gf === "" || $gf === null) ? null : (int)$gf;

		if ($jogoId <= 0) continue;
		if ($gc === null || $gf === null) continue;
		if ($gc < 0 || $gc > 99 || $gf < 0 || $gf > 99) continue;

		$normalized[] = ["jogo_id" => $jogoId, "gols_casa" => $gc, "gols_fora" => $gf];
	}

	if (count($normalized) === 0) {
		json_response(["ok" => false, "message" => "Preencha os placares antes de salvar."], 422);
	}

	try {
		$pdo->beginTransaction();

		$sqlCheck = "
            SELECT j.id, j.data_hora
            FROM jogos j
            INNER JOIN edicoes e ON e.id = j.edicao_id AND e.ativo = 1
            WHERE j.id = :jogo_id
              AND j.grupo_id IS NOT NULL
              AND (j.fase = 'GRUPOS' OR j.fase = 'GRUPO' OR j.fase = 'FASE_DE_GRUPOS' OR j.fase LIKE '%GRUP%')
            LIMIT 1
        ";
		$stCheck = $pdo->prepare($sqlCheck);

		$sqlUpsert = "
            INSERT INTO palpites (usuario_id, jogo_id, gols_casa, gols_fora)
            VALUES (:usuario_id, :jogo_id, :gols_casa, :gols_fora)
            ON DUPLICATE KEY UPDATE
              gols_casa = VALUES(gols_casa),
              gols_fora = VALUES(gols_fora),
              atualizado_em = CURRENT_TIMESTAMP
        ";
		$stUpsert = $pdo->prepare($sqlUpsert);

		$blocked = [];
		$saved = 0;

		foreach ($normalized as $row) {
			$stCheck->execute([":jogo_id" => $row["jogo_id"]]);
			$game = $stCheck->fetch(PDO::FETCH_ASSOC);

			if (!is_array($game) || empty($game["id"])) {
				$blocked[] = [
					"jogo_id" => $row["jogo_id"],
					"reason"  => "Jogo inválido (não é fase de grupos/edição ativa)."
				];
				continue;
			}

			$gameDt = dt_from_mysql(isset($game["data_hora"]) ? (string)$game["data_hora"] : null);
			$reason = lock_reason_for_game($gameDt, $now, $pdo, $lockCache);

			if ($reason !== null) {
				$blocked[] = [
					"jogo_id" => (int)$row["jogo_id"],
					"reason"  => $reason,
				];
				continue;
			}

			$stUpsert->execute([
				":usuario_id" => $usuarioId,
				":jogo_id"    => $row["jogo_id"],
				":gols_casa"  => $row["gols_casa"],
				":gols_fora"  => $row["gols_fora"],
			]);
			$saved++;
		}

		if (count($blocked) > 0) {
			if ($pdo->inTransaction()) $pdo->rollBack();

			$firstMsg = $blocked[0]["reason"] ?? "Apostas bloqueadas.";
			json_response([
				"ok" => false,
				"message" => $firstMsg,
				"blocked_count" => count($blocked),
				"blocked" => $blocked,
			], 423);
		}

		$pdo->commit();

		if ($saved <= 0) {
			json_response(["ok" => false, "message" => "Nenhum palpite foi salvo (verifique se são jogos da fase de grupos)."], 422);
		}

		json_response(["ok" => true, "saved" => $saved, "message" => "Palpites salvos com sucesso."]);
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar palpites."], 500);
	}
}

/* ---------------------------
   API: salvar classificação do grupo (1º/2º/3º) (JSON)
   - tabela real: palpite_grupo_classificacao (1 linha por usuario_id+grupo_id)
   - colunas: primeiro_time_id, segundo_time_id, terceiro_time_id
--------------------------- */
if (isset($_GET["action"]) && $_GET["action"] === "save_group_rank") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		json_response(["ok" => false, "message" => "Método inválido."], 405);
	}

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);
	if (!is_array($payload)) {
		json_response(["ok" => false, "message" => "JSON inválido."], 400);
	}

	$grupoId = isset($payload["grupo_id"]) ? (int)$payload["grupo_id"] : 0;
	$picks   = $payload["picks"] ?? null;

	if ($grupoId <= 0 || !is_array($picks)) {
		json_response(["ok" => false, "message" => "Payload inválido."], 422);
	}

	$pos1 = isset($picks["1"]) ? (int)$picks["1"] : 0;
	$pos2 = isset($picks["2"]) ? (int)$picks["2"] : 0;
	$pos3 = isset($picks["3"]) ? (int)$picks["3"] : 0;

	// Seu banco exige NOT NULL e FK -> não aceita 0
	if ($pos1 <= 0 || $pos2 <= 0 || $pos3 <= 0) {
		json_response([
			"ok" => false,
			"message" => "Você precisa escolher 1º, 2º e 3º antes de salvar."
		], 422);
	}

	if ($pos1 === $pos2 || $pos1 === $pos3 || $pos2 === $pos3) {
		json_response(["ok" => false, "message" => "Não pode repetir o mesmo time em 1º/2º/3º."], 422);
	}

	try {
		$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
		if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

		$stGrupo = $pdo->prepare("SELECT id FROM grupos WHERE id = :gid AND edicao_id = :eid LIMIT 1");
		$stGrupo->execute([":gid" => $grupoId, ":eid" => $edicaoId]);
		$gidOk = (int)$stGrupo->fetchColumn();
		if ($gidOk <= 0) {
			json_response(["ok" => false, "message" => "Grupo inválido."], 422);
		}

		// valida times pertencem ao grupo
		$ids = [$pos1, $pos2, $pos3];
		$in = implode(',', array_fill(0, count($ids), '?'));

		$sqlVal = "
			SELECT COUNT(*)
			FROM grupo_time gt
			WHERE gt.grupo_id = ?
			  AND gt.time_id IN ($in)
		";
		$params = array_merge([$grupoId], $ids);

		$stVal = $pdo->prepare($sqlVal);
		$stVal->execute($params);
		$cnt = (int)$stVal->fetchColumn();

		if ($cnt !== count($ids)) {
			json_response(["ok" => false, "message" => "Um ou mais times não pertencem a este grupo."], 422);
		}

		$pdo->beginTransaction();

		$sqlUp = "
			INSERT INTO palpite_grupo_classificacao
				(edicao_id, grupo_id, usuario_id, primeiro_time_id, segundo_time_id, terceiro_time_id)
			VALUES
				(:eid, :gid, :uid, :t1, :t2, :t3)
			ON DUPLICATE KEY UPDATE
				edicao_id = VALUES(edicao_id),
				primeiro_time_id = VALUES(primeiro_time_id),
				segundo_time_id  = VALUES(segundo_time_id),
				terceiro_time_id = VALUES(terceiro_time_id),
				atualizado_em = CURRENT_TIMESTAMP
		";
		$stUp = $pdo->prepare($sqlUp);
		$stUp->execute([
			":eid" => $edicaoId,
			":gid" => $grupoId,
			":uid" => $usuarioId,
			":t1"  => $pos1,
			":t2"  => $pos2,
			":t3"  => $pos3,
		]);

		$pdo->commit();

		json_response([
			"ok" => true,
			"message" => "Classificação do grupo salva.",
		]);
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar classificação do grupo."], 500);
	}
}

/* ---------------------------
   HTML: carregar grupos + jogos + times por grupo + picks salvos
--------------------------- */
try {
	$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
	if ($edicaoId <= 0) {
		throw new RuntimeException("Nenhuma edição ativa.");
	}

	$lockNowLogicalDayAt = get_lock_for_logical_day($pdo, $lockCache, $nowLogicalDay);
	$lockNowLogicalDayActive = ($lockNowLogicalDayAt instanceof DateTimeImmutable) ? ($now >= $lockNowLogicalDayAt) : false;

	$sqlGrupos = "
        SELECT g.id, g.codigo, COALESCE(g.nome, CONCAT('Grupo ', g.codigo)) AS nome
        FROM grupos g
        WHERE g.edicao_id = :edicao_id
        ORDER BY g.codigo
    ";
	$stGrupos = $pdo->prepare($sqlGrupos);
	$stGrupos->execute([":edicao_id" => $edicaoId]);
	$grupos = $stGrupos->fetchAll(PDO::FETCH_ASSOC);

	$sqlJogos = "
        SELECT
            j.id,
            j.codigo_fifa,
            j.grupo_id,
            g.codigo AS grupo_codigo,
            j.rodada,
            j.data_hora,
            j.status,
            tc.nome AS casa_nome,
            tc.sigla AS casa_sigla,
            tf.nome AS fora_nome,
            tf.sigla AS fora_sigla,
            j.zebra_time_id,
            p.gols_casa AS palpite_casa,
            p.gols_fora AS palpite_fora
        FROM jogos j
        INNER JOIN grupos g
            ON g.id = j.grupo_id
           AND g.edicao_id = :edicao_id1
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        LEFT JOIN palpites p
            ON p.jogo_id = j.id
           AND p.usuario_id = :usuario_id
        WHERE j.edicao_id = :edicao_id2
          AND j.grupo_id IS NOT NULL
          AND (j.fase = 'GRUPOS' OR j.fase = 'GRUPO' OR j.fase = 'FASE_DE_GRUPOS' OR j.fase LIKE '%GRUP%')
        ORDER BY g.codigo, j.rodada, j.data_hora, j.id
    ";
	$stJogos = $pdo->prepare($sqlJogos);
	$stJogos->execute([
		":edicao_id1" => $edicaoId,
		":edicao_id2" => $edicaoId,
		":usuario_id" => $usuarioId,
	]);
	$jogos = $stJogos->fetchAll(PDO::FETCH_ASSOC);

	$jogosPorGrupo = [];
	foreach ($jogos as $j) {
		$gc = (string)$j["grupo_codigo"];
		if (!isset($jogosPorGrupo[$gc])) $jogosPorGrupo[$gc] = [];
		$jogosPorGrupo[$gc][] = $j;
	}

	// mapa: grupo -> próximo/anteriores grupos com jogos
	$codigos = array_map(static fn($g) => (string)$g["codigo"], $grupos);

	$temJogos = [];
	foreach ($codigos as $c) {
		$temJogos[$c] = isset($jogosPorGrupo[$c]) && count($jogosPorGrupo[$c]) > 0;
	}

	$nextGrupo = [];
	$prevGrupo = [];

	$len = count($codigos);
	for ($i = 0; $i < $len; $i++) {
		$c = $codigos[$i];

		$next = null;
		for ($j = $i + 1; $j < $len; $j++) {
			$c2 = $codigos[$j];
			if (!empty($temJogos[$c2])) {
				$next = $c2;
				break;
			}
		}
		$nextGrupo[$c] = $next;

		$prev = null;
		for ($j = $i - 1; $j >= 0; $j--) {
			$c2 = $codigos[$j];
			if (!empty($temJogos[$c2])) {
				$prev = $c2;
				break;
			}
		}
		$prevGrupo[$c] = $prev;
	}

	// Times por grupo (para os combobox 1º/2º/3º)
	$timesPorGrupoId = [];
	if (count($grupos) > 0) {
		$grupoIds = array_map(static fn($g) => (int)$g["id"], $grupos);
		$in = implode(',', array_fill(0, count($grupoIds), '?'));

		$sqlTimes = "
			SELECT gt.grupo_id, t.id AS time_id, t.nome AS time_nome, t.sigla AS time_sigla
			FROM grupo_time gt
			INNER JOIN times t ON t.id = gt.time_id
			WHERE gt.grupo_id IN ($in)
			ORDER BY gt.grupo_id, t.nome
		";
		$stTimes = $pdo->prepare($sqlTimes);
		$stTimes->execute($grupoIds);
		$rowsTimes = $stTimes->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rowsTimes as $r) {
			$gid = (int)$r["grupo_id"];
			if (!isset($timesPorGrupoId[$gid])) $timesPorGrupoId[$gid] = [];
			$timesPorGrupoId[$gid][] = [
				"time_id" => (int)$r["time_id"],
				"time_nome" => (string)$r["time_nome"],
				"time_sigla" => (string)$r["time_sigla"],
			];
		}
	}

	// Picks salvos (palpite_grupo_classificacao)
	$picksPorGrupoId = []; // [grupo_id => [1=>time_id,2=>time_id,3=>time_id]]
	if (count($grupos) > 0) {
		$grupoIds = array_map(static fn($g) => (int)$g["id"], $grupos);
		$in = implode(',', array_fill(0, count($grupoIds), '?'));

		$sqlPicks = "
			SELECT grupo_id, primeiro_time_id, segundo_time_id, terceiro_time_id
			FROM palpite_grupo_classificacao
			WHERE usuario_id = ?
			  AND grupo_id IN ($in)
		";
		$params = array_merge([$usuarioId], $grupoIds);

		$stPicks = $pdo->prepare($sqlPicks);
		$stPicks->execute($params);
		$rowsPicks = $stPicks->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rowsPicks as $r) {
			$gid = (int)$r["grupo_id"];
			if (!isset($picksPorGrupoId[$gid])) $picksPorGrupoId[$gid] = [];
			$picksPorGrupoId[$gid][1] = (int)$r["primeiro_time_id"];
			$picksPorGrupoId[$gid][2] = (int)$r["segundo_time_id"];
			$picksPorGrupoId[$gid][3] = (int)$r["terceiro_time_id"];
		}
	}

} catch (Throwable $e) {
	http_response_code(500);
	echo "<pre style='white-space:pre-wrap;font:14px/1.4 monospace'>";
	echo "Erro ao carregar jogos:\n\n";
	echo $e->getMessage() . "\n\n";
	echo $e->getFile() . ":" . $e->getLine() . "\n\n";
	echo $e->getTraceAsString();
	echo "</pre>";
	exit;
}

require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
	<meta charset="UTF-8" />
	<title>Bolão da Copa - Palpites</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<link rel="stylesheet" href="<?php echo strh($ASSET_WEB_BASE . "/css/app.css?v=" . (string)@filemtime($ASSET_FS_BASE . "/css/app.css")); ?>">
</head>
<body>

<div class="app-wrap">

	<?php
		render_app_header(
			$usuarioNome,
			$isAdmin,
			"apostas",
			"Fase de Grupos • Palpites",
			$LOGOUT_URL
		);
	?>

	<div class="app-shell">
		<aside class="app-menu">
			<div class="menu-title">Grupos</div>

			<div class="menu-list" id="menuGrupos">
				<?php foreach ($grupos as $g): ?>
					<?php
					$codigo = (string)$g["codigo"];
					$has = isset($jogosPorGrupo[$codigo]) && count($jogosPorGrupo[$codigo]) > 0;
					?>
					<a class="menu-link <?php echo $has ? "" : "is-disabled"; ?>"
					   href="#"
					   data-grupo="<?php echo strh($codigo); ?>"
					   <?php echo $has ? "" : "aria-disabled='true' tabindex='-1'"; ?>>
						<span class="menu-link-text">Grupo <?php echo strh($codigo); ?></span>
						<?php if ($has): ?>
							<span class="badge"><?php echo (int)count($jogosPorGrupo[$codigo]); ?></span>
						<?php else: ?>
							<span class="badge badge-muted">0</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>

				<a class="menu-link menu-link-champion"
				   href="<?php echo strh($CHAMP_URL); ?>"
				   title="Escolher o campeão">
					<span class="menu-link-text">Quem será o campeão</span>
					<span class="badge badge-champion">★</span>
				</a>
			</div>

			<div class="menu-actions">
				<button class="btn-save-all" id="btnSalvarTudo" type="button">
					Salvar tudo
					<span class="kbd">Ctrl</span><span class="kbd">↵</span>
				</button>

				<button class="btn-receipt" id="btnRecibo" type="button" data-receipt-url="<?php echo strh($RECIBO_URL); ?>">
					Recibo
				</button>

				<div class="hint">
					Dica: preencha os placares e salve. Você também pode salvar jogo a jogo.
					<?php if ($lockNowLogicalDayAt instanceof DateTimeImmutable): ?>
						<br>
						<small>
							Trava do dia (lógico) às <strong><?php echo strh(fmt_hm($lockNowLogicalDayAt)); ?></strong>
							(1h antes do primeiro jogo do dia).
						</small>
					<?php endif; ?>
				</div>
			</div>
		</aside>

		<main class="app-content">
			<div class="content-head">
				<h1 class="content-h1">Seus palpites</h1>
				<p class="content-sub">Preencha o placar de cada jogo. E no fim do grupo, escolha livremente 1º/2º/3º.</p>
			</div>

			<?php if (count($grupos) === 0): ?>
				<div class="placeholder">Nenhum grupo encontrado na edição ativa.</div>
			<?php else: ?>

				<?php foreach ($grupos as $g): ?>
					<?php
					$grupoId = (int)$g["id"];
					$codigo = (string)$g["codigo"];
					$nome = (string)$g["nome"];
					$lista = $jogosPorGrupo[$codigo] ?? [];

					$defaultNome = "Grupo " . $codigo;
					$nomeCustom = trim($nome);
					$showCustom = ($nomeCustom !== "" && mb_strtoupper($nomeCustom, "UTF-8") !== mb_strtoupper($defaultNome, "UTF-8"));

					$prox = $nextGrupo[$codigo] ?? null;
					$ant  = $prevGrupo[$codigo] ?? null;

					$hasNext = ($prox !== null);
					$hasPrev = ($ant  !== null);

					$timesGrupo = $timesPorGrupoId[$grupoId] ?? [];
					$picks = $picksPorGrupoId[$grupoId] ?? [];
					$pick1 = isset($picks[1]) ? (int)$picks[1] : 0;
					$pick2 = isset($picks[2]) ? (int)$picks[2] : 0;
					$pick3 = isset($picks[3]) ? (int)$picks[3] : 0;
					?>
					<section class="group-block" data-grupo="<?php echo strh($codigo); ?>" data-grupo-id="<?php echo (int)$grupoId; ?>">
						<div class="group-head">
							<div class="group-title">
								<div class="group-line">
									<div class="group-pill">Grupo <?php echo strh($codigo); ?></div>
									<div class="group-count"><?php echo count($lista); ?> jogos</div>
								</div>
								<?php if ($showCustom): ?>
									<div class="group-name"><?php echo strh($nomeCustom); ?></div>
								<?php endif; ?>
							</div>
						</div>

						<?php if (count($lista) === 0): ?>
							<div class="placeholder">Sem jogos cadastrados para este grupo.</div>
						<?php else: ?>

							<div class="matches">
								<?php foreach ($lista as $j): ?>
									<?php
									$jid = (int)$j["id"];
									$casa = (string)$j["casa_nome"];
									$fora = (string)$j["fora_nome"];
									$csig = (string)$j["casa_sigla"];
									$fsig = (string)$j["fora_sigla"];

									$codigoFifa = isset($j["codigo_fifa"]) ? trim((string)$j["codigo_fifa"]) : "";

									$dtGame = dt_from_mysql((string)$j["data_hora"]);
									$lockReason = lock_reason_for_game($dtGame, $now, $pdo, $lockCache);
									$isLocked = ($lockReason !== null);

									$dh = fmt_datahora((string)$j["data_hora"]);
									$rodada = $j["rodada"] !== null ? (int)$j["rodada"] : null;

									$pc = $j["palpite_casa"];
									$pf = $j["palpite_fora"];

									$pcVal = ($pc === null) ? "" : (string)(int)$pc;
									$pfVal = ($pf === null) ? "" : (string)(int)$pf;

									$flagCasa = flag_url($casa, $ASSET_FS_BASE, $ASSET_WEB_BASE);
									$flagFora = flag_url($fora, $ASSET_FS_BASE, $ASSET_WEB_BASE);
									?>
									<article class="match-card <?php echo $isLocked ? "is-locked" : ""; ?>"
											 data-jogo-id="<?php echo $jid; ?>"
											 data-grupo="<?php echo strh($codigo); ?>"
											 data-when="<?php echo strh($dh); ?>"
											 data-home="<?php echo strh($casa); ?>"
											 data-away="<?php echo strh($fora); ?>"
											 data-fifa="<?php echo strh($codigoFifa); ?>"
											 data-locked="<?php echo $isLocked ? "1" : "0"; ?>">
										<div class="match-top">
											<div class="match-when">
												<span class="when"><?php echo strh($dh); ?></span>
												<?php if ($rodada !== null): ?>
													<span class="round">Rodada <?php echo $rodada; ?></span>
												<?php endif; ?>
												<?php if ($codigoFifa !== ""): ?>
													<span class="round">FIFA <?php echo strh($codigoFifa); ?></span>
												<?php endif; ?>
											</div>

											<div class="match-status status-<?php echo strh((string)$j["status"]); ?>">
												<?php echo strh((string)$j["status"]); ?>
											</div>
										</div>

										<div class="match-body">
											<div class="team team-home">
												<div class="team-name"><?php echo strh($casa); ?></div>

												<?php if ($flagCasa !== null): ?>
													<img
														class="team-flag"
														src="<?php echo strh($flagCasa); ?>"
														alt="Bandeira <?php echo strh($casa); ?>"
														width="36" height="36"
														loading="lazy" decoding="async"
														style="width:36px;height:36px;border-radius:14px;object-fit:cover;border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 18px rgba(0,0,0,.22);"
													>
												<?php else: ?>
													<div class="team-badge"><?php echo strh($csig); ?></div>
												<?php endif; ?>
											</div>

											<div class="scorebox">
												<input class="score score-home" type="number" inputmode="numeric" min="0" max="99"
													   value="<?php echo strh($pcVal); ?>"
													   placeholder="0" aria-label="Gols casa"
													   <?php echo $isLocked ? "disabled" : ""; ?>>
												<div class="x">×</div>
												<input class="score score-away" type="number" inputmode="numeric" min="0" max="99"
													   value="<?php echo strh($pfVal); ?>"
													   placeholder="0" aria-label="Gols fora"
													   <?php echo $isLocked ? "disabled" : ""; ?>>
											</div>

											<div class="team team-away">
												<?php if ($flagFora !== null): ?>
													<img
														class="team-flag"
														src="<?php echo strh($flagFora); ?>"
														alt="Bandeira <?php echo strh($fora); ?>"
														width="36" height="36"
														loading="lazy" decoding="async"
														style="width:36px;height:36px;border-radius:14px;object-fit:cover;border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 18px rgba(0,0,0,.22);"
													>
												<?php else: ?>
													<div class="team-badge"><?php echo strh($fsig); ?></div>
												<?php endif; ?>

												<div class="team-name"><?php echo strh($fora); ?></div>
											</div>
										</div>

										<div class="match-actions">
											<button class="btn-save-one" type="button" title="Salvar este jogo" <?php echo $isLocked ? "disabled" : ""; ?>>
												<?php echo $isLocked ? "Bloqueado" : "Salvar"; ?>
											</button>
											<div class="save-state" aria-live="polite">
												<?php if ($isLocked): ?>
													<span class="lock-reason"><?php echo strh($lockReason); ?></span>
												<?php endif; ?>
											</div>
										</div>
									</article>
								<?php endforeach; ?>
							</div>

							<div class="group-rank-card" data-grupo-rank="<?php echo (int)$grupoId; ?>">
								<div class="group-rank-head">
									<div class="group-rank-title">Classificação do grupo</div>
									<div class="group-rank-sub">Escolha livremente (independe dos placares).</div>
								</div>

								<?php if (count($timesGrupo) === 0): ?>
									<div class="group-rank-empty">Sem times vinculados a este grupo (grupo_time).</div>
								<?php else: ?>
									<div class="group-rank-grid">
										<div class="rank-field">
											<label>1º</label>
											<select class="rank-select" data-rank-pos="1">
												<option value="0"><?php echo strh("—"); ?></option>
												<?php foreach ($timesGrupo as $t): ?>
													<?php
													$tid = (int)$t["time_id"];
													$tname = (string)$t["time_nome"];
													?>
													<option value="<?php echo (int)$tid; ?>" <?php echo ($tid === $pick1) ? "selected" : ""; ?>>
														<?php echo strh($tname); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>2º</label>
											<select class="rank-select" data-rank-pos="2">
												<option value="0"><?php echo strh("—"); ?></option>
												<?php foreach ($timesGrupo as $t): ?>
													<?php
													$tid = (int)$t["time_id"];
													$tname = (string)$t["time_nome"];
													?>
													<option value="<?php echo (int)$tid; ?>" <?php echo ($tid === $pick2) ? "selected" : ""; ?>>
														<?php echo strh($tname); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>3º</label>
											<select class="rank-select" data-rank-pos="3">
												<option value="0"><?php echo strh("—"); ?></option>
												<?php foreach ($timesGrupo as $t): ?>
													<?php
													$tid = (int)$t["time_id"];
													$tname = (string)$t["time_nome"];
													?>
													<option value="<?php echo (int)$tid; ?>" <?php echo ($tid === $pick3) ? "selected" : ""; ?>>
														<?php echo strh($tname); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>

									<div class="group-rank-actions">
										<button class="btn-rank-save" type="button">Salvar classificação</button>
										<div class="rank-state" aria-live="polite"></div>
									</div>
								<?php endif; ?>
							</div>

							<?php if ($hasPrev || $hasNext): ?>
								<div class="group-nav">
									<?php if ($hasPrev): ?>
										<button class="btn-prev-group" type="button" data-prev-grupo="<?php echo strh((string)$ant); ?>">
											Anterior <span class="muted">(Grupo <?php echo strh((string)$ant); ?>)</span>
										</button>
									<?php else: ?>
										<div></div>
									<?php endif; ?>

									<?php if ($hasNext): ?>
										<button class="btn-next-group" type="button" data-next-grupo="<?php echo strh((string)$prox); ?>">
											Próximo <span class="muted">(Grupo <?php echo strh((string)$prox); ?>)</span>
										</button>
									<?php else: ?>
										<button class="btn-next-group btn-go-champion" type="button" data-champion-url="<?php echo strh($CHAMP_URL); ?>">
											Quem será o campeão <span class="muted">(salva antes)</span>
										</button>
									<?php endif; ?>
								</div>
							<?php endif; ?>

						<?php endif; ?>
					</section>
				<?php endforeach; ?>

			<?php endif; ?>
		</main>
	</div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

<script type="application/json" id="app-config">
<?php echo json_encode([
	"user" => [
		"nome" => $usuarioNome,
		"id"   => $usuarioId,
	],
	"lock" => [
		"now_logical_day" => $nowLogicalDay,
		"lock_logical_day_at" => ($lockNowLogicalDayAt instanceof DateTimeImmutable) ? $lockNowLogicalDayAt->format('Y-m-d H:i:s') : null,
		"lock_logical_day_active" => (bool)$lockNowLogicalDayActive,
		"logical_day_rule" => "00:00-04:59 pertence ao dia anterior",
	],
	"endpoints" => [
		"save_games" => $SAVE_GAMES_URL,
		"save_group_rank" => $SAVE_GROUP_RANK_URL,
		"receipt_url" => $RECIBO_URL,
	],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<script src="<?php echo strh($ASSET_WEB_BASE . "/js/app.js"); ?>"></script>

<div id="popupJogos" class="popup-overlay" style="display:none;">
  <div class="popup-card">
    <h2>Jogos de Hoje</h2>
    <div id="popupLista"></div>
    <button onclick="fecharPopup()">Fechar</button>
  </div>
</div>

</body>
</html>