<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONEXÃO PDO - BOLÃO DA COPA
|--------------------------------------------------------------------------
| HostGator: este arquivo fica em /home2/mauri075/php/conexao.php
| e é incluído pelos scripts em /home2/mauri075/php/*.php e pelo public_html.
|--------------------------------------------------------------------------
| Banco: mauri075_bolao
| Engine: MySQL (InnoDB)
| Charset: utf8mb4
|--------------------------------------------------------------------------
*/

$host = "69.6.249.199";
$port = "3306";
$db   = "mauri075_bolao";
$user = "mauri075_mauricio";
$pass = "Eng%3571Hawaii";

// ✅ alteração mínima: timeout de conexão
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4;connect_timeout=5";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // lança exception
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // retorna array associativo
    PDO::ATTR_EMULATE_PREPARES   => false,                    // prepared real (segurança)
    PDO::ATTR_PERSISTENT         => false,                    // conexão não persistente

    // ✅ alteração mínima: timeout (quando suportado pelo driver/ambiente)
    PDO::ATTR_TIMEOUT            => 5,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Nunca expor erro detalhado em produção
    http_response_code(500);
    exit("Erro na conexão com o banco de dados.");
}