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

const AUDIT_CAMPEAO_PICK_DEADLINE = '2026-06-11 15:00:00';
const AUDIT_GROUP_RANK_DEADLINE = '2026-06-11 15:00:00';

function champion_pick_deadline_audit(): DateTimeImmutable {
	static $deadline = null;
	if ($deadline instanceof DateTimeImmutable) return $deadline;
	$deadline = new DateTimeImmutable(AUDIT_CAMPEAO_PICK_DEADLINE, new DateTimeZone('America/Sao_Paulo'));
	return $deadline;
}

function champion_pick_is_locked_audit(?DateTimeImmutable $now = null): bool {
	$now = $now ?? new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
	return $now > champion_pick_deadline_audit();
}

function group_rank_deadline_audit(): DateTimeImmutable {
	static $deadline = null;
	if ($deadline instanceof DateTimeImmutable) return $deadline;
	$deadline = new DateTimeImmutable(AUDIT_GROUP_RANK_DEADLINE, new DateTimeZone('America/Sao_Paulo'));
	return $deadline;
}

function group_rank_is_locked_audit(?DateTimeImmutable $now = null): bool {
	$now = $now ?? new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
	return $now >= group_rank_deadline_audit();
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

function flag_slug_from_name_audit(string $nome): string {
	$s = trim($nome);
	if ($s === '') return '';

	$s = preg_replace('/\s+ou\s+.*/iu', '', $s) ?? $s;
	$s = preg_replace('/\s*\(.*?\)\s*/u', ' ', $s) ?? $s;
	$s = mb_strtolower($s, 'UTF-8');

	$t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
	if ($t !== false && $t !== '') $s = $t;

	$s = preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;
	return $s;
}

function flag_slug_aliases_audit(string $slugBase): array {
	$map = [
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
	if ($slugBase !== '') {
		$out[] = $slugBase;
		if (isset($map[$slugBase])) $out[] = $map[$slugBase];
	}

	return array_values(array_unique($out));
}

function flag_url_for_team_audit(string $teamName, string $sigla): ?string {
	$baseDir = __DIR__ . "/img/flags";
	$candidates = [];

	$slugByName = flag_slug_from_name_audit($teamName);
	foreach (flag_slug_aliases_audit($slugByName) as $slug) {
		if ($slug !== '') $candidates[] = $slug;
	}

	$sig = trim($sigla);
	if ($sig !== '') {
		$sig = mb_strtolower($sig, 'UTF-8');
		$sig = preg_replace('/[^a-z0-9]+/i', '', $sig) ?? $sig;
		if ($sig !== '') $candidates[] = $sig;
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

function render_audit_flag_media(?string $flagUrl, string $sigla, string $teamName, string $sizeClass = ''): void {
	$sig = trim($sigla);
	if ($sig === '') $sig = upper_utf8(mb_substr(trim($teamName), 0, 3, 'UTF-8'));
	?>
	<span class="audit-flag<?php echo $sizeClass !== '' ? ' ' . strh($sizeClass) : ''; ?><?php echo $flagUrl ? '' : ' no-flag'; ?>">
		<?php if ($flagUrl): ?>
			<img src="<?php echo strh($flagUrl); ?>" alt="" loading="lazy" decoding="async">
		<?php endif; ?>
		<span class="audit-flag-badge"><?php echo strh($sig); ?></span>
	</span>
	<?php
}

function render_audit_team_choice(?array $team, string $label, string $sizeClass = 'is-small'): void {
	$name = trim((string)($team['nome'] ?? ''));
	$sigla = trim((string)($team['sigla'] ?? ''));
	$flagUrl = trim((string)($team['flag_url'] ?? ''));
	$hasTeam = $name !== '';
	?>
	<div class="audit-team-choice<?php echo $hasTeam ? '' : ' is-empty'; ?>">
		<?php if ($hasTeam): ?>
			<?php render_audit_flag_media($flagUrl !== '' ? $flagUrl : null, $sigla, $name, $sizeClass); ?>
		<?php else: ?>
			<span class="audit-team-choice-placeholder<?php echo $sizeClass !== '' ? ' ' . strh($sizeClass) : ''; ?>">-</span>
		<?php endif; ?>
		<div class="audit-team-choice-copy">
			<?php if ($label !== ''): ?><div class="audit-team-choice-label"><?php echo strh($label); ?></div><?php endif; ?>
			<div class="audit-team-choice-name"><?php echo strh($hasTeam ? $name : 'Nao preenchido'); ?></div>
			<?php if ($hasTeam && $sigla !== ''): ?><div class="audit-team-choice-meta"><?php echo strh($sigla); ?></div><?php endif; ?>
		</div>
	</div>
	<?php
}

function render_audit_game_card(array $game, array $usuarios, array $picks): void {
	$filled = count($picks);
	$missing = max(0, count($usuarios) - $filled);
	$casa = (string)($game["casa_nome"] ?? "");
	$fora = (string)($game["fora_nome"] ?? "");
	$casaSigla = (string)($game["casa_sigla"] ?? "");
	$foraSigla = (string)($game["fora_sigla"] ?? "");
	$flagCasa = isset($game["casa_flag"]) ? (string)$game["casa_flag"] : flag_url_for_team_audit($casa, $casaSigla);
	$flagFora = isset($game["fora_flag"]) ? (string)$game["fora_flag"] : flag_url_for_team_audit($fora, $foraSigla);
	$phase = phase_label_audit((string)($game["fase"] ?? ""), isset($game["grupo_codigo"]) ? (string)$game["grupo_codigo"] : null);
	?>
	<article class="audit-game is-collapsed" data-search="<?php echo strh(lower_utf8($casa . ' ' . $casaSigla . ' ' . $fora . ' ' . $foraSigla . ' ' . $phase . ' ' . (string)($game["codigo_fifa"] ?? ''))); ?>">
		<header class="audit-game-head">
			<div class="audit-game-main">
				<div class="audit-game-meta">
					<span><?php echo strh(fmt_when_audit((string)$game["data_hora"])); ?></span>
					<span><?php echo strh($phase); ?></span>
					<?php if (!empty($game["codigo_fifa"])): ?><span>FIFA <?php echo strh((string)$game["codigo_fifa"]); ?></span><?php endif; ?>
				</div>

				<div class="audit-game-matchup">
					<div class="audit-game-team">
						<?php render_audit_flag_media($flagCasa, $casaSigla, $casa, 'is-large'); ?>
						<div class="audit-game-team-copy">
							<div class="audit-game-team-name"><?php echo strh($casa); ?></div>
							<?php if ($casaSigla !== ''): ?><div class="audit-game-team-sigla"><?php echo strh($casaSigla); ?></div><?php endif; ?>
						</div>
					</div>

					<div class="audit-game-versus" aria-hidden="true">x</div>

					<div class="audit-game-team">
						<?php render_audit_flag_media($flagFora, $foraSigla, $fora, 'is-large'); ?>
						<div class="audit-game-team-copy">
							<div class="audit-game-team-name"><?php echo strh($fora); ?></div>
							<?php if ($foraSigla !== ''): ?><div class="audit-game-team-sigla"><?php echo strh($foraSigla); ?></div><?php endif; ?>
						</div>
					</div>
				</div>
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
				$uid = (int)($user["id"] ?? 0);
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
					<div class="audit-pick<?php echo $isUserAdmin ? ' is-admin' : ''; ?><?php echo $pick ? '' : ' is-missing'; ?>" data-pick-status="<?php echo $pick ? 'filled' : 'missing'; ?>" data-is-admin="<?php echo $isUserAdmin ? '1' : '0'; ?>" data-search="<?php echo strh(lower_utf8((string)$user["nome"] . ' ' . $pickText . ' ' . $passText . ' ' . $casa . ' ' . $fora . ' ' . $casaSigla . ' ' . $foraSigla)); ?>">
						<div class="audit-person">
							<strong><?php echo strh((string)$user["nome"]); ?></strong>
							<?php if ($isUserAdmin): ?><span>ADMIN</span><?php endif; ?>
						</div>
						<div class="audit-score">
							<div class="audit-scoreboard" aria-hidden="true">
								<div class="audit-score-team is-home">
									<?php render_audit_flag_media($flagCasa, $casaSigla, $casa, 'is-small'); ?>
									<span class="audit-score-team-label"><?php echo strh($casa); ?></span>
								</div>

								<div class="audit-score-result">
									<strong><?php echo strh($pickText); ?></strong>
									<?php if ($passText !== ''): ?><small><?php echo strh($passText); ?></small><?php endif; ?>
								</div>

								<div class="audit-score-team is-away">
									<span class="audit-score-team-label"><?php echo strh($fora); ?></span>
									<?php render_audit_flag_media($flagFora, $foraSigla, $fora, 'is-small'); ?>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
	</article>
	<?php
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

	$championDeadlineAt = champion_pick_deadline_audit();
	$championDeadlineLabel = $championDeadlineAt->format('d/m/Y \à\s H:i:s');
	$championAuditUnlocked = champion_pick_is_locked_audit($now);

	$groupRankDeadlineAt = group_rank_deadline_audit();
	$groupRankDeadlineLabel = $groupRankDeadlineAt->format('d/m/Y \à\s H:i');
	$groupRankAuditUnlocked = group_rank_is_locked_audit($now);

	$stGroups = $pdo->prepare("
		SELECT id, codigo, COALESCE(nome, CONCAT('Grupo ', codigo)) AS nome
		FROM grupos
		WHERE edicao_id = ?
		ORDER BY codigo ASC, id ASC
	");
	$stGroups->execute([$edicaoId]);
	$groups = $stGroups->fetchAll(PDO::FETCH_ASSOC);
	if (!is_array($groups)) $groups = [];

	$championPicksByUser = [];
	$championFilledCount = 0;
	if ($championAuditUnlocked) {
		$stChampion = $pdo->prepare("
			SELECT
				pc.usuario_id,
				pc.time_id,
				t.nome AS time_nome,
				t.sigla AS time_sigla
			FROM palpite_campeao pc
			LEFT JOIN times t ON t.id = pc.time_id
			WHERE pc.edicao_id = ?
		");
		$stChampion->execute([$edicaoId]);
		while ($row = $stChampion->fetch(PDO::FETCH_ASSOC)) {
			$uid = (int)($row['usuario_id'] ?? 0);
			if ($uid <= 0) continue;

			$teamId = (int)($row['time_id'] ?? 0);
			$teamName = (string)($row['time_nome'] ?? '');
			$teamSigla = (string)($row['time_sigla'] ?? '');
			$championPicksByUser[$uid] = [
				'time_id' => $teamId,
				'nome' => $teamName,
				'sigla' => $teamSigla,
				'flag_url' => $teamId > 0 ? flag_url_for_team_audit($teamName, $teamSigla) : null,
			];

			if ($teamId > 0) $championFilledCount++;
		}
	}
	$championCoverage = count($usuarios) > 0 ? round(($championFilledCount / count($usuarios)) * 100, 1) : 0;

	$groupRankPicksByGroupUser = [];
	$groupRankFilledCount = 0;
	if ($groupRankAuditUnlocked && count($groups) > 0) {
		$stGroupRanks = $pdo->prepare("
			SELECT
				pgc.usuario_id,
				pgc.grupo_id,
				pgc.primeiro_time_id,
				pgc.segundo_time_id,
				pgc.terceiro_time_id,
				t1.nome AS primeiro_nome,
				t1.sigla AS primeiro_sigla,
				t2.nome AS segundo_nome,
				t2.sigla AS segundo_sigla,
				t3.nome AS terceiro_nome,
				t3.sigla AS terceiro_sigla
			FROM palpite_grupo_classificacao pgc
			LEFT JOIN times t1 ON t1.id = pgc.primeiro_time_id
			LEFT JOIN times t2 ON t2.id = pgc.segundo_time_id
			LEFT JOIN times t3 ON t3.id = pgc.terceiro_time_id
			WHERE pgc.edicao_id = ?
			ORDER BY pgc.grupo_id ASC, pgc.usuario_id ASC
		");
		$stGroupRanks->execute([$edicaoId]);
		while ($row = $stGroupRanks->fetch(PDO::FETCH_ASSOC)) {
			$gid = (int)($row['grupo_id'] ?? 0);
			$uid = (int)($row['usuario_id'] ?? 0);
			if ($gid <= 0 || $uid <= 0) continue;

			$slot1 = [
				'time_id' => (int)($row['primeiro_time_id'] ?? 0),
				'nome' => (string)($row['primeiro_nome'] ?? ''),
				'sigla' => (string)($row['primeiro_sigla'] ?? ''),
			];
			$slot2 = [
				'time_id' => (int)($row['segundo_time_id'] ?? 0),
				'nome' => (string)($row['segundo_nome'] ?? ''),
				'sigla' => (string)($row['segundo_sigla'] ?? ''),
			];
			$slot3 = [
				'time_id' => (int)($row['terceiro_time_id'] ?? 0),
				'nome' => (string)($row['terceiro_nome'] ?? ''),
				'sigla' => (string)($row['terceiro_sigla'] ?? ''),
			];

			$slot1['flag_url'] = ((int)$slot1['time_id'] > 0)
				? flag_url_for_team_audit((string)$slot1['nome'], (string)$slot1['sigla'])
				: null;
			$slot2['flag_url'] = ((int)$slot2['time_id'] > 0)
				? flag_url_for_team_audit((string)$slot2['nome'], (string)$slot2['sigla'])
				: null;
			$slot3['flag_url'] = ((int)$slot3['time_id'] > 0)
				? flag_url_for_team_audit((string)$slot3['nome'], (string)$slot3['sigla'])
				: null;

			$isFilled = ((int)$slot1['time_id'] > 0 && (int)$slot2['time_id'] > 0 && (int)$slot3['time_id'] > 0);
			if ($isFilled) $groupRankFilledCount++;

			if (!isset($groupRankPicksByGroupUser[$gid])) $groupRankPicksByGroupUser[$gid] = [];
			$groupRankPicksByGroupUser[$gid][$uid] = [
				1 => $slot1,
				2 => $slot2,
				3 => $slot3,
			];
		}
	}
	$groupRankExpectedCount = count($usuarios) * count($groups);
	$groupRankCoverage = $groupRankExpectedCount > 0 ? round(($groupRankFilledCount / $groupRankExpectedCount) * 100, 1) : 0;

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
		$game["casa_flag"] = flag_url_for_team_audit((string)($game["casa_nome"] ?? ""), (string)($game["casa_sigla"] ?? ""));
		$game["fora_flag"] = flag_url_for_team_audit((string)($game["fora_nome"] ?? ""), (string)($game["fora_sigla"] ?? ""));
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

		<section class="audit-specials" aria-label="Apostas especiais travadas">
			<section class="audit-special-block is-collapsed"<?php echo ($championAuditUnlocked && count($usuarios) > 0) ? ' data-filter-target=".audit-champion-card"' : ''; ?>>
				<div class="audit-day-head audit-special-head">
					<div>
						<div class="audit-section-title">Campeao</div>
						<div class="audit-day-sub">Auditoria consolidada de quem cada apostador escolheu para vencer a edicao.</div>
					</div>
					<div class="audit-special-actions">
						<div class="audit-special-stats">
							<?php if ($championAuditUnlocked): ?>
								<span><?php echo (int)$championFilledCount; ?>/<?php echo (int)count($usuarios); ?> preenchidos</span>
								<span><?php echo strh((string)$championCoverage); ?>% cobertura</span>
							<?php else: ?>
								<span>Libera em <?php echo strh($championDeadlineLabel); ?></span>
							<?php endif; ?>
						</div>
						<button class="audit-toggle-special" type="button" aria-expanded="false">Mostrar</button>
					</div>
				</div>

				<div class="audit-special-body">
					<?php if (!$championAuditUnlocked): ?>
						<div class="audit-special-pending">
							A auditoria do campeao aparece aqui automaticamente quando a trava fechar em <?php echo strh($championDeadlineLabel); ?>.
						</div>
					<?php elseif (count($usuarios) === 0): ?>
						<div class="audit-special-pending">Nenhum apostador ativo encontrado para auditar.</div>
					<?php else: ?>
						<div class="audit-champion-grid">
							<?php foreach ($usuarios as $user): ?>
								<?php
								$uid = (int)($user['id'] ?? 0);
								$isUserAdmin = (upper_utf8((string)($user['tipo_usuario'] ?? '')) === 'ADMIN');
								$championPick = $championPicksByUser[$uid] ?? null;
								$isFilled = is_array($championPick) && (int)($championPick['time_id'] ?? 0) > 0;
								$championSearch = lower_utf8(trim(
									(string)($user['nome'] ?? '') . ' ' .
									($isFilled ? (string)($championPick['nome'] ?? '') . ' ' . (string)($championPick['sigla'] ?? '') : 'sem palpite campeao')
								));
								?>
								<article class="audit-champion-card<?php echo $isUserAdmin ? ' is-admin' : ''; ?><?php echo $isFilled ? '' : ' is-missing'; ?>"
								         data-pick-status="<?php echo $isFilled ? 'filled' : 'missing'; ?>"
								         data-is-admin="<?php echo $isUserAdmin ? '1' : '0'; ?>"
								         data-search="<?php echo strh($championSearch); ?>">
									<div class="audit-champion-top">
										<div class="audit-person">
											<strong><?php echo strh((string)$user['nome']); ?></strong>
											<?php if ($isUserAdmin): ?><span>ADMIN</span><?php endif; ?>
										</div>
										<span class="audit-summary-state <?php echo $isFilled ? 'is-filled' : 'is-missing'; ?>">
											<?php echo $isFilled ? 'Preenchido' : 'Sem palpite'; ?>
										</span>
									</div>

									<div class="audit-champion-pick">
										<?php render_audit_team_choice($isFilled ? $championPick : null, 'Campeao', 'is-large'); ?>
									</div>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<section class="audit-special-block is-collapsed"<?php echo ($groupRankAuditUnlocked && count($groups) > 0 && count($usuarios) > 0) ? ' data-filter-target=".audit-rank-user-card"' : ''; ?>>
				<div class="audit-day-head audit-special-head">
					<div>
						<div class="audit-section-title">1o, 2o e 3o de cada grupo</div>
						<div class="audit-day-sub">Auditoria das classificacoes travadas, organizada por grupo para comparacao rapida.</div>
					</div>
					<div class="audit-special-actions">
						<div class="audit-special-stats">
							<?php if ($groupRankAuditUnlocked): ?>
								<span><?php echo (int)$groupRankFilledCount; ?>/<?php echo (int)$groupRankExpectedCount; ?> grupos completos</span>
								<span><?php echo strh((string)$groupRankCoverage); ?>% cobertura</span>
							<?php else: ?>
								<span>Libera em <?php echo strh($groupRankDeadlineLabel); ?></span>
							<?php endif; ?>
						</div>
						<button class="audit-toggle-special" type="button" aria-expanded="false">Mostrar</button>
					</div>
				</div>

				<div class="audit-special-body">
					<?php if (count($groups) === 0): ?>
						<div class="audit-special-pending">Nenhum grupo cadastrado na edicao ativa.</div>
					<?php elseif (count($usuarios) === 0): ?>
						<div class="audit-special-pending">Nenhum apostador ativo encontrado para auditar.</div>
					<?php elseif (!$groupRankAuditUnlocked): ?>
						<div class="audit-special-pending">
							A auditoria das classificacoes de grupo sera liberada aqui automaticamente em <?php echo strh($groupRankDeadlineLabel); ?>.
						</div>
					<?php else: ?>
						<div class="audit-rank-users">
							<?php foreach ($usuarios as $user): ?>
								<?php
								$uid = (int)($user['id'] ?? 0);
								$isUserAdmin = (upper_utf8((string)($user['tipo_usuario'] ?? '')) === 'ADMIN');
								$completedGroups = 0;
								$searchParts = [(string)($user['nome'] ?? '')];
								foreach ($groups as $groupIndexItem) {
									$gidSearch = (int)($groupIndexItem['id'] ?? 0);
									$groupCodeSearch = trim((string)($groupIndexItem['codigo'] ?? ''));
									$groupNameSearch = trim((string)($groupIndexItem['nome'] ?? ''));
									$rankPickSearch = $groupRankPicksByGroupUser[$gidSearch][$uid] ?? null;
									$slot1Search = is_array($rankPickSearch) ? ($rankPickSearch[1] ?? null) : null;
									$slot2Search = is_array($rankPickSearch) ? ($rankPickSearch[2] ?? null) : null;
									$slot3Search = is_array($rankPickSearch) ? ($rankPickSearch[3] ?? null) : null;
									$isGroupFilledSearch = is_array($slot1Search) && is_array($slot2Search) && is_array($slot3Search)
										&& (int)($slot1Search['time_id'] ?? 0) > 0
										&& (int)($slot2Search['time_id'] ?? 0) > 0
										&& (int)($slot3Search['time_id'] ?? 0) > 0;
									if ($isGroupFilledSearch) $completedGroups++;
									$searchParts[] = $groupNameSearch;
									$searchParts[] = $groupCodeSearch;
									$searchParts[] = (string)($slot1Search['nome'] ?? '');
									$searchParts[] = (string)($slot1Search['sigla'] ?? '');
									$searchParts[] = (string)($slot2Search['nome'] ?? '');
									$searchParts[] = (string)($slot2Search['sigla'] ?? '');
									$searchParts[] = (string)($slot3Search['nome'] ?? '');
									$searchParts[] = (string)($slot3Search['sigla'] ?? '');
								}
								$totalGroups = count($groups);
								$isFilled = ($totalGroups > 0 && $completedGroups === $totalGroups);
								$statusLabel = $isFilled ? 'Completo' : ($completedGroups > 0 ? 'Incompleto' : 'Sem palpite');
								$rankSearch = lower_utf8(trim(implode(' ', $searchParts)));
								?>
								<article class="audit-rank-user-card<?php echo $isUserAdmin ? ' is-admin' : ''; ?><?php echo $isFilled ? '' : ' is-missing'; ?> is-collapsed"
								         data-pick-status="<?php echo $isFilled ? 'filled' : 'missing'; ?>"
								         data-is-admin="<?php echo $isUserAdmin ? '1' : '0'; ?>"
								         data-search="<?php echo strh($rankSearch); ?>">
									<div class="audit-rank-user-card-head">
										<div class="audit-person">
											<strong><?php echo strh((string)$user['nome']); ?></strong>
											<?php if ($isUserAdmin): ?><span>ADMIN</span><?php endif; ?>
										</div>
										<div class="audit-rank-user-card-actions">
											<span class="audit-summary-state <?php echo $isFilled ? 'is-filled' : 'is-missing'; ?>">
												<?php echo strh($statusLabel); ?>
											</span>
											<span class="audit-rank-user-count"><?php echo (int)$completedGroups; ?>/<?php echo (int)$totalGroups; ?> grupos</span>
											<button class="audit-toggle-rank-user" type="button" aria-expanded="false">Mostrar</button>
										</div>
									</div>

									<div class="audit-rank-user-card-body">
										<div class="audit-rank-user-groups">
											<?php foreach ($groups as $group): ?>
												<?php
												$gid = (int)($group['id'] ?? 0);
												$groupCode = trim((string)($group['codigo'] ?? ''));
												$groupName = trim((string)($group['nome'] ?? ''));
												$rankPick = $groupRankPicksByGroupUser[$gid][$uid] ?? null;
												$slot1 = is_array($rankPick) ? ($rankPick[1] ?? null) : null;
												$slot2 = is_array($rankPick) ? ($rankPick[2] ?? null) : null;
												$slot3 = is_array($rankPick) ? ($rankPick[3] ?? null) : null;
												$isGroupFilled = is_array($slot1) && is_array($slot2) && is_array($slot3)
													&& (int)($slot1['time_id'] ?? 0) > 0
													&& (int)($slot2['time_id'] ?? 0) > 0
													&& (int)($slot3['time_id'] ?? 0) > 0;
												?>
												<section class="audit-rank-user-group<?php echo $isGroupFilled ? '' : ' is-missing'; ?>">
													<div class="audit-rank-user-group-head">
														<div>
															<div class="audit-rank-user-group-title"><?php echo strh($groupName !== '' ? $groupName : ('Grupo ' . $groupCode)); ?></div>
															<?php if ($groupCode !== ''): ?><div class="audit-rank-user-group-sub">Grupo <?php echo strh($groupCode); ?></div><?php endif; ?>
														</div>
														<span class="audit-summary-state <?php echo $isGroupFilled ? 'is-filled' : 'is-missing'; ?>">
															<?php echo $isGroupFilled ? 'Completo' : 'Pendente'; ?>
														</span>
													</div>

													<div class="audit-rank-slots">
														<div class="audit-rank-slot"><?php render_audit_team_choice($slot1, '1o'); ?></div>
														<div class="audit-rank-slot"><?php render_audit_team_choice($slot2, '2o'); ?></div>
														<div class="audit-rank-slot"><?php render_audit_team_choice($slot3, '3o'); ?></div>
													</div>
												</section>
											<?php endforeach; ?>
										</div>
									</div>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>
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
						?>
						<?php render_audit_game_card($game, $usuarios, $picks); ?>
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
						?>
						<?php render_audit_game_card($game, $usuarios, $picks); ?>
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

	function setSpecialExpanded(block, expanded) {
		var btn = block.querySelector(".audit-toggle-special");
		block.classList.toggle("is-collapsed", !expanded);
		if (btn) {
			btn.setAttribute("aria-expanded", expanded ? "true" : "false");
			btn.textContent = expanded ? "Ocultar" : "Mostrar";
		}
	}

	function setRankUserExpanded(card, expanded) {
		var btn = card.querySelector(".audit-toggle-rank-user");
		card.classList.toggle("is-collapsed", !expanded);
		if (btn) {
			btn.setAttribute("aria-expanded", expanded ? "true" : "false");
			btn.textContent = expanded ? "Ocultar" : "Mostrar";
		}
	}

	function applyFilters() {
		var q = input ? (input.value || "").trim().toLowerCase() : "";
		var status = pickStatus ? String(pickStatus.value || "all") : "all";
		var hasActiveFilter = q !== "" || status !== "all";

		document.querySelectorAll(".audit-champion-card").forEach(function (card) {
			var cardText = card.getAttribute("data-search") || "";
			var statusOk = status === "all"
				|| (status === "admin" && card.getAttribute("data-is-admin") === "1")
				|| (status === card.getAttribute("data-pick-status"));
			var textOk = !q || cardText.indexOf(q) >= 0;
			card.hidden = !(statusOk && textOk);
		});

		document.querySelectorAll(".audit-rank-user-card").forEach(function (card) {
			var cardText = card.getAttribute("data-search") || "";
			var statusOk = status === "all"
				|| (status === "admin" && card.getAttribute("data-is-admin") === "1")
				|| (status === card.getAttribute("data-pick-status"));
			var textOk = !q || cardText.indexOf(q) >= 0;
			var hit = statusOk && textOk;
			card.hidden = !hit;
			if (hasActiveFilter && hit) setRankUserExpanded(card, true);
		});

		document.querySelectorAll("[data-filter-target]").forEach(function (block) {
			var selector = block.getAttribute("data-filter-target");
			if (!selector) return;
			var anyVisible = Array.prototype.some.call(block.querySelectorAll(selector), function (item) {
				return !item.hidden;
			});
			block.hidden = !anyVisible;
			if (hasActiveFilter && anyVisible) setSpecialExpanded(block, true);
		});

		var view = activeView();
		if (!view) return;

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

	document.querySelectorAll(".audit-toggle-special").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var block = btn.closest(".audit-special-block");
			if (!block) return;
			setSpecialExpanded(block, block.classList.contains("is-collapsed"));
		});
	});

	document.querySelectorAll(".audit-toggle-rank-user").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var card = btn.closest(".audit-rank-user-card");
			if (!card) return;
			setRankUserExpanded(card, card.classList.contains("is-collapsed"));
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
			document.querySelectorAll(".audit-special-block:not([hidden])").forEach(function (block) {
				setSpecialExpanded(block, true);
			});
			document.querySelectorAll(".audit-rank-user-card:not([hidden])").forEach(function (card) {
				setRankUserExpanded(card, true);
			});
			var view = activeView();
			if (!view) return;
			view.querySelectorAll(".audit-game:not([hidden])").forEach(function (game) {
				setGameExpanded(game, true);
			});
		});
	}

	if (collapseAll) {
		collapseAll.addEventListener("click", function () {
			document.querySelectorAll(".audit-special-block").forEach(function (block) {
				setSpecialExpanded(block, false);
			});
			document.querySelectorAll(".audit-rank-user-card").forEach(function (card) {
				setRankUserExpanded(card, false);
			});
			var view = activeView();
			if (!view) return;
			view.querySelectorAll(".audit-game").forEach(function (game) {
				setGameExpanded(game, false);
			});
		});
	}

	document.querySelectorAll(".audit-special-block").forEach(function (block) {
		setSpecialExpanded(block, false);
	});

	document.querySelectorAll(".audit-rank-user-card").forEach(function (card) {
		setRankUserExpanded(card, false);
	});

	document.querySelectorAll(".audit-game").forEach(function (game) {
		setGameExpanded(game, false);
	});

	applyFilters();
});
</script>
</body>
</html>
