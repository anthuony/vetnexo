<?php
// tienda/buscar.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';

$database = new Database();
$conn = $database->conectar();

$termino = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($termino)) {
    header('Location: productos.php');
    exit();
}

// Búsqueda en nombre, descripción y código
$stmt = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 
    AND (p.nombre LIKE ? OR p.descripcion LIKE ? OR p.codigo LIKE ?)
    ORDER BY 
        CASE 
            WHEN p.nombre LIKE ? THEN 1
            WHEN p.codigo LIKE ? THEN 2
            ELSE 3
        END,
        p.nombre
");
$search_term = "%$termino%";
$stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resto del código similar a productos.php pero mostrando resultados de búsqueda...