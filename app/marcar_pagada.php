<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

header('Content-Type: application/json; charset=UTF-8');
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
$rol = $_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? '';
if (!in_array($rol, ['admin', 'veterinarian', 'assistant', 'reception'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tiene permisos para registrar pagos de facturas']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$factura_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$montoRaw = str_replace(',', '.', trim((string)($_POST['amount'] ?? '')));
$metodo = trim((string)($_POST['method'] ?? 'cash'));
$fecha = trim((string)($_POST['date'] ?? date('Y-m-d')));
$notas = trim((string)($_POST['notes'] ?? ''));

$mapMetodo = [
    'cash' => 'cash', 'efectivo' => 'cash',
    'card' => 'card', 'tarjeta' => 'card',
    'transfer' => 'transfer', 'transferencia' => 'transfer', 'pago_movil' => 'transfer'
];
$metodoFactura = $mapMetodo[$metodo] ?? 'cash';

if (!$factura_id || !isset($mapMetodo[$metodo]) || !av_valid_date($fecha) || $fecha>date('Y-m-d') || !is_numeric($montoRaw) || !is_finite((float)$montoRaw) || (float)$montoRaw <= 0) {
    echo json_encode(['success' => false, 'message' => 'Factura o monto inválido']);
    exit;
}

try {
    $db = getDBConnection();
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT id, total, COALESCE(pagado, 0) pagado, estado FROM facturas WHERE id = ? FOR UPDATE");
    $stmt->execute([$factura_id]);
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$factura) throw new RuntimeException('Factura no encontrada');
    if ($factura['estado'] === 'cancelled') throw new RuntimeException('No se puede pagar una factura cancelada');
    if ($factura['estado'] === 'paid') throw new RuntimeException('La factura ya está pagada');

    $saldo = round((float)$factura['total'] - (float)$factura['pagado'], 2);
    $monto = round((float)$montoRaw, 2);
    if ($monto > $saldo + 0.001) throw new RuntimeException('El monto supera el saldo pendiente');

    $nuevoPagado = round((float)$factura['pagado'] + $monto, 2);
    $nuevoEstado = $nuevoPagado + 0.001 >= (float)$factura['total'] ? 'paid' : 'partial';
    $fechaPago = $nuevoEstado === 'paid' ? ($fecha ?: date('Y-m-d')) . ' ' . date('H:i:s') : null;

    $upd = $db->prepare("UPDATE facturas SET pagado = ?, estado = ?, metodo_pago = ?, fecha_pago = ? WHERE id = ?");
    $upd->execute([$nuevoPagado, $nuevoEstado, $metodoFactura, $fechaPago, $factura_id]);

    $usuario_id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'];
    $ins = $db->prepare("INSERT INTO pagos_facturas (factura_id, monto, metodo_pago, fecha_pago, notas, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute([$factura_id, $monto, $metodoFactura, $fecha ?: date('Y-m-d'), $notas ?: null, $usuario_id]);

    $db->commit();
    echo json_encode([
        'success' => true,
        'message' => $nuevoEstado === 'paid' ? 'Factura pagada completamente.' : 'Pago parcial registrado correctamente.',
        'estado' => $nuevoEstado,
        'pagado' => $nuevoPagado,
        'saldo' => max(0, round((float)$factura['total'] - $nuevoPagado, 2))
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('[VetNexo][pago_factura] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Error al registrar el pago']);
}
