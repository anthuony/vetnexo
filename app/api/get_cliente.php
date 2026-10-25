<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_staff(true);

include '../config/database.php';

header('Content-Type: application/json');

$cliente_id = $_GET['id'] ?? null;

if (!$cliente_id) {
    echo json_encode(null);
    exit();
}

try {
    $db = getDBConnection();
    $sql = "SELECT nombre, documento_identidad, telefono FROM clientes WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($cliente);
} catch (PDOException $e) {
    echo json_encode(null);
}