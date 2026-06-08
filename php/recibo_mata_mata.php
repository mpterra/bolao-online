<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
app_start_session();
app_send_security_headers();

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/apostas_export_service.php';

date_default_timezone_set('America/Sao_Paulo');

function require_login(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /index.php');
        exit;
    }
}

function pdf_winansi(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $value) ?? $value;
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    if ($converted === false) {
        $converted = preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? $value;
    }
    return $converted;
}

function pdf_escape_literal(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function fmt_dt_br(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return (string)$dt;
    }
    return date('d/m H:i', $ts);
}

function pdf_col(string $utf8, int $width, string $align = 'L'): string
{
    $value = pdf_winansi(trim($utf8));
    if ($width <= 0) {
        return '';
    }

    $len = strlen($value);
    if ($len > $width) {
        $value = $width >= 2 ? substr($value, 0, $width - 1) . chr(0x85) : substr($value, 0, $width);
        $len = strlen($value);
    }

    if ($len < $width) {
        $pad = $width - $len;
        if ($align === 'R') {
            $value = str_repeat(' ', $pad) . $value;
        } elseif ($align === 'C') {
            $left = intdiv($pad, 2);
            $right = $pad - $left;
            $value = str_repeat(' ', $left) . $value . str_repeat(' ', $right);
        } else {
            $value .= str_repeat(' ', $pad);
        }
    }

    return $value;
}

final class SimplePdf
{
    private array $objects = [];
    private array $pages = [];
    private int $objCount = 0;
    private int $pageW = 595;
    private int $pageH = 842;
    private int $marginL = 38;
    private int $marginR = 38;
    private int $marginT = 44;
    private int $marginB = 44;
    private int $y;
    private string $content = '';
    private int $fontHelveticaObj;
    private int $fontCourierObj;
    private string $title = 'Recibo';

    public function __construct(string $title = 'Recibo')
    {
        $this->title = $title;
        $this->y = $this->pageH - $this->marginT;
        $this->fontHelveticaObj = $this->newObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->fontCourierObj = $this->newObject('<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>');
        $this->newPage();
    }

    private function newObject(string $body): int
    {
        $this->objCount++;
        $this->objects[$this->objCount] = $body;
        return $this->objCount;
    }

    private function startContent(): void
    {
        $this->content = '';
        $this->y = $this->pageH - $this->marginT;
    }

    private function finalizePage(): void
    {
        $stream = $this->content;
        $contentObj = $this->newObject("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");
        $resources = "<< /Font << /F1 {$this->fontHelveticaObj} 0 R /F2 {$this->fontCourierObj} 0 R >> >>";
        $pageObj = $this->newObject("<< /Type /Page /Parent 0 0 R /Resources $resources /MediaBox [0 0 {$this->pageW} {$this->pageH}] /Contents {$contentObj} 0 R >>");
        $this->pages[] = $pageObj;
    }

    public function newPage(): void
    {
        if (!empty($this->content) || count($this->pages) > 0) {
            $this->finalizePage();
        }
        $this->startContent();
    }

    private function ensureSpace(int $needed): void
    {
        if ($this->y - $needed < $this->marginB) {
            $this->newPage();
        }
    }

    private function textUtf(int $x, int $y, string $fontKey, int $size, string $utf8): void
    {
        $esc = pdf_escape_literal(pdf_winansi($utf8));
        $this->content .= "BT /{$fontKey} {$size} Tf {$x} {$y} Td ({$esc}) Tj ET\n";
    }

    private function textRawWin1252(int $x, int $y, string $fontKey, int $size, string $win1252): void
    {
        $esc = pdf_escape_literal($win1252);
        $this->content .= "BT /{$fontKey} {$size} Tf {$x} {$y} Td ({$esc}) Tj ET\n";
    }

