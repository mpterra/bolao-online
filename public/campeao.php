<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";

date_default_timezone_set('America/Sao_Paulo');

function json_response(array $data, int $code = 200): void {
	http_response_code($code);
	header("Content-Type: application/json; charset=utf-8");
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

/**
 * Normaliza nome do time -> nome do arquivo da bandeira (igual sua pasta /flags):
 * - minúsculas
 * - remove acentos
 * - remove espaços e símbolos
 * - se tiver " OU " pega apenas a primeira opção
 * Ex.: "Costa do Marfim" -> "costadomarfim"
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

function flag_url(string $teamName): ?string {
	$slug = flag_slug($teamName);
	if ($slug === "") return null;

	$fsPath = __DIR__ . "/img/flags/" . $slug . ".png"; // campeao.php está em /public
	if (is_file($fsPath)) {
		return "/img/flags/" . $slug . ".png";
	}
	return null;
}

require_login();

$usuarioId   = (int)($_SESSION["usuario_id"] ?? 0);
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";

$tipoUsuario = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoUsuario, "UTF-8") === "ADMIN");

/* Logout */
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
	session_destroy();
	header("Location: /index.php");
	exit;
}

try {
	$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
	if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

	// Times participantes da edição ativa (distintos via grupo_time)
	$sqlTimes = "
		SELECT DISTINCT t.id, t.nome, t.sigla
		FROM grupo_time gt
		INNER JOIN times t ON t.id = gt.time_id
		WHERE gt.edicao_id = :eid
		ORDER BY t.nome
	";
	$st = $pdo->prepare($sqlTimes);
	$st->execute([":eid" => $edicaoId]);
	$times = $st->fetchAll(PDO::FETCH_ASSOC);
	if (!is_array($times)) $times = [];

	// Campeão atual do usuário (se houver)
	$sqlPick = "
		SELECT pc.time_id
		FROM palpite_campeao pc
		WHERE pc.edicao_id = :eid
		  AND pc.usuario_id = :uid
		LIMIT 1
	";
	$stPick = $pdo->prepare($sqlPick);
	$stPick->execute([":eid" => $edicaoId, ":uid" => $usuarioId]);
	$selectedTimeId = (int)$stPick->fetchColumn();

} catch (Throwable $e) {
	http_response_code(500);
	echo "<pre style='white-space:pre-wrap;font:14px/1.4 monospace'>";
	echo "Erro ao carregar página Campeão:\n\n";
	echo $e->getMessage() . "\n\n";
	echo $e->getFile() . ":" . $e->getLine() . "\n\n";
	echo $e->getTraceAsString();
	echo "</pre>";
	exit;
}

/* ============================
   API: salvar campeão (JSON)
   ============================ */
if (isset($_GET["action"]) && $_GET["action"] === "save") {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		json_response(["ok" => false, "message" => "Método inválido."], 405);
	}

	$raw = file_get_contents("php://input");
	$payload = json_decode($raw ?: "{}", true);
	if (!is_array($payload)) {
		json_response(["ok" => false, "message" => "JSON inválido."], 400);
	}

	$timeId = isset($payload["time_id"]) ? (int)$payload["time_id"] : 0;
	if ($timeId <= 0) {
		json_response(["ok" => false, "message" => "Selecione um time."], 422);
	}

	try {
		// Valida se o time participa da edição ativa
		$sqlVal = "
			SELECT COUNT(*)
			FROM grupo_time gt
			WHERE gt.edicao_id = :eid
			  AND gt.time_id  = :tid
			LIMIT 1
		";
		$stVal = $pdo->prepare($sqlVal);
		$stVal->execute([":eid" => $edicaoId, ":tid" => $timeId]);
		$ok = (int)$stVal->fetchColumn();

		if ($ok <= 0) {
			json_response(["ok" => false, "message" => "Time inválido para a edição ativa."], 422);
		}

		$pdo->beginTransaction();

		$sqlUp = "
			INSERT INTO palpite_campeao (edicao_id, usuario_id, time_id)
			VALUES (:eid, :uid, :tid)
			ON DUPLICATE KEY UPDATE
				time_id = VALUES(time_id),
				atualizado_em = CURRENT_TIMESTAMP
		";
		$stUp = $pdo->prepare($sqlUp);
		$stUp->execute([":eid" => $edicaoId, ":uid" => $usuarioId, ":tid" => $timeId]);

		$pdo->commit();

		json_response(["ok" => true, "message" => "Campeão salvo com sucesso.", "time_id" => $timeId]);

	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_response(["ok" => false, "message" => "Falha ao salvar campeão."], 500);
	}
}

