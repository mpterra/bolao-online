<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . "/conexao.php";

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        header("Location: /bolao-da-copa/public/index.php");
        exit;
    }
}

function require_admin(): void {
    $tipo = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
    if (mb_strtoupper($tipo, "UTF-8") !== "ADMIN") {
        http_response_code(403);
        echo "Acesso negado.";
        exit;
    }
}

function strh(?string $s): string {
    return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

function filename_slug(string $s): string {
    $s = trim($s);
    if ($s === "") return "usuario";
    $s = mb_strtolower($s, "UTF-8");
    $t = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s);
    if ($t !== false && $t !== "") $s = $t;
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
    $s = trim($s, "_");
    return $s !== "" ? $s : "usuario";
}

require_login();
require_admin();

$usuarioId = isset($_GET["usuario_id"]) ? (int)$_GET["usuario_id"] : 0;
if ($usuarioId <= 0) {
    http_response_code(400);
    exit("Parâmetro usuario_id inválido.");
}

try {
    $stUser = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = :id LIMIT 1");
    $stUser->execute([":id" => $usuarioId]);
    $u = $stUser->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        http_response_code(404);
        exit("Usuário não encontrado.");
    }

    $usuarioNome = (string)$u["nome"];

    $edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
    if ($edicaoId <= 0) {
        $edicaoId = (int)$pdo->query("SELECT id FROM edicoes ORDER BY ano DESC LIMIT 1")->fetchColumn();
    }
    if ($edicaoId <= 0) {
        throw new RuntimeException("Nenhuma edição encontrada.");
    }

    $sql = "
        SELECT
            g.codigo AS grupo_codigo,
            j.data_hora,
            tc.nome AS casa_nome,
            p.gols_casa,
            tf.nome AS fora_nome,
            p.gols_fora
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
        ORDER BY g.codigo, j.data_hora, j.id
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ":edicao_id1" => $edicaoId,
        ":edicao_id2" => $edicaoId,
        ":usuario_id" => $usuarioId,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $slug = filename_slug($usuarioNome);
    $ts = date("Y-m-d_H-i");
    $filename = "apostas_{$slug}_{$ts}.xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>Grupo</th>";
    echo "<th>Data/Hora</th>";
    echo "<th>Time da casa</th>";
    echo "<th>Placar casa</th>";
    echo "<th>Time visitante</th>";
    echo "<th>Placar visitante</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($rows as $r) {
        $grupo = (string)$r["grupo_codigo"];
        $dhRaw = (string)$r["data_hora"];
        $dh = $dhRaw;
        $ts2 = strtotime($dhRaw);
        if ($ts2 !== false) $dh = date("d/m/Y H:i", $ts2);

        $casa = (string)$r["casa_nome"];
        $fora = (string)$r["fora_nome"];

        $gc = $r["gols_casa"];
        $gf = $r["gols_fora"];
        $gcStr = ($gc === null) ? "" : (string)(int)$gc;
        $gfStr = ($gf === null) ? "" : (string)(int)$gf;

        echo "<tr>";
        echo "<td>" . strh($grupo) . "</td>";
        echo "<td>" . strh($dh) . "</td>";
        echo "<td>" . strh($casa) . "</td>";
        echo "<td>" . strh($gcStr) . "</td>";
        echo "<td>" . strh($fora) . "</td>";
        echo "<td>" . strh($gfStr) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";
    echo "</body></html>";
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    exit("Erro ao gerar Excel.");
}
