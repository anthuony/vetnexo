<?php
// api/eliminar_carrito.php - Endpoint JSON real para eliminar productos del carrito.
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';

$response = [
    'success' => false,
    'message' => '',
    'total_items' => 0,
    'subtotal' => '0.00',
    'iva' => '0.00',
    'total' => '0.00',
    'carrito_vacio' => true,
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Método no permitido';
    echo json_encode($response);
    exit;
}

if (!isset($_SESSION['carrito']) || !is_array($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

$accion = $_POST['accion'] ?? 'eliminar';
$producto_id = isset($_POST['producto_id']) ? (int)$_POST['producto_id'] : 0;

try {
    switch ($accion) {
        case 'eliminar':
            if ($producto_id <= 0) {
                throw new RuntimeException('Producto inválido');
            }
            unset($_SESSION['carrito'][$producto_id]);
            $response['message'] = 'Producto eliminado del carrito';
            break;

        case 'eliminar_multiple':
            $productos = json_decode($_POST['productos'] ?? '[]', true);
            if (!is_array($productos)) {
                throw new RuntimeException('Lista de productos inválida');
            }
            foreach ($productos as $id) {
                unset($_SESSION['carrito'][(int)$id]);
            }
            $response['message'] = 'Productos eliminados del carrito';
            break;

        case 'vaciar':
            $_SESSION['carrito'] = [];
            $response['message'] = 'Carrito vaciado correctamente';
            break;

        default:
            throw new RuntimeException('Acción no válida');
    }

    // Recalcular totales después de eliminar.
    $subtotal = 0.0;
    $total_items = 0;

    if (!empty($_SESSION['carrito'])) {
        $ids = array_map('intval', array_keys($_SESSION['carrito']));
        $ids = array_filter($ids, fn($id) => $id > 0);

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT id, precio_venta FROM productos WHERE id IN ($placeholders) AND activo = 1");
            $stmt->execute($ids);
            $productos = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($_SESSION['carrito'] as $id => $item) {
                $id = (int)$id;
                if (!isset($productos[$id])) {
                    unset($_SESSION['carrito'][$id]);
                    continue;
                }
                $cantidad = max(1, (int)($item['cantidad'] ?? 1));
                $_SESSION['carrito'][$id]['cantidad'] = $cantidad;
                $subtotal += ((float)$productos[$id]) * $cantidad;
                $total_items += $cantidad;
            }
        }
    }

    $iva = $subtotal * 0.16;
    $total = $subtotal + $iva;

    $response['success'] = true;
    $response['total_items'] = $total_items;
    $response['subtotal'] = number_format($subtotal, 2, '.', '');
    $response['iva'] = number_format($iva, 2, '.', '');
    $response['total'] = number_format($total, 2, '.', '');
    $response['carrito_vacio'] = empty($_SESSION['carrito']);
} catch (Throwable $e) {
    http_response_code(400);
    $response['message'] = $e->getMessage();
    error_log('api/eliminar_carrito.php: ' . $e->getMessage());
}

echo json_encode($response);
exit;
