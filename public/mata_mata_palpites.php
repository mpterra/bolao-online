<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";
require_once __DIR__ . "/../php/bet_update_notifier.php";

date_default_timezone_set('America/Sao_Paulo');

function json_response(array $data, int $code = 200): void {
	http_response_code($code);
	header("Content-Type: application/json; charset=utf-8");
	header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function require_login(): void {
	if (empty($_SESSION["usuario_id"])) {
		header("Location: /index.php");
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

function flag_url(string $teamName): ?string {
	$slug = flag_slug($teamName);
	if ($slug === "") return null;

	$fsPath = __DIR__ . "/img/flags/" . $slug . ".png";
	if (is_file($fsPath)) return "/img/flags/" . $slug . ".png";
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

function logical_bet_day(DateTimeImmutable $dt): string {
	$hour = (int)$dt->format('H');
	if ($hour >= 0 && $hour < 5) return $dt->sub(new DateInterval('P1D'))->format('Y-m-d');
	return $dt->format('Y-m-d');
}

function phases_knockout(): array {
	return [
		'16_DE_FINAL'    => '16 de final',
		'OITAVAS'        => 'Oitavas',
		'QUARTAS'        => 'Quartas',
		'SEMI'           => 'Semifinal',
		'TERCEIRO_LUGAR' => '3º lugar',
		'FINAL'          => 'Final',
	];
}

function get_pdo(): PDO {
	if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

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

function compute_lock_for_logical_day_knockout(PDO $pdo, string $dayYmd): ?DateTimeImmutable {
	$ph = array_keys(phases_knockout());
	$in = implode(',', array_fill(0, count($ph), '?'));

	$sql = "
		SELECT MIN(j.data_hora)
		FROM jogos j
		INNER JOIN edicoes e ON e.id = j.edicao_id AND e.ativo = 1
		WHERE j.grupo_id IS NULL
		  AND j.fase IN ($in)
		  AND (
				(DATE(j.data_hora) = ? AND TIME(j.data_hora) >= '05:00:00')
			 OR (DATE(j.data_hora) = DATE_ADD(?, INTERVAL 1 DAY) AND TIME(j.data_hora) < '05:00:00')
		  )
	";
	$params = array_merge($ph, [$dayYmd, $dayYmd]);

	$st = $pdo->prepare($sql);
	$st->execute($params);
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
	$lockAt = compute_lock_for_logical_day_knockout($pdo, $logicalDayYmd);
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
	if ($gameDt <= $now) return "Jogo já iniciado/encerrado.";

	$logicalDay = logical_bet_day($gameDt);
	$lockAt = get_lock_for_logical_day($pdo, $lockCache, $logicalDay);

	if ($lockAt instanceof DateTimeImmutable && $now >= $lockAt) {
		return "Apostas do dia bloqueadas desde " . fmt_hm($lockAt) . ".";
	}
	return null;
}

require_login();

$usuarioId   = (int)($_SESSION["usuario_id"] ?? 0);
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";
$tipoUsuario = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoUsuario, "UTF-8") === "ADMIN");

$pdo = get_pdo();

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$nowLogicalDay = logical_bet_day($now);
$lockCache = [];

/* Logout */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
	session_destroy();
	header("Location: /index.php");
	exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "notify_changes") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(["ok" => false, "message" => "Método inválido."], 405);
	if (!function_exists('bet_notify_flush')) json_response(["ok" => false, "message" => "Notificador indisponível."], 500);

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);
	if (!is_array($payload)) $payload = [];

	$force = (bool)($payload["force"] ?? true);
	$result = bet_notify_flush($pdo, $usuarioId, $force);

	json_response([
		"ok" => (bool)($result["ok"] ?? false),
		"sent" => (bool)($result["sent"] ?? false),
		"pending" => (int)($result["pending"] ?? 0),
		"reason" => (string)($result["reason"] ?? ""),
	], ((bool)($result["ok"] ?? false)) ? 200 : 500);
}

