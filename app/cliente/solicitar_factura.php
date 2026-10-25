<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

if (empty($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'cliente' || empty($_SESSION['cliente_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    $_SESSION['mensaje_error'] = 'La solicitud expiró o no es válida. Inténtelo nuevamente.';
    header('Location: mis_citas.php');
    exit;
}

$db = getDBConnection();
$clienteId = (int) $_SESSION['cliente_id'];
$citaId = filter_input(INPUT_POST, 'cita_id', FILTER_VALIDATE_INT);
if (!$citaId) {
    header('Location: mis_citas.php');
    exit;
}

$stmt = $db->prepare("SELECT c.*, s.nombre AS servicio_nombre, s.precio AS servicio_precio
                      FROM citas c
                      JOIN servicios s ON s.id = c.servicio_id
                      WHERE c.id = ? AND c.cliente_id = ?");
$stmt->execute([$citaId, $clienteId]);
$cita = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cita) {
    http_response_code(404);
    exit('Cita no encontrada.');
}
if (($cita['estado'] ?? '') !== 'completed') {
    $_SESSION['mensaje_error'] = 'La factura solo puede generarse cuando la cita esté completada.';
    header('Location: ver_cita.php?id=' . $citaId);
    exit;
}

$stmt = $db->prepare('SELECT id FROM facturas WHERE cita_id = ? AND cliente_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$citaId, $clienteId]);
if ($existingId = $stmt->fetchColumn()) {
    header('Location: ver_factura.php?id=' . (int) $existingId);
    exit;
}

$total = (float) (($cita['costo_total'] ?? null) !== null ? $cita['costo_total'] : $cita['servicio_precio']);
$total = max(0, $total);
$numero = 'CITA-' . str_pad((string) $citaId, 8, '0', STR_PAD_LEFT);

try {
    $db->beginTransaction();

    // Revalidar dentro de la transacción para evitar facturas duplicadas por doble clic.
    $lock = $db->prepare('SELECT id FROM facturas WHERE cita_id = ? AND cliente_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $lock->execute([$citaId, $clienteId]);
    if ($existingId = $lock->fetchColumn()) {
        $db->rollBack();
        header('Location: ver_factura.php?id=' . (int) $existingId);
        exit;
    }

    $ins = $db->prepare("INSERT INTO facturas
        (numero_factura, cliente_id, cita_id, mascota_id, fecha_emision, fecha_vencimiento,
         subtotal, impuestos, total, pagado, estado, metodo_pago, notas, usuario_id)
        VALUES (?, ?, ?, ?, NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?, ?, 0, 'pending', NULL, ?, ?)");
    $ins->execute([
        $numero,
        $clienteId,
        $citaId,
        (int) $cita['mascota_id'],
        $total,
        0,
        $total,
        'Factura generada desde cita #' . $citaId,
        (int) $_SESSION['user_id'],
    ]);
    $facturaId = (int) $db->lastInsertId();

    $detalle = $db->prepare("INSERT INTO factura_detalles
        (factura_id, tipo_item, servicio_id, producto_id, descripcion, cantidad, precio_unitario,
         descuento, subtotal, aplica_iva, porcentaje_iva, iva_item)
        VALUES (?, 'servicio', ?, NULL, ?, 1, ?, 0, ?, 0, 16, 0)");
    $detalle->execute([
        $facturaId,
        (int) $cita['servicio_id'],
        $cita['servicio_nombre'],
        $total,
        $total,
    ]);

    $db->commit();
    header('Location: ver_factura.php?id=' . $facturaId);
    exit;
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[VetNexo][solicitar_factura] ' . $e->getMessage());

    // Si otro proceso alcanzó a crearla, reutilizar la factura existente.
    if ((string) $e->getCode() === '23000') {
        $stmt = $db->prepare('SELECT id FROM facturas WHERE cita_id = ? AND cliente_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$citaId, $clienteId]);
        if ($existingId = $stmt->fetchColumn()) {
            header('Location: ver_factura.php?id=' . (int) $existingId);
            exit;
        }
    }

    http_response_code(500);
    exit('No se pudo generar la factura.');
}
