<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado (versión compatible con ambos formatos)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Definir variables de sesión de forma consistente
$user_id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? 'usuario';
$usuario_email = $_SESSION['email'] ?? $_SESSION['usuario_email'] ?? '';

// Incluir configuración de base de datos
include 'config/database.php';

// Parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$estado = $_GET['estado'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

try {
    $db = getDBConnection();
    
    // Obtener información del usuario actual para el avatar
    $stmt = $db->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $usuario_nombre = $user_data['nombre'];
        $usuario_email = $user_data['email'];
        $usuario_rol = $user_data['rol'];
    }
    
    // Construir consulta con filtros
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(f.numero_factura LIKE :search OR c.nombre LIKE :search2 OR c.documento_identidad LIKE :search3)";
        $params[':search'] = $params[':search2'] = $params[':search3'] = "%$search%";
    }
    
    if (!empty($fecha_desde)) {
        $where_conditions[] = "DATE(f.fecha_emision) >= :fecha_desde";
        $params[':fecha_desde'] = $fecha_desde;
    }
    
    if (!empty($fecha_hasta)) {
        $where_conditions[] = "DATE(f.fecha_emision) <= :fecha_hasta";
        $params[':fecha_hasta'] = $fecha_hasta;
    }
    
    if (!empty($estado)) {
        $where_conditions[] = "f.estado = :estado";
        $params[':estado'] = $estado;
    }
    
    $where_sql = '';
    if (!empty($where_conditions)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Consulta para contar total de facturas (para paginación)
    $count_sql = "
        SELECT COUNT(DISTINCT f.id) as total
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        $where_sql
    ";
    
    $count_stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_facturas = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_facturas / $limit);
    
    // Consulta para obtener facturas con paginación
    $sql = "
        SELECT 
            f.*,
            c.nombre as cliente_nombre,
            c.documento_identidad as cliente_identificacion,
            c.telefono as cliente_telefono,
            c.direccion as cliente_direccion,
            u.nombre as usuario_nombre,
            (SELECT COUNT(*) FROM factura_detalles WHERE factura_id = f.id) as total_items,
            CASE 
                WHEN f.estado = 'paid' THEN 'pagada'
                WHEN f.estado = 'pending' THEN 'pendiente'
                WHEN f.estado = 'cancelled' THEN 'anulada'
                ELSE f.estado
            END as estado_mostrar
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        LEFT JOIN usuarios u ON f.usuario_id = u.id
        $where_sql
        ORDER BY f.fecha_emision DESC, f.numero_factura DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas de facturas (últimos 30 días)
    $stats_sql = "
        SELECT 
            COUNT(*) as total_facturas,
            COALESCE(SUM(total), 0) as ingresos_totales,
            COALESCE(AVG(total), 0) as promedio_factura,
            COUNT(CASE WHEN estado = 'paid' THEN 1 END) as facturas_pagadas,
            COUNT(CASE WHEN estado = 'pending' THEN 1 END) as facturas_pendientes,
            COUNT(CASE WHEN estado = 'cancelled' THEN 1 END) as facturas_anuladas,
            COALESCE(SUM(CASE WHEN estado = 'paid' THEN total ELSE 0 END), 0) as ingresos_pagados,
            COALESCE(SUM(CASE WHEN estado = 'pending' THEN total ELSE 0 END), 0) as ingresos_pendientes
        FROM facturas
        WHERE fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ";
    
    $stats_stmt = $db->query($stats_sql);
    $estadisticas = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Facturas recientes para el resumen
    $recientes_sql = "
        SELECT 
            f.id,
            f.numero_factura,
            f.fecha_emision,
            f.total,
            f.estado,
            c.nombre as cliente_nombre
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        ORDER BY f.fecha_emision DESC
        LIMIT 5
    ";
    
    $recientes_stmt = $db->query($recientes_sql);
    $facturas_recientes = $recientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en historial de facturas: " . $e->getMessage());
    $facturas = [];
    $facturas_recientes = [];
    $estadisticas = [
        'total_facturas' => 0,
        'ingresos_totales' => 0,
        'promedio_factura' => 0,
        'facturas_pagadas' => 0,
        'facturas_pendientes' => 0,
        'facturas_anuladas' => 0,
        'ingresos_pagados' => 0,
        'ingresos_pendientes' => 0
    ];
    $total_facturas = 0;
    $total_pages = 1;
}

