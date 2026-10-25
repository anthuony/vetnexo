<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_staff(true);

include '../config/database.php';

header('Content-Type: application/json');

$cliente_id = $_GET['cliente_id'] ?? null;

if (!$cliente_id) {
    echo json_encode([]);
    exit();
}

try {
    $db = getDBConnection();
    $sql = "SELECT id, nombre, raza FROM mascotas WHERE cliente_id = ? ORDER BY nombre";
    $stmt = $db->prepare($sql);
    $stmt->execute([$cliente_id]);
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($mascotas);
} catch (PDOException $e) {
    echo json_encode([]);
}