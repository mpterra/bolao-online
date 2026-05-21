<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/php/security.php";
app_start_session();
app_send_security_headers();

$CONEXAO_PATH_1 = __DIR__ . "/../php/conexao.php";
$CONEXAO_PATH_2 = __DIR__ . "/php/conexao.php";
if (is_file($CONEXAO_PATH_1)) {
	require_once $CONEXAO_PATH_1;
} else {
	require_once $CONEXAO_PATH_2;
}

date_default_timezone_set('America/Sao_Paulo');

function require_login(): void {
	if (empty($_SESSION["usuario_id"])) {
		header("Location: /index.php");
		exit;
	}
}

if (!function_exists("strh")) {
	function strh(?string $s): string {
		return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8");
	}
}

function upper_utf8(string $s): string {
	return mb_strtoupper($s, "UTF-8");
}

function lower_utf8(string $s): string {
	return mb_strtolower($s, "UTF-8");
}

function get_pdo_auditoria(): PDO {
	if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
	if (function_exists('conectar')) {
		$p = conectar();
		if ($p instanceof PDO) return $p;
	}
	if (function_exists('getConnection')) {
		$p = getConnection();
		if ($p instanceof PDO) return $p;
	}
	throw new RuntimeException("Nao foi possivel obter conexao com o banco.");
}

function dt_from_mysql_audit(?string $dt): ?DateTimeImmutable {
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

function logical_bet_day_audit(DateTimeImmutable $dt): string {
	$hour = (int)$dt->format('H');
	if ($hour >= 0 && $hour < 5) return $dt->sub(new DateInterval('P1D'))->format('Y-m-d');
	return $dt->format('Y-m-d');
}

function fmt_when_audit(string $dt): string {
	$ts = strtotime($dt);
	if ($ts === false) return $dt;
	return date("d/m H:i", $ts);
}

function fmt_day_title_audit(string $ymd): string {
	$dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, new DateTimeZone('America/Sao_Paulo'));
	if (!$dt) return $ymd;
	$days = ['Sun' => 'Domingo', 'Mon' => 'Segunda', 'Tue' => 'Terca', 'Wed' => 'Quarta', 'Thu' => 'Quinta', 'Fri' => 'Sexta', 'Sat' => 'Sabado'];
	return ($days[$dt->format('D')] ?? $dt->format('D')) . ', ' . $dt->format('d/m');
}

function phase_label_audit(string $fase, ?string $grupoCodigo): string {
	$f = upper_utf8(trim($fase));
	if ($grupoCodigo !== null && $grupoCodigo !== '') return "Grupo " . $grupoCodigo;
	$map = [
		'16_DE_FINAL' => '16 de final',
		'OITAVAS' => 'Oitavas',
		'QUARTAS' => 'Quartas',
		'SEMI' => 'Semifinal',
		'TERCEIRO_LUGAR' => '3o lugar',
		'FINAL' => 'Final',
	];
	return $map[$f] ?? $fase;
}

function group_section_title_audit(string $key, array $games): string {
	$first = $games[0] ?? [];
	$grupoCodigo = trim((string)($first["grupo_codigo"] ?? ""));
	if ($grupoCodigo !== '') return "Grupo " . $grupoCodigo;
	return phase_label_audit((string)($first["fase"] ?? $key), null);
}

require_login();

$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";
$tipoUsuario = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (upper_utf8($tipoUsuario) === "ADMIN");

if (session_status() === PHP_SESSION_ACTIVE) {
	session_write_close();
}

$pdo = get_pdo_auditoria();
$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$previewMode = $isAdmin && (!isset($_GET["preview"]) || (string)$_GET["preview"] !== "0");
if ($previewMode) {
	$now = new DateTimeImmutable('2030-01-01 12:00:00', $tz);
}

