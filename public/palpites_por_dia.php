<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

/*
|--------------------------------------------------------------------------
| AUTO-BASE
|--------------------------------------------------------------------------
*/
$SCRIPT_DIR = str_replace('\\', '/', (string)dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$WEB_BASE = rtrim($SCRIPT_DIR, '/');
if ($WEB_BASE === '/') $WEB_BASE = '';

$IS_ROOT_LAYOUT = is_dir(__DIR__ . '/public') && is_file(__DIR__ . '/public/css/app.css');

$ASSET_FS_BASE  = $IS_ROOT_LAYOUT ? (__DIR__ . '/public') : __DIR__;
$ASSET_WEB_BASE = $IS_ROOT_LAYOUT
	? (($WEB_BASE === '' ? '' : $WEB_BASE) . '/public')
	: ($WEB_BASE === '' ? '' : $WEB_BASE);

$PAGE_WEB_BASE = ($WEB_BASE === '' ? '' : $WEB_BASE);

if (preg_match('#/public$#', $WEB_BASE)) {
	$PHP_WEB_BASE = preg_replace('#/public$#', '', $WEB_BASE) . '/php';
} else {
	$PHP_WEB_BASE = ($WEB_BASE === '' ? '' : $WEB_BASE) . '/php';
}

$CONEXAO_PATH_1 = __DIR__ . "/../php/conexao.php";
$CONEXAO_PATH_2 = __DIR__ . "/php/conexao.php";

if (is_file($CONEXAO_PATH_1)) {
	require_once $CONEXAO_PATH_1;
} else {
	require_once $CONEXAO_PATH_2;
}

date_default_timezone_set('America/Sao_Paulo');

/* ============================================================================
 * HELPERS
 * ==========================================================================*/
function json_response(array $data, int $code = 200): void {
	http_response_code($code);
	header("Content-Type: application/json; charset=utf-8");
	header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
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

function get_pdo(): PDO {
	if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
		return $GLOBALS['pdo'];
	}

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

	throw new RuntimeException("Não foi possível obter PDO. Verifique o conexao.php.");
}

function fmt_datahora(string $dt): string {
	$ts = strtotime($dt);
	if ($ts === false) return $dt;
	return date("d/m H:i", $ts);
}

function fmt_hm(DateTimeInterface $dt): string {
	return $dt->format('H:i');
}

function fmt_day_menu_label(string $ymd): string {
	$dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, new DateTimeZone('America/Sao_Paulo'));
	if (!$dt) return $ymd;

	$dias = [
		'Sun' => 'Dom',
		'Mon' => 'Seg',
		'Tue' => 'Ter',
		'Wed' => 'Qua',
		'Thu' => 'Qui',
		'Fri' => 'Sex',
		'Sat' => 'Sáb',
	];

	$dw = $dias[$dt->format('D')] ?? $dt->format('D');
	return $dw . ' • ' . $dt->format('d/m');
}

function fmt_day_title(string $ymd): string {
	$dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, new DateTimeZone('America/Sao_Paulo'));
	if (!$dt) return $ymd;

	$meses = [
		1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
		5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
		9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
	];

	$dias = [
		'Sun' => 'Domingo',
		'Mon' => 'Segunda',
		'Tue' => 'Terça',
		'Wed' => 'Quarta',
		'Thu' => 'Quinta',
		'Fri' => 'Sexta',
		'Sat' => 'Sábado',
	];

	$dw = $dias[$dt->format('D')] ?? $dt->format('D');
	$mes = $meses[(int)$dt->format('n')] ?? $dt->format('m');

	return $dw . ', ' . $dt->format('d') . ' de ' . $mes;
}

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
 * 00:00 a 04:59 pertence ao dia anterior
 */
function logical_bet_day(DateTimeImmutable $dt): string {
	$hour = (int)$dt->format('H');
	if ($hour >= 0 && $hour < 5) {
		return $dt->sub(new DateInterval('P1D'))->format('Y-m-d');
	}
	return $dt->format('Y-m-d');
}

function phase_labels(): array {
	return [
		'GRUPOS'         => 'Fase de grupos',
		'GRUPO'          => 'Fase de grupos',
		'FASE_DE_GRUPOS' => 'Fase de grupos',
		'16_DE_FINAL'    => '16 de final',
		'OITAVAS'        => 'Oitavas',
		'QUARTAS'        => 'Quartas',
		'SEMI'           => 'Semifinal',
		'TERCEIRO_LUGAR' => '3º lugar',
		'FINAL'          => 'Final',
	];
}

function knockout_phases(): array {
	return [
		'16_DE_FINAL',
		'OITAVAS',
		'QUARTAS',
		'SEMI',
		'TERCEIRO_LUGAR',
		'FINAL',
	];
}

function is_group_phase_row(array $row): bool {
	$grupoId = isset($row['grupo_id']) ? (int)$row['grupo_id'] : 0;
	$fase = mb_strtoupper(trim((string)($row['fase'] ?? '')), 'UTF-8');

	return $grupoId > 0 && (
		$fase === 'GRUPOS' ||
		$fase === 'GRUPO' ||
		$fase === 'FASE_DE_GRUPOS' ||
		strpos($fase, 'GRUP') !== false
	);
}