// Función para obtener color del rol
function getRolColor($rol) {
    switch ($rol) {
        case 'admin':
            return '#F44336';
        case 'veterinarian':
            return '#2196F3';
        case 'reception':
            return '#FF9800';
        case 'assistant':
            return '#9C27B0';
        default:
            return '#4CAF50';
    }
}

// Función para obtener texto del rol
function getRolTexto($rol) {
    switch ($rol) {
        case 'admin':
            return 'Administrador';
        case 'veterinarian':
            return 'Veterinario';
        case 'reception':
            return 'Recepcionista';
        case 'assistant':
            return 'Asistente';
        default:
            return 'Usuario';
    }
}

// Función para formatear fecha
function formatDateToDisplay($date) {
    if (!$date) return 'N/A';
    $dateObj = new DateTime($date);
    return $dateObj->format('d/m/Y');
}

// Función para obtener clase de estado
function getStatusClass($estado) {
    switch ($estado) {
        case 'paid':
        case 'pagada':
            return 'badge-success';
        case 'pending':
        case 'pendiente':
            return 'badge-warning';
        case 'cancelled':
        case 'anulada':
            return 'badge-danger';
        default:
            return 'badge-info';
    }
}

// Función para obtener texto de estado
function getStatusText($estado) {
    switch ($estado) {
        case 'paid':
            return 'Pagada';
        case 'pending':
            return 'Pendiente';
        case 'cancelled':
            return 'Anulada';
        default:
            return ucfirst($estado);
    }
}

// Función para obtener nombre del método de pago
function getMetodoPagoText($metodo) {
    switch ($metodo) {
        case 'cash':
            return 'Efectivo';
        case 'card':
            return 'Tarjeta';
        case 'transfer':
            return 'Transferencia';
        default:
            return $metodo ?? 'N/A';
    }
}