try {
	$edicao = $pdo->query("SELECT id, nome FROM edicoes WHERE ativo = 1 ORDER BY ano DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
	if (!is_array($edicao) || empty($edicao["id"])) {
		throw new RuntimeException("Nenhuma edicao ativa encontrada.");
	}
	$edicaoId = (int)$edicao["id"];
	$edicaoNome = (string)($edicao["nome"] ?? "Edicao ativa");

	$stUsers = $pdo->query("
		SELECT id, nome, tipo_usuario, ativo
		FROM usuarios
		WHERE ativo = 1
		ORDER BY (tipo_usuario = 'ADMIN') DESC, nome ASC, id ASC
	");
	$usuarios = $stUsers->fetchAll(PDO::FETCH_ASSOC);
	if (!is_array($usuarios)) $usuarios = [];

	$admins = array_values(array_filter($usuarios, static function (array $u): bool {
		return upper_utf8((string)($u["tipo_usuario"] ?? "")) === "ADMIN";
	}));

	$stGames = $pdo->prepare("
		SELECT
			j.id,
			j.fase,
			j.grupo_id,
			j.rodada,
			j.data_hora,
			j.codigo_fifa,
			j.time_casa_id,
			j.time_fora_id,
			g.codigo AS grupo_codigo,
			tc.nome AS casa_nome,
			tc.sigla AS casa_sigla,
			tf.nome AS fora_nome,
			tf.sigla AS fora_sigla
		FROM jogos j
		INNER JOIN times tc ON tc.id = j.time_casa_id
		INNER JOIN times tf ON tf.id = j.time_fora_id
		LEFT JOIN grupos g ON g.id = j.grupo_id
		WHERE j.edicao_id = ?
		ORDER BY j.data_hora ASC, j.id ASC
	");
	$stGames->execute([$edicaoId]);
	$allGames = $stGames->fetchAll(PDO::FETCH_ASSOC);
	if (!is_array($allGames)) $allGames = [];

	$dayFirstGame = [];
	foreach ($allGames as $game) {
		$dt = dt_from_mysql_audit((string)($game["data_hora"] ?? ""));
		if (!$dt) continue;
		$day = logical_bet_day_audit($dt);
		if (!isset($dayFirstGame[$day]) || $dt < $dayFirstGame[$day]) {
			$dayFirstGame[$day] = $dt;
		}
	}

	$dayLockAt = [];
	foreach ($dayFirstGame as $day => $firstDt) {
		$dayLockAt[$day] = $firstDt->sub(new DateInterval('PT1H'));
	}

	$lockedGames = [];
	foreach ($allGames as $game) {
		$dt = dt_from_mysql_audit((string)($game["data_hora"] ?? ""));
		if (!$dt) continue;

		$day = logical_bet_day_audit($dt);
		$lockAt = $dayLockAt[$day] ?? null;
		$isLockedByRule = ($lockAt instanceof DateTimeImmutable && $now >= $lockAt);
		$isStarted = ($dt <= $now);

		if (!$isLockedByRule && !$isStarted) continue;

		$game["logical_day"] = $day;
		$game["lock_at"] = $lockAt instanceof DateTimeImmutable ? $lockAt : $dt;
		$lockedGames[] = $game;
	}

	$gameIds = array_map(static fn(array $g): int => (int)$g["id"], $lockedGames);
	$palpitesByGameUser = [];
	if (count($gameIds) > 0) {
		$in = implode(',', array_fill(0, count($gameIds), '?'));
		$stPicks = $pdo->prepare("
			SELECT
				p.usuario_id,
				p.jogo_id,
				p.gols_casa,
				p.gols_fora,
				p.passa_time_id,
				tp.nome AS passa_nome
			FROM palpites p
			LEFT JOIN times tp ON tp.id = p.passa_time_id
			WHERE p.jogo_id IN ($in)
		");
		$stPicks->execute($gameIds);
		while ($row = $stPicks->fetch(PDO::FETCH_ASSOC)) {
			$jid = (int)$row["jogo_id"];
			$uid = (int)$row["usuario_id"];
			if (!isset($palpitesByGameUser[$jid])) $palpitesByGameUser[$jid] = [];
			$palpitesByGameUser[$jid][$uid] = $row;
		}
	}

	$gamesByDay = [];
	$gamesByGroup = [];
	foreach ($lockedGames as $game) {
		$day = (string)$game["logical_day"];
		if (!isset($gamesByDay[$day])) $gamesByDay[$day] = [];
		$gamesByDay[$day][] = $game;

		$grupoCodigo = trim((string)($game["grupo_codigo"] ?? ""));
		$fase = trim((string)($game["fase"] ?? ""));
		$groupKey = $grupoCodigo !== '' ? ('grupo-' . $grupoCodigo) : ('fase-' . upper_utf8($fase));
		if (!isset($gamesByGroup[$groupKey])) $gamesByGroup[$groupKey] = [];
		$gamesByGroup[$groupKey][] = $game;
	}
	ksort($gamesByDay);
	uksort($gamesByGroup, static function (string $a, string $b): int {
		$aGroup = (substr($a, 0, 6) === 'grupo-');
		$bGroup = (substr($b, 0, 6) === 'grupo-');
		if ($aGroup && $bGroup) return strcmp($a, $b);
		if ($aGroup) return -1;
		if ($bGroup) return 1;
		return strcmp($a, $b);
	});

	$totalPalpitesEsperados = count($usuarios) * count($lockedGames);
	$totalPalpitesFeitos = 0;
	foreach ($lockedGames as $game) {
		$totalPalpitesFeitos += count($palpitesByGameUser[(int)$game["id"]] ?? []);
	}
	$coverage = $totalPalpitesEsperados > 0 ? round(($totalPalpitesFeitos / $totalPalpitesEsperados) * 100, 1) : 0;
} catch (Throwable $e) {
	http_response_code(500);
	echo "<pre style='white-space:pre-wrap;font:14px/1.4 monospace'>";
	echo "Erro ao carregar auditoria:\n\n" . strh($e->getMessage());
	echo "</pre>";
	exit;
}

require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
	<meta charset="UTF-8">
	<title>Bolao da Copa - Auditoria</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
	<link rel="stylesheet" href="/css/auditoria.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/auditoria.css'); ?>">
</head>
<body data-page="auditoria">
<div class="app-wrap audit-wrap">
	<?php render_app_header($usuarioNome, $isAdmin, "auditoria", "Auditoria das apostas travadas", "/app.php?action=logout"); ?>

	<main class="audit-shell">
		<?php if ($previewMode): ?>
			<section class="audit-preview-warning">
				<strong>Modo preview ativo</strong>
				<span>Simulando auditoria como se a data atual fosse <?php echo strh($now->format('d/m/Y H:i')); ?>. Nada foi alterado no banco. Para desligar: /auditoria.php?preview=0</span>
			</section>
		<?php endif; ?>

		<section class="audit-hero">
			<div>
				<p class="audit-kicker"><?php echo strh($edicaoNome); ?></p>
				<h1>Auditoria</h1>
				<p>Mostra os palpites de todos os apostadores apenas para jogos cujo prazo ja travou.</p>
			</div>
			<div class="audit-search">
				<input id="auditFilter" type="search" autocomplete="off" placeholder="Filtrar apostador, jogo ou placar">
			</div>
		</section>

		<section class="audit-controls" aria-label="Filtros da auditoria">
			<div class="audit-control">
				<label for="auditGroupMode">Agrupar</label>
				<select id="auditGroupMode">
					<option value="date">Por data</option>
					<option value="group">Por grupo/fase</option>
				</select>
			</div>
			<div class="audit-control">
				<label for="auditPickStatus">Status</label>
				<select id="auditPickStatus">
					<option value="all">Todos</option>
					<option value="filled">Com palpite</option>
					<option value="missing">Sem palpite</option>
					<option value="admin">Somente admins</option>
				</select>
			</div>
			<div class="audit-control audit-control-actions">
				<label>Apostas</label>
				<div class="audit-control-buttons">
					<button type="button" id="auditExpandAll">Expandir todos</button>
					<button type="button" id="auditCollapseAll">Contrair todos</button>
				</div>
			</div>
		</section>

		<section class="audit-metrics" aria-label="Resumo da auditoria">
			<div class="audit-metric"><strong><?php echo (int)count($lockedGames); ?></strong><span>jogos visiveis</span></div>
			<div class="audit-metric"><strong><?php echo (int)count($usuarios); ?></strong><span>apostadores</span></div>
			<div class="audit-metric audit-metric-admin"><strong><?php echo (int)count($admins); ?></strong><span>admins na lista</span></div>
			<div class="audit-metric"><strong><?php echo strh((string)$coverage); ?>%</strong><span>palpites preenchidos</span></div>
		</section>

		<?php if (count($lockedGames) === 0): ?>
			<section class="audit-empty">
				<strong>Nenhum jogo travado ainda.</strong>
				<span>Assim que a primeira trava de apostas acontecer, os jogos aparecem aqui automaticamente.</span>
			</section>
		<?php else: ?>
			<div class="audit-view is-active" data-audit-view="date">
			<?php foreach ($gamesByDay as $day => $games): ?>
				<section class="audit-day" data-audit-day="<?php echo strh($day); ?>">
					<div class="audit-day-head">
						<div>
							<div class="audit-section-title"><?php echo strh(fmt_day_title_audit($day)); ?></div>
							<div class="audit-day-sub">Trava as <?php echo strh(($dayLockAt[$day] ?? $now)->format('H:i')); ?>, <?php echo (int)count($games); ?> jogos liberados para auditoria</div>
						</div>
					</div>

					<?php foreach ($games as $game): ?>
						<?php
						$jid = (int)$game["id"];
						$picks = $palpitesByGameUser[$jid] ?? [];
						$filled = count($picks);
						$missing = max(0, count($usuarios) - $filled);
						$casa = (string)$game["casa_nome"];
						$fora = (string)$game["fora_nome"];
						$phase = phase_label_audit((string)$game["fase"], isset($game["grupo_codigo"]) ? (string)$game["grupo_codigo"] : null);
						?>
						<article class="audit-game is-collapsed" data-search="<?php echo strh(lower_utf8($casa . ' ' . $fora . ' ' . $phase . ' ' . (string)($game["codigo_fifa"] ?? ''))); ?>">
							<header class="audit-game-head">
								<div>
									<div class="audit-game-meta">
										<span><?php echo strh(fmt_when_audit((string)$game["data_hora"])); ?></span>
										<span><?php echo strh($phase); ?></span>
										<?php if (!empty($game["codigo_fifa"])): ?><span>FIFA <?php echo strh((string)$game["codigo_fifa"]); ?></span><?php endif; ?>
									</div>
									<h2><?php echo strh($casa); ?> <span>x</span> <?php echo strh($fora); ?></h2>
								</div>
								<div class="audit-game-counts">
									<strong><?php echo (int)$filled; ?>/<?php echo (int)count($usuarios); ?></strong>
									<span><?php echo (int)$missing; ?> sem palpite</span>
									<button class="audit-toggle-game" type="button" aria-expanded="false">Mostrar apostas</button>
								</div>
							</header>

							<div class="audit-picks">
								<?php foreach ($usuarios as $user): ?>
									<?php
									$uid = (int)$user["id"];
									$isUserAdmin = (upper_utf8((string)($user["tipo_usuario"] ?? "")) === "ADMIN");
									$pick = $picks[$uid] ?? null;
									$pickText = "Sem palpite";
									$passText = "";
									if (is_array($pick)) {
										$pickText = (string)(int)$pick["gols_casa"] . " x " . (string)(int)$pick["gols_fora"];
										if ((int)$pick["gols_casa"] === (int)$pick["gols_fora"] && !empty($pick["passa_nome"])) {
											$passText = "Passa: " . (string)$pick["passa_nome"];
										}
									}
									?>
									<div class="audit-pick<?php echo $isUserAdmin ? ' is-admin' : ''; ?><?php echo $pick ? '' : ' is-missing'; ?>" data-pick-status="<?php echo $pick ? 'filled' : 'missing'; ?>" data-is-admin="<?php echo $isUserAdmin ? '1' : '0'; ?>" data-search="<?php echo strh(lower_utf8((string)$user["nome"] . ' ' . $pickText . ' ' . $passText)); ?>">
										<div class="audit-person">
											<strong><?php echo strh((string)$user["nome"]); ?></strong>
											<?php if ($isUserAdmin): ?><span>ADMIN</span><?php endif; ?>
										</div>
										<div class="audit-score">
											<strong><?php echo strh($pickText); ?></strong>
											<?php if ($passText !== ''): ?><small><?php echo strh($passText); ?></small><?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
			</div>

			<div class="audit-view" data-audit-view="group">
			<?php foreach ($gamesByGroup as $groupKey => $games): ?>
				<section class="audit-day" data-audit-group="<?php echo strh($groupKey); ?>">
					<div class="audit-day-head">
						<div>
							<div class="audit-section-title"><?php echo strh(group_section_title_audit($groupKey, $games)); ?></div>
							<div class="audit-day-sub"><?php echo (int)count($games); ?> jogos travados nesta chave de auditoria</div>
						</div>
					</div>

					<?php foreach ($games as $game): ?>
						<?php
						$jid = (int)$game["id"];
						$picks = $palpitesByGameUser[$jid] ?? [];
						$filled = count($picks);
						$missing = max(0, count($usuarios) - $filled);
						$casa = (string)$game["casa_nome"];
						$fora = (string)$game["fora_nome"];
						$phase = phase_label_audit((string)$game["fase"], isset($game["grupo_codigo"]) ? (string)$game["grupo_codigo"] : null);
						?>
						<article class="audit-game is-collapsed" data-search="<?php echo strh(lower_utf8($casa . ' ' . $fora . ' ' . $phase . ' ' . (string)($game["codigo_fifa"] ?? ''))); ?>">
							<header class="audit-game-head">
								<div>
									<div class="audit-game-meta">
										<span><?php echo strh(fmt_when_audit((string)$game["data_hora"])); ?></span>
										<span><?php echo strh($phase); ?></span>
										<?php if (!empty($game["codigo_fifa"])): ?><span>FIFA <?php echo strh((string)$game["codigo_fifa"]); ?></span><?php endif; ?>
									</div>
									<h2><?php echo strh($casa); ?> <span>x</span> <?php echo strh($fora); ?></h2>
								</div>
								<div class="audit-game-counts">
									<strong><?php echo (int)$filled; ?>/<?php echo (int)count($usuarios); ?></strong>
									<span><?php echo (int)$missing; ?> sem palpite</span>
									<button class="audit-toggle-game" type="button" aria-expanded="false">Mostrar apostas</button>
								</div>
							</header>

							<div class="audit-picks">
								<?php foreach ($usuarios as $user): ?>
									<?php
									$uid = (int)$user["id"];
									$isUserAdmin = (upper_utf8((string)($user["tipo_usuario"] ?? "")) === "ADMIN");
									$pick = $picks[$uid] ?? null;
									$pickText = "Sem palpite";
									$passText = "";
									if (is_array($pick)) {
										$pickText = (string)(int)$pick["gols_casa"] . " x " . (string)(int)$pick["gols_fora"];
										if ((int)$pick["gols_casa"] === (int)$pick["gols_fora"] && !empty($pick["passa_nome"])) {
											$passText = "Passa: " . (string)$pick["passa_nome"];
										}
									}
									?>
									<div class="audit-pick<?php echo $isUserAdmin ? ' is-admin' : ''; ?><?php echo $pick ? '' : ' is-missing'; ?>" data-pick-status="<?php echo $pick ? 'filled' : 'missing'; ?>" data-is-admin="<?php echo $isUserAdmin ? '1' : '0'; ?>" data-search="<?php echo strh(lower_utf8((string)$user["nome"] . ' ' . $pickText . ' ' . $passText)); ?>">
										<div class="audit-person">
											<strong><?php echo strh((string)$user["nome"]); ?></strong>
											<?php if ($isUserAdmin): ?><span>ADMIN</span><?php endif; ?>
										</div>
										<div class="audit-score">
											<strong><?php echo strh($pickText); ?></strong>
											<?php if ($passText !== ''): ?><small><?php echo strh($passText); ?></small><?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
	var input = document.getElementById("auditFilter");
	var groupMode = document.getElementById("auditGroupMode");
	var pickStatus = document.getElementById("auditPickStatus");
	var expandAll = document.getElementById("auditExpandAll");
	var collapseAll = document.getElementById("auditCollapseAll");

	function activeView() {
		return document.querySelector('.audit-view.is-active');
	}

	function setGameExpanded(game, expanded) {
		var btn = game.querySelector(".audit-toggle-game");
		game.classList.toggle("is-collapsed", !expanded);
		if (btn) {
			btn.setAttribute("aria-expanded", expanded ? "true" : "false");
			btn.textContent = expanded ? "Contrair apostas" : "Mostrar apostas";
		}
	}

	function applyFilters() {
		var view = activeView();
		if (!view) return;

		var q = input ? (input.value || "").trim().toLowerCase() : "";
		var status = pickStatus ? String(pickStatus.value || "all") : "all";
		var hasActiveFilter = q !== "" || status !== "all";

		view.querySelectorAll(".audit-game").forEach(function (game) {
			var gameText = game.getAttribute("data-search") || "";
			var gameMatches = q && gameText.indexOf(q) >= 0;
			var anyPick = false;
			game.querySelectorAll(".audit-pick").forEach(function (pick) {
				var pickText = pick.getAttribute("data-search") || "";
				var statusOk = status === "all"
					|| (status === "admin" && pick.getAttribute("data-is-admin") === "1")
					|| (status === pick.getAttribute("data-pick-status"));
				var textOk = !q || gameMatches || pickText.indexOf(q) >= 0;
				var hit = statusOk && textOk;
				pick.hidden = !hit;
				if (hit) anyPick = true;
			});
			game.hidden = !anyPick;
			if (hasActiveFilter && anyPick) setGameExpanded(game, true);
		});

		view.querySelectorAll(".audit-day").forEach(function (section) {
			var anyGame = Array.prototype.some.call(section.querySelectorAll(".audit-game"), function (game) {
				return !game.hidden;
			});
			section.hidden = !anyGame;
		});
	}

	document.querySelectorAll(".audit-toggle-game").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var game = btn.closest(".audit-game");
			if (!game) return;
			setGameExpanded(game, game.classList.contains("is-collapsed"));
		});
	});

	if (groupMode) {
		groupMode.addEventListener("change", function () {
			document.querySelectorAll(".audit-view").forEach(function (view) {
				view.classList.toggle("is-active", view.getAttribute("data-audit-view") === groupMode.value);
			});
			applyFilters();
		});
	}

	if (input) input.addEventListener("input", applyFilters);
	if (pickStatus) pickStatus.addEventListener("change", applyFilters);

	if (expandAll) {
		expandAll.addEventListener("click", function () {
			var view = activeView();
			if (!view) return;
			view.querySelectorAll(".audit-game:not([hidden])").forEach(function (game) {
				setGameExpanded(game, true);
			});
		});
	}

	if (collapseAll) {
		collapseAll.addEventListener("click", function () {
			var view = activeView();
			if (!view) return;
			view.querySelectorAll(".audit-game").forEach(function (game) {
				setGameExpanded(game, false);
			});
		});
	}

	document.querySelectorAll(".audit-game").forEach(function (game) {
		setGameExpanded(game, false);
	});
});
</script>
</body>
</html>
