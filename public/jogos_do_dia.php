<?php
declare(strict_types=1);

require_once __DIR__ . "/../php/conexao.php";
require_once __DIR__ . "/../php/performance_cache.php";

date_default_timezone_set('America/Sao_Paulo');

header("Content-Type: application/json; charset=utf-8");

$edicaoId = (int)$pdo->query("
    SELECT id
    FROM edicoes
    WHERE ativo = 1
    ORDER BY ano DESC
    LIMIT 1
")->fetchColumn();

if ($edicaoId <= 0) {
    echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$logicalDay = (int)$now->format('H') < 5
    ? $now->sub(new DateInterval('P1D'))->format('Y-m-d')
    : $now->format('Y-m-d');

$dayStartDt = new DateTimeImmutable($logicalDay . ' 05:00:00', $tz);
$dayStart = $dayStartDt->format('Y-m-d H:i:s');
$dayEnd = $dayStartDt->add(new DateInterval('P1D'))->format('Y-m-d H:i:s');

$jogos = app_cache_remember('jogos_do_dia:' . $edicaoId . ':' . $logicalDay, 30, static function () use ($pdo, $edicaoId, $dayStart, $dayEnd): array {
    $sql = "
        SELECT
            j.id,
            g.codigo AS grupo,
            j.data_hora,
            tc.nome AS casa,
            tf.nome AS fora
        FROM jogos j
        INNER JOIN grupos g ON g.id = j.grupo_id
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        WHERE j.edicao_id = :edicao_id
          AND j.grupo_id IS NOT NULL
          AND j.data_hora >= :day_start
          AND j.data_hora < :day_end
        ORDER BY j.data_hora
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ":edicao_id" => $edicaoId,
        ":day_start" => $dayStart,
        ":day_end" => $dayEnd,
    ]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
});

echo json_encode($jogos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
