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

require_login();
require_admin();

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException("PDO não inicializado em conexao.php");
    }

    $stmt = $pdo->query("\n        SELECT id, nome, email, telefone\n        FROM usuarios\n        WHERE ativo = 1\n        ORDER BY nome ASC, id ASC\n    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "participantes_" . date("Y-m-d_H-i") . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr><th colspan='4'>Participantes do Bolão</th></tr>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Nome</th>";
    echo "<th>E-mail</th>";
    echo "<th>Telefone</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($usuarios as $u) {
        echo "<tr>";
        echo "<td>" . strh((string)($u["id"] ?? "")) . "</td>";
        echo "<td>" . strh((string)($u["nome"] ?? "")) . "</td>";
        echo "<td>" . strh((string)($u["email"] ?? "")) . "</td>";
        echo "<td>" . strh((string)($u["telefone"] ?? "")) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";
    echo "</body></html>";
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao exportar participantes.";
    exit;
}
