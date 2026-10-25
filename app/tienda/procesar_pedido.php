<?php
// tienda/procesar_pedido.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=tienda/checkout.php');
    exit();
}

// Verificar token CSRF para seguridad
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['checkout_error'] = 'Error de seguridad. Por favor, intenta de nuevo.';
        header('Location: checkout.php');
        exit();
    }
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_id = $_SESSION['user_id'];

// Si no hay cliente_id en sesión, intentar obtenerlo
if (!$cliente_id) {
    $stmt = $conn->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $cliente_id = $usuario['cliente_id'] ?? null;
    $_SESSION['cliente_id'] = $cliente_id;
}

// Si no hay cliente_id, redirigir a completar perfil
if (!$cliente_id) {
    header('Location: ../cliente/perfil.php?error=completar_datos');
    exit();
}

// Verificar carrito
if (empty($_SESSION['carrito'])) {
    header('Location: carrito.php?error=carrito_vacio');
    exit();
}

// Procesar el formulario POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit();
}

$error = '';
$success = '';

// Obtener y validar datos del formulario
$metodo_pago = $_POST['metodo_pago'] ?? '';
$direccion_envio = trim($_POST['direccion_envio'] ?? '');
$telefono_contacto = trim($_POST['telefono_contacto'] ?? '');
$notas = trim($_POST['notas'] ?? '');
$acepto_terminos = isset($_POST['acepto_terminos']) ? true : false;

// Validaciones básicas
if (!in_array($metodo_pago,['efectivo','transferencia','pago_movil'],true)) {
    $error = "Por favor selecciona un método de pago";
} elseif (empty($direccion_envio)) {
    $error = "Por favor ingresa una dirección para retirar el pedido";
} elseif (empty($telefono_contacto)) {
    $error = "Por favor ingresa un teléfono de contacto";
} elseif (!$acepto_terminos) {
    $error = "Debes aceptar los términos y condiciones";
}

// Validar campos específicos según método de pago
if (empty($error)) {
    if ($metodo_pago === 'transferencia') {
        $referencia_pago = trim($_POST['referencia_pago'] ?? '');
        if (empty($referencia_pago)) {
            $error = "Por favor ingresa el número de referencia de la transferencia";
        }
    } elseif ($metodo_pago === 'pago_movil') {
        $referencia_pago_movil = trim($_POST['referencia_pago_movil'] ?? '');
        if (empty($referencia_pago_movil)) {
            $error = "Por favor ingresa el número de confirmación del pago móvil";
        }
    }
}

if (!empty($error)) {
    $_SESSION['checkout_error'] = $error;
    $_SESSION['checkout_data'] = $_POST;
    header('Location: checkout.php');
    exit();
}