/* API salvar palpites (placares + quem passa no empate) */
if (isset($_GET["action"]) && $_GET["action"] === "save") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(["ok" => false, "message" => "Método inválido."], 405);

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);
	if (!is_array($payload)) json_response(["ok" => false, "message" => "JSON inválido."], 400);

	$items = $payload["items"] ?? null;
	if (!is_array($items) || count($items) === 0) json_response(["ok" => false, "message" => "Nada para salvar."], 400);

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
	if (count($normalized) === 0) json_response(["ok" => false, "message" => "Preencha os placares antes de salvar."], 422);

	try {
		$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
		if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

		$ph = array_keys(phases_knockout());
		$in = implode(',', array_fill(0, count($ph), '?'));

		$sqlCheck = "
			SELECT j.id, j.data_hora, j.time_casa_id, j.time_fora_id
			FROM jogos j
			WHERE j.id = ?
			  AND j.edicao_id = ?
			  AND j.grupo_id IS NULL
			  AND j.fase IN ($in)
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
			$params = array_merge([(int)$row["jogo_id"], $edicaoId], $ph);
			$stCheck->execute($params);
			$game = $stCheck->fetch(PDO::FETCH_ASSOC);

			if (!is_array($game) || empty($game["id"])) {
				$blocked[] = ["jogo_id" => (int)$row["jogo_id"], "reason" => "Jogo inválido (não é mata-mata/edição ativa)."];
				continue;
			}

			$gameDt = dt_from_mysql(isset($game["data_hora"]) ? (string)$game["data_hora"] : null);
			$reason = lock_reason_for_game($gameDt, $now, $pdo, $lockCache);
			if ($reason !== null) {
				$blocked[] = ["jogo_id" => (int)$row["jogo_id"], "reason" => $reason];
				continue;
			}

			$timeCasaId = (int)($game["time_casa_id"] ?? 0);
			$timeForaId = (int)($game["time_fora_id"] ?? 0);

			$gc = (int)$row["gols_casa"];
			$gf = (int)$row["gols_fora"];
			$empate = ($gc === $gf);

			$passa = $row["passa_time_id"];
			$passaInt = ($passa === null) ? 0 : (int)$passa;

			if ($empate) {
				if ($passaInt <= 0) {
					$blocked[] = ["jogo_id" => (int)$row["jogo_id"], "reason" => "Empate: escolha quem passa."];
					continue;
				}
				if ($passaInt !== $timeCasaId && $passaInt !== $timeForaId) {
					$blocked[] = ["jogo_id" => (int)$row["jogo_id"], "reason" => "Empate: escolha um dos dois times do jogo."];
					continue;
				}
			} else {
				$passa = null;
			}

			$stUpsert->execute([
				":usuario_id" => $usuarioId,
				":jogo_id"    => (int)$row["jogo_id"],
				":gols_casa"  => $gc,
				":gols_fora"  => $gf,
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
		if (function_exists('bet_notify_track_update') && $saved > 0) {
			bet_notify_track_update($pdo, $usuarioId);
		}

		if ($saved <= 0) json_response(["ok" => false, "message" => "Nenhum palpite foi salvo."], 422);

		json_response(["ok" => true, "saved" => $saved, "message" => "Palpites salvos com sucesso."]);
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar palpites."], 500);
	}
}

/* API salvar Top4 (somente times da semifinal) */
if (isset($_GET["action"]) && $_GET["action"] === "save_top4") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(["ok" => false, "message" => "Método inválido."], 405);

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);
	if (!is_array($payload)) json_response(["ok" => false, "message" => "JSON inválido."], 400);

	$picks = $payload["picks"] ?? null;
	if (!is_array($picks)) json_response(["ok" => false, "message" => "Payload inválido."], 422);

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
		if (!$hasSemi) json_response(["ok" => false, "message" => "Top 4 só libera após cadastrar os jogos da semifinal."], 423);

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
		if (function_exists('bet_notify_track_update')) {
			bet_notify_track_update($pdo, $usuarioId);
		}
		json_response(["ok" => true, "message" => "Top 4 salvo."]);
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar Top 4."], 500);
	}
}

