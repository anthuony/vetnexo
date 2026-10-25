<?php
// api/get_producto.php
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

require_once '../config/database.php';

// Inicializar respuesta
$response = [
    'success' => false,
    'message' => '',
    'producto' => null
];

// Obtener ID del producto
$producto_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($producto_id <= 0) {
    $response['message'] = 'ID de producto inválido';
    echo json_encode($response);
    exit();
}

try {
    $database = new Database();
    $conn = $database->conectar();
    
    // Obtener información del producto
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.codigo,
            p.nombre,
            p.descripcion,
            p.descripcion_corta,
            p.imagen,
            p.precio_venta,
            p.stock_actual,
            p.stock_minimo,
            p.aplica_iva,
            p.porcentaje_iva,
            p.activo,
            p.destacado,
            p.categoria_id,
            c.nombre as categoria_nombre,
            p.principio_activo,
            p.fabricante,
            p.via_administracion,
            p.tipo_medicamento,
            p.fecha_creacion
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        WHERE p.id = ? AND p.activo = 1
    ");
    
    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$producto) {
        $response['message'] = 'Producto no encontrado';
        echo json_encode($response);
        exit();
    }
    
    // No inferir ofertas usando el costo interno. El API público nunca expone precio de compra.
    $descuento = 0;
    $precio_original = (float)$producto['precio_venta'];
    
    // Determinar estado del stock
    $estado_stock = 'disponible';
    if ($producto['stock_actual'] <= 0) {
        $estado_stock = 'agotado';
    } elseif ($producto['stock_actual'] <= $producto['stock_minimo']) {
        $estado_stock = 'bajo';
    }
    
    // Verificar si el producto está en el carrito
    $en_carrito = 0;
    if (isset($_SESSION['carrito'][$producto_id])) {
        $en_carrito = $_SESSION['carrito'][$producto_id]['cantidad'];
    }
    
    // Obtener productos relacionados (misma categoría)
    $stmt_relacionados = $conn->prepare("
        SELECT id, nombre, imagen, precio_venta
        FROM productos
        WHERE categoria_id = ? AND id != ? AND activo = 1 AND stock_actual > 0
        ORDER BY RAND()
        LIMIT 4
    ");
    $stmt_relacionados->execute([$producto['categoria_id'], $producto_id]);
    $relacionados = $stmt_relacionados->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener imágenes adicionales (si existiera tabla de imágenes)
    $stmt_imagenes = $conn->prepare("
        SELECT imagen, orden
        FROM producto_imagenes
        WHERE producto_id = ?
        ORDER BY orden ASC
    ");
    $stmt_imagenes->execute([$producto_id]);
    $imagenes_adicionales = $stmt_imagenes->fetchAll(PDO::FETCH_ASSOC);
    
    // Construir array de imágenes
    $imagenes = [];
    if (!empty($producto['imagen'])) {
        $imagenes[] = [
            'url' => '../uploads/productos/' . $producto['imagen'],
            'principal' => true,
            'orden' => 0
        ];
    }
    
    foreach ($imagenes_adicionales as $img) {
        $imagenes[] = [
            'url' => '../uploads/productos/' . $img['imagen'],
            'principal' => false,
            'orden' => $img['orden']
        ];
    }
    
    // Preparar datos del producto para la respuesta
    $response['success'] = true;
    $response['producto'] = [
        'id' => $producto['id'],
        'codigo' => $producto['codigo'],
        'nombre' => $producto['nombre'],
        'descripcion' => $producto['descripcion'],
        'descripcion_corta' => $producto['descripcion_corta'],
        'imagenes' => $imagenes,
        'imagen_principal' => !empty($producto['imagen']) ? '../uploads/productos/' . $producto['imagen'] : null,
        'precio' => [
            'usd' => number_format($producto['precio_venta'], 2, '.', ''),
            'bs' => number_format($producto['precio_venta'] * 45.50, 2, '.', '') // Tasa de cambio ejemplo
        ],
        'precio_original' => $precio_original != $producto['precio_venta'] ? number_format($precio_original, 2, '.', '') : null,
        'descuento' => $descuento,
        'stock' => [
            'actual' => $producto['stock_actual'],
            'minimo' => $producto['stock_minimo'],
            'estado' => $estado_stock,
            'mensaje' => $estado_stock == 'agotado' ? 'Producto agotado' : 
                        ($estado_stock == 'bajo' ? '¡Últimas ' . $producto['stock_actual'] . ' unidades!' : 
                        $producto['stock_actual'] . ' disponibles')
        ],
        'categoria' => [
            'id' => $producto['categoria_id'],
            'nombre' => $producto['categoria_nombre']
        ],
        'fabricante' => $producto['fabricante'],
        'principio_activo' => $producto['principio_activo'],
        'via_administracion' => $producto['via_administracion'],
        'tipo_medicamento' => $producto['tipo_medicamento'],
        'iva' => [
            'aplica' => (bool)$producto['aplica_iva'],
            'porcentaje' => $producto['porcentaje_iva']
        ],
        'en_carrito' => $en_carrito,
        'relacionados' => $relacionados,
        'destacado' => (bool)$producto['destacado'],
        'fecha_creacion' => $producto['fecha_creacion']
    ];
    
} catch (PDOException $e) {
    $response['message'] = 'Error en la base de datos';
    error_log("Error en get_producto: " . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Error en get_producto: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();