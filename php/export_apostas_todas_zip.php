<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
app_start_session();
app_send_security_headers();

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/apostas_export_service.php';

app_require_admin();

@set_time_limit(0);
ini_set('memory_limit', '512M');

$snapshotStarted = false;
$pdoRef = null;
$tmpZip = null;
$zip = null;

try {
    $pdoRef = apostas_export_require_pdo($pdo ?? null);
    $snapshotStarted = apostas_export_begin_snapshot($pdoRef);

    $edicaoId = apostas_export_active_edicao_id($pdoRef);
    $usuarios = apostas_export_load_active_users($pdoRef);
    if (count($usuarios) === 0) {
        apostas_export_commit_snapshot($pdoRef, $snapshotStarted);
        http_response_code(404);
        exit('Nenhum usuario ativo encontrado.');
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'bolao_apostas_zip_');
    if ($tmpZip === false) {
        throw new RuntimeException('Falha ao criar arquivo temporario.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        $tmpZip = null;
        throw new RuntimeException('Falha ao abrir ZIP.');
    }

    $tsPack = date('Y-m-d_H-i');
    $folder = 'apostas_' . $tsPack;

    foreach ($usuarios as $usuario) {
        $bundle = apostas_export_load_bundle($pdoRef, $edicaoId, $usuario);
        $slug = apostas_export_filename_slug((string)$usuario['nome']);
        $filename = 'apostas_' . $slug . '_id' . (int)$usuario['id'] . '_' . $tsPack . '.xls';
        $zipPath = $folder . '/' . $filename;

        if (!$zip->addFromString($zipPath, apostas_export_render_excel_html($bundle))) {
            throw new RuntimeException('Falha ao adicionar arquivo ao ZIP.');
        }
    }

    apostas_export_commit_snapshot($pdoRef, $snapshotStarted);

    $zip->close();
    $zip = null;

    clearstatcache(true, $tmpZip);
    $zipSize = filesize($tmpZip);
    if ($zipSize === false) {
        throw new RuntimeException('Falha ao calcular tamanho do ZIP.');
    }

    $zipName = 'apostas_todos_' . $tsPack . '.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . $zipSize);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
} catch (DomainException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    if ($zip instanceof ZipArchive) {
        $zip->close();
    }
    if (is_string($tmpZip) && $tmpZip !== '' && file_exists($tmpZip)) {
        @unlink($tmpZip);
    }

    apostas_export_log('bulk export blocked by integrity validation', [
        'exception' => $e->getMessage(),
    ]);
    http_response_code(409);
    exit('Inconsistencia de dados detectada. Exportacao cancelada para evitar arquivo incorreto.');
} catch (Throwable $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    if ($zip instanceof ZipArchive) {
        $zip->close();
    }
    if (is_string($tmpZip) && $tmpZip !== '' && file_exists($tmpZip)) {
        @unlink($tmpZip);
    }

    apostas_export_log('bulk export failed', [
        'exception' => $e->getMessage(),
    ]);
    http_response_code(500);
    exit('Erro ao gerar ZIP.');
}