// include do header único
require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
	<meta charset="UTF-8" />
	<title>Bolão da Copa - Campeão</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<link rel="stylesheet" href="/css/campeao.css">
</head>
<body>

<div class="app-wrap">

	<?php
		render_app_header(
			$usuarioNome,
			$isAdmin,
			"campeao",
			"Quem será o campeão?",
			"/campeao.php?action=logout"
		);
	?>

	<div class="app-shell">
		<main class="app-content">

			<div class="content-head">
				<h1 class="content-h1">Quem será o campeão?</h1>
				<p class="content-sub">Toque no time e salve seu palpite.</p>
			</div>

			<section class="champ-card">
				<div class="champ-card-head">
					<div class="h1">Selecione o campeão</div>

					<!-- ✅ novo: busca -->
					<div class="champ-search" role="search" aria-label="Buscar time">
						<input
							type="search"
							id="champSearch"
							placeholder="Buscar time pelo nome (ESC limpa)…"
							autocomplete="off"
							spellcheck="false"
							aria-label="Buscar time pelo nome"
						/>
					</div>
				</div>

				<?php if (count($times) === 0): ?>
					<div class="champ-empty">Nenhum time encontrado na edição ativa (grupo_time).</div>
				<?php else: ?>

					<!-- ✅ novo: feedback de “nenhum resultado” -->
					<div class="champ-no-results" id="champNoResults">
						Nenhum time encontrado com esse filtro.
					</div>

					<div class="champ-grid" id="champGrid">
						<?php foreach ($times as $t): ?>
							<?php
							$tid = (int)$t["id"];
							$nome = (string)$t["nome"];
							$sigla = (string)$t["sigla"];
							$flag = flag_url($nome);
							$isSel = ($tid === $selectedTimeId);
							?>
							<button
								type="button"
								class="team-tile <?php echo $isSel ? "is-selected" : ""; ?>"
								data-time-id="<?php echo (int)$tid; ?>"
								data-time-name="<?php echo strh($nome); ?>"
								data-time-sigla="<?php echo strh($sigla); ?>"
								aria-pressed="<?php echo $isSel ? "true" : "false"; ?>"
							>
								<?php if ($flag !== null): ?>
									<img class="flag" src="<?php echo strh($flag); ?>" alt="Bandeira <?php echo strh($nome); ?>" loading="lazy" decoding="async">
								<?php else: ?>
									<div class="flag flag-fallback"><?php echo strh($sigla); ?></div>
								<?php endif; ?>

								<div class="meta">
									<div class="sigla"><?php echo strh($sigla); ?></div>
									<div class="nome"><?php echo strh($nome); ?></div>
								</div>

								<div class="check" aria-hidden="true">✓</div>
							</button>
						<?php endforeach; ?>
					</div>

					<div class="champ-footer">
						<button class="btn-save-champ" id="btnSaveChamp" type="button" disabled>Salvar campeão</button>
						<div class="champ-hint" id="champHint">
							<?php if ($selectedTimeId > 0): ?>
								Seu campeão já está selecionado. Você pode trocar e salvar novamente.
							<?php else: ?>
								Selecione um time para habilitar o botão.
							<?php endif; ?>
						</div>

						<div style="margin-top:10px;">
							<a class="btn-back" href="/app.php">Voltar para Apostas</a>
						</div>
					</div>

				<?php endif; ?>
			</section>

		</main>
	</div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

<script type="application/json" id="campeao-config">
<?php echo json_encode([
	"user" => ["id" => $usuarioId, "nome" => $usuarioNome],
	"edicao" => ["id" => $edicaoId],
	"selected_time_id" => $selectedTimeId,
	"endpoints" => [
		"save" => "/campeao.php?action=save",
		// opcional (o JS já tem fallback):
		"recibo" => "/php/recibo.php",
	],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<script src="/js/campeao.js"></script>
</body>
</html>