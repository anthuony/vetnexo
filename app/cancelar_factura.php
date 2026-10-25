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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}
$factura_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$reason = trim((string)($_POST['reason'] ?? ''));
if (!$factura_id || $reason === '') {
    echo json_encode(['success' => false, 'message' => 'Debe indicar una factura y un motivo de cancelación']);
    exit;
}
try {
    $db = getDBConnection();
    require_once __DIR__.'/config/ventas.php';
    $db->beginTransaction();$q=$db->prepare('SELECT * FROM facturas WHERE id=? FOR UPDATE');$q->execute([$factura_id]);$f=$q->fetch();
    if(!$f)throw new RuntimeException('Factura no encontrada.');
    if($f['estado']!=='pending'||(float)$f['pagado']>0)throw new RuntimeException('Solo se pueden cancelar facturas pendientes sin pagos.');
    if($f['inventario_descontado'])av_restore_invoice_stock($db,(int)$factura_id,auth_user_id());
    $db->prepare("UPDATE facturas SET estado='cancelled',inventario_descontado=0,motivo_cancelacion=?,fecha_cancelacion=NOW() WHERE id=?")->execute([$reason,$factura_id]);$db->commit();
    echo json_encode(['success' => true, 'message' => 'Factura cancelada correctamente']);
} catch (Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    error_log('[VetNexo][cancelar_factura] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Error al cancelar la factura']);
}