/* HTML load */
try {
	$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
	if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

	$lockNowLogicalDayAt = get_lock_for_logical_day($pdo, $lockCache, $nowLogicalDay);
	$lockNowLogicalDayActive = ($lockNowLogicalDayAt instanceof DateTimeImmutable) ? ($now >= $lockNowLogicalDayAt) : false;

	$phMap = phases_knockout();
	$phKeys = array_keys($phMap);

	$sqlJogos = "
		SELECT
			j.id,
			j.fase,
			j.data_hora,
			j.codigo_fifa,
			j.status,
			j.time_casa_id,
			j.time_fora_id,
			tc.nome AS casa_nome,
			tc.sigla AS casa_sigla,
			tf.nome AS fora_nome,
			tf.sigla AS fora_sigla,
			j.zebra_time_id,
			p.gols_casa AS palpite_casa,
			p.gols_fora AS palpite_fora,
			p.passa_time_id AS palpite_passa_time_id
		FROM jogos j
		INNER JOIN times tc ON tc.id = j.time_casa_id
		INNER JOIN times tf ON tf.id = j.time_fora_id
		LEFT JOIN palpites p
			ON p.jogo_id = j.id
		   AND p.usuario_id = ?
		WHERE j.edicao_id = ?
		  AND j.grupo_id IS NULL
		  AND j.fase IN ('16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL')
		ORDER BY FIELD(j.fase,'16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL'),
		         j.data_hora, j.id
	";
	$st = $pdo->prepare($sqlJogos);
	$st->execute([$usuarioId, $edicaoId]);
	$jogos = $st->fetchAll(PDO::FETCH_ASSOC);

	$jogosPorFase = [];
	foreach ($phKeys as $k) $jogosPorFase[$k] = [];
	foreach ($jogos as $j) {
		$f = (string)$j["fase"];
		if (!isset($jogosPorFase[$f])) $jogosPorFase[$f] = [];
		$jogosPorFase[$f][] = $j;
	}

	$temJogosFase = [];
	foreach ($phKeys as $k) $temJogosFase[$k] = (!empty($jogosPorFase[$k]));

	$semiTeamIds = [];
	if (!empty($temJogosFase["SEMI"])) {
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
		$rows = $stSemi->fetchAll(PDO::FETCH_COLUMN, 0);
		foreach ($rows as $rid) $semiTeamIds[] = (int)$rid;
	}

	$top4Enabled = !empty($temJogosFase["SEMI"]) && count($semiTeamIds) > 0;

	$timesSemi = [];
	if ($top4Enabled) {
		$in = implode(',', array_fill(0, count($semiTeamIds), '?'));
		$stT = $pdo->prepare("SELECT id, nome, sigla FROM times WHERE id IN ($in) ORDER BY nome");
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
	<title>Bolão da Copa - Palpites (Mata-mata)</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<link rel="stylesheet" href="/css/app.css?v=<?php echo filemtime(__DIR__ . '/css/app.css'); ?>">
	<link rel="stylesheet" href="/css/mata_mata_palpites.css?v=<?php echo filemtime(__DIR__ . '/css/mata_mata_palpites.css'); ?>">
</head>
<body>

<div class="app-wrap">

	<?php
		render_app_header(
			$usuarioNome,
			$isAdmin,
			"mata_mata_palpites",
			"Mata-mata • Palpites",
			"/mata_mata_palpites.php?action=logout"
		);
	?>

	<div class="app-shell">
		<aside class="app-menu">
			<div class="menu-title">Fases</div>

			<div class="menu-list" id="menuFases">
				<?php foreach (phases_knockout() as $code => $label): ?>
					<?php $has = !empty($temJogosFase[$code]); ?>
					<a class="menu-link <?php echo $has ? "" : "is-disabled"; ?>"
					   href="#"
					   data-fase="<?php echo strh($code); ?>"
					   <?php echo $has ? "" : "aria-disabled='true' tabindex='-1'"; ?>>
						<span class="menu-link-text"><?php echo strh($label); ?></span>
						<?php if ($has): ?>
							<span class="badge"><?php echo (int)count($jogosPorFase[$code] ?? []); ?></span>
						<?php else: ?>
							<span class="badge badge-muted">0</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="menu-actions">
				<button class="btn-receipt" id="btnRecibo" type="button" data-receipt-url="/php/recibo_mata_mata.php">
					Recibo
				</button>

				<div class="hint">
					Os palpites do mata-mata salvam automaticamente ao preencher placar e ao escolher quem passa.
					<?php if ($lockNowLogicalDayAt instanceof DateTimeImmutable): ?>
						<br>
						<small>
							Trava do dia (lógico) às <strong><?php echo strh(fmt_hm($lockNowLogicalDayAt)); ?></strong>
							(1h antes do primeiro jogo do dia).
						</small>
					<?php endif; ?>
					<?php if ($top4Enabled): ?>
						<br><br>
						<small><strong>Top 4 liberado</strong> (semifinal cadastrada) e com salvamento automático.</small>
					<?php endif; ?>
				</div>
			</div>
		</aside>

		<main class="app-content">
			<div class="content-head" id="mmContentHead">
				<h1 class="content-h1">Seus palpites</h1>
				<p class="content-sub">Preencha o placar de cada jogo do mata-mata. O Top 4 libera após existir jogo(s) na semifinal.</p>
			</div>

			<?php
			$totalJogos = 0;
			foreach (array_keys(phases_knockout()) as $k) $totalJogos += (int)count($jogosPorFase[$k] ?? []);
			?>

			<?php if ($totalJogos <= 0): ?>
				<div class="placeholder">Nenhum jogo de mata-mata foi cadastrado pelo admin nesta edição.</div>
			<?php else: ?>

				<?php foreach (phases_knockout() as $faseCode => $faseLabel): ?>
					<?php $lista = $jogosPorFase[$faseCode] ?? []; ?>
					<section class="group-block" data-fase-block="<?php echo strh($faseCode); ?>">
						<div class="group-head">
							<div class="group-title">
								<div class="group-line">
									<div class="group-pill"><?php echo strh($faseLabel); ?></div>
									<div class="group-count"><?php echo (int)count($lista); ?> jogos</div>
								</div>
							</div>
						</div>

						<?php if (count($lista) === 0): ?>
							<div class="placeholder">Sem jogos cadastrados para esta fase.</div>
						<?php else: ?>

							<div class="matches">
								<?php foreach ($lista as $j): ?>
									<?php
									$jid  = (int)$j["id"];
									$casa = (string)$j["casa_nome"];
									$fora = (string)$j["fora_nome"];
									$csig = (string)$j["casa_sigla"];
									$fsig = (string)$j["fora_sigla"];

									$codigoFifa = isset($j["codigo_fifa"]) ? trim((string)$j["codigo_fifa"]) : "";

									$dtGame = dt_from_mysql((string)$j["data_hora"]);
									$lockReason = lock_reason_for_game($dtGame, $now, $pdo, $lockCache);
									$isLocked = ($lockReason !== null);

									$dh = fmt_datahora((string)$j["data_hora"]);

									$pc = $j["palpite_casa"];
									$pf = $j["palpite_fora"];

									$pcVal = ($pc === null) ? "" : (string)(int)$pc;
									$pfVal = ($pf === null) ? "" : (string)(int)$pf;

									$flagCasa = flag_url($casa);
									$flagFora = flag_url($fora);

									$homeId = (int)$j["time_casa_id"];
									$awayId = (int)$j["time_fora_id"];

									$passDb = $j["palpite_passa_time_id"] ?? null;
									$passDbVal = ($passDb === null) ? 0 : (int)$passDb;
									?>
									<article class="match-card <?php echo $isLocked ? "is-locked" : ""; ?>"
											 data-jogo-id="<?php echo $jid; ?>"
											 data-fase="<?php echo strh($faseCode); ?>"
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
											<button class="btn-pass" type="button" title="Escolher quem passa" <?php echo $isLocked ? "disabled" : ""; ?> style="display:none;">
												Quem passa?
											</button>

											<div class="pass-chooser" style="display:none;">
												<button type="button" class="pass-choice" data-pass="home"><?php echo strh($casa); ?></button>
												<button type="button" class="pass-choice" data-pass="away"><?php echo strh($fora); ?></button>
											</div>

											<div class="save-state" aria-live="polite">
												<?php if ($isLocked): ?>
													<span class="lock-reason"><?php echo strh($lockReason); ?></span>
												<?php endif; ?>
											</div>
										</div>
									</article>
								<?php endforeach; ?>
							</div>

							<?php if ($faseCode === "SEMI"): ?>
								<div class="group-rank-card" data-top4-card="1">
									<div class="group-rank-head">
										<div class="group-rank-title">Top 4 do torneio</div>
										<div class="group-rank-sub">Libera após existir jogo(s) na semifinal. Independe dos placares.</div>
									</div>

									<?php if (!$top4Enabled): ?>
										<div class="group-rank-empty">Top 4 ainda bloqueado. Cadastre os jogos da semifinal primeiro.</div>
									<?php elseif (count($timesSemi) === 0): ?>
										<div class="group-rank-empty">Sem times válidos na semifinal.</div>
									<?php else: ?>
										<div class="group-rank-grid">
											<div class="rank-field">
												<label>1º</label>
												<select class="rank-select" data-top4-pos="1">
													<option value="0"><?php echo strh("—"); ?></option>
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
													<option value="0"><?php echo strh("—"); ?></option>
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
													<option value="0"><?php echo strh("—"); ?></option>
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
													<option value="0"><?php echo strh("—"); ?></option>
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

						<?php endif; ?>
					</section>
				<?php endforeach; ?>

			<?php endif; ?>
		</main>
	</div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

<script type="application/json" id="mm-palpites-config"><?php
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
	"knockout" => [
		"top4_enabled" => (bool)$top4Enabled,
	],
	"endpoints" => [
		"save_games"  => "/mata_mata_palpites.php?action=save",
		"save_top4"   => "/mata_mata_palpites.php?action=save_top4",
		"notify_changes" => "/mata_mata_palpites.php?action=notify_changes",
		"receipt_url" => "/php/recibo_mata_mata.php?action=pdf",
	],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?></script>

<script src="/js/mata_mata_palpites.js?v=<?php echo filemtime(__DIR__ . '/js/mata_mata_palpites.js'); ?>"></script>
</body>
</html>
