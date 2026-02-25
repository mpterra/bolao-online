<?php
declare(strict_types=1);

require_once __DIR__ . "/../php/conexao.php";
date_default_timezone_set('America/Sao_Paulo');

header("Content-Type: application/json; charset=utf-8");

// pega edição ativa
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

/**
 * ✅ DIA LÓGICO (mesma regra do app.php)
 * - 00:00–04:59 conta como "dia anterior"
 * - 05:00+ conta como o dia do calendário
 */
$now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$logicalDay = (int)$now->format('H') < 5
    ? $now->sub(new DateInterval('P1D'))->format('Y-m-d')
    : $now->format('Y-m-d');

/**
 * Jogos do "dia lógico":
 * - no próprio dia: >= 05:00
 * - na madrugada do dia seguinte: < 05:00
 */
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
      AND (
            (DATE(j.data_hora) = :day1 AND TIME(j.data_hora) >= '05:00:00')
         OR (DATE(j.data_hora) = DATE_ADD(:day2, INTERVAL 1 DAY) AND TIME(j.data_hora) < '05:00:00')
      )
    ORDER BY j.data_hora
";

$st = $pdo->prepare($sql);
$st->execute([
    ":edicao_id" => $edicaoId,
    ":day1" => $logicalDay,
    ":day2" => $logicalDay,
]);

$jogos = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($jogos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);