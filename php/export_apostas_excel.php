<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
app_start_session();
app_send_security_headers();

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/apostas_export_service.php';

app_require_admin();

$usuarioId = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
if ($usuarioId <= 0) {
    http_response_code(400);
    exit('Parametro usuario_id invalido.');
}

$snapshotStarted = false;
$pdoRef = null;

try {
    $pdoRef = apostas_export_require_pdo($pdo ?? null);
    $snapshotStarted = apostas_export_begin_snapshot($pdoRef);

    $usuario = apostas_export_load_user($pdoRef, $usuarioId, false);
    $edicaoId = apostas_export_active_edicao_id($pdoRef);
    $bundle = apostas_export_load_bundle($pdoRef, $edicaoId, $usuario);

    apostas_export_commit_snapshot($pdoRef, $snapshotStarted);

    $slug = apostas_export_filename_slug((string)$usuario['nome']);
    $tsPack = date('Y-m-d_H-i');
    $filename = 'apostas_' . $slug . '_id' . (int)$usuario['id'] . '_' . $tsPack . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo apostas_export_render_excel_html($bundle);
    exit;
} catch (InvalidArgumentException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    http_response_code(400);
    exit('Parametro usuario_id invalido.');
} catch (OutOfBoundsException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    http_response_code(404);
    exit('Usuario nao encontrado.');
} catch (DomainException $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    apostas_export_log('single export blocked by integrity validation', [
        'usuario_id' => $usuarioId,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(409);
    exit('Inconsistencia de dados detectada. Exportacao cancelada para evitar arquivo incorreto.');
} catch (Throwable $e) {
    apostas_export_rollback_snapshot($pdoRef, $snapshotStarted);
    apostas_export_log('single export failed', [
        'usuario_id' => $usuarioId,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(500);
    exit('Erro ao gerar Excel.');
}
