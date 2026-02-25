<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/../php/conexao.php";

/**
 * ✅ FIX FUSO HORÁRIO (BRASIL)
 */
date_default_timezone_set('America/Sao_Paulo');

function require_login(): void {
	if (empty($_SESSION["usuario_id"])) {
		header("Location: /index.php");
		exit;
	}
}

/**
 * UTF-8 -> Windows-1252 (WinAnsi) para fontes base do PDF.
 */
function pdf_winansi(string $s): string {
	$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $s) ?? $s;

	$converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
	if ($converted === false) {
		$converted = preg_replace('/[^\x20-\x7E]/', ' ', $s) ?? $s;
	}
	return $converted;
}

/**
 * Escapa string literal do PDF. (assume string já em Win-1252)
 */
function pdf_escape_literal(string $win1252): string {
	return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $win1252);
}

function fmt_dt_br(?string $dt): string {
	if (!$dt) return "";
	$ts = strtotime($dt);
	if ($ts === false) return (string)$dt;
	return date("d/m H:i", $ts);
}

/**
 * Trunca e alinha considerando largura em bytes (Win-1252 / monoespaçada).
 * $align: 'L' (left), 'R' (right), 'C' (center)
 */
function pdf_col(string $utf8, int $width, string $align = 'L'): string {
	$w = pdf_winansi(trim($utf8));

	if ($width <= 0) return "";

	$len = strlen($w);
	if ($len > $width) {
		if ($width >= 2) {
			// ellipsis Win-1252 (0x85)
			$w = substr($w, 0, $width - 1) . chr(0x85);
		} else {
			$w = substr($w, 0, $width);
		}
		$len = strlen($w);
	}

	if ($len < $width) {
		$pad = $width - $len;

		if ($align === 'R') {
			$w = str_repeat(' ', $pad) . $w;
		} elseif ($align === 'C') {
			$left = intdiv($pad, 2);
			$right = $pad - $left;
			$w = str_repeat(' ', $left) . $w . str_repeat(' ', $right);
		} else {
			$w = $w . str_repeat(' ', $pad);
		}
	}

	return $w;
}

/**
 * PDF mínimo (sem libs) — Helvetica/Courier com WinAnsiEncoding.
 */
final class SimplePdf {
	private array $objects = [];
	private array $pages = [];
	private int $objCount = 0;

	private int $pageW = 595; // A4 portrait (pt)
	private int $pageH = 842;

	private int $marginL = 38;
	private int $marginR = 38;
	private int $marginT = 44;
	private int $marginB = 44;

	private int $y;
	private string $content = "";

	private int $fontHelveticaObj;
	private int $fontCourierObj;

	private string $title = "Recibo";

	public function __construct(string $title = "Recibo") {
		$this->title = $title;
		$this->y = $this->pageH - $this->marginT;

		// ✅ CRÍTICO: /Encoding /WinAnsiEncoding
		$this->fontHelveticaObj = $this->newObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
		$this->fontCourierObj   = $this->newObject("<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>");

		$this->newPage();
	}

	private function newObject(string $body): int {
		$this->objCount++;
		$this->objects[$this->objCount] = $body;
		return $this->objCount;
	}

	private function startContent(): void {
		$this->content = "";
		$this->y = $this->pageH - $this->marginT;
	}

	private function finalizePage(): void {
		$stream = $this->content;

		$contentObj = $this->newObject("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");

		$resources = "<< /Font << /F1 {$this->fontHelveticaObj} 0 R /F2 {$this->fontCourierObj} 0 R >> >>";

		$pageObj = $this->newObject("<< /Type /Page /Parent 0 0 R /Resources $resources /MediaBox [0 0 {$this->pageW} {$this->pageH}] /Contents {$contentObj} 0 R >>");
		$this->pages[] = $pageObj;
	}

	public function newPage(): void {
		if (!empty($this->content) || count($this->pages) > 0) {
			$this->finalizePage();
		}
		$this->startContent();
	}

	private function ensureSpace(int $needed): void {
		if ($this->y - $needed < $this->marginB) {
			$this->newPage();
		}
	}

	private function textUtf(int $x, int $y, string $fontKey, int $size, string $utf8): void {
		$win = pdf_winansi($utf8);
		$esc = pdf_escape_literal($win);
		$this->content .= "BT /{$fontKey} {$size} Tf {$x} {$y} Td ({$esc}) Tj ET\n";
	}

