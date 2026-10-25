<?php
// api/actualizar_carrito.php
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

require_once '../config/database.php';

// Inicializar respuesta
$response = [
    'success' => false,
    'message' => '',
    'total_items' => 0,
    'subtotal' => 0,
    'iva' => 0,
    'total' => 0,
    'item_subtotal' => 0
];

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Método no permitido';
    echo json_encode($response);
    exit();
}

// Obtener datos del POST
$producto_id = isset($_POST['producto_id']) ? (int)$_POST['producto_id'] : 0;
$cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;
$accion = isset($_POST['accion']) ? $_POST['accion'] : 'actualizar';

// Validar datos básicos
if ($producto_id <= 0) {
    $response['message'] = 'ID de producto inválido';
    echo json_encode($response);
    exit();
}

if ($cantidad < 0 || $cantidad > 99) {
    $response['message'] = 'Cantidad inválida';
    echo json_encode($response);
    exit();
}

// Inicializar carrito si no existe
if (!isset($_SESSION['carrito']) || !is_array($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

try {
    $database = new Database();
    $conn = $database->conectar();
    
    // Obtener información actualizada del producto
    $stmt = $conn->prepare("
        SELECT id, nombre, precio_venta, stock_actual, activo 
        FROM productos 
        WHERE id = ? AND activo = 1
    ");
    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$producto) {
        $response['message'] = 'Producto no encontrado';
        echo json_encode($response);
        exit();
    }
    
    // Verificar stock
    if ($cantidad > $producto['stock_actual']) {
        $response['message'] = 'Stock insuficiente. Disponible: ' . $producto['stock_actual'];
        echo json_encode($response);
        exit();
    }
    
    // Procesar según la acción
    switch ($accion) {
        case 'actualizar':
            if ($cantidad > 0) {
                $_SESSION['carrito'][$producto_id] = [
                    'id' => $producto_id,
                    'cantidad' => $cantidad,
                    'fecha_agregado' => $_SESSION['carrito'][$producto_id]['fecha_agregado'] ?? date('Y-m-d H:i:s')
                ];
                $response['message'] = 'Cantidad actualizada';
            } else {
                // Si cantidad es 0, eliminar del carrito
                unset($_SESSION['carrito'][$producto_id]);
                $response['message'] = 'Producto eliminado';
            }
            break;
            
        case 'incrementar':
            $cantidad_actual = isset($_SESSION['carrito'][$producto_id]) ? $_SESSION['carrito'][$producto_id]['cantidad'] : 0;
            $nueva_cantidad = $cantidad_actual + 1;
            
            if ($nueva_cantidad > $producto['stock_actual']) {
                $response['message'] = 'Stock insuficiente. Máximo: ' . $producto['stock_actual'];
                echo json_encode($response);
                exit();
            }
            
            $_SESSION['carrito'][$producto_id] = [
                'id' => $producto_id,
                'cantidad' => $nueva_cantidad,
                'fecha_agregado' => $_SESSION['carrito'][$producto_id]['fecha_agregado'] ?? date('Y-m-d H:i:s')
            ];
            $response['message'] = 'Cantidad incrementada';
            $cantidad = $nueva_cantidad;
            break;
            
        case 'decrementar':
            $cantidad_actual = isset($_SESSION['carrito'][$producto_id]) ? $_SESSION['carrito'][$producto_id]['cantidad'] : 0;
            $nueva_cantidad = $cantidad_actual - 1;
            
            if ($nueva_cantidad <= 0) {
                // Si llega a 0, eliminar del carrito
                unset($_SESSION['carrito'][$producto_id]);
                $response['message'] = 'Producto eliminado';
                $cantidad = 0;
            } else {
                $_SESSION['carrito'][$producto_id] = [
                    'id' => $producto_id,
                    'cantidad' => $nueva_cantidad,
                    'fecha_agregado' => $_SESSION['carrito'][$producto_id]['fecha_agregado'] ?? date('Y-m-d H:i:s')
                ];
                $response['message'] = 'Cantidad decrementada';
                $cantidad = $nueva_cantidad;
            }
            break;
            
        case 'eliminar':
            unset($_SESSION['carrito'][$producto_id]);
            $response['message'] = 'Producto eliminado';
            $cantidad = 0;
            break;
            
        default:
            $response['message'] = 'Acción no válida';
            echo json_encode($response);
            exit();
    }
    
    // Recalcular totales del carrito
    $subtotal = 0;
    $total_items = 0;
    
    if (!empty($_SESSION['carrito'])) {
        $ids = array_keys($_SESSION['carrito']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $conn->prepare("
            SELECT id, precio_venta 
            FROM productos 
            WHERE id IN ($placeholders) AND activo = 1
        ");
        $stmt->execute($ids);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Crear array asociativo para fácil acceso
        $precios = [];
        foreach ($productos as $p) {
            $precios[$p['id']] = $p['precio_venta'];
        }
        
        foreach ($_SESSION['carrito'] as $id => $item) {
            if (isset($precios[$id])) {
                $item_cantidad = $item['cantidad'];
                $item_precio = $precios[$id];
                $subtotal += $item_precio * $item_cantidad;
                $total_items += $item_cantidad;
                
                // Si es el producto actualizado, calcular su subtotal
                if ($id == $producto_id) {
                    $response['item_subtotal'] = $item_precio * $item_cantidad;
                }
            } else {
                // Si el producto ya no existe o está inactivo, eliminarlo del carrito
                unset($_SESSION['carrito'][$id]);
            }
        }
    }
    
    // Calcular IVA y total
    require_once __DIR__.'/../config/ventas.php';
    $totales=av_cart_totals($conn);$subtotal=$totales['subtotal'];$iva=$totales['iva'];
    $total = $subtotal + $iva;
    
    // Preparar respuesta exitosa
    $response['success'] = true;
    $response['total_items'] = $total_items;
    $response['subtotal'] = number_format($subtotal, 2, '.', '');
    $response['iva'] = number_format($iva, 2, '.', '');
    $response['total'] = number_format($total, 2, '.', '');
    $response['item_cantidad'] = $cantidad;
    $response['item_id'] = $producto_id;
    $response['carrito'] = $_SESSION['carrito']; // Opcional, para depuración
    
} catch (PDOException $e) {
    $response['message'] = 'Error en la base de datos';
    error_log("Error en actualizar_carrito: " . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Error en actualizar_carrito: " . $e->getMessage());
}

echo json_encode($response);
exit();