$page_title = "Historial de Facturas - VetNexo";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #2E7D32;
            --primary-dark: #1B5E20;
            --primary-light: #4CAF50;
            --primary-soft: #E8F5E9;
            --accent: #FF9800;
            --accent-light: #FFB74D;
            --accent-soft: #FFF3E0;
            --accent-dark: #F57C00;
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --text-lighter: #888888;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --danger: #F44336;
            --warning: #FFC107;
            --success: #4CAF50;
            --info: #2196F3;
            --purple: #9C27B0;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow: 0 5px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
            --radius-sm: 8px;
            --radius: 16px;
            --radius-lg: 24px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--background);
            color: var(--text-dark);
            min-height: 100vh;
        }

        /* Layout Principal */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        /* Header */
        .dashboard-header {
            background: var(--white);
            padding: 1.5rem 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-section h1 {
            font-size: 1.8rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .welcome-section h1 i {
            color: var(--accent);
        }

        .welcome-section p {
            color: var(--text-light);
            font-size: 1rem;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 1px solid var(--primary-light);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.3rem;
            box-shadow: var(--shadow);
        }

        .user-info h3 {
            font-size: 1rem;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
        }

        .user-info span {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            background: var(--white);
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
        }

        /* Page Actions */
        .page-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            justify-content: flex-end;
        }

        .btn-primary, .btn-secondary, .btn-success {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.4);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .stat-card.success::before {
            background: linear-gradient(90deg, var(--success), #66BB6A);
        }

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--warning), var(--accent));
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--danger), #ff6b6b);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: var(--accent-soft);
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #ffebee;
            color: var(--danger);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filters-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-header h3 i {
            color: var(--accent);
        }

        .filters-toggle {
            background: var(--white);
            border: 1px solid var(--primary-light);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .filters-toggle:hover {
            background: var(--primary-soft);
        }

        .filters-content {
            padding: 1.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .filter-select, .filter-input {
            padding: 0.6rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            background: var(--white);
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        /* Grid Principal */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Tabla de Facturas */
        .table-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .section-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--accent);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .data-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 1px solid var(--gray);
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tr:hover td {
            background: var(--gray-light);
        }

        .client-cell {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .client-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        .badge-secondary {
            background: var(--gray);
            color: var(--text-light);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: transparent;
        }

        .btn-icon-primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-icon-primary:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-success {
            background: #d4edda;
            color: var(--success);
        }

        .btn-icon-success:hover {
            background: var(--success);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-warning {
            background: var(--accent-soft);
            color: var(--warning);
        }

        .btn-icon-warning:hover {
            background: var(--warning);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .btn-icon-danger:hover {
            background: var(--danger);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-info {
            background: #cce5ff;
            color: var(--info);
        }

        .btn-icon-info:hover {
            background: var(--info);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Paginación */
        .pagination {
            padding: 1.5rem;
            border-top: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            background: var(--white);
        }

        .pagination-info {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray);
            background: var(--white);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            color: var(--text-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .pagination-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .pagination-btn:hover:not(.active):not(:disabled) {
            background: var(--primary-soft);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Cards laterales */
        .info-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .info-card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .info-card-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card-body {
            padding: 1.5rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .summary-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .summary-value.success {
            color: var(--success);
        }

        .summary-value.warning {
            color: var(--warning);
        }

        .summary-value.danger {
            color: var(--danger);
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .recent-item:last-child {
            border-bottom: none;
        }

        .recent-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--primary-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .recent-info {
            flex: 1;
        }

        .recent-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .recent-subtitle {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress-bar {
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 4px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Footer */
        .main-footer {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 1.5rem 0;
            margin-top: 2rem;
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .footer-content {
            text-align: center;
        }

        .footer-content p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .dashboard-header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .pagination {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-history'></i>
                        Historial de Facturas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestión y consulta de facturas
                    </p>
                </div>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <h3><?php echo htmlspecialchars($usuario_nombre); ?></h3>
                        <span>
                            <i class='bx bx-badge-check' style="color: <?php echo getRolColor($usuario_rol); ?>"></i>
                            <?php echo getRolTexto($usuario_rol); ?>
                        </span>
                    </div>
                </div>
            </header>

            <!-- Page Actions -->
            <div class="page-actions fade-in">
                <a href="nuevafactura.php" class="btn-primary btn-sm">
                    <i class='bx bx-plus'></i>
                    Nueva Factura
                </a>
                <button class="btn-secondary btn-sm" onclick="exportToExcel()">
                    <i class='bx bx-download'></i>
                    Exportar Excel
                </button>
                <button class="btn-success btn-sm" onclick="printReport()">
                    <i class='bx bx-printer'></i>
                    Imprimir Reporte
                </button>
            </div>

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-receipt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_facturas']); ?></div>
                        <div class="stat-label">Facturas (30 días)</div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-money'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ingresos_totales'], 2, ',', '.'); ?></div>
                        <div class="stat-label">Ingresos Totales</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['promedio_factura'], 2, ',', '.'); ?></div>
                        <div class="stat-label">Promedio por Factura</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['facturas_pendientes']; ?></div>
                        <div class="stat-label">Pendientes</div>
                    </div>
                </div>
            </div>

            <!-- Filtros de Búsqueda -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h3>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Búsqueda
                    </h3>
                    <button class="filters-toggle" onclick="toggleFilters()">
                        <i class='bx bx-filter-alt'></i>
                        Mostrar/Ocultar Filtros
                    </button>
                </div>
                <div class="filters-content" id="filtersContent">
                    <form method="GET" action="historialfacturas.php">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label class="filter-label">Buscar</label>
                                <input type="text" class="filter-input" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="N° factura, cliente, identificación...">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Fecha Desde</label>
                                <input type="date" class="filter-input" name="fecha_desde" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Fecha Hasta</label>
                                <input type="date" class="filter-input" name="fecha_hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Estado</label>
                                <select class="filter-select" name="estado">
                                    <option value="">Todos los estados</option>
                                    <option value="paid" <?php echo $estado === 'paid' ? 'selected' : ''; ?>>Pagada</option>
                                    <option value="pending" <?php echo $estado === 'pending' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="cancelled" <?php echo $estado === 'cancelled' ? 'selected' : ''; ?>>Anulada</option>
                                </select>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-primary btn-sm">
                                <i class='bx bx-search'></i>
                                Buscar
                            </button>
                            <a href="historialfacturas.php" class="btn-secondary btn-sm">
                                <i class='bx bx-reset'></i>
                                Limpiar Filtros
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Grid Principal -->
            <div class="dashboard-grid">
                <!-- Tabla de Facturas -->
                <div class="table-section fade-in">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class='bx bx-list-ul'></i>
                            Lista de Facturas
                        </h3>
                        <div class="pagination-info">
                            Mostrando <?php echo count($facturas); ?> de <?php echo $total_facturas; ?> facturas
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>N° Factura</th>
                                    <th>Fecha</th>
                                    <th>Cliente</th>
                                    <th>Identificación</th>
                                    <th>Items</th>
                                    <th>Subtotal</th>
                                    <th>IVA</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($facturas)): ?>
                                    <?php foreach ($facturas as $factura): 
                                        $iva_calculado = $factura['impuestos'] ?? ($factura['total'] - $factura['subtotal']);
                                    ?>
                                    <tr class="fade-in">
                                        <td>
                                            <strong><?php echo htmlspecialchars($factura['numero_factura']); ?></strong>
                                        </td>
                                        <td><?php echo formatDateToDisplay($factura['fecha_emision']); ?></td>
                                        <td class="client-cell">
                                            <div class="client-avatar">
                                                <?php echo strtoupper(substr($factura['cliente_nombre'] ?? 'C', 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'N/A'); ?></div>
                                                <small style="color: var(--text-light);"><?php echo htmlspecialchars($factura['cliente_telefono'] ?? ''); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($factura['cliente_identificacion'] ?? 'N/A'); ?></td>
                                        <td><?php echo $factura['total_items'] ?? 0; ?></td>
                                        <td>$<?php echo number_format($factura['subtotal'] ?? 0, 2, ',', '.'); ?></td>
                                        <td>$<?php echo number_format($iva_calculado, 2, ',', '.'); ?></td>
                                        <td>
                                            <strong>$<?php echo number_format($factura['total'] ?? 0, 2, ',', '.'); ?></strong>
                                            <br>
                                            <small style="color: var(--text-light);"><?php echo getMetodoPagoText($factura['metodo_pago'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo getStatusClass($factura['estado']); ?>">
                                                <?php echo getStatusText($factura['estado']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-primary" onclick="viewInvoice(<?php echo $factura['id']; ?>)" title="Ver Factura">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-success" onclick="printInvoice(<?php echo $factura['id']; ?>)" title="Imprimir">
                                                    <i class='bx bx-printer'></i>
                                                </button>
                                                <?php if (($factura['estado'] ?? '') === 'pending'): ?>
                                                    <button class="btn-icon btn-icon-warning" onclick="markAsPaid(<?php echo $factura['id']; ?>)" title="Marcar como Pagada">
                                                        <i class='bx bx-check'></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (($factura['estado'] ?? '') !== 'cancelled'): ?>
                                                    <button class="btn-icon btn-icon-danger" onclick="cancelInvoice(<?php echo $factura['id']; ?>)" title="Anular Factura">
                                                        <i class='bx bx-x'></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <i class='bx bx-receipt'></i>
                                                <h3>No se encontraron facturas</h3>
                                                <p>No hay facturas que coincidan con los criterios de búsqueda</p>
                                                <a href="nuevafactura.php" class="btn-primary btn-sm" style="margin-top: 1rem; display: inline-flex;">
                                                    <i class='bx bx-plus'></i>
                                                    Crear Primera Factura
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginación -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Mostrando <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $total_facturas); ?> de <?php echo $total_facturas; ?> facturas
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">
                                    <i class='bx bx-chevron-left'></i>
                                </a>
                            <?php else: ?>
                                <button class="pagination-btn" disabled>
                                    <i class='bx bx-chevron-left'></i>
                                </button>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $total_pages); $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">
                                    <i class='bx bx-chevron-right'></i>
                                </a>
                            <?php else: ?>
                                <button class="pagination-btn" disabled>
                                    <i class='bx bx-chevron-right'></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar Derecho -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <!-- Resumen Financiero -->
                    <div class="info-card fade-in">
                        <div class="info-card-header">
                            <h3>
                                <i class='bx bx-pie-chart-alt-2'></i>
                                Resumen Financiero
                            </h3>
                        </div>
                        <div class="info-card-body">
                            <div class="summary-item">
                                <span class="summary-label">Ingresos Pagados:</span>
                                <span class="summary-value success">$<?php echo number_format($estadisticas['ingresos_pagados'], 2, ',', '.'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Ingresos Pendientes:</span>
                                <span class="summary-value warning">$<?php echo number_format($estadisticas['ingresos_pendientes'], 2, ',', '.'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Facturas Pagadas:</span>
                                <span class="summary-value success"><?php echo $estadisticas['facturas_pagadas']; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Facturas Pendientes:</span>
                                <span class="summary-value warning"><?php echo $estadisticas['facturas_pendientes']; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Facturas Anuladas:</span>
                                <span class="summary-value danger"><?php echo $estadisticas['facturas_anuladas']; ?></span>
                            </div>
                            
                            <div class="progress-bar" style="margin: 1rem 0 0.5rem;">
                                <?php 
                                $total_no_anuladas = $estadisticas['facturas_pagadas'] + $estadisticas['facturas_pendientes'];
                                $porcentaje_cobrado = $total_no_anuladas > 0 ? ($estadisticas['facturas_pagadas'] / $total_no_anuladas) * 100 : 0;
                                ?>
                                <div class="progress-fill" style="width: <?php echo $porcentaje_cobrado; ?>%;"></div>
                            </div>
                            <div style="text-align: center; font-size: 0.8rem; color: var(--text-light);">
                                <?php echo number_format($porcentaje_cobrado, 1); ?>% de facturas cobradas
                            </div>
                        </div>
                    </div>

                    <!-- Facturas Recientes -->
                    <div class="info-card fade-in">
                        <div class="info-card-header">
                            <h3>
                                <i class='bx bx-time'></i>
                                Facturas Recientes
                            </h3>
                        </div>
                        <div class="info-card-body">
                            <?php if (!empty($facturas_recientes)): ?>
                                <?php foreach ($facturas_recientes as $reciente): ?>
                                <div class="recent-item">
                                    <div class="recent-icon">
                                        <i class='bx bx-receipt'></i>
                                    </div>
                                    <div class="recent-info">
                                        <div class="recent-title">
                                            <?php echo htmlspecialchars($reciente['numero_factura']); ?>
                                        </div>
                                        <div class="recent-subtitle">
                                            <span><?php echo htmlspecialchars($reciente['cliente_nombre'] ?? 'N/A'); ?></span>
                                            <span class="badge <?php echo getStatusClass($reciente['estado']); ?>" style="font-size: 0.7rem;">
                                                <?php echo getStatusText($reciente['estado']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div style="font-weight: 600; color: var(--primary);">
                                        $<?php echo number_format($reciente['total'], 2, ',', '.'); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 1rem; color: var(--text-light);">
                                    <i class='bx bx-data' style="font-size: 2rem;"></i>
                                    <p>No hay facturas recientes</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Acciones Rápidas -->
                    <div class="info-card fade-in">
                        <div class="info-card-header">
                            <h3>
                                <i class='bx bx-zap'></i>
                                Acciones Rápidas
                            </h3>
                        </div>
                        <div class="info-card-body" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <button class="btn-primary btn-sm" onclick="window.location.href='nuevafactura.php'" style="width: 100%;">
                                <i class='bx bx-plus'></i>
                                Nueva Factura
                            </button>
                            <button class="btn-secondary btn-sm" onclick="window.location.href='facturaspendientes.php'" style="width: 100%;">
                                <i class='bx bx-time'></i>
                                Ver Pendientes
                            </button>
                            <button class="btn-success btn-sm" onclick="exportToExcel()" style="width: 100%;">
                                <i class='bx bx-download'></i>
                                Exportar Todo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-content">
                    <p>© <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
                </div>
            </footer>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Variables globales
        let filtersVisible = true;

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Validación de fechas
            const fechaDesde = document.querySelector('input[name="fecha_desde"]');
            const fechaHasta = document.querySelector('input[name="fecha_hasta"]');
            const today = new Date().toISOString().split('T')[0];
            
            if (fechaDesde) fechaDesde.max = today;
            if (fechaHasta) fechaHasta.max = today;
            
            if (fechaDesde && fechaHasta) {
                fechaDesde.addEventListener('change', function() {
                    if (fechaHasta.value && this.value > fechaHasta.value) {
                        fechaHasta.value = this.value;
                        showNotification('Se ajustó la fecha "hasta"', 'info');
                    }
                });
                
                fechaHasta.addEventListener('change', function() {
                    if (fechaDesde.value && this.value < fechaDesde.value) {
                        fechaDesde.value = this.value;
                        showNotification('Se ajustó la fecha "desde"', 'info');
                    }
                });
            }

            // Animación de contadores
            animateStats();
            
            // Animación de filas de tabla
            animateTableRows();
        });

        // Toggle filtros
        function toggleFilters() {
            const filtersContent = document.getElementById('filtersContent');
            filtersVisible = !filtersVisible;
            filtersContent.style.display = filtersVisible ? 'block' : 'none';
        }

        // Animar estadísticas
        function animateStats() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.-]+/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        if (text.includes('$')) {
                            stat.textContent = '$' + current.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        } else {
                            stat.textContent = Math.floor(current).toLocaleString('es-ES');
                        }
                    }, 30);
                }
            });
        }

        // Animar filas de tabla
        function animateTableRows() {
            const rows = document.querySelectorAll('.data-table tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(10px)';
                
                setTimeout(() => {
                    row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50 + 100);
            });
        }

        // Sistema de notificaciones
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: var(--radius-sm);
                background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#F44336' : type === 'warning' ? '#FFC107' : '#2196F3'};
                color: white;
                font-weight: 600;
                z-index: 10000;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 400px;
                box-shadow: var(--shadow-lg);
            `;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Funciones de acciones
        function viewInvoice(invoiceId) {
            window.location.href = `verfactura.php?id=${invoiceId}`;
        }

        function printInvoice(invoiceId) {
            window.open(`imprimirfactura.php?id=${invoiceId}`, '_blank');
        }

        function markAsPaid(invoiceId) {
            if (confirm('¿Está seguro de que desea marcar esta factura como pagada?')) {
                showNotification(`Factura marcada como pagada`, 'success');
                // En producción, aquí iría una llamada AJAX para actualizar el estado
                // fetch(`api/facturas/${invoiceId}/pagar`, { method: 'POST' })
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
        }

        function cancelInvoice(invoiceId) {
            if (confirm('¿Está seguro de que desea anular esta factura? Esta acción no se puede deshacer.')) {
                showNotification(`Factura anulada`, 'warning');
                // En producción, aquí iría una llamada AJAX
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
        }

        function exportToExcel() {
            showNotification('Exportando datos a Excel...', 'info');
            // En producción: window.location.href = 'exportar_facturas.php?' + new URLSearchParams(window.location.search);
        }

        function printReport() {
            showNotification('Generando reporte para impresión...', 'info');
            window.print();
        }

        // Manejar tecla Enter en búsqueda
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && e.target.closest('input[name="search"]')) {
                e.preventDefault();
                e.target.closest('form').submit();
            }
        });
    </script>
</body>
</html>