	private function textRawWin1252(int $x, int $y, string $fontKey, int $size, string $win1252): void {
		$esc = pdf_escape_literal($win1252);
		$this->content .= "BT /{$fontKey} {$size} Tf {$x} {$y} Td ({$esc}) Tj ET\n";
	}

	private function line(int $x1, int $y1, int $x2, int $y2): void {
		$this->content .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
	}

	private function rectStroke(int $x, int $y, int $w, int $h): void {
		$this->content .= "{$x} {$y} {$w} {$h} re S\n";
	}

	private function setGrayStroke(float $g): void {
		$this->content .= sprintf("%.3f G\n", $g);
	}

	private function setGrayFill(float $g): void {
		$this->content .= sprintf("%.3f g\n", $g);
	}

	private function fillRect(int $x, int $y, int $w, int $h): void {
		$this->content .= "{$x} {$y} {$w} {$h} re f\n";
	}

	public function header(string $main, string $subLeft, string $subRight): void {
		$this->ensureSpace(90);

		$left = $this->marginL;
		$right = $this->pageW - $this->marginR;

		$this->setGrayFill(0.95);
		$this->fillRect($left, $this->y - 56, $right - $left, 56);

		$this->setGrayStroke(0.80);
		$this->rectStroke($left, $this->y - 56, $right - $left, 56);

		$this->setGrayStroke(0);
		$this->setGrayFill(0);

		$this->textUtf($left + 12, $this->y - 26, "F1", 16, $main);
		$this->textUtf($left + 12, $this->y - 44, "F1", 10, $subLeft);
		$this->textUtf($right - 220, $this->y - 44, "F1", 10, $subRight);

		$this->y -= 74;

		$this->setGrayStroke(0.85);
		$this->line($left, $this->y, $right, $this->y);
		$this->setGrayStroke(0);

		$this->y -= 14;
	}

	public function sectionTitle(string $title): void {
		$this->ensureSpace(30);

		$left = $this->marginL;
		$right = $this->pageW - $this->marginR;

		$this->setGrayFill(0.97);
		$this->fillRect($left, $this->y - 22, $right - $left, 22);

		$this->setGrayStroke(0.86);
		$this->rectStroke($left, $this->y - 22, $right - $left, 22);

		$this->setGrayStroke(0);
		$this->setGrayFill(0);

		$this->textUtf($left + 12, $this->y - 16, "F1", 11, $title);

		$this->y -= 30;
	}

	public function sectionLine(string $text): void {
		$this->ensureSpace(16);
		$left = $this->marginL;
		$this->textUtf($left + 12, $this->y, "F1", 10, $text);
		$this->y -= 12;
	}

	/**
	 * Reaproveita o mesmo bloco do "Grupo X", mas com título livre (fase).
	 */
	public function blockTitle(string $title, int $count): void {
		$this->ensureSpace(46);

		$left = $this->marginL;
		$right = $this->pageW - $this->marginR;

		$this->setGrayFill(0.93);
		$this->fillRect($left, $this->y - 26, $right - $left, 26);

		$this->setGrayStroke(0.82);
		$this->rectStroke($left, $this->y - 26, $right - $left, 26);

		$this->setGrayStroke(0);
		$this->setGrayFill(0);

		$this->textUtf($left + 12, $this->y - 18, "F1", 12, $title);
		$this->textUtf($right - 120, $this->y - 18, "F1", 10, $count . " jogo(s)");

		$this->y -= 36;

		// Cabeçalho 4 colunas (Courier)
		$this->ensureSpace(18);

		$hdr =
			pdf_col("Quando", 12, 'L') . "  " .
			pdf_col("Seleção Casa", 22, 'L') . "  " .
			pdf_col("Palpite", 8, 'C') . "  " .
			pdf_col("Seleção Fora", 22, 'L');

		$this->textRawWin1252($left + 12, $this->y, "F2", 10, $hdr);

		$this->y -= 12;

		$this->setGrayStroke(0.88);
		$this->line($left, $this->y, $right, $this->y);
		$this->setGrayStroke(0);

		$this->y -= 10;
	}

	public function row(string $when, string $home, string $away, string $score): void {
		$this->ensureSpace(14);

		$left = $this->marginL;

		$line =
			pdf_col($when, 12, 'L') . "  " .
			pdf_col($home, 22, 'L') . "  " .
			pdf_col($score !== "" ? $score : "—", 8, 'C') . "  " .
			pdf_col($away, 22, 'L');

		$this->textRawWin1252($left + 12, $this->y, "F2", 10, $line);
		$this->y -= 12;
	}