function is_knockout_phase_row(array $row): bool {
	$grupoId = isset($row['grupo_id']) ? (int)$row['grupo_id'] : 0;
	$fase = mb_strtoupper(trim((string)($row['fase'] ?? '')), 'UTF-8');

	return $grupoId <= 0 && in_array($fase, knockout_phases(), true);
}

function compute_lock_for_logical_day_all(PDO $pdo, string $dayYmd): ?DateTimeImmutable {
	$sql = "
		SELECT MIN(j.data_hora)
		FROM jogos j
		INNER JOIN edicoes e ON e.id = j.edicao_id AND e.ativo = 1
		WHERE (
			(DATE(j.data_hora) = :day1 AND TIME(j.data_hora) >= '05:00:00')
		 OR (DATE(j.data_hora) = DATE_ADD(:day2, INTERVAL 1 DAY) AND TIME(j.data_hora) < '05:00:00')
		)
	";
	$st = $pdo->prepare($sql);
	$st->execute([
		':day1' => $dayYmd,
		':day2' => $dayYmd,
	]);
	$minDt = $st->fetchColumn();

	$first = dt_from_mysql(is_string($minDt) ? $minDt : null);
	if (!$first) return null;

	return $first->sub(new DateInterval('PT1H'));
}

function get_lock_for_logical_day(PDO $pdo, array &$cache, string $logicalDayYmd): ?DateTimeImmutable {
	if (array_key_exists($logicalDayYmd, $cache)) {
		$val = $cache[$logicalDayYmd];
		return ($val instanceof DateTimeImmutable) ? $val : null;
	}
	$lockAt = compute_lock_for_logical_day_all($pdo, $logicalDayYmd);
	$cache[$logicalDayYmd] = $lockAt;
	return $lockAt;
}

function lock_reason_for_game(
	?DateTimeImmutable $gameDt,
	DateTimeImmutable $now,
	PDO $pdo,
	array &$lockCache
): ?string {
	if (!$gameDt) return "Data/hora inválida do jogo.";

	if ($gameDt <= $now) {
		return "Jogo já iniciado/encerrado.";
	}

	$logicalDay = logical_bet_day($gameDt);
	$lockAt = get_lock_for_logical_day($pdo, $lockCache, $logicalDay);

	if ($lockAt instanceof DateTimeImmutable && $now >= $lockAt) {
		return "Apostas do dia bloqueadas desde " . fmt_hm($lockAt) . ".";
	}

	return null;
}

function resolve_receipt_url(string $phpWebBase): ?string {
	$candidates = [
		['fs' => __DIR__ . '/../php/recibo_por_dia.php',   'web' => $phpWebBase . '/recibo_por_dia.php?action=pdf'],
		['fs' => __DIR__ . '/php/recibo_por_dia.php',      'web' => $phpWebBase . '/recibo_por_dia.php?action=pdf'],
		['fs' => __DIR__ . '/../php/recibo.php',           'web' => $phpWebBase . '/recibo.php?action=pdf'],
		['fs' => __DIR__ . '/php/recibo.php',              'web' => $phpWebBase . '/recibo.php?action=pdf'],
		['fs' => __DIR__ . '/../php/recibo_mata_mata.php', 'web' => $phpWebBase . '/recibo_mata_mata.php?action=pdf'],
		['fs' => __DIR__ . '/php/recibo_mata_mata.php',    'web' => $phpWebBase . '/recibo_mata_mata.php?action=pdf'],
	];

	foreach ($candidates as $c) {
		if (is_file($c['fs'])) return $c['web'];
	}
	return null;
}

/* ============================================================================
 * URLS
 * ==========================================================================*/
$LOGIN_URL  = ($PAGE_WEB_BASE === "" ? "" : $PAGE_WEB_BASE) . "/index.php";
$APP_URL    = ($PAGE_WEB_BASE === "" ? "" : $PAGE_WEB_BASE) . "/palpites_por_dia.php";
$CHAMP_URL  = ($PAGE_WEB_BASE === "" ? "" : $PAGE_WEB_BASE) . "/campeao.php";
$LOGOUT_URL = $APP_URL . "?action=logout";

$RECEIPT_URL = resolve_receipt_url($PHP_WEB_BASE);

require_login($LOGIN_URL);

$usuarioId   = (int)($_SESSION["usuario_id"] ?? 0);
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";

$tipoUsuario = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoUsuario, "UTF-8") === "ADMIN");

$pdo = get_pdo();

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$nowLogicalDay = logical_bet_day($now);
$lockCache = [];

/* ============================================================================
 * LOGOUT
 * ==========================================================================*/
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
	session_destroy();
	header("Location: " . $LOGIN_URL);
	exit;
}

