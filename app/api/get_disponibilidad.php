<?php
// api/get_disponibilidad.php - Consulta básica de horarios ocupados para citas.
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

$response = [
    'success' => false,
    'message' => '',
    'ocupados' => [],
    'disponibles' => [],
];

try {
    $fecha = $_GET['fecha'] ?? $_POST['fecha'] ?? date('Y-m-d');
    $veterinario_id = isset($_GET['veterinario_id']) ? (int)$_GET['veterinario_id'] : (int)($_POST['veterinario_id'] ?? 0);

    if (!av_valid_date($fecha)) {
        throw new RuntimeException('Fecha inválida');
    }

    $db = getDBConnection();
    $params = [$fecha];
    $whereVet = '';
    if ($veterinario_id > 0) {
        $whereVet = ' AND (veterinario_id = ? OR veterinario_id IS NULL)';
        $params[] = $veterinario_id;
    }

    $stmt = $db->prepare("SELECT hora_cita FROM citas WHERE DATE(fecha_cita) = ? AND estado NOT IN ('cancelled','completed') $whereVet");
    $stmt->execute($params);
    $ocupados = array_map(fn($r) => substr((string)$r['hora_cita'], 0, 5), $stmt->fetchAll());

    $disponibles = [];
    $weekend=(int)date('N',strtotime($fecha))>=6;
    $inicio = strtotime($fecha . ($weekend?' 08:00':' 07:00'));
    $fin = strtotime($fecha . ($weekend?' 14:00':' 19:00'));
    for ($t = $inicio; $t <= $fin; $t += 30 * 60) {
        $hora = date('H:i', $t);
        if (!in_array($hora, $ocupados, true)) {
            $disponibles[] = $hora;
        }
    }

    $response['success'] = true;
    $response['ocupados'] = $ocupados;
    $response['disponibles'] = $disponibles;
} catch (Throwable $e) {
    http_response_code(400);
    $response['message'] = $e->getMessage();
    error_log('api/get_disponibilidad.php: ' . $e->getMessage());
}

echo json_encode($response);
exit;