	public function footerNote(string $note): void {
		$this->ensureSpace(18);
		$left = $this->marginL;
		$this->textUtf($left + 12, $this->y, "F1", 9, $note);
		$this->y -= 12;
	}

	public function output(string $filename): void {
		$this->finalizePage();

		$kids = "";
		foreach ($this->pages as $p) {
			$kids .= "{$p} 0 R ";
		}

		$pagesObj = $this->newObject("<< /Type /Pages /Kids [ $kids ] /Count " . count($this->pages) . " >>");

		foreach ($this->pages as $pageObjId) {
			$body = $this->objects[$pageObjId] ?? "";
			$body = str_replace("/Parent 0 0 R", "/Parent {$pagesObj} 0 R", $body);
			$this->objects[$pageObjId] = $body;
		}

		$catalogObj = $this->newObject("<< /Type /Catalog /Pages {$pagesObj} 0 R >>");

		$now = date("YmdHis");
		$infoObj = $this->newObject("<< /Title (" . pdf_escape_literal(pdf_winansi($this->title)) . ") /Producer (BolaoPHP) /CreationDate (D:$now) >>");

		$pdf = "%PDF-1.4\n";
		$offsets = [0];

		for ($i = 1; $i <= $this->objCount; $i++) {
			$offsets[$i] = strlen($pdf);
			$pdf .= $i . " 0 obj\n" . $this->objects[$i] . "\nendobj\n";
		}

		$xrefPos = strlen($pdf);
		$pdf .= "xref\n0 " . ($this->objCount + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($i = 1; $i <= $this->objCount; $i++) {
			$pdf .= str_pad((string)$offsets[$i], 10, "0", STR_PAD_LEFT) . " 00000 n \n";
		}

		$pdf .= "trailer\n<< /Size " . ($this->objCount + 1) . " /Root {$catalogObj} 0 R /Info {$infoObj} 0 R >>\n";
		$pdf .= "startxref\n{$xrefPos}\n%%EOF";

		header("Content-Type: application/pdf");
		header("Content-Disposition: inline; filename=\"" . $filename . "\"");
		header("Content-Length: " . strlen($pdf));
		echo $pdf;
		exit;
	}
}

require_login();

$usuarioId = (int)($_SESSION["usuario_id"] ?? 0);
$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Apostador";

/**
 * Opcional: aceita /php/recibo_mata_mata.php?action=pdf
 * (se vier outro action, só gera do mesmo jeito)
 */
try {
	// $pdo vem do conexao.php (mesmo padrão do recibo de grupos)
	if (!isset($pdo) || !($pdo instanceof PDO)) {
		throw new RuntimeException("PDO não inicializado em conexao.php");
	}

	$edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
	if ($edicaoId <= 0) throw new RuntimeException("Nenhuma edição ativa.");

	// =========================================================
	// 1) Top 4 (1 por usuário por edição)
	// =========================================================
	$sqlTop4 = "
		SELECT
			t1.nome AS primeiro_nome,
			t2.nome AS segundo_nome,
			t3.nome AS terceiro_nome,
			t4.nome AS quarto_nome
		FROM palpite_top4 pt
		INNER JOIN times t1 ON t1.id = pt.primeiro_time_id
		INNER JOIN times t2 ON t2.id = pt.segundo_time_id
		INNER JOIN times t3 ON t3.id = pt.terceiro_time_id
		INNER JOIN times t4 ON t4.id = pt.quarto_time_id
		WHERE pt.edicao_id = :edicao_id
		  AND pt.usuario_id = :usuario_id
		LIMIT 1
	";
	$stTop4 = $pdo->prepare($sqlTop4);
	$stTop4->execute([
		":edicao_id" => $edicaoId,
		":usuario_id" => $usuarioId,
	]);
	$top4Row = $stTop4->fetch(PDO::FETCH_ASSOC);

	$top4 = [
		"1" => "",
		"2" => "",
		"3" => "",
		"4" => "",
	];
	if (is_array($top4Row)) {
		$top4["1"] = (string)($top4Row["primeiro_nome"] ?? "");
		$top4["2"] = (string)($top4Row["segundo_nome"] ?? "");
		$top4["3"] = (string)($top4Row["terceiro_nome"] ?? "");
		$top4["4"] = (string)($top4Row["quarto_nome"] ?? "");
	}

	// =========================================================
	// 2) Jogos + palpites (mata-mata)
	// =========================================================
	$sql = "
		SELECT
			j.fase,
			j.data_hora,
			tc.nome AS casa_nome,
			tf.nome AS fora_nome,
			p.gols_casa AS palpite_casa,
			p.gols_fora AS palpite_fora
		FROM jogos j
		INNER JOIN times tc ON tc.id = j.time_casa_id
		INNER JOIN times tf ON tf.id = j.time_fora_id
		LEFT JOIN palpites p
			ON p.jogo_id = j.id
		   AND p.usuario_id = :usuario_id
		WHERE j.edicao_id = :edicao_id
		  AND j.grupo_id IS NULL
		  AND j.fase IN ('16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL')
		ORDER BY
		  FIELD(j.fase,'16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL'),
		  j.data_hora, j.id
	";
	$st = $pdo->prepare($sql);
	$st->execute([
		":edicao_id" => $edicaoId,
		":usuario_id" => $usuarioId,
	]);
	$rows = $st->fetchAll(PDO::FETCH_ASSOC);

	// Agrupa por fase
	$labels = [
		"16_DE_FINAL"    => "16 de final",
		"OITAVAS"        => "Oitavas",
		"QUARTAS"        => "Quartas",
		"SEMI"           => "Semifinal",
		"TERCEIRO_LUGAR" => "3º lugar",
		"FINAL"          => "Final",
	];

	$map = [];
	foreach (array_keys($labels) as $k) $map[$k] = [];

	foreach ($rows as $r) {
		$f = (string)($r["fase"] ?? "");
		if ($f === "" || !isset($map[$f])) continue;
		$map[$f][] = $r;
	}

	// =========================================================
	// 3) Geração PDF
	// =========================================================
	$dtGerado = date("d/m/Y H:i");
	$mainTitle = "Recibo de Apostas — Mata-mata";
	$subLeft = "Apostador: " . $usuarioNome;
	$subRight = "Gerado em: " . $dtGerado;

	$pdf = new SimplePdf("Recibo - Bolão da Copa (Mata-mata)");
	$pdf->header($mainTitle, $subLeft, $subRight);

	// Seção Top 4
	$pdf->sectionTitle("Top 4 do Torneio");
	$pdf->footerNote(" "); // 1 linha em branco (mesmo padrão do recibo de grupos)

	$hasTop4 = (
		trim($top4["1"]) !== "" ||
		trim($top4["2"]) !== "" ||
		trim($top4["3"]) !== "" ||
		trim($top4["4"]) !== ""
	);

	if ($hasTop4) {
		$pdf->sectionLine("1º: " . ($top4["1"] !== "" ? $top4["1"] : "—"));
		$pdf->sectionLine("2º: " . ($top4["2"] !== "" ? $top4["2"] : "—"));
		$pdf->sectionLine("3º: " . ($top4["3"] !== "" ? $top4["3"] : "—"));
		$pdf->sectionLine("4º: " . ($top4["4"] !== "" ? $top4["4"] : "—"));
	} else {
		$pdf->sectionLine("Top 4: — (não preenchido)");
	}

	$pdf->footerNote(" ");

	// Jogos por fase
	foreach ($labels as $faseCode => $faseLabel) {
		$list = $map[$faseCode] ?? [];
		if (!is_array($list) || count($list) === 0) continue;

		$pdf->blockTitle("Fase: " . $faseLabel, count($list));

		foreach ($list as $r) {
			$when = fmt_dt_br((string)($r["data_hora"] ?? ""));
			$home = (string)($r["casa_nome"] ?? "");
			$away = (string)($r["fora_nome"] ?? "");

			$pc = $r["palpite_casa"] ?? null;
			$pf = $r["palpite_fora"] ?? null;

			$score = ($pc === null || $pf === null) ? "—" : ((int)$pc . "x" . (int)$pf);

			$pdf->row($when, $home, $away, $score);
		}

		$pdf->footerNote(" ");
	}

	$pdf->footerNote("Obs.: placares “—” indicam jogo sem palpite preenchido.");
	$pdf->output("recibo_mata_mata.pdf");

} catch (Throwable $e) {
	http_response_code(500);
	header("Content-Type: text/plain; charset=utf-8");
	echo "Erro ao gerar PDF.";
	exit;
}