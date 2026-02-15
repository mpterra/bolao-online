<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../php/db.php';

try {
    $stmt = $pdo->query("SELECT 1");
    echo "✅ Conexão com o banco realizada com sucesso.";
} catch (PDOException $e) {
    echo "❌ Erro ao executar teste: " . $e->getMessage();
}
