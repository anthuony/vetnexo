<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff(true);

include 'config/database.php';

header('Content-Type: application/json');

try {
    $db = getDBConnection();
    
    $searchTerm = $_GET['q'] ?? '';
    
    if (strlen($searchTerm) < 2) {
        echo json_encode([]);
        exit();
    }
    
    $sql = "SELECT id, nombre, documento_identidad, telefono, direccion 
            FROM clientes 
            WHERE nombre LIKE ? OR documento_identidad LIKE ? OR telefono LIKE ?
            ORDER BY nombre 
            LIMIT 10";
    
    $stmt = $db->prepare($sql);
    $searchPattern = "%$searchTerm%";
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
    
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($clientes);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>