try {
    // Iniciar transacción
    $conn->beginTransaction();

    // Obtener productos del carrito y verificar stock
    $productos_carrito = [];
    $subtotal = 0; $iva = 0;
    $total_items = 0;
    $productos_sin_stock = [];

    foreach ($_SESSION['carrito'] as $producto_id => $item) {
        // Obtener información actualizada del producto con bloqueo
        $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ? AND activo = 1 FOR UPDATE");
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new Exception("Producto no encontrado: ID $producto_id");
        }

        $cantidad_solicitada = filter_var($item['cantidad'],FILTER_VALIDATE_INT);
        if(!$cantidad_solicitada || $cantidad_solicitada<1 || $cantidad_solicitada>99)throw new RuntimeException('Cantidad no válida.');

        // Verificar stock
        if ($producto['stock_actual'] < $cantidad_solicitada) {
            $productos_sin_stock[] = "{$producto['nombre']} (stock disponible: {$producto['stock_actual']}, solicitado: {$cantidad_solicitada})";
            continue;
        }

        $precio = $producto['precio_venta'];
        $importe = round($precio * $cantidad_solicitada,2);
        $iva_item=$producto['aplica_iva']?round($importe*$producto['porcentaje_iva']/100,2):0; $iva+=$iva_item;

        $productos_carrito[] = [
            'id' => $producto_id,
            'nombre' => $producto['nombre'],
            'codigo' => $producto['codigo'],
            'precio' => $precio,
            'cantidad' => $cantidad_solicitada,
            'importe' => $importe, 'aplica_iva'=>$producto['aplica_iva'], 'porcentaje_iva'=>$producto['porcentaje_iva'], 'iva_item'=>$iva_item,
            'stock_actual' => $producto['stock_actual']
        ];

        $subtotal += $importe;
        $total_items += $cantidad_solicitada;
    }

    // Si hay productos sin stock, mostrar error
    if (!empty($productos_sin_stock)) {
        $conn->rollBack();
        $_SESSION['checkout_error'] = "Los siguientes productos no tienen stock suficiente:<br>" . implode('<br>', $productos_sin_stock);
        $_SESSION['checkout_data'] = $_POST;
        header('Location: checkout.php');
        exit();
    }

    // Calcular impuestos y total
    $iva = round($iva,2);
    $total = $subtotal + $iva;

    // Obtener datos del cliente
    $stmt = $conn->prepare("SELECT nombre, email FROM clientes WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        throw new Exception("Cliente no encontrado");
    }

    $conn->exec("INSERT IGNORE INTO secuencias_app(nombre,valor) VALUES('pedido',0)");
    $conn->query("SELECT valor FROM secuencias_app WHERE nombre='pedido' FOR UPDATE")->fetchColumn();
    // Generar número de pedido único
    $fecha_actual = date('Ymd');
    $prefijo = 'AH'; // VetNexo
    
    // Buscar el último número para hoy
    $stmt = $conn->prepare("
        SELECT numero_pedido FROM pedidos 
        WHERE numero_pedido LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["{$prefijo}-{$fecha_actual}-%"]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo) {
        $partes = explode('-', $ultimo['numero_pedido']);
        $ultimo_numero = intval(end($partes));
        $nuevo_numero = str_pad($ultimo_numero + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $nuevo_numero = '0001';
    }
    
    $numero_pedido = "{$prefijo}-{$fecha_actual}-{$nuevo_numero}";

    // Construir información de pago para guardar en JSON
    $info_pago = [
        'metodo' => $metodo_pago,
        'fecha_registro' => date('Y-m-d H:i:s'),
        'monto' => $total,
        'subtotal' => $subtotal,
        'iva' => $iva
    ];

    // Agregar campos específicos según método de pago
    if ($metodo_pago === 'transferencia') {
        $info_pago['banco_origen'] = $_POST['banco_origen'] ?? '';
        $info_pago['referencia'] = $_POST['referencia_pago'] ?? '';
        $info_pago['fecha_transferencia'] = $_POST['fecha_transferencia'] ?? date('Y-m-d');
        $info_pago['monto_transferido'] = $_POST['monto_transferido'] ?? $total;
        $estado_pedido = 'pendiente_pago';
    } elseif ($metodo_pago === 'pago_movil') {
        $info_pago['telefono'] = $_POST['telefono_pago'] ?? '';
        $info_pago['banco'] = $_POST['banco_pago'] ?? '';
        $info_pago['referencia'] = $_POST['referencia_pago_movil'] ?? '';
        $estado_pedido = 'pendiente_pago';
    } else { // efectivo
        $estado_pedido = 'pendiente_pago';
        $info_pago['notas'] = 'Pago en efectivo al retirar';
    }

    $info_pago['notas_cliente'] = $notas;

    // Insertar pedido - AHORA CON LOS CAMPOS CORRECTOS
    $stmt = $conn->prepare("
        INSERT INTO pedidos (
            numero_pedido, 
            cliente_id, 
            fecha_pedido, 
            subtotal, 
            impuestos, 
            total, 
            estado, 
            metodo_pago, 
            direccion_envio, 
            telefono_contacto, 
            info_pago, 
            notas,
            fecha_actualizacion
        ) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $numero_pedido, 
        $cliente_id, 
        $subtotal, 
        $iva, 
        $total,
        $estado_pedido, 
        $metodo_pago, 
        $direccion_envio, 
        $telefono_contacto,
        json_encode($info_pago, JSON_UNESCAPED_UNICODE),
        $notas
    ]);
    
    $pedido_id = $conn->lastInsertId();
    $conn->prepare('UPDATE pedidos SET stock_reservado=1 WHERE id=?')->execute([$pedido_id]);

    // Insertar detalles del pedido
    $stmt_detalle = $conn->prepare("
        INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario, subtotal,aplica_iva,porcentaje_iva,iva_item)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Si el pago es en efectivo, reservar stock (reducir)
    $reservar_stock = true;

    foreach ($productos_carrito as $item) {
        // Insertar detalle
        $stmt_detalle->execute([
            $pedido_id, 
            $item['id'], 
            $item['cantidad'], 
            $item['precio'], 
            $item['importe'], $item['aplica_iva'], $item['porcentaje_iva'], $item['iva_item']
        ]);
        
        // Si es efectivo, reducir stock inmediatamente
        if ($reservar_stock) {
            // Actualizar stock
            $stmt_update_stock = $conn->prepare("
                UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?
            ");
            $stmt_update_stock->execute([$item['cantidad'], $item['id']]);
            
            // Registrar movimiento de inventario
            $stmt_movimiento = $conn->prepare("
                INSERT INTO movimientos_inventario (
                    producto_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, 
                    motivo, usuario_id, fecha_movimiento
                ) VALUES (?, 'salida', ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt_movimiento->execute([
                $item['id'],
                $item['cantidad'],
                $item['stock_actual'],
                $item['stock_actual'] - $item['cantidad'],
                "Reserva por pedido #{$numero_pedido}",
                $usuario_id
            ]);
        }
    }

    // Si el método es transferencia o pago móvil, crear registro de pago pendiente
    if ($metodo_pago === 'transferencia' || $metodo_pago === 'pago_movil') {
        $stmt_pago = $conn->prepare("
            INSERT INTO pagos (
                pedido_id, cliente_id, metodo_pago, monto, referencia, 
                banco_origen, telefono_origen, fecha_pago, notas, estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
        ");
        
        $banco_origen = $_POST['banco_origen'] ?? ($_POST['banco_pago'] ?? '');
        $telefono_origen = $_POST['telefono_pago'] ?? '';
        $referencia = $_POST['referencia_pago'] ?? $_POST['referencia_pago_movil'] ?? '';
        $fecha_pago = $_POST['fecha_transferencia'] ?? date('Y-m-d');
        $notas_pago = "Registro automático desde checkout. Cliente debe subir comprobante.";
        
        $stmt_pago->execute([
            $pedido_id, 
            $cliente_id, 
            $metodo_pago, 
            $total, 
            $referencia,
            $banco_origen,
            $telefono_origen,
            $fecha_pago,
            $notas_pago
        ]);
        
        $pago_id = $conn->lastInsertId();
        
        // Actualizar pedido con el pago_id
        $stmt_update = $conn->prepare("UPDATE pedidos SET pago_id = ? WHERE id = ?");
        $stmt_update->execute([$pago_id, $pedido_id]);
    }

    // Crear notificación para el cliente
    $mensaje_notificacion = "¡Tu pedido #{$numero_pedido} ha sido registrado!\n";
    $mensaje_notificacion .= "Total: $" . number_format($total, 2) . "\n";
    
    if ($metodo_pago === 'efectivo') {
        $mensaje_notificacion .= "Pagarás en efectivo al retirar en tienda.\n";
        $mensaje_notificacion .= "Tienes 24 horas para retirar tus productos.";
        $titulo_notif = "💰 Pedido #{$numero_pedido} - Pendiente de retiro";
    } elseif ($metodo_pago === 'transferencia' || $metodo_pago === 'pago_movil') {
        $mensaje_notificacion .= "Por favor, sube el comprobante de pago en 'Mis Pedidos'.\n";
        $mensaje_notificacion .= "Tu pago será verificado en máximo 24 horas.";
        $titulo_notif = "📦 Pedido #{$numero_pedido} - Pendiente de pago";
    }
    
    $stmt_notif = $conn->prepare("
        INSERT INTO notificaciones_cliente (cliente_id, tipo, titulo, mensaje, fecha_creacion)
        VALUES (?, 'pedido_estado', ?, ?, NOW())
    ");
    
    $stmt_notif->execute([
        $cliente_id,
        $titulo_notif,
        $mensaje_notificacion
    ]);

    // Registrar en logs de verificación (si aplica)
    if ($metodo_pago !== 'efectivo') {
        $stmt_log = $conn->prepare("
            INSERT INTO logs_verificacion_pagos (pedido_id, usuario_id, accion, observaciones)
            VALUES (?, ?, 'pedido_creado', ?)
        ");
        $stmt_log->execute([
            $pedido_id,
            $usuario_id,
            "Pedido creado con método {$metodo_pago}. Pendiente de pago y verificación."
        ]);
    }

    // Commit de la transacción
    $conn->commit();

    // Limpiar carrito de la sesión
    unset($_SESSION['carrito']);

    // Guardar datos del pedido en sesión para la página de confirmación
    $_SESSION['ultimo_pedido'] = [
        'id' => $pedido_id,
        'numero' => $numero_pedido,
        'total' => $total,
        'fecha' => date('Y-m-d H:i:s'),
        'metodo_pago' => $metodo_pago,
        'instrucciones' => $metodo_pago === 'efectivo' 
            ? 'Presenta este comprobante al retirar en tienda' 
            : 'Sube tu comprobante de pago en "Mis Pedidos" para verificar tu pago'
    ];

    // Redirigir a página de confirmación
    header("Location: confirmacion.php?pedido_id={$pedido_id}&numero=" . urlencode($numero_pedido));
    exit();

} catch (PDOException $e) {
    // Rollback en caso de error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Registrar error
    error_log("Error en procesar_pedido (PDO): " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    $_SESSION['checkout_error'] = "Error en la base de datos: " . $e->getMessage();
    $_SESSION['checkout_data'] = $_POST;
    header('Location: checkout.php');
    exit();
    
} catch (Exception $e) {
    // Rollback en caso de error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Registrar error
    error_log("Error en procesar_pedido: " . $e->getMessage());
    
    $_SESSION['checkout_error'] = $e->getMessage();
    $_SESSION['checkout_data'] = $_POST;
    header('Location: checkout.php');
    exit();
}
?>