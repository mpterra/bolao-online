<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";

/*
|--------------------------------------------------------------------------
| APP.PHP - BOLÃO DA COPA (APOSTAS)
|--------------------------------------------------------------------------
| - Tela única para palpites na fase de grupos
| - Menu de grupos FILTRA (não rola a página)
| - Persistência em `palpites` (UPSERT por usuario_id + jogo_id)
| - Endpoint JSON no próprio arquivo (action=save)
| - Botão "Próximo (Grupo X)" ao final do último jogo do grupo
| - Botão "Anterior (Grupo X)" ao final do grupo (NOVO)
| - Botão "Recibo" imprime todas as apostas do apostador (por grupo)
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

function require_login(): void {
	if (empty($_SESSION["usuario_id"])) {
		header("Location: /bolao-da-copa/public/index.php");
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

	// se tiver opções "A ou B ou C", fica com a primeira
	$s = preg_replace('/\s+ou\s+.*/iu', '', $s) ?? $s;

	// remove conteúdo entre parênteses (se existir)
	$s = preg_replace('/\s*\(.*?\)\s*/u', ' ', $s) ?? $s;

	$s = mb_strtolower($s, 'UTF-8');

	// translitera acentos -> ASCII
	$t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
	if ($t !== false && $t !== "") $s = $t;

	// remove tudo que não é alfanumérico
	$s = preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;

	return $s;
}

/**
 * Retorna URL pública da bandeira se existir no disco, senão null.
 * Pasta: /public/img/flags/{slug}.png
 */
function flag_url(string $teamName): ?string {
	$slug = flag_slug($teamName);
	if ($slug === "") return null;

	$fsPath = __DIR__ . "/img/flags/" . $slug . ".png"; // app.php está em /public
	if (is_file($fsPath)) {
		return "/bolao-da-copa/public/img/flags/" . $slug . ".png";
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
		// formato típico MySQL: Y-m-d H:i:s
		$parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
		if ($parsed instanceof DateTimeImmutable) return $parsed;

		// fallback mais permissivo
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
 */
function compute_lock_for_logical_day(PDO $pdo, string $dayYmd): ?DateTimeImmutable {
	$sql = "
		SELECT MIN(j.data_hora)
		FROM jogos j
		INNER JOIN edicoes e ON e.id = j.edicao_id AND e.ativo = 1
		WHERE j.grupo_id IS NOT NULL
		  AND (j.fase = 'GRUPOS' OR j.fase = 'GRUPO' OR j.fase = 'FASE_DE_GRUPOS' OR j.fase LIKE '%GRUP%')
		  AND (
				(DATE(j.data_hora) = :day AND TIME(j.data_hora) >= '05:00:00')
			 OR (DATE(j.data_hora) = DATE_ADD(:day, INTERVAL 1 DAY) AND TIME(j.data_hora) < '05:00:00')
		  )
	";
	$st = $pdo->prepare($sql);
	$st->execute([":day" => $dayYmd]);
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

	// (1) Jogo passado / já iniciado
	if ($gameDt <= $now) {
		return "Jogo já iniciado/encerrado.";
	}

	// (2) Regra do dia lógico: 1h antes do primeiro jogo do dia lógico, trava TODOS daquele dia lógico
	$logicalDay = logical_bet_day($gameDt);
	$lockAt = get_lock_for_logical_day($pdo, $lockCache, $logicalDay);

	if ($lockAt instanceof DateTimeImmutable) {
		if ($now >= $lockAt) {
			return "Apostas do dia bloqueadas desde " . fmt_hm($lockAt) . ".";
		}
	}

	return null; // liberado
}

require_login();

$usuarioId   = (int)$_SESSION["usuario_id"];
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";

$tipoUsuario = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoUsuario, "UTF-8") === "ADMIN");

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$nowLogicalDay = logical_bet_day($now);

// cache global por request (dia lógico -> lockAt|null)
$lockCache = [];

/* ---------------------------
   Logout (opcional)
--------------------------- */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
	session_destroy();
	header("Location: /bolao-da-copa/public/index.php");
	exit;
}

