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
        return $dt;
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

    public function groupTitle(string $groupCode, int $count): void
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

        $this->textUtf($left + 12, $this->y - 18, 'F1', 12, 'Grupo ' . $groupCode);
        $this->textUtf($right - 120, $this->y - 18, 'F1', 10, $count . ' jogo(s)');
        $this->y -= 36;

        $this->ensureSpace(18);
        $hdr =
            pdf_col('Quando', 12, 'L') . '  ' .
            pdf_col('Selecao Casa', 22, 'L') . '  ' .
            pdf_col('Palpite', 8, 'C') . '  ' .
            pdf_col('Selecao Fora', 22, 'L');
        $this->textRawWin1252($left + 12, $this->y, 'F2', 10, $hdr);
        $this->y -= 12;

        $this->setGrayStroke(0.88);
        $this->line($left, $this->y, $right, $this->y);
        $this->setGrayStroke(0.0);
        $this->y -= 10;
    }

    public function groupClassificationLine(?string $line): void
    {
        if ($line === null || trim($line) === '') {
            return;
        }
        $this->ensureSpace(16);
        $this->textUtf($this->marginL + 12, $this->y, 'F1', 9, $line);
        $this->y -= 12;
    }

    public function row(string $when, string $home, string $away, string $score): void
    {
        $this->ensureSpace(14);
        $line =
            pdf_col($when, 12, 'L') . '  ' .
            pdf_col($home, 22, 'L') . '  ' .
            pdf_col($score !== '' ? $score : '-', 8, 'C') . '  ' .
            pdf_col($away, 22, 'L');
        $this->textRawWin1252($this->marginL + 12, $this->y, 'F2', 10, $line);
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
    $campeaoNome = (string)($bundle['campeao_nome'] ?? '');

    $mapClassif = [];
    foreach (($bundle['classificacao'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $groupCode = (string)($row['grupo_codigo'] ?? '');
        if ($groupCode === '') {
            continue;
        }
        $mapClassif[$groupCode] = [
            'primeiro' => (string)($row['primeiro_nome'] ?? ''),
            'segundo' => (string)($row['segundo_nome'] ?? ''),
            'terceiro' => (string)($row['terceiro_nome'] ?? ''),
        ];
    }

    $map = [];
    foreach (($bundle['jogos_grupos'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $groupCode = (string)($row['grupo_codigo'] ?? '');
        if ($groupCode === '') {
            continue;
        }
        if (!isset($map[$groupCode])) {
            $map[$groupCode] = [];
        }
        $map[$groupCode][] = [
            'data_hora' => (string)($row['data_hora'] ?? ''),
            'casa_nome' => (string)($row['casa_nome'] ?? ''),
            'fora_nome' => (string)($row['fora_nome'] ?? ''),
            'palpite_casa' => apostas_export_int_or_null($row['gols_casa'] ?? null),
            'palpite_fora' => apostas_export_int_or_null($row['gols_fora'] ?? null),
        ];
    }

    ksort($map, SORT_NATURAL);

    $dtGerado = date('d/m/Y H:i');
    $pdf = new SimplePdf('Recibo - Bolao da Copa');
    $pdf->header('Recibo de Apostas - Fase de Grupos', 'Apostador: ' . $usuarioNome, 'Gerado em: ' . $dtGerado);

    $pdf->sectionTitle('Palpite de Campeao');
    $pdf->footerNote(' ');
    if ($campeaoNome !== '') {
        $pdf->sectionLine('Campeao escolhido: ' . $campeaoNome);
    } else {
        $pdf->sectionLine('Campeao escolhido: - (nao preenchido)');
    }
    $pdf->footerNote(' ');

    foreach ($map as $groupCode => $list) {
        $pdf->groupTitle($groupCode, count($list));

        foreach ($list as $row) {
            $score = ($row['palpite_casa'] === null || $row['palpite_fora'] === null)
                ? '-'
                : ((int)$row['palpite_casa'] . 'x' . (int)$row['palpite_fora']);

            $pdf->row(
                fmt_dt_br($row['data_hora']),
                $row['casa_nome'],
                $row['fora_nome'],
                $score
            );
        }

        $pdf->footerNote(' ');

        $classif = $mapClassif[$groupCode] ?? null;
        if (is_array($classif)) {
            $line = 'Classificacao escolhida: 1o ' . ($classif['primeiro'] !== '' ? $classif['primeiro'] : '-') .
                ' | 2o ' . ($classif['segundo'] !== '' ? $classif['segundo'] : '-') .
                ' | 3o ' . ($classif['terceiro'] !== '' ? $classif['terceiro'] : '-');
            $pdf->groupClassificationLine($line);
        } else {
            $pdf->groupClassificationLine('Classificacao escolhida: - (nao preenchido)');
        }

        $pdf->footerNote(' ');
    }

    $pdf->footerNote('Obs.: placares "-" indicam jogo sem palpite preenchido.');
    $pdf->output('recibo_apostas.pdf');
} catch (OutOfBoundsException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Usuario nao encontrado.';
    exit;
} catch (DomainException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    apostas_export_log('group receipt blocked by integrity validation', [
        'usuario_id' => $usuarioId,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Inconsistencia de dados detectada. Recibo cancelado para evitar informacoes incorretas.';
    exit;
} catch (Throwable $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    apostas_export_log('group receipt failed', [
        'usuario_id' => $usuarioId,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Erro ao gerar PDF.';
    exit;
}