/* ============================================================================
 * API SALVAR PALPITES (GRUPO + MATA-MATA)
 * ==========================================================================*/
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

		$passa = $it["passa_time_id"] ?? null;
		$passa = ($passa === "" || $passa === null) ? null : (int)$passa;

		if ($jogoId <= 0) continue;
		if ($gc === null || $gf === null) continue;
		if ($gc < 0 || $gc > 99 || $gf < 0 || $gf > 99) continue;

		$normalized[] = [
			"jogo_id" => $jogoId,
			"gols_casa" => $gc,
			"gols_fora" => $gf,
			"passa_time_id" => $passa,
		];
	}

	if (count($normalized) === 0) {
		json_response(["ok" => false, "message" => "Preencha os placares antes de salvar."], 422);
	}

	try {
		$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
		if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

		$sqlCheck = "
			SELECT
				j.id,
				j.data_hora,
				j.fase,
				j.grupo_id,
				j.time_casa_id,
				j.time_fora_id
			FROM jogos j
			WHERE j.id = :jogo_id
			  AND j.edicao_id = :edicao_id
			  AND (
					(j.grupo_id IS NOT NULL AND (
						j.fase = 'GRUPOS' OR
						j.fase = 'GRUPO' OR
						j.fase = 'FASE_DE_GRUPOS' OR
						j.fase LIKE '%GRUP%'
					))
				 OR
					(j.grupo_id IS NULL AND j.fase IN ('16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL'))
			  )
			LIMIT 1
		";
		$stCheck = $pdo->prepare($sqlCheck);

		$sqlUpsert = "
			INSERT INTO palpites (usuario_id, jogo_id, gols_casa, gols_fora, passa_time_id)
			VALUES (:usuario_id, :jogo_id, :gols_casa, :gols_fora, :passa_time_id)
			ON DUPLICATE KEY UPDATE
			  gols_casa = VALUES(gols_casa),
			  gols_fora = VALUES(gols_fora),
			  passa_time_id = VALUES(passa_time_id),
			  atualizado_em = CURRENT_TIMESTAMP
		";
		$stUpsert = $pdo->prepare($sqlUpsert);

		$pdo->beginTransaction();

		$blocked = [];
		$saved = 0;

		foreach ($normalized as $row) {
			$stCheck->execute([
				":jogo_id" => (int)$row["jogo_id"],
				":edicao_id" => $edicaoId,
			]);
			$game = $stCheck->fetch(PDO::FETCH_ASSOC);

			if (!is_array($game) || empty($game["id"])) {
				$blocked[] = [
					"jogo_id" => (int)$row["jogo_id"],
					"reason" => "Jogo inválido."
				];
				continue;
			}

			$gameDt = dt_from_mysql(isset($game["data_hora"]) ? (string)$game["data_hora"] : null);
			$reason = lock_reason_for_game($gameDt, $now, $pdo, $lockCache);
			if ($reason !== null) {
				$blocked[] = [
					"jogo_id" => (int)$row["jogo_id"],
					"reason" => $reason
				];
				continue;
			}

			$isKnockout = ((int)($game["grupo_id"] ?? 0) <= 0);

			$timeCasaId = (int)($game["time_casa_id"] ?? 0);
			$timeForaId = (int)($game["time_fora_id"] ?? 0);

			$gc = (int)$row["gols_casa"];
			$gf = (int)$row["gols_fora"];
			$empate = ($gc === $gf);

			$passa = $row["passa_time_id"];
			$passaInt = ($passa === null) ? 0 : (int)$passa;

			if ($isKnockout && $empate) {
				if ($passaInt <= 0) {
					$blocked[] = [
						"jogo_id" => (int)$row["jogo_id"],
						"reason" => "Empate: escolha quem passa."
					];
					continue;
				}
				if ($passaInt !== $timeCasaId && $passaInt !== $timeForaId) {
					$blocked[] = [
						"jogo_id" => (int)$row["jogo_id"],
						"reason" => "Empate: escolha um dos dois times do jogo."
					];
					continue;
				}
			} else {
				$passa = null;
			}

			$stUpsert->execute([
				":usuario_id" => $usuarioId,
				":jogo_id" => (int)$row["jogo_id"],
				":gols_casa" => $gc,
				":gols_fora" => $gf,
				":passa_time_id" => $passa,
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
			json_response(["ok" => false, "message" => "Nenhum palpite foi salvo."], 422);
		}

		json_response(["ok" => true, "saved" => $saved, "message" => "Palpites salvos com sucesso."]);
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar palpites."], 500);
	}
}

/* ============================================================================
 * API SALVAR CLASSIFICAÇÃO DO GRUPO
 * ==========================================================================*/
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

	if ($pos1 <= 0 || $pos2 <= 0 || $pos3 <= 0) {
		json_response(["ok" => false, "message" => "Você precisa escolher 1º, 2º e 3º antes de salvar."], 422);
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

/* ============================================================================
 * API SALVAR TOP 4
 * ==========================================================================*/
if (isset($_GET["action"]) && $_GET["action"] === "save_top4") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		json_response(["ok" => false, "message" => "Método inválido."], 405);
	}

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);
	if (!is_array($payload)) {
		json_response(["ok" => false, "message" => "JSON inválido."], 400);
	}

	$picks = $payload["picks"] ?? null;
	if (!is_array($picks)) {
		json_response(["ok" => false, "message" => "Payload inválido."], 422);
	}

	$t1 = isset($picks["1"]) ? (int)$picks["1"] : 0;
	$t2 = isset($picks["2"]) ? (int)$picks["2"] : 0;
	$t3 = isset($picks["3"]) ? (int)$picks["3"] : 0;
	$t4 = isset($picks["4"]) ? (int)$picks["4"] : 0;

	if ($t1 <= 0 || $t2 <= 0 || $t3 <= 0 || $t4 <= 0) {
		json_response(["ok" => false, "message" => "Você precisa escolher 1º, 2º, 3º e 4º antes de salvar."], 422);
	}
	if ($t1 === $t2 || $t1 === $t3 || $t1 === $t4 || $t2 === $t3 || $t2 === $t4 || $t3 === $t4) {
		json_response(["ok" => false, "message" => "Não pode repetir o mesmo time no Top 4."], 422);
	}

	try {
		$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
		if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

		$stGate = $pdo->prepare("SELECT COUNT(*) FROM jogos WHERE edicao_id = ? AND grupo_id IS NULL AND fase = 'SEMI'");
		$stGate->execute([$edicaoId]);
		$hasSemi = ((int)$stGate->fetchColumn() > 0);
		if (!$hasSemi) {
			json_response(["ok" => false, "message" => "Top 4 só libera após cadastrar os jogos da semifinal."], 423);
		}

		$stAllowed = $pdo->prepare("
			SELECT DISTINCT x.tid
			FROM (
				SELECT time_casa_id AS tid
				FROM jogos
				WHERE edicao_id = ? AND grupo_id IS NULL AND fase = 'SEMI'
				UNION ALL
				SELECT time_fora_id AS tid
				FROM jogos
				WHERE edicao_id = ? AND grupo_id IS NULL AND fase = 'SEMI'
			) x
		");
		$stAllowed->execute([$edicaoId, $edicaoId]);
		$allowed = $stAllowed->fetchAll(PDO::FETCH_COLUMN, 0);

		$allowedSet = [];
		foreach ($allowed as $aid) $allowedSet[(int)$aid] = true;

		$ids = [$t1, $t2, $t3, $t4];
		foreach ($ids as $tid) {
			if (!isset($allowedSet[(int)$tid])) {
				json_response(["ok" => false, "message" => "Top 4 deve ser escolhido apenas entre os times da semifinal."], 422);
			}
		}

		$pdo->beginTransaction();

		$sql = "
			INSERT INTO palpite_top4
				(edicao_id, usuario_id, primeiro_time_id, segundo_time_id, terceiro_time_id, quarto_time_id)
			VALUES
				(:eid, :uid, :t1, :t2, :t3, :t4)
			ON DUPLICATE KEY UPDATE
				primeiro_time_id = VALUES(primeiro_time_id),
				segundo_time_id  = VALUES(segundo_time_id),
				terceiro_time_id = VALUES(terceiro_time_id),
				quarto_time_id   = VALUES(quarto_time_id),
				atualizado_em = CURRENT_TIMESTAMP
		";
		$st = $pdo->prepare($sql);
		$st->execute([
			":eid" => $edicaoId,
			":uid" => $usuarioId,
			":t1"  => $t1,
			":t2"  => $t2,
			":t3"  => $t3,
			":t4"  => $t4,
		]);

		$pdo->commit();
		json_response(["ok" => true, "message" => "Top 4 salvo."]);
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar Top 4."], 500);
	}
}