/* ---------------------------
   API: salvar palpites (JSON)
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

		// ✅ traz data_hora para validar bloqueio
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

		// Se tentou salvar qualquer jogo bloqueado, falha o request (regra rígida)
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
   HTML: carregar grupos + jogos
--------------------------- */
try {
	$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
	if ($edicaoId <= 0) {
		throw new RuntimeException("Nenhuma edição ativa.");
	}

	// lock do “dia lógico atual” (para exibir no hint)
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
		$nextGrupo[$c] = $next; // pode ser null

		$prev = null;
		for ($j = $i - 1; $j >= 0; $j--) {
			$c2 = $codigos[$j];
			if (!empty($temJogos[$c2])) {
				$prev = $c2;
				break;
			}
		}
		$prevGrupo[$c] = $prev; // pode ser null
	}

} catch (Throwable $e) {
	http_response_code(500);
	echo "Erro ao carregar jogos.";
	exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
	<meta charset="UTF-8" />
	<title>Bolão da Copa - Palpites</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<link rel="stylesheet" href="/bolao-da-copa/public/css/style.css">
</head>
<body>

<div class="app-wrap">
	<header class="app-header">
		<div class="app-brand">
			<img src="/bolao-da-copa/public/img/logo.png" alt="Bolão" onerror="this.style.display='none'">
			<div class="app-title">
				<strong>Bolão da Copa</strong>
				<span>Fase de Grupos • Palpites</span>
			</div>
		</div>

		<div class="app-actions">
			<div class="user-chip" title="<?php echo strh($usuarioNome); ?>">
				<span class="dot"></span>
				<span class="user-chip-name"><?php echo strh($usuarioNome); ?></span>
			</div>

			<a class="btn-logout" href="/bolao-da-copa/public/app.php?action=logout">Sair</a>
		</div>
	</header>

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
			</div>

			<div class="menu-actions">
				<button class="btn-save-all" id="btnSalvarTudo" type="button">
					Salvar tudo
					<span class="kbd">Ctrl</span><span class="kbd">↵</span>
				</button>

				<!-- FIX: garante que o Recibo NÃO navega na aba atual (mesmo que o script.js tente). -->
				<button class="btn-receipt" id="btnRecibo" type="button" data-receipt-url="/bolao-da-copa/php/recibo.php">
					Recibo
				</button>

				<?php if ($isAdmin): ?>
					<a class="btn-admin" href="/bolao-da-copa/public/admin.php">Admin</a>
				<?php endif; ?>

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
				<p class="content-sub">Preencha o placar de cada jogo. Tudo é salvo no banco em <strong>palpites</strong>.</p>
			</div>

			<?php if (count($grupos) === 0): ?>
				<div class="placeholder">Nenhum grupo encontrado na edição ativa.</div>
			<?php else: ?>

				<?php foreach ($grupos as $g): ?>
					<?php
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
					?>
					<section class="group-block" data-grupo="<?php echo strh($codigo); ?>">
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

									$dtGame = dt_from_mysql((string)$j["data_hora"]);
									$lockReason = lock_reason_for_game($dtGame, $now, $pdo, $lockCache);
									$isLocked = ($lockReason !== null);

									$dh = fmt_datahora((string)$j["data_hora"]);
									$rodada = $j["rodada"] !== null ? (int)$j["rodada"] : null;

									$pc = $j["palpite_casa"];
									$pf = $j["palpite_fora"];

									$pcVal = ($pc === null) ? "" : (string)(int)$pc;
									$pfVal = ($pf === null) ? "" : (string)(int)$pf;

									$flagCasa = flag_url($casa);
									$flagFora = flag_url($fora);
									?>
									<article class="match-card <?php echo $isLocked ? "is-locked" : ""; ?>"
											 data-jogo-id="<?php echo $jid; ?>"
											 data-grupo="<?php echo strh($codigo); ?>"
											 data-when="<?php echo strh($dh); ?>"
											 data-home="<?php echo strh($casa); ?>"
											 data-away="<?php echo strh($fora); ?>"
											 data-locked="<?php echo $isLocked ? "1" : "0"; ?>">
										<div class="match-top">
											<div class="match-when">
												<span class="when"><?php echo strh($dh); ?></span>
												<?php if ($rodada !== null): ?>
													<span class="round">Rodada <?php echo $rodada; ?></span>
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

<script>
  window.__APP_USER__ = <?php echo json_encode([
      "nome" => $usuarioNome,
      "id"   => $usuarioId,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  // Info útil pro front (dia lógico + trava do dia lógico atual)
  window.__LOCK_INFO__ = <?php echo json_encode([
      "now_logical_day" => $nowLogicalDay,
      "lock_logical_day_at" => ($lockNowLogicalDayAt instanceof DateTimeImmutable) ? $lockNowLogicalDayAt->format('Y-m-d H:i:s') : null,
      "lock_logical_day_active" => (bool)$lockNowLogicalDayActive,
      "logical_day_rule" => "00:00-04:59 pertence ao dia anterior",
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

<script>
  // FIX Recibo: abre em nova aba e impede qualquer navegação/alteração na aba atual,
  // mesmo que exista handler no script.js que faça window.location.
  (function () {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('#btnRecibo') : null;
      if (!btn) return;

      e.preventDefault();
      e.stopImmediatePropagation(); // mata handlers do script.js no mesmo clique

      var url = btn.getAttribute('data-receipt-url') || '/bolao-da-copa/public/recibo.php';
      window.open(url, '_blank', 'noopener,noreferrer');
    }, true); // capture: roda antes de handlers normais
  })();
</script>

<script src="/bolao-da-copa/public/js/script.js"></script>
</body>
</html>
