<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

/**
 * HostGator (padrão):
 * - Este arquivo fica em /public_html (raiz do domínio)
 * - conexao.php fica fora do public_html em /home2/.../php/conexao.php
 */
require_once __DIR__ . "/../php/conexao.php";

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        header("Location: /index.php");
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
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException("PDO não inicializado em conexao.php");
    }

    $stUser = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = :id LIMIT 1");
    $stUser->execute([":id" => $usuarioId]);
    $u = $stUser->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        http_response_code(404);
        exit("Usuário não encontrado.");
    }

    $usuarioDbId = (int)($u["id"] ?? 0);
    if ($usuarioDbId <= 0) {
        throw new RuntimeException("ID do usuário inválido no banco.");
    }

    $usuarioNome = (string)$u["nome"];

    $edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
    if ($edicaoId <= 0) {
        $edicaoId = (int)$pdo->query("SELECT id FROM edicoes ORDER BY ano DESC LIMIT 1")->fetchColumn();
    }
    if ($edicaoId <= 0) {
        throw new RuntimeException("Nenhuma edição encontrada.");
    }

    // =========================================================
    // 1) Jogos + placares apostados (Fase de Grupos)
    // =========================================================
    $sqlJogos = "
        SELECT
            g.codigo AS grupo_codigo,
            j.data_hora,
            j.codigo_fifa,
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
    $stJogos = $pdo->prepare($sqlJogos);
    $stJogos->execute([
        ":edicao_id1" => $edicaoId,
        ":edicao_id2" => $edicaoId,
        ":usuario_id" => $usuarioDbId,
    ]);
    $rowsJogos = $stJogos->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================
    // 2) Classificação (1º/2º/3º) por grupo — sempre lista TODOS os grupos da edição
    // =========================================================
    $sqlClass = "
        SELECT
            g.codigo AS grupo_codigo,
            t1.nome AS primeiro_nome,
            t2.nome AS segundo_nome,
            t3.nome AS terceiro_nome
        FROM grupos g
        LEFT JOIN palpite_grupo_classificacao pgc
               ON pgc.grupo_id = g.id
              AND pgc.usuario_id = :usuario_id
              AND pgc.edicao_id = :edicao_id1
        LEFT JOIN times t1 ON t1.id = pgc.primeiro_time_id
        LEFT JOIN times t2 ON t2.id = pgc.segundo_time_id
        LEFT JOIN times t3 ON t3.id = pgc.terceiro_time_id
        WHERE g.edicao_id = :edicao_id2
        ORDER BY g.codigo
    ";
    $stClass = $pdo->prepare($sqlClass);
    $stClass->execute([
        ":usuario_id" => $usuarioDbId,
        ":edicao_id1" => $edicaoId,
        ":edicao_id2" => $edicaoId,
    ]);
    $rowsClass = $stClass->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================
    // 3) Campeão — 1 por usuário por edição (se existir)
    // =========================================================
    $sqlCamp = "
        SELECT t.nome AS campeao_nome
        FROM palpite_campeao pc
        INNER JOIN times t ON t.id = pc.time_id
        WHERE pc.usuario_id = :usuario_id
          AND pc.edicao_id = :edicao_id
        LIMIT 1
    ";
    $stCamp = $pdo->prepare($sqlCamp);
    $stCamp->execute([
        ":usuario_id" => $usuarioDbId,
        ":edicao_id"  => $edicaoId,
    ]);
    $campeaoNome = $stCamp->fetchColumn();
    if ($campeaoNome === false || $campeaoNome === null) {
        $campeaoNome = "";
    } else {
        $campeaoNome = (string)$campeaoNome;
    }

    // =========================================================
    // 4) TOP 4 (Mata-mata) — 1 por usuário por edição (se existir)
    // =========================================================
    $sqlTop4 = "
        SELECT
            t1.nome AS primeiro_nome,
            t2.nome AS segundo_nome,
            t3.nome AS terceiro_nome,
            t4.nome AS quarto_nome
        FROM palpite_top4 pt
        LEFT JOIN times t1 ON t1.id = pt.primeiro_time_id
        LEFT JOIN times t2 ON t2.id = pt.segundo_time_id
        LEFT JOIN times t3 ON t3.id = pt.terceiro_time_id
        LEFT JOIN times t4 ON t4.id = pt.quarto_time_id
        WHERE pt.usuario_id = :usuario_id
          AND pt.edicao_id = :edicao_id
        LIMIT 1
    ";
    $stTop4 = $pdo->prepare($sqlTop4);
    $stTop4->execute([
        ":usuario_id" => $usuarioDbId,
        ":edicao_id"  => $edicaoId,
    ]);
    $rowTop4 = $stTop4->fetch(PDO::FETCH_ASSOC);
    if (!is_array($rowTop4)) $rowTop4 = [];

    // =========================================================
    // 5) Jogos + placares apostados (Mata-mata)
    // =========================================================
    $sqlJogosMM = "
        SELECT
            j.fase,
            j.data_hora,
            j.codigo_fifa,
            tc.nome AS casa_nome,
            p.gols_casa,
            tf.nome AS fora_nome,
            p.gols_fora
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
    $stJogosMM = $pdo->prepare($sqlJogosMM);
    $stJogosMM->execute([
        ":edicao_id" => $edicaoId,
        ":usuario_id" => $usuarioDbId,
    ]);
    $rowsJogosMM = $stJogosMM->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================
    // Saída XLS (HTML)
    // =========================================================
    $slug = filename_slug($usuarioNome);
    $ts = date("Y-m-d_H-i");
    $filename = "apostas_{$slug}_{$ts}.xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<html><head><meta charset='UTF-8'></head><body>";

    // ---------- Cabeçalho rápido ----------
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<tbody>";
    echo "<tr><th colspan='4'>Exportação de Apostas</th></tr>";

    echo "<tr>";
    echo "<td><b>Usuário</b></td>";
    echo "<td>" . strh($usuarioNome) . "</td>";
    echo "<td><b>ID</b></td>";
    echo "<td>" . strh((string)$usuarioDbId) . "</td>";
    echo "</tr>";

    echo "<tr><td><b>Edição ID</b></td><td colspan='3'>" . strh((string)$edicaoId) . "</td></tr>";
    echo "<tr><td><b>Campeão</b></td><td colspan='3'>" . strh($campeaoNome) . "</td></tr>";
    echo "</tbody>";
    echo "</table>";

    echo "<br/>";

    // ---------- Tabela: Classificação por Grupo ----------
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th colspan='4'>Palpite de Classificação por Grupo (1º / 2º / 3º)</th>";
    echo "</tr>";
    echo "<tr>";
    echo "<th>Grupo</th>";
    echo "<th>1º</th>";
    echo "<th>2º</th>";
    echo "<th>3º</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($rowsClass as $r) {
        $grupo = (string)($r["grupo_codigo"] ?? "");
        $p1 = (string)($r["primeiro_nome"] ?? "");
        $p2 = (string)($r["segundo_nome"] ?? "");
        $p3 = (string)($r["terceiro_nome"] ?? "");

        echo "<tr>";
        echo "<td>" . strh($grupo) . "</td>";
        echo "<td>" . strh($p1) . "</td>";
        echo "<td>" . strh($p2) . "</td>";
        echo "<td>" . strh($p3) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";

    echo "<br/>";

    // ---------- Tabela: Jogos (Fase de Grupos) ----------
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th colspan='7'>Palpites de Jogos (Fase de Grupos)</th>";
    echo "</tr>";
    echo "<tr>";
    echo "<th>Grupo</th>";
    echo "<th>Data/Hora</th>";
    echo "<th>Código FIFA</th>";
    echo "<th>Time da casa</th>";
    echo "<th>Placar casa</th>";
    echo "<th>Time visitante</th>";
    echo "<th>Placar visitante</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($rowsJogos as $r) {
        $grupo = (string)$r["grupo_codigo"];

        $dhRaw = (string)$r["data_hora"];
        $dh = $dhRaw;
        $ts2 = strtotime($dhRaw);
        if ($ts2 !== false) $dh = date("d/m/Y H:i", $ts2);

        $codigoFifaRaw = $r["codigo_fifa"] ?? "";
        $codigoFifa = "";
        if ($codigoFifaRaw !== null) {
            $codigoFifa = trim((string)$codigoFifaRaw);
        }

        $casa = (string)$r["casa_nome"];
        $fora = (string)$r["fora_nome"];

        $gc = $r["gols_casa"];
        $gf = $r["gols_fora"];
        $gcStr = ($gc === null) ? "" : (string)(int)$gc;
        $gfStr = ($gf === null) ? "" : (string)(int)$gf;

        echo "<tr>";
        echo "<td>" . strh($grupo) . "</td>";
        echo "<td>" . strh($dh) . "</td>";
        echo "<td>" . strh($codigoFifa) . "</td>";
        echo "<td>" . strh($casa) . "</td>";
        echo "<td>" . strh($gcStr) . "</td>";
        echo "<td>" . strh($fora) . "</td>";
        echo "<td>" . strh($gfStr) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";

    // =========================================================
    // ✅ A PARTIR DAQUI: ADIÇÕES DO MATA-MATA (abaixo do que já existe)
    // =========================================================

    echo "<br/>";
    echo "<br/>";

    // ---------- Tabela: Top 4 (Mata-mata) ----------
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr><th colspan='4'>Top 4 (Mata-mata)</th></tr>";
    echo "<tr>";
    echo "<th>1º</th>";
    echo "<th>2º</th>";
    echo "<th>3º</th>";
    echo "<th>4º</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    $t1 = (string)($rowTop4["primeiro_nome"] ?? "");
    $t2 = (string)($rowTop4["segundo_nome"] ?? "");
    $t3 = (string)($rowTop4["terceiro_nome"] ?? "");
    $t4 = (string)($rowTop4["quarto_nome"] ?? "");

    echo "<tr>";
    echo "<td>" . strh($t1) . "</td>";
    echo "<td>" . strh($t2) . "</td>";
    echo "<td>" . strh($t3) . "</td>";
    echo "<td>" . strh($t4) . "</td>";
    echo "</tr>";

    echo "</tbody>";
    echo "</table>";

    echo "<br/>";

    // ---------- Tabela: Jogos (Mata-mata) ----------
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th colspan='7'>Palpites de Jogos (Mata-mata)</th>";
    echo "</tr>";
    echo "<tr>";
    echo "<th>Fase</th>";
    echo "<th>Data/Hora</th>";
    echo "<th>Código FIFA</th>";
    echo "<th>Time da casa</th>";
    echo "<th>Placar casa</th>";
    echo "<th>Time visitante</th>";
    echo "<th>Placar visitante</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    $faseLabel = [
        '16_DE_FINAL' => '16 de final',
        'OITAVAS' => 'Oitavas',
        'QUARTAS' => 'Quartas',
        'SEMI' => 'Semifinal',
        'TERCEIRO_LUGAR' => '3º lugar',
        'FINAL' => 'Final',
    ];

    foreach ($rowsJogosMM as $r) {
        $fase = (string)($r["fase"] ?? "");
        $faseOut = isset($faseLabel[$fase]) ? $faseLabel[$fase] : $fase;

        $dhRaw = (string)($r["data_hora"] ?? "");
        $dh = $dhRaw;
        $ts2 = strtotime($dhRaw);
        if ($ts2 !== false) $dh = date("d/m/Y H:i", $ts2);

        $codigoFifaRaw = $r["codigo_fifa"] ?? "";
        $codigoFifa = "";
        if ($codigoFifaRaw !== null) {
            $codigoFifa = trim((string)$codigoFifaRaw);
        }

        $casa = (string)($r["casa_nome"] ?? "");
        $fora = (string)($r["fora_nome"] ?? "");

        $gc = $r["gols_casa"] ?? null;
        $gf = $r["gols_fora"] ?? null;
        $gcStr = ($gc === null) ? "" : (string)(int)$gc;
        $gfStr = ($gf === null) ? "" : (string)(int)$gf;

        echo "<tr>";
        echo "<td>" . strh($faseOut) . "</td>";
        echo "<td>" . strh($dh) . "</td>";
        echo "<td>" . strh($codigoFifa) . "</td>";
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