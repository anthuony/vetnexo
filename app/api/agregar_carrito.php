<?php
// api/agregar_carrito.php
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

require_once '../config/database.php';

$response = ['success' => false, 'message' => '', 'total_items' => 0];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Método no permitido';
    echo json_encode($response);
    exit();
}

$producto_id = isset($_POST['producto_id']) ? (int)$_POST['producto_id'] : 0;
$cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 1;

if ($producto_id <= 0 || $cantidad < 1 || $cantidad > 99) {
    $response['message'] = 'ID de producto inválido';
    echo json_encode($response);
    exit();
}

try {
    $database = new Database();
    $conn = $database->conectar();
    
    // Verificar que el producto existe y tiene stock
    $stmt = $conn->prepare("SELECT id, nombre, stock_actual, activo FROM productos WHERE id = ?");
    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$producto) {
        $response['message'] = 'Producto no encontrado';
        echo json_encode($response);
        exit();
    }
    
    if ($producto['activo'] != 1) {
        $response['message'] = 'Producto no disponible';
        echo json_encode($response);
        exit();
    }
    
    if ($producto['stock_actual'] < $cantidad) {
        $response['message'] = 'Stock insuficiente. Disponible: ' . $producto['stock_actual'];
        echo json_encode($response);
        exit();
    }
    
    // Inicializar carrito si no existe
    if (!isset($_SESSION['carrito'])) {
        $_SESSION['carrito'] = [];
    }
    
    // Agregar o actualizar producto en el carrito sin superar el stock disponible
    $cantidad_actual = isset($_SESSION['carrito'][$producto_id]) ? (int)$_SESSION['carrito'][$producto_id]['cantidad'] : 0;
    $nueva_cantidad = $cantidad_actual + $cantidad;

    if ($nueva_cantidad > 99 || $nueva_cantidad > (int)$producto['stock_actual']) {
        $response['message'] = 'Stock insuficiente. Ya tienes ' . $cantidad_actual . ' en el carrito. Disponible: ' . $producto['stock_actual'];
        echo json_encode($response);
        exit();
    }

    $_SESSION['carrito'][$producto_id] = [
        'id' => $producto_id,
        'cantidad' => $nueva_cantidad,
        'fecha_agregado' => $_SESSION['carrito'][$producto_id]['fecha_agregado'] ?? date('Y-m-d H:i:s')
    ];
    
    // Calcular total de items
    $total_items = 0;
    foreach ($_SESSION['carrito'] as $item) {
        $total_items += $item['cantidad'];
    }
    
    $response['success'] = true;
    $response['message'] = 'Producto agregado al carrito';
    $response['total_items'] = $total_items;
    
} catch (Exception $e) {
    $response['message'] = 'Error en el servidor';
    error_log("Error en agregar_carrito: " . $e->getMessage());
}

echo json_encode($response);
?>