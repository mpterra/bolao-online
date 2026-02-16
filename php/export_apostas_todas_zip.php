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

// Para ~200 usuários
@set_time_limit(0);
ini_set('memory_limit', '512M');

try {
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
    // SQL base (igual ao export individual)
    // -----------------------------
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

    // Timestamp único do pacote
    $tsPack = date("Y-m-d_H-i");
    $folder = "apostas_{$tsPack}";

    // -----------------------------
    // Gera 1 XLS (HTML) por usuário e adiciona ao ZIP
    // -----------------------------
    foreach ($usuarios as $u) {
        $usuarioId = (int)$u["id"];
        $usuarioNome = (string)$u["nome"];

        // Executa query para o usuário
        $st->execute([
            ":edicao_id1" => $edicaoId,
            ":edicao_id2" => $edicaoId,
            ":usuario_id" => $usuarioId,
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $slug = filename_slug($usuarioNome);
        $filename = "apostas_{$slug}_id{$usuarioId}_{$tsPack}.xls";
        $zipPath = "{$folder}/{$filename}";

        // Monta o mesmo "xls" em HTML (Excel abre)
        $html = "";
        $html .= "<html><head><meta charset='UTF-8'></head><body>";
        $html .= "<table border='1' cellspacing='0' cellpadding='6'>";
        $html .= "<thead>";
        $html .= "<tr>";
        $html .= "<th>Grupo</th>";
        $html .= "<th>Data/Hora</th>";
        $html .= "<th>Time da casa</th>";
        $html .= "<th>Placar casa</th>";
        $html .= "<th>Time visitante</th>";
        $html .= "<th>Placar visitante</th>";
        $html .= "</tr>";
        $html .= "</thead>";
        $html .= "<tbody>";

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

            $html .= "<tr>";
            $html .= "<td>" . strh($grupo) . "</td>";
            $html .= "<td>" . strh($dh) . "</td>";
            $html .= "<td>" . strh($casa) . "</td>";
            $html .= "<td>" . strh($gcStr) . "</td>";
            $html .= "<td>" . strh($fora) . "</td>";
            $html .= "<td>" . strh($gfStr) . "</td>";
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