    private function line(int $x1, int $y1, int $x2, int $y2): void
    {
        $this->content .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    private function rectStroke(int $x, int $y, int $w, int $h): void
    {
        $this->content .= "{$x} {$y} {$w} {$h} re S\n";
    }

    private function setGrayStroke(float $g): void
    {
        $this->content .= sprintf("%.3f G\n", $g);
    }

    private function setGrayFill(float $g): void
    {
        $this->content .= sprintf("%.3f g\n", $g);
    }

    private function fillRect(int $x, int $y, int $w, int $h): void
    {
        $this->content .= "{$x} {$y} {$w} {$h} re f\n";
    }

    public function header(string $main, string $subLeft, string $subRight): void
    {
        $this->ensureSpace(90);
        $left = $this->marginL;
        $right = $this->pageW - $this->marginR;

        $this->setGrayFill(0.95);
        $this->fillRect($left, $this->y - 56, $right - $left, 56);
        $this->setGrayStroke(0.80);
        $this->rectStroke($left, $this->y - 56, $right - $left, 56);
        $this->setGrayStroke(0.0);
        $this->setGrayFill(0.0);

        $this->textUtf($left + 12, $this->y - 26, 'F1', 16, $main);
        $this->textUtf($left + 12, $this->y - 44, 'F1', 10, $subLeft);
        $this->textUtf($right - 220, $this->y - 44, 'F1', 10, $subRight);

        $this->y -= 74;
        $this->setGrayStroke(0.85);
        $this->line($left, $this->y, $right, $this->y);
        $this->setGrayStroke(0.0);
        $this->y -= 14;
    }

    public function sectionTitle(string $title): void
    {
        $this->ensureSpace(30);
        $left = $this->marginL;
        $right = $this->pageW - $this->marginR;

        $this->setGrayFill(0.97);
        $this->fillRect($left, $this->y - 22, $right - $left, 22);
        $this->setGrayStroke(0.86);
        $this->rectStroke($left, $this->y - 22, $right - $left, 22);
        $this->setGrayStroke(0.0);
        $this->setGrayFill(0.0);

        $this->textUtf($left + 12, $this->y - 16, 'F1', 11, $title);
        $this->y -= 30;
    }

    public function sectionLine(string $text): void
    {
        $this->ensureSpace(16);
        $this->textUtf($this->marginL + 12, $this->y, 'F1', 10, $text);
        $this->y -= 12;
    }

    public function blockTitle(string $title, int $count): void
    {
        $this->ensureSpace(46);
        $left = $this->marginL;
        $right = $this->pageW - $this->marginR;

        $this->setGrayFill(0.93);
        $this->fillRect($left, $this->y - 26, $right - $left, 26);
        $this->setGrayStroke(0.82);
        $this->rectStroke($left, $this->y - 26, $right - $left, 26);
        $this->setGrayStroke(0.0);
        $this->setGrayFill(0.0);

        $this->textUtf($left + 12, $this->y - 18, 'F1', 12, $title);
        $this->textUtf($right - 120, $this->y - 18, 'F1', 10, $count . ' jogo(s)');
        $this->y -= 36;

        $this->ensureSpace(18);
        $hdr =
            pdf_col('Quando', 12, 'L') . '  ' .
            pdf_col('Selecao Casa', 16, 'L') . '  ' .
            pdf_col('Palpite', 8, 'C') . '  ' .
            pdf_col('Selecao Fora', 16, 'L') . '  ' .
            pdf_col('Quem passa', 14, 'L');
        $this->textRawWin1252($left + 12, $this->y, 'F2', 9, $hdr);
        $this->y -= 12;

        $this->setGrayStroke(0.88);
        $this->line($left, $this->y, $right, $this->y);
        $this->setGrayStroke(0.0);
        $this->y -= 10;
    }

    public function row(string $when, string $home, string $score, string $away, string $passa): void
    {
        $this->ensureSpace(14);
        $line =
            pdf_col($when, 12, 'L') . '  ' .
            pdf_col($home, 16, 'L') . '  ' .
            pdf_col($score !== '' ? $score : '-', 8, 'C') . '  ' .
            pdf_col($away, 16, 'L') . '  ' .
            pdf_col($passa !== '' ? $passa : '-', 14, 'L');
        $this->textRawWin1252($this->marginL + 12, $this->y, 'F2', 9, $line);
        $this->y -= 12;
    }

    public function footerNote(string $note): void
    {
        $this->ensureSpace(18);
        $this->textUtf($this->marginL + 12, $this->y, 'F1', 9, $note);
        $this->y -= 12;
    }

    public function output(string $filename): void
    {
        $this->finalizePage();

        $kids = '';
        foreach ($this->pages as $pageId) {
            $kids .= "{$pageId} 0 R ";
        }

        $pagesObj = $this->newObject('<< /Type /Pages /Kids [ ' . $kids . ' ] /Count ' . count($this->pages) . ' >>');
        foreach ($this->pages as $pageId) {
            $body = $this->objects[$pageId] ?? '';
            $this->objects[$pageId] = str_replace('/Parent 0 0 R', '/Parent ' . $pagesObj . ' 0 R', $body);
        }

        $catalogObj = $this->newObject('<< /Type /Catalog /Pages ' . $pagesObj . ' 0 R >>');
        $now = date('YmdHis');
        $infoObj = $this->newObject('<< /Title (' . pdf_escape_literal(pdf_winansi($this->title)) . ') /Producer (BolaoPHP) /CreationDate (D:' . $now . ') >>');

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        for ($i = 1; $i <= $this->objCount; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $this->objects[$i] . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . ($this->objCount + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $this->objCount; $i++) {
            $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= 'trailer' . "\n<< /Size " . ($this->objCount + 1) . ' /Root ' . $catalogObj . ' 0 R /Info ' . $infoObj . " 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}

require_login();

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$usuarioNome = isset($_SESSION['usuario_nome']) ? (string)$_SESSION['usuario_nome'] : 'Apostador';
$snapshotStarted = false;
$pdoRef = null;

try {
    $pdoRef = apostas_export_require_pdo($pdo ?? null);
    $snapshotStarted = apostas_export_begin_snapshot($pdoRef);

    $usuario = apostas_export_load_user($pdoRef, $usuarioId, false);
    $edicaoId = apostas_export_active_edicao_id($pdoRef);
    $bundle = apostas_export_load_bundle($pdoRef, $edicaoId, $usuario);

    apostas_export_commit_snapshot($pdoRef, $snapshotStarted);

    $usuarioNome = (string)($bundle['usuario']['nome'] ?? $usuarioNome);
    $top4 = is_array($bundle['top4'] ?? null) ? $bundle['top4'] : [];
    $rows = is_array($bundle['jogos_mata_mata'] ?? null) ? $bundle['jogos_mata_mata'] : [];
    $phaseLabels = apostas_export_knockout_phase_labels();

    $groupedByPhase = [];
    foreach (array_keys($phaseLabels) as $phaseCode) {
        $groupedByPhase[$phaseCode] = [];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $phaseCode = (string)($row['fase'] ?? '');
        if ($phaseCode === '' || !isset($groupedByPhase[$phaseCode])) {
            continue;
        }
        $groupedByPhase[$phaseCode][] = $row;
    }

    $dtGerado = date('d/m/Y H:i');
    $pdf = new SimplePdf('Recibo - Bolao da Copa (Mata-mata)');
    $pdf->header('Recibo de Apostas - Mata-mata', 'Apostador: ' . $usuarioNome, 'Gerado em: ' . $dtGerado);

    $pdf->sectionTitle('Top 4 do Torneio');
    $pdf->footerNote(' ');

    $hasTop4 = false;
    foreach (['primeiro_nome', 'segundo_nome', 'terceiro_nome', 'quarto_nome'] as $field) {
        if (trim((string)($top4[$field] ?? '')) !== '') {
            $hasTop4 = true;
            break;
        }
    }

    if ($hasTop4) {
        $pdf->sectionLine('1o: ' . ((string)($top4['primeiro_nome'] ?? '') !== '' ? (string)$top4['primeiro_nome'] : '-'));
        $pdf->sectionLine('2o: ' . ((string)($top4['segundo_nome'] ?? '') !== '' ? (string)$top4['segundo_nome'] : '-'));
        $pdf->sectionLine('3o: ' . ((string)($top4['terceiro_nome'] ?? '') !== '' ? (string)$top4['terceiro_nome'] : '-'));
        $pdf->sectionLine('4o: ' . ((string)($top4['quarto_nome'] ?? '') !== '' ? (string)$top4['quarto_nome'] : '-'));
    } else {
        $pdf->sectionLine('Top 4: - (nao preenchido)');
    }

    $pdf->footerNote(' ');

    foreach ($phaseLabels as $phaseCode => $phaseLabel) {
        $list = $groupedByPhase[$phaseCode] ?? [];
        if (count($list) === 0) {
            continue;
        }

        $pdf->blockTitle('Fase: ' . $phaseLabel, count($list));

        foreach ($list as $row) {
            $golsCasa = apostas_export_int_or_null($row['gols_casa'] ?? null);
            $golsFora = apostas_export_int_or_null($row['gols_fora'] ?? null);
            $score = ($golsCasa === null || $golsFora === null) ? '-' : ($golsCasa . 'x' . $golsFora);
            $passa = '-';

            if ($golsCasa !== null && $golsFora !== null && $golsCasa === $golsFora) {
                $passaNome = trim((string)($row['passa_nome'] ?? ''));
                $passa = $passaNome !== '' ? $passaNome : '-';
            }

            $pdf->row(
                fmt_dt_br((string)($row['data_hora'] ?? '')),
                (string)($row['casa_nome'] ?? ''),
                $score,
                (string)($row['fora_nome'] ?? ''),
                $passa
            );
        }

        $pdf->footerNote(' ');
    }

    $pdf->footerNote('Obs.: placares "-" indicam jogo sem palpite preenchido. Em caso de empate, a coluna "Quem passa" mostra a selecao escolhida.');
    $pdf->output('recibo_mata_mata.pdf');
} catch (OutOfBoundsException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Usuario nao encontrado.';
    exit;
} catch (DomainException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    apostas_export_log('knockout receipt blocked by integrity validation', [
        'usuario_id' => $usuarioId,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Inconsistencia de dados detectada. Recibo cancelado para evitar informacoes incorretas.';
    exit;
} catch (Throwable $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    apostas_export_log('knockout receipt failed', [
        'usuario_id' => $usuarioId,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Erro ao gerar PDF.';
    exit;
}
