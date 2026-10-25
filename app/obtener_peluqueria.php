<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

// Incluir configuración de base de datos
include 'config/database.php';

// Obtener ID de la mascota
$mascota_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($mascota_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'ID de mascota inválido']);
    exit();
}

try {
    $db = getDBConnection();
    
    // Obtener datos generales de peluquería
    $sql = "SELECT COUNT(*) as total, MAX(fecha_corte) as ultimo_corte 
            FROM cortes_peluqueria 
            WHERE mascota_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$mascota_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener historial detallado
    $sql_detalle = "SELECT fecha_corte, tipo_corte_realizado, observaciones, costo 
                    FROM cortes_peluqueria 
                    WHERE mascota_id = ? 
                    ORDER BY fecha_corte DESC 
                    LIMIT 20";
    $stmt_detalle = $db->prepare($sql_detalle);
    $stmt_detalle->execute([$mascota_id]);
    $cortes = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);
    
    $data['cortes'] = $cortes;
    
    header('Content-Type: application/json');
    echo json_encode($data);
    
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Error al obtener datos: ' . $e->getMessage()]);
}