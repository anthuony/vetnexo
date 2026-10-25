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

$cita_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$cita_id) {
    $_SESSION['mensaje_error'] = 'Cita inválida.';
    header('Location: mis_citas.php');
    exit;
}
try {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT id, estado, fecha_cita FROM citas WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$cita_id, (int)$_SESSION['cliente_id']]);
    $cita = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cita) throw new RuntimeException('La cita no existe o no pertenece a su cuenta.');
    if (!in_array($cita['estado'], ['scheduled','confirmed'], true)) {
        throw new RuntimeException('Esta cita ya no puede cancelarse.');
    }
    $upd = $db->prepare("UPDATE citas SET estado = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND cliente_id = ?");
    $upd->execute([$cita_id, (int)$_SESSION['cliente_id']]);
    $_SESSION['mensaje_success'] = 'Cita cancelada correctamente.';
} catch (Throwable $e) {
    $_SESSION['mensaje_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo cancelar la cita.';
    error_log('[VetNexo][cliente_cancelar_cita] ' . $e->getMessage());
}
header('Location: mis_citas.php');
exit;
