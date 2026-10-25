<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff(true);

include 'config/database.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$searchTerm = $_GET['q'] ?? '';

// Limpiar y validar término de búsqueda
$searchTerm = trim($searchTerm);

if (strlen($searchTerm) < 2) {
    echo json_encode([]);
    exit();
}

try {
    $db = getDBConnection();
    
    // Buscar servicios - siempre sin IVA
    $sqlServicios = "SELECT 
                        id, 
                        nombre, 
                        precio, 
                        'servicio' as tipo, 
                        0 as aplica_iva, 
                        0.00 as porcentaje_iva,
                        '' as stock,
                        '' as codigo
                     FROM servicios 
                     WHERE nombre LIKE ? 
                     AND activo = 1 
                     ORDER BY nombre
                     LIMIT 10";
    
    $stmtServicios = $db->prepare($sqlServicios);
    $stmtServicios->execute(["%$searchTerm%"]);
    $servicios = $stmtServicios->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar productos - incluyendo campos de IVA
    $sqlProductos = "SELECT 
                        id, 
                        nombre, 
                        precio_venta as precio, 
                        'producto' as tipo, 
                        COALESCE(aplica_iva, 0) as aplica_iva, 
                        COALESCE(porcentaje_iva, 16.00) as porcentaje_iva,
                        COALESCE(stock_actual, 0) as stock,
                        codigo
                     FROM productos 
                     WHERE COALESCE(es_medicamento_con_presentaciones,0)=0 AND (nombre LIKE ? OR codigo LIKE ?) 
                     AND activo = 1 
                     ORDER BY nombre
                     LIMIT 10";
    
    $stmtProductos = $db->prepare($sqlProductos);
    $stmtProductos->execute(["%$searchTerm%", "%$searchTerm%"]);
    $productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);
    
    // Combinar resultados
    $q=$db->prepare("SELECT p.id,mp.id AS presentacion_id,CONCAT(p.nombre,' · ',mp.nombre_presentacion) AS nombre,mp.precio_venta AS precio,'producto' AS tipo,p.aplica_iva,p.porcentaje_iva,mp.stock_actual AS stock,p.codigo FROM medicamento_presentaciones mp JOIN productos p ON p.id=mp.medicamento_id WHERE mp.activo=1 AND p.activo=1 AND p.es_medicamento_con_presentaciones=1 AND (p.nombre LIKE ? OR mp.nombre_presentacion LIKE ? OR p.codigo LIKE ?) LIMIT 20");$q->execute(["%$searchTerm%","%$searchTerm%","%$searchTerm%"]);
    $results = array_merge($servicios, $productos,$q->fetchAll());
    
    // Agregar información adicional para depuración (opcional)
    foreach ($results as &$item) {
        // Asegurar que los tipos sean correctos
        $item['id'] = (int)$item['id'];
        $item['precio'] = (float)$item['precio'];
        $item['aplica_iva'] = (int)$item['aplica_iva'];
        $item['porcentaje_iva'] = (float)$item['porcentaje_iva'];
        
        // Para depuración, agregar información sobre stock si es producto
        if ($item['tipo'] === 'producto') {
            $item['stock_info'] = "Stock: " . $item['stock'];
        }
    }
    
    // Configurar headers para JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Si no hay resultados, devolver array vacío
    if (empty($results)) {
        echo json_encode([]);
    } else {
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    }
    
} catch (PDOException $e) {
    // Log del error (en producción usarías un sistema de logging)
    error_log("Error en buscar_items.php: " . $e->getMessage());
    
    // Devolver error controlado
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'error' => 'Error en la búsqueda',
        'message' => 'No se pudieron cargar los items. Por favor, intente nuevamente.'
    ]);
}