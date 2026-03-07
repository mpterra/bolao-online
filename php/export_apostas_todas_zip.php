<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
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

// Para ~200 usuários
@set_time_limit(0);
ini_set('memory_limit', '512M');

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException("PDO não inicializado em conexao.php");
    }

    // -----------------------------
    // Edição ativa (mesma lógica)
    // -----------------------------
    $edicaoId = (int)$pdo->query("SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1")->fetchColumn();
    if ($edicaoId <= 0) {
        $edicaoId = (int)$pdo->query("SELECT id FROM edicoes ORDER BY ano DESC LIMIT 1")->fetchColumn();
    }
    if ($edicaoId <= 0) {
        throw new RuntimeException("Nenhuma edição encontrada.");
    }

    // -----------------------------
    // Usuários ativos
    // -----------------------------
    $stUsers = $pdo->query("
        SELECT id, nome, tipo_usuario
        FROM usuarios
        WHERE ativo = 1
        ORDER BY (tipo_usuario='ADMIN') DESC, nome ASC, id ASC
    ");
    $usuarios = $stUsers->fetchAll(PDO::FETCH_ASSOC);

    if (!$usuarios) {
        http_response_code(404);
        exit("Nenhum usuário ativo encontrado.");
    }

    // -----------------------------
    // Prepara ZIP em arquivo temporário
    // -----------------------------
    $tmpZip = tempnam(sys_get_temp_dir(), 'bolao_apostas_zip_');
    if ($tmpZip === false) {
        throw new RuntimeException("Falha ao criar arquivo temporário.");
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        throw new RuntimeException("Falha ao abrir ZIP.");
    }

    // -----------------------------
    // SQL: Jogos + palpites (por usuário) - FASE DE GRUPOS
    // -----------------------------
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

    // -----------------------------
    // SQL: Classificação 1º/2º/3º por grupo (por usuário) — lista TODOS os grupos da edição
    // -----------------------------
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

    // -----------------------------
    // SQL: Campeão (por usuário)
    // -----------------------------
    $sqlCamp = "
        SELECT t.nome AS campeao_nome
        FROM palpite_campeao pc
        INNER JOIN times t ON t.id = pc.time_id
        WHERE pc.usuario_id = :usuario_id
          AND pc.edicao_id = :edicao_id
        LIMIT 1
    ";
    $stCamp = $pdo->prepare($sqlCamp);

    // =========================================================
    // ✅ ADIÇÃO: SQL TOP 4 (MATA-MATA) (por usuário)
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

    // =========================================================
    // ✅ ALTERAÇÃO: SQL Jogos + palpites (MATA-MATA) (por usuário)
    //    Inclui o time selecionado pelo apostador para "passa em caso de empate"
    // =========================================================
    $sqlJogosMM = "
        SELECT
            j.fase,
            j.data_hora,
            j.codigo_fifa,
            tc.nome AS casa_nome,
            p.gols_casa,
            tf.nome AS fora_nome,
            p.gols_fora,
            tp.nome AS passa_nome
        FROM jogos j
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        LEFT JOIN palpites p
            ON p.jogo_id = j.id
           AND p.usuario_id = :usuario_id
        LEFT JOIN times tp
            ON tp.id = p.passa_time_id
        WHERE j.edicao_id = :edicao_id
          AND j.grupo_id IS NULL
          AND j.fase IN ('16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL')
        ORDER BY
          FIELD(j.fase,'16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL'),
          j.data_hora, j.id
    ";
    $stJogosMM = $pdo->prepare($sqlJogosMM);

    // Timestamp único do pacote
    $tsPack = date("Y-m-d_H-i");
    $folder = "apostas_{$tsPack}";

    // -----------------------------
    // Gera 1 XLS (HTML) por usuário e adiciona ao ZIP
    // -----------------------------
    foreach ($usuarios as $u) {
        $usuarioId = (int)$u["id"];
        $usuarioNome = (string)$u["nome"];

        // Campeão do usuário
        $stCamp->execute([
            ":usuario_id" => $usuarioId,
            ":edicao_id"  => $edicaoId,
        ]);
        $campeaoNome = $stCamp->fetchColumn();
        if ($campeaoNome === false || $campeaoNome === null) {
            $campeaoNome = "";
        } else {
            $campeaoNome = (string)$campeaoNome;
        }

        // Classificação por grupo do usuário
        $stClass->execute([
            ":usuario_id" => $usuarioId,
            ":edicao_id1" => $edicaoId,
            ":edicao_id2" => $edicaoId,
        ]);
        $rowsClass = $stClass->fetchAll(PDO::FETCH_ASSOC);

        // Jogos do usuário (fase de grupos)
        $stJogos->execute([
            ":edicao_id1" => $edicaoId,
            ":edicao_id2" => $edicaoId,
            ":usuario_id" => $usuarioId,
        ]);
        $rowsJogos = $stJogos->fetchAll(PDO::FETCH_ASSOC);

        // =============================
        // ✅ Top4 do usuário
        // =============================
        $stTop4->execute([
            ":usuario_id" => $usuarioId,
            ":edicao_id"  => $edicaoId,
        ]);
        $rowTop4 = $stTop4->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rowTop4)) $rowTop4 = [];

        // =============================
        // ✅ Jogos mata-mata do usuário
        // =============================
        $stJogosMM->execute([
            ":edicao_id" => $edicaoId,
            ":usuario_id" => $usuarioId,
        ]);
        $rowsJogosMM = $stJogosMM->fetchAll(PDO::FETCH_ASSOC);

        $slug = filename_slug($usuarioNome);
        $filename = "apostas_{$slug}_id{$usuarioId}_{$tsPack}.xls";
        $zipPath = "{$folder}/{$filename}";

        // Monta o XLS (HTML)
        $html = "";
        $html .= "<html><head><meta charset='UTF-8'></head><body>";

        // ---------- Cabeçalho ----------
        $html .= "<table border='1' cellspacing='0' cellpadding='6'>";
        $html .= "<tbody>";
        $html .= "<tr><th colspan='4'>Exportação de Apostas</th></tr>";

        $html .= "<tr>";
        $html .= "<td><b>Usuário</b></td>";
        $html .= "<td>" . strh($usuarioNome) . "</td>";
        $html .= "<td><b>ID</b></td>";
        $html .= "<td>" . strh((string)$usuarioId) . "</td>";
        $html .= "</tr>";

        $html .= "<tr><td><b>Edição ID</b></td><td colspan='3'>" . strh((string)$edicaoId) . "</td></tr>";
        $html .= "<tr><td><b>Campeão</b></td><td colspan='3'>" . strh($campeaoNome) . "</td></tr>";
        $html .= "</tbody>";
        $html .= "</table>";

        $html .= "<br/>";

        // ---------- Classificação por Grupo ----------
        $html .= "<table border='1' cellspacing='0' cellpadding='6'>";
        $html .= "<thead>";
        $html .= "<tr><th colspan='4'>Palpite de Classificação por Grupo (1º / 2º / 3º)</th></tr>";
        $html .= "<tr>";
        $html .= "<th>Grupo</th>";
        $html .= "<th>1º</th>";
        $html .= "<th>2º</th>";
        $html .= "<th>3º</th>";
        $html .= "</tr>";
        $html .= "</thead>";
        $html .= "<tbody>";

        foreach ($rowsClass as $r) {
            $grupo = (string)($r["grupo_codigo"] ?? "");
            $p1 = (string)($r["primeiro_nome"] ?? "");
            $p2 = (string)($r["segundo_nome"] ?? "");
            $p3 = (string)($r["terceiro_nome"] ?? "");

            $html .= "<tr>";
            $html .= "<td>" . strh($grupo) . "</td>";
            $html .= "<td>" . strh($p1) . "</td>";
            $html .= "<td>" . strh($p2) . "</td>";
            $html .= "<td>" . strh($p3) . "</td>";
            $html .= "</tr>";
        }

        $html .= "</tbody>";
        $html .= "</table>";

        $html .= "<br/>";

        // ---------- Jogos (Fase de Grupos) ----------
        $html .= "<table border='1' cellspacing='0' cellpadding='6'>";
        $html .= "<thead>";
        $html .= "<tr><th colspan='7'>Palpites de Jogos (Fase de Grupos)</th></tr>";
        $html .= "<tr>";
        $html .= "<th>Grupo</th>";
        $html .= "<th>Data/Hora</th>";
        $html .= "<th>Código FIFA</th>";
        $html .= "<th>Time da casa</th>";
        $html .= "<th>Placar casa</th>";
        $html .= "<th>Time visitante</th>";
        $html .= "<th>Placar visitante</th>";
        $html .= "</tr>";
        $html .= "</thead>";
        $html .= "<tbody>";

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

            $html .= "<tr>";
            $html .= "<td>" . strh($grupo) . "</td>";
            $html .= "<td>" . strh($dh) . "</td>";
            $html .= "<td>" . strh($codigoFifa) . "</td>";
            $html .= "<td>" . strh($casa) . "</td>";
            $html .= "<td>" . strh($gcStr) . "</td>";
            $html .= "<td>" . strh($fora) . "</td>";
            $html .= "<td>" . strh($gfStr) . "</td>";
            $html .= "</tr>";
        }

        $html .= "</tbody>";
        $html .= "</table>";

        // =========================================================
        // ✅ A PARTIR DAQUI: ADIÇÕES DO MATA-MATA
        // =========================================================

        $html .= "<br/>";
        $html .= "<br/>";

        // ---------- Top 4 (Mata-mata) ----------
        $html .= "<table border='1' cellspacing='0' cellpadding='6'>";
        $html .= "<thead>";
        $html .= "<tr><th colspan='4'>Top 4 (Mata-mata)</th></tr>";
        $html .= "<tr>";
        $html .= "<th>1º</th>";
        $html .= "<th>2º</th>";
        $html .= "<th>3º</th>";
        $html .= "<th>4º</th>";
        $html .= "</tr>";
        $html .= "</thead>";
        $html .= "<tbody>";

        $t1 = (string)($rowTop4["primeiro_nome"] ?? "");
        $t2 = (string)($rowTop4["segundo_nome"] ?? "");
        $t3 = (string)($rowTop4["terceiro_nome"] ?? "");
        $t4 = (string)($rowTop4["quarto_nome"] ?? "");

        $html .= "<tr>";
        $html .= "<td>" . strh($t1) . "</td>";
        $html .= "<td>" . strh($t2) . "</td>";
        $html .= "<td>" . strh($t3) . "</td>";
        $html .= "<td>" . strh($t4) . "</td>";
        $html .= "</tr>";

        $html .= "</tbody>";
        $html .= "</table>";

        $html .= "<br/>";

        // ---------- Jogos (Mata-mata) ----------
        // ✅ Coluna extra: "Quem passa (se empate)" = tp.nome (palpites.passa_time_id)
        $html .= "<table border='1' cellspacing='0' cellpadding='6'>";
        $html .= "<thead>";
        $html .= "<tr><th colspan='8'>Palpites de Jogos (Mata-mata)</th></tr>";
        $html .= "<tr>";
        $html .= "<th>Fase</th>";
        $html .= "<th>Data/Hora</th>";
        $html .= "<th>Código FIFA</th>";
        $html .= "<th>Time da casa</th>";
        $html .= "<th>Placar casa</th>";
        $html .= "<th>Time visitante</th>";
        $html .= "<th>Placar visitante</th>";
        $html .= "<th>Quem passa (se empate)</th>";
        $html .= "</tr>";
        $html .= "</thead>";
        $html .= "<tbody>";

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

            $passaEmpate = "";
            if ($gc !== null && $gf !== null) {
                $igc = (int)$gc;
                $igf = (int)$gf;
                if ($igc === $igf) {
                    $passaEmpate = trim((string)($r["passa_nome"] ?? ""));
                }
            }

            $html .= "<tr>";
            $html .= "<td>" . strh($faseOut) . "</td>";
            $html .= "<td>" . strh($dh) . "</td>";
            $html .= "<td>" . strh($codigoFifa) . "</td>";
            $html .= "<td>" . strh($casa) . "</td>";
            $html .= "<td>" . strh($gcStr) . "</td>";
            $html .= "<td>" . strh($fora) . "</td>";
            $html .= "<td>" . strh($gfStr) . "</td>";
            $html .= "<td>" . strh($passaEmpate) . "</td>";
            $html .= "</tr>";
        }

        $html .= "</tbody>";
        $html .= "</table>";

        $html .= "</body></html>";

        $zip->addFromString($zipPath, $html);
    }

    $zip->close();

    // -----------------------------
    // Entrega ZIP
    // -----------------------------
    $zipName = "apostas_todos_{$tsPack}.zip";

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    exit("Erro ao gerar ZIP.");
}