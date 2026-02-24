<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONEXÃO PDO - BOLÃO DA COPA
|--------------------------------------------------------------------------
| Banco: bolao_copa
| Engine: MySQL (InnoDB)
| Charset: utf8mb4
|--------------------------------------------------------------------------
*/

$host = "127.0.0.1";
$port = "3306";
$db   = "bolao_copa";
$user = "root";
$pass = "Eng%3571";

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // lança exception
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // retorna array associativo
    PDO::ATTR_EMULATE_PREPARES   => false,                    // prepared real (segurança)
    PDO::ATTR_PERSISTENT         => false                     // conexão não persistente
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {

    // Nunca expor erro detalhado em produção
    http_response_code(500);
    exit("Erro na conexão com o banco de dados.");
}