/* ============================================================================
 * HTML LOAD
 * ==========================================================================*/
try {
	$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
	if ($edicaoId <= 0) {
		throw new RuntimeException("Nenhuma edição ativa.");
	}

	$lockNowLogicalDayAt = get_lock_for_logical_day($pdo, $lockCache, $nowLogicalDay);
	$lockNowLogicalDayActive = ($lockNowLogicalDayAt instanceof DateTimeImmutable) ? ($now >= $lockNowLogicalDayAt) : false;

	$sqlJogos = "
		SELECT
			j.id,
			j.edicao_id,
			j.grupo_id,
			j.fase,
			j.rodada,
			j.data_hora,
			j.codigo_fifa,
			j.status,
			j.time_casa_id,
			j.time_fora_id,
			g.codigo AS grupo_codigo,
			COALESCE(g.nome, CONCAT('Grupo ', g.codigo)) AS grupo_nome,
			tc.nome AS casa_nome,
			tc.sigla AS casa_sigla,
			tf.nome AS fora_nome,
			tf.sigla AS fora_sigla,
			p.gols_casa AS palpite_casa,
			p.gols_fora AS palpite_fora,
			p.passa_time_id AS palpite_passa_time_id
		FROM jogos j
		INNER JOIN times tc ON tc.id = j.time_casa_id
		INNER JOIN times tf ON tf.id = j.time_fora_id
		LEFT JOIN grupos g ON g.id = j.grupo_id
		LEFT JOIN palpites p
			ON p.jogo_id = j.id
		   AND p.usuario_id = :usuario_id
		WHERE j.edicao_id = :edicao_id
		  AND (
				(j.grupo_id IS NOT NULL AND (
					j.fase = 'GRUPOS' OR
					j.fase = 'GRUPO' OR
					j.fase = 'FASE_DE_GRUPOS' OR
					j.fase LIKE '%GRUP%'
				))
			 OR
				(j.grupo_id IS NULL AND j.fase IN ('16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL'))
		  )
		ORDER BY j.data_hora, j.id
	";
	$stJogos = $pdo->prepare($sqlJogos);
	$stJogos->execute([
		":usuario_id" => $usuarioId,
		":edicao_id" => $edicaoId,
	]);
	$jogos = $stJogos->fetchAll(PDO::FETCH_ASSOC);

	$jogosPorDia = [];
	$grupoLastDay = [];
	$semiDays = [];

	foreach ($jogos as &$j) {
		$dtGame = dt_from_mysql((string)$j["data_hora"]);
		$logicalDay = $dtGame ? logical_bet_day($dtGame) : '';
		$j["logical_day"] = $logicalDay;

		if ($logicalDay !== '') {
			if (!isset($jogosPorDia[$logicalDay])) $jogosPorDia[$logicalDay] = [];
			$jogosPorDia[$logicalDay][] = $j;
		}

		if (is_group_phase_row($j)) {
			$gid = (int)($j["grupo_id"] ?? 0);
			if ($gid > 0 && $logicalDay !== '') {
				if (!isset($grupoLastDay[$gid]) || strcmp($logicalDay, $grupoLastDay[$gid]) > 0) {
					$grupoLastDay[$gid] = $logicalDay;
				}
			}
		}

		if (is_knockout_phase_row($j) && (string)$j["fase"] === 'SEMI' && $logicalDay !== '') {
			$semiDays[$logicalDay] = true;
		}
	}
	unset($j);

	ksort($jogosPorDia);

	$days = array_keys($jogosPorDia);

	/* -------- grupos/times/picks classificação -------- */
	$sqlGrupos = "
		SELECT g.id, g.codigo, COALESCE(g.nome, CONCAT('Grupo ', g.codigo)) AS nome
		FROM grupos g
		WHERE g.edicao_id = :edicao_id
		ORDER BY g.codigo
	";
	$stGrupos = $pdo->prepare($sqlGrupos);
	$stGrupos->execute([":edicao_id" => $edicaoId]);
	$grupos = $stGrupos->fetchAll(PDO::FETCH_ASSOC);

	$timesPorGrupoId = [];
	$picksPorGrupoId = [];
	$grupoInfoById = [];

	if (count($grupos) > 0) {
		foreach ($grupos as $g) {
			$grupoInfoById[(int)$g["id"]] = $g;
		}

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
			$picksPorGrupoId[$gid] = [
				1 => (int)$r["primeiro_time_id"],
				2 => (int)$r["segundo_time_id"],
				3 => (int)$r["terceiro_time_id"],
			];
		}
	}

	$rankCardsByDay = [];
	foreach ($grupoLastDay as $gid => $dayYmd) {
		if (!isset($rankCardsByDay[$dayYmd])) $rankCardsByDay[$dayYmd] = [];
		$rankCardsByDay[$dayYmd][] = (int)$gid;
	}
	foreach ($rankCardsByDay as &$ids) {
		sort($ids);
	}
	unset($ids);

	/* -------- Top 4 -------- */
	$semiTeamIds = [];
	$stGate = $pdo->prepare("SELECT COUNT(*) FROM jogos WHERE edicao_id = ? AND grupo_id IS NULL AND fase = 'SEMI'");
	$stGate->execute([$edicaoId]);
	$top4Enabled = ((int)$stGate->fetchColumn() > 0);

	if ($top4Enabled) {
		$sqlSemiTeams = "
			SELECT DISTINCT x.tid
			FROM (
				SELECT time_casa_id AS tid
				FROM jogos
				WHERE edicao_id = ? AND grupo_id IS NULL AND fase = 'SEMI'
				UNION ALL
				SELECT time_fora_id AS tid
				FROM jogos
				WHERE edicao_id = ? AND grupo_id IS NULL AND fase = 'SEMI'
			) x
		";
		$stSemi = $pdo->prepare($sqlSemiTeams);
		$stSemi->execute([$edicaoId, $edicaoId]);
		$rowsSemi = $stSemi->fetchAll(PDO::FETCH_COLUMN, 0);
		foreach ($rowsSemi as $rid) $semiTeamIds[] = (int)$rid;
	}

	$timesSemi = [];
	if ($top4Enabled && count($semiTeamIds) > 0) {
		$inSemi = implode(',', array_fill(0, count($semiTeamIds), '?'));
		$stT = $pdo->prepare("SELECT id, nome, sigla FROM times WHERE id IN ($inSemi) ORDER BY nome");
		$stT->execute($semiTeamIds);

		while ($r = $stT->fetch(PDO::FETCH_ASSOC)) {
			$timesSemi[] = [
				"id" => (int)$r["id"],
				"nome" => (string)$r["nome"],
				"sigla" => (string)$r["sigla"],
			];
		}
	}

	$top4 = ["1" => 0, "2" => 0, "3" => 0, "4" => 0];
	$stP = $pdo->prepare("
		SELECT primeiro_time_id, segundo_time_id, terceiro_time_id, quarto_time_id
		FROM palpite_top4
		WHERE edicao_id = ? AND usuario_id = ?
		LIMIT 1
	");
	$stP->execute([$edicaoId, $usuarioId]);
	$rTop = $stP->fetch(PDO::FETCH_ASSOC);
	if ($rTop) {
		$top4["1"] = (int)$rTop["primeiro_time_id"];
		$top4["2"] = (int)$rTop["segundo_time_id"];
		$top4["3"] = (int)$rTop["terceiro_time_id"];
		$top4["4"] = (int)$rTop["quarto_time_id"];
	}

	$top4Day = null;
	if (!empty($semiDays)) {
		$semiDayKeys = array_keys($semiDays);
		sort($semiDayKeys);
		$top4Day = end($semiDayKeys);
		if ($top4Day === false) $top4Day = null;
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
	<title>Bolão da Copa - Palpites por Dia</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<link rel="stylesheet" href="<?php echo strh($ASSET_WEB_BASE . "/css/app.css?v=" . (string)@filemtime($ASSET_FS_BASE . "/css/app.css")); ?>">
	<link rel="stylesheet" href="<?php echo strh($ASSET_WEB_BASE . "/css/palpites_por_dia.css?v=" . (string)@filemtime($ASSET_FS_BASE . "/css/palpites_por_dia.css")); ?>">
</head>
<body>

<div class="app-wrap">

	<?php
		render_app_header(
			$usuarioNome,
			$isAdmin,
			"apostas_por_dia",
			"Palpites por Dia",
			$LOGOUT_URL
		);
	?>

	<div class="app-shell">
		<aside class="app-menu">
			<div class="menu-title">Datas</div>

			<div class="menu-list" id="menuDias">
				<a class="menu-link menu-link-champion"
				   href="<?php echo strh($CHAMP_URL); ?>"
				   title="Escolher o campeão">
					<span class="menu-link-text">Quem será o campeão</span>
					<span class="badge badge-champion">★</span>
				</a>

				<?php foreach ($days as $dayYmd): ?>
					<?php $listaDay = $jogosPorDia[$dayYmd] ?? []; ?>
					<a class="menu-link" href="#" data-day="<?php echo strh($dayYmd); ?>">
						<span class="menu-link-text"><?php echo strh(fmt_day_menu_label($dayYmd)); ?></span>
						<span class="badge"><?php echo (int)count($listaDay); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="menu-actions">
				<?php if ($RECEIPT_URL !== null): ?>
					<button class="btn-receipt" id="btnRecibo" type="button" data-receipt-url="<?php echo strh($RECEIPT_URL); ?>">
						Recibo
					</button>
				<?php endif; ?>

				<div class="hint">
					Tela única por <strong>dia lógico</strong>, juntando grupos + mata-mata.
					<?php if ($lockNowLogicalDayAt instanceof DateTimeImmutable): ?>
						<br>
						<small>
							Trava do dia às <strong><?php echo strh(fmt_hm($lockNowLogicalDayAt)); ?></strong>
							(1h antes do primeiro jogo do dia lógico).
						</small>
					<?php endif; ?>
					<?php if ($top4Enabled): ?>
						<br><br>
						<small><strong>Top 4 liberado</strong> porque a semifinal já existe.</small>
					<?php endif; ?>
				</div>
			</div>
		</aside>

		<main class="app-content">
			<div class="content-head">
				<h1 class="content-h1">Seus palpites</h1>
				<p class="content-sub">Os jogos agora aparecem por data. A classificação dos grupos aparece no último dia lógico de cada grupo. Empates no mata-mata exigem escolher quem passa.</p>
			</div>

			<?php if (count($days) === 0): ?>
				<div class="placeholder">Nenhum jogo encontrado na edição ativa.</div>
			<?php else: ?>

				<?php foreach ($days as $dayYmd): ?>
					<?php $lista = $jogosPorDia[$dayYmd] ?? []; ?>
					<section class="group-block day-block" data-day-block="<?php echo strh($dayYmd); ?>">
						<div class="group-head">
							<div class="group-title">
								<div class="group-line">
									<div class="group-pill"><?php echo strh(fmt_day_title($dayYmd)); ?></div>
									<div class="group-count"><?php echo (int)count($lista); ?> jogos</div>
								</div>
							</div>
						</div>

						<div class="matches">
							<?php foreach ($lista as $j): ?>
								<?php
								$jid  = (int)$j["id"];
								$casa = (string)$j["casa_nome"];
								$fora = (string)$j["fora_nome"];
								$csig = (string)$j["casa_sigla"];
								$fsig = (string)$j["fora_sigla"];

								$codigoFifa = isset($j["codigo_fifa"]) ? trim((string)$j["codigo_fifa"]) : "";
								$grupoId = (int)($j["grupo_id"] ?? 0);
								$grupoCodigo = trim((string)($j["grupo_codigo"] ?? ''));
								$grupoNome = trim((string)($j["grupo_nome"] ?? ''));
								$faseRaw = trim((string)($j["fase"] ?? ''));
								$faseUp = mb_strtoupper($faseRaw, 'UTF-8');
								$isGroup = is_group_phase_row($j);
								$isKnockout = is_knockout_phase_row($j);

								$phaseMap = phase_labels();
								$faseLabel = $phaseMap[$faseUp] ?? $faseRaw;

								$dtGame = dt_from_mysql((string)$j["data_hora"]);
								$lockReason = lock_reason_for_game($dtGame, $now, $pdo, $lockCache);
								$isLocked = ($lockReason !== null);

								$dh = fmt_datahora((string)$j["data_hora"]);
								$rodada = $j["rodada"] !== null ? (int)$j["rodada"] : null;

								$pc = $j["palpite_casa"];
								$pf = $j["palpite_fora"];
								$pcVal = ($pc === null) ? "" : (string)(int)$pc;
								$pfVal = ($pf === null) ? "" : (string)(int)$pf;

								$homeId = (int)$j["time_casa_id"];
								$awayId = (int)$j["time_fora_id"];
								$passDb = $j["palpite_passa_time_id"] ?? null;
								$passDbVal = ($passDb === null) ? 0 : (int)$passDb;

								$flagCasa = flag_url($casa, $ASSET_FS_BASE, $ASSET_WEB_BASE);
								$flagFora = flag_url($fora, $ASSET_FS_BASE, $ASSET_WEB_BASE);
								?>
								<article class="match-card <?php echo $isLocked ? "is-locked" : ""; ?>"
										 data-jogo-id="<?php echo $jid; ?>"
										 data-day="<?php echo strh($dayYmd); ?>"
										 data-is-group="<?php echo $isGroup ? '1' : '0'; ?>"
										 data-is-knockout="<?php echo $isKnockout ? '1' : '0'; ?>"
										 data-grupo-id="<?php echo (int)$grupoId; ?>"
										 data-fase="<?php echo strh($faseUp); ?>"
										 data-when="<?php echo strh($dh); ?>"
										 data-home="<?php echo strh($casa); ?>"
										 data-away="<?php echo strh($fora); ?>"
										 data-home-id="<?php echo (int)$homeId; ?>"
										 data-away-id="<?php echo (int)$awayId; ?>"
										 data-pass-team-id="<?php echo (int)$passDbVal; ?>"
										 data-fifa="<?php echo strh($codigoFifa); ?>"
										 data-locked="<?php echo $isLocked ? "1" : "0"; ?>">
									<div class="match-top">
										<div class="match-when">
											<span class="when"><?php echo strh($dh); ?></span>

											<?php if ($isGroup && $grupoCodigo !== ''): ?>
												<span class="round mm-tag mm-tag-group">Grupo <?php echo strh($grupoCodigo); ?></span>
											<?php endif; ?>

											<?php if ($isKnockout): ?>
												<span class="round mm-tag mm-tag-phase"><?php echo strh($faseLabel); ?></span>
											<?php endif; ?>

											<?php if ($rodada !== null && $isGroup): ?>
												<span class="round">Rodada <?php echo (int)$rodada; ?></span>
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
										<?php if ($isKnockout): ?>
											<button class="btn-pass" type="button" title="Escolher quem passa" <?php echo $isLocked ? "disabled" : ""; ?> style="display:none;">
												Quem passa?
											</button>

											<div class="pass-chooser" style="display:none;">
												<button type="button" class="pass-choice" data-pass="home"><?php echo strh($casa); ?></button>
												<button type="button" class="pass-choice" data-pass="away"><?php echo strh($fora); ?></button>
											</div>
										<?php endif; ?>

										<div class="save-state" aria-live="polite">
											<?php if ($isLocked): ?>
												<span class="lock-reason"><?php echo strh($lockReason); ?></span>
											<?php endif; ?>
										</div>
									</div>
								</article>
							<?php endforeach; ?>
						</div>

						<?php
						$rankGroupIds = $rankCardsByDay[$dayYmd] ?? [];
						foreach ($rankGroupIds as $grupoIdCard):
							$ginfo = $grupoInfoById[$grupoIdCard] ?? null;
							if (!$ginfo) continue;

							$codigo = (string)$ginfo["codigo"];
							$timesGrupo = $timesPorGrupoId[$grupoIdCard] ?? [];
							$picks = $picksPorGrupoId[$grupoIdCard] ?? [];
							$pick1 = isset($picks[1]) ? (int)$picks[1] : 0;
							$pick2 = isset($picks[2]) ? (int)$picks[2] : 0;
							$pick3 = isset($picks[3]) ? (int)$picks[3] : 0;
						?>
							<div class="group-rank-card" data-grupo-rank="<?php echo (int)$grupoIdCard; ?>">
								<div class="group-rank-head">
									<div class="group-rank-title">Classificação do Grupo <?php echo strh($codigo); ?></div>
									<div class="group-rank-sub">Escolha livremente 1º, 2º e 3º. Este card aparece no último dia lógico do grupo.</div>
								</div>

								<?php if (count($timesGrupo) === 0): ?>
									<div class="group-rank-empty">Sem times vinculados a este grupo (grupo_time).</div>
								<?php else: ?>
									<div class="group-rank-grid">
										<div class="rank-field">
											<label>1º</label>
											<select class="rank-select" data-rank-pos="1">
												<option value="0">—</option>
												<?php foreach ($timesGrupo as $t): ?>
													<?php $tid = (int)$t["time_id"]; ?>
													<option value="<?php echo (int)$tid; ?>" <?php echo ($tid === $pick1) ? "selected" : ""; ?>>
														<?php echo strh((string)$t["time_nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>2º</label>
											<select class="rank-select" data-rank-pos="2">
												<option value="0">—</option>
												<?php foreach ($timesGrupo as $t): ?>
													<?php $tid = (int)$t["time_id"]; ?>
													<option value="<?php echo (int)$tid; ?>" <?php echo ($tid === $pick2) ? "selected" : ""; ?>>
														<?php echo strh((string)$t["time_nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>3º</label>
											<select class="rank-select" data-rank-pos="3">
												<option value="0">—</option>
												<?php foreach ($timesGrupo as $t): ?>
													<?php $tid = (int)$t["time_id"]; ?>
													<option value="<?php echo (int)$tid; ?>" <?php echo ($tid === $pick3) ? "selected" : ""; ?>>
														<?php echo strh((string)$t["time_nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>

									<div class="group-rank-actions">
										<button class="btn-group-save" type="button">Salvar grupo</button>
										<div class="rank-state" aria-live="polite"></div>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

						<?php if ($top4Day !== null && $top4Day === $dayYmd): ?>
							<div class="group-rank-card" data-top4-card="1">
								<div class="group-rank-head">
									<div class="group-rank-title">Top 4 do torneio</div>
									<div class="group-rank-sub">Liberado após existir jogo(s) na semifinal. Independe dos placares.</div>
								</div>

								<?php if (!$top4Enabled): ?>
									<div class="group-rank-empty">Top 4 ainda bloqueado.</div>
								<?php elseif (count($timesSemi) === 0): ?>
									<div class="group-rank-empty">Sem times válidos na semifinal.</div>
								<?php else: ?>
									<div class="group-rank-grid">
										<div class="rank-field">
											<label>1º</label>
											<select class="rank-select" data-top4-pos="1">
												<option value="0">—</option>
												<?php foreach ($timesSemi as $t): ?>
													<option value="<?php echo (int)$t["id"]; ?>" <?php echo ((int)$t["id"] === (int)$top4["1"]) ? "selected" : ""; ?>>
														<?php echo strh($t["nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>2º</label>
											<select class="rank-select" data-top4-pos="2">
												<option value="0">—</option>
												<?php foreach ($timesSemi as $t): ?>
													<option value="<?php echo (int)$t["id"]; ?>" <?php echo ((int)$t["id"] === (int)$top4["2"]) ? "selected" : ""; ?>>
														<?php echo strh($t["nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>3º</label>
											<select class="rank-select" data-top4-pos="3">
												<option value="0">—</option>
												<?php foreach ($timesSemi as $t): ?>
													<option value="<?php echo (int)$t["id"]; ?>" <?php echo ((int)$t["id"] === (int)$top4["3"]) ? "selected" : ""; ?>>
														<?php echo strh($t["nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="rank-field">
											<label>4º</label>
											<select class="rank-select" data-top4-pos="4">
												<option value="0">—</option>
												<?php foreach ($timesSemi as $t): ?>
													<option value="<?php echo (int)$t["id"]; ?>" <?php echo ((int)$t["id"] === (int)$top4["4"]) ? "selected" : ""; ?>>
														<?php echo strh($t["nome"]); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>

									<div class="group-rank-actions">
										<div class="rank-state" id="top4State" aria-live="polite"></div>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>

					</section>
				<?php endforeach; ?>

			<?php endif; ?>
		</main>
	</div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

<script type="application/json" id="palpites-dia-config"><?php
echo json_encode([
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
	"top4" => [
		"enabled" => (bool)$top4Enabled,
	],
	"endpoints" => [
		"save_games" => $APP_URL . "?action=save",
		"save_group_rank" => $APP_URL . "?action=save_group_rank",
		"save_top4" => $APP_URL . "?action=save_top4",
		"receipt_url" => $RECEIPT_URL,
	],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?></script>

<script src="<?php echo strh($ASSET_WEB_BASE . "/js/palpites_por_dia.js?v=" . (string)@filemtime($ASSET_FS_BASE . "/js/palpites_por_dia.js")); ?>"></script>
</body>
</html>