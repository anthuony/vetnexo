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

// Inicializar variables
$db = null;
$estadisticas = [];
$ingresos_mensuales = [];
$servicios_populares = [];
$facturas_pendientes = [];
$productos_populares = [];
$metodos_pago = [];

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
    
    // Obtener parámetros de filtro
    $periodo = $_GET['periodo'] ?? 'month';
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $fecha_dia = $_GET['fecha_dia'] ?? date('Y-m-d');
    
    // Calcular y validar fechas según el período
    $hoy = date('Y-m-d');
    $fechaValida = static function ($fecha) {
        $d = DateTime::createFromFormat('Y-m-d', (string)$fecha);
        return $d && $d->format('Y-m-d') === $fecha;
    };
    switch($periodo) {
        case 'day':
            if (!$fechaValida($fecha_dia)) $fecha_dia = $hoy;
            $start_date = $fecha_dia;
            $end_date = $fecha_dia;
            break;
        case 'last-month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date = date('Y-m-t', strtotime('last month'));
            break;
        case 'quarter':
            $mesActual = (int)date('n');
            $mesInicioTrimestre = ((int)floor(($mesActual - 1) / 3) * 3) + 1;
            $start_date = sprintf('%s-%02d-01', date('Y'), $mesInicioTrimestre);
            $end_date = $hoy;
            break;
        case 'year':
        case 'ytd':
            $start_date = date('Y-01-01');
            $end_date = $hoy;
            break;
        case 'custom':
            if (!$fechaValida($start_date) || !$fechaValida($end_date) || $start_date > $end_date) {
                $start_date = date('Y-m-01');
                $end_date = $hoy;
            }
            break;
        default: // month
            $periodo = 'month';
            $start_date = date('Y-m-01');
            $end_date = $hoy;
    }
    
    // ESTADÍSTICAS PRINCIPALES
    $stats_sql = "
        SELECT 
            COUNT(*) as total_facturas,
            SUM(CASE WHEN estado = 'paid' THEN total ELSE 0 END) as ingresos_totales,
            SUM(CASE WHEN estado = 'pending' THEN total ELSE 0 END) as pendiente_cobro,
            SUM(CASE WHEN estado = 'cancelled' THEN total ELSE 0 END) as cancelados,
            AVG(CASE WHEN estado = 'paid' THEN total ELSE NULL END) as ticket_promedio,
            MAX(CASE WHEN estado = 'paid' THEN total ELSE 0 END) as ticket_maximo,
            MIN(CASE WHEN estado = 'paid' AND total > 0 THEN total ELSE NULL END) as ticket_minimo,
            COUNT(DISTINCT cliente_id) as clientes_activos
        FROM facturas 
        WHERE fecha_emision BETWEEN ? AND ?
    ";
    $stats_stmt = $db->prepare($stats_sql);
    $stats_stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $estadisticas = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // INGRESOS MENSUALES (últimos 6 meses) - Solo para periodos mensuales
    if ($periodo !== 'day') {
        $ingresos_sql = "
            SELECT 
                DATE_FORMAT(fecha_emision, '%Y-%m') as mes,
                DATE_FORMAT(fecha_emision, '%b') as mes_nombre,
                SUM(CASE WHEN estado = 'paid' THEN total ELSE 0 END) as ingresos,
                COUNT(CASE WHEN estado = 'paid' THEN 1 ELSE NULL END) as facturas_pagadas,
                COUNT(CASE WHEN estado = 'pending' THEN 1 ELSE NULL END) as facturas_pendientes
            FROM facturas 
            WHERE fecha_emision >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(fecha_emision, '%Y-%m'), DATE_FORMAT(fecha_emision, '%b')
            ORDER BY mes ASC
        ";
        $ingresos_stmt = $db->query($ingresos_sql);
        $ingresos_mensuales = $ingresos_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Para día específico, mostrar datos horarios
        $ingresos_horarios_sql = "
            SELECT 
                HOUR(fecha_emision) as hora,
                COUNT(*) as total_facturas,
                SUM(CASE WHEN estado = 'paid' THEN total ELSE 0 END) as ingresos
            FROM facturas 
            WHERE DATE(fecha_emision) = ?
            GROUP BY HOUR(fecha_emision)
            ORDER BY hora ASC
        ";
        $ingresos_stmt = $db->prepare($ingresos_horarios_sql);
        $ingresos_stmt->execute([$fecha_dia]);
        $ingresos_mensuales = $ingresos_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // SERVICIOS MÁS POPULARES
    $servicios_sql = "
        SELECT 
            s.nombre,
            s.precio as precio_unitario,
            COUNT(fd.id) as total_ventas,
            SUM(fd.subtotal) as ingresos_totales,
            ROUND((COUNT(fd.id) / (SELECT COUNT(*) FROM factura_detalles WHERE tipo_item = 'servicio' AND factura_id IN (
                SELECT id FROM facturas WHERE fecha_emision BETWEEN ? AND ?
            )) * 100), 1) as porcentaje
        FROM factura_detalles fd
        INNER JOIN servicios s ON fd.servicio_id = s.id
        INNER JOIN facturas f ON fd.factura_id = f.id
        WHERE fd.tipo_item = 'servicio' AND f.fecha_emision BETWEEN ? AND ?
        GROUP BY s.id, s.nombre, s.precio
        ORDER BY ingresos_totales DESC
        LIMIT 6
    ";
    $servicios_stmt = $db->prepare($servicios_sql);
    $servicios_stmt->execute([
        $start_date . ' 00:00:00', $end_date . ' 23:59:59',
        $start_date . ' 00:00:00', $end_date . ' 23:59:59'
    ]);
    $servicios_populares = $servicios_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // PRODUCTOS MÁS VENDIDOS
    $productos_sql = "
        SELECT 
            p.nombre,
            p.precio_venta as precio_unitario,
            SUM(fd.cantidad) as total_vendido,
            SUM(fd.subtotal) as ingresos_totales
        FROM factura_detalles fd
        INNER JOIN productos p ON fd.producto_id = p.id
        INNER JOIN facturas f ON fd.factura_id = f.id
        WHERE fd.tipo_item = 'producto' AND f.fecha_emision BETWEEN ? AND ?
        GROUP BY p.id, p.nombre, p.precio_venta
        ORDER BY ingresos_totales DESC
        LIMIT 5
    ";
    $productos_stmt = $db->prepare($productos_sql);
    $productos_stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $productos_populares = $productos_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // FACTURAS PENDIENTES
    $pendientes_sql = "
        SELECT f.*, c.nombre as cliente_nombre, c.telefono
        FROM facturas f
        INNER JOIN clientes c ON f.cliente_id = c.id
        WHERE f.estado = 'pending'
        ORDER BY f.fecha_emision DESC
        LIMIT 8
    ";
    $pendientes_stmt = $db->query($pendientes_sql);
    $facturas_pendientes = $pendientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // MÉTODOS DE PAGO
    $metodos_sql = "
        SELECT 
            metodo_pago,
            COUNT(*) as total_facturas,
            SUM(total) as total_ingresos
        FROM facturas 
        WHERE estado = 'paid' AND fecha_emision BETWEEN ? AND ?
        GROUP BY metodo_pago
        ORDER BY total_ingresos DESC
    ";
    $metodos_stmt = $db->prepare($metodos_sql);
    $metodos_stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $metodos_pago = $metodos_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CÁLCULO DE MÉTRICAS AVANZADAS
    if ($estadisticas['ingresos_totales'] > 0) {
        $estadisticas['eficiencia_cobranza'] = round(
            ($estadisticas['ingresos_totales'] / 
            ($estadisticas['ingresos_totales'] + $estadisticas['pendiente_cobro'])) * 100, 1
        );
        
        $estadisticas['tasa_cancelacion'] = $estadisticas['total_facturas'] > 0 ? 
            round(($estadisticas['cancelados'] / $estadisticas['total_facturas']) * 100, 1) : 0;
    } else {
        $estadisticas['eficiencia_cobranza'] = 0;
        $estadisticas['tasa_cancelacion'] = 0;
    }
    
    // CRECIMIENTO vs período inmediatamente anterior de igual duración.
    if ($periodo !== 'day') {
        $inicioActual = new DateTime($start_date);
        $finActual = new DateTime($end_date);
        $diasPeriodo = ((int)$inicioActual->diff($finActual)->format('%a')) + 1;
        $finAnterior = (clone $inicioActual)->modify('-1 day');
        $inicioAnterior = (clone $finAnterior)->modify('-' . max(0, $diasPeriodo - 1) . ' days');
        $periodo_anterior_start = $inicioAnterior->format('Y-m-d');
        $periodo_anterior_end = $finAnterior->format('Y-m-d');
        
        $crecimiento_sql = "
            SELECT 
                COALESCE(SUM(total), 0) as ingresos_anteriores
            FROM facturas 
            WHERE estado = 'paid' 
            AND fecha_emision BETWEEN ? AND ?
        ";
        $crecimiento_stmt = $db->prepare($crecimiento_sql);
        $crecimiento_stmt->execute([$periodo_anterior_start . ' 00:00:00', $periodo_anterior_end . ' 23:59:59']);
        $crecimiento_data = $crecimiento_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($crecimiento_data['ingresos_anteriores'] > 0) {
            $estadisticas['crecimiento'] = round(
                (($estadisticas['ingresos_totales'] - $crecimiento_data['ingresos_anteriores']) / 
                $crecimiento_data['ingresos_anteriores']) * 100, 1
            );
        } else {
            $estadisticas['crecimiento'] = $estadisticas['ingresos_totales'] > 0 ? 100 : 0;
        }
    } else {
        $dia_anterior = date('Y-m-d', strtotime($fecha_dia . ' -1 day'));
        
        $crecimiento_sql = "
            SELECT 
                COALESCE(SUM(total), 0) as ingresos_anteriores
            FROM facturas 
            WHERE estado = 'paid' 
            AND DATE(fecha_emision) = ?
        ";
        $crecimiento_stmt = $db->prepare($crecimiento_sql);
        $crecimiento_stmt->execute([$dia_anterior]);
        $crecimiento_data = $crecimiento_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($crecimiento_data['ingresos_anteriores'] > 0) {
            $estadisticas['crecimiento'] = round(
                (($estadisticas['ingresos_totales'] - $crecimiento_data['ingresos_anteriores']) / 
                $crecimiento_data['ingresos_anteriores']) * 100, 1
            );
        } else {
            $estadisticas['crecimiento'] = $estadisticas['ingresos_totales'] > 0 ? 100 : 0;
        }
    }
    
} catch (PDOException $e) {
    error_log("Error en dashboardfinanciero.php: " . $e->getMessage());
    $error = "Error al cargar datos financieros: " . $e->getMessage();
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
            return $metodo ?? 'No especificado';
    }
}

$page_title = "Dashboard Financiero - VetNexo";
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

        .btn-primary, .btn-secondary {
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-error {
            background: #ffebee;
            color: var(--danger);
            border: 1px solid #ffcdd2;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        }

        .filters-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-content {
            padding: 1.5rem;
        }

        .filters-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-width: 200px;
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
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .quick-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .quick-filter-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray);
            background: var(--white);
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .quick-filter-btn:hover {
            background: var(--primary-soft);
            border-color: var(--primary);
        }

        .quick-filter-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .day-navigation {
            display: flex;
            gap: 0.5rem;
            align-items: center;
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

        .trend-indicator {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);
        }

        .trend-indicator.negative {
            background: rgba(244, 67, 54, 0.1);
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

        .stat-subtext {
            font-size: 0.8rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.5rem;
        }

        /* Grid de Gráficos */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .chart-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-body {
            padding: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Secciones de Contenido */
        .content-section {
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
        }

        .section-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .view-all-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.4rem 1rem;
            border: 1px solid var(--primary-light);
            border-radius: 50px;
            background: var(--white);
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .view-all-btn:hover {
            background: var(--primary);
            color: var(--white);
        }

        /* Tablas */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
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
        }

        .data-table tr:hover td {
            background: var(--gray-light);
        }

        /* Listas */
        .content-list {
            list-style: none;
            max-height: 400px;
            overflow-y: auto;
        }

        .content-list::-webkit-scrollbar {
            width: 6px;
        }

        .content-list::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 3px;
        }

        .content-list::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 3px;
        }

        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-light);
            transition: background 0.3s ease;
        }

        .list-item:hover {
            background: var(--gray-light);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .item-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .item-content {
            flex: 1;
        }

        .item-title {
            font-weight: 600;
            margin-bottom: 0.2rem;
        }

        .item-subtitle {
            font-size: 0.85rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .item-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        /* Métricas */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .metric-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray);
        }

        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .metric-footer {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--text-light);
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

        /* Partículas */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .particle {
            position: absolute;
            background: rgba(46, 125, 50, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px) rotate(0deg);
            }
            50% {
                transform: translateY(-10px) rotate(180deg);
            }
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
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .quick-filters {
                justify-content: center;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Partículas de fondo -->
    <div class="particles">
        <div class="particle" style="top: 20%; left: 10%; width: 8px; height: 8px; animation-delay: 0s;"></div>
        <div class="particle" style="top: 60%; left: 80%; width: 12px; height: 12px; animation-delay: 1s;"></div>
        <div class="particle" style="top: 40%; left: 90%; width: 6px; height: 6px; animation-delay: 2s;"></div>
        <div class="particle" style="top: 80%; left: 20%; width: 10px; height: 10px; animation-delay: 3s;"></div>
        <div class="particle" style="top: 30%; left: 70%; width: 8px; height: 8px; animation-delay: 4s;"></div>
    </div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-line-chart'></i>
                        Dashboard Financiero
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php if ($periodo === 'day'): ?>
                            Análisis del día <?php echo date('d/m/Y', strtotime($fecha_dia)); ?>
                        <?php else: ?>
                            Período: <?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?>
                        <?php endif; ?>
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

            <!-- Mensajes de error -->
            <?php if (isset($error)): ?>
                <div class="alert alert-error fade-in">
                    <i class='bx bx-error-circle'></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h3>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Período
                    </h3>
                </div>
                <div class="filters-content">
                    <form method="GET" action="" id="filterForm">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label class="filter-label">Período</label>
                                <select class="filter-select" name="periodo" id="periodFilter" onchange="updateFilters()">
                                    <option value="day" <?php echo $periodo === 'day' ? 'selected' : ''; ?>>Día Específico</option>
                                    <option value="month" <?php echo $periodo === 'month' ? 'selected' : ''; ?>>Este Mes</option>
                                    <option value="last-month" <?php echo $periodo === 'last-month' ? 'selected' : ''; ?>>Mes Anterior</option>
                                    <option value="quarter" <?php echo $periodo === 'quarter' ? 'selected' : ''; ?>>Este Trimestre</option>
                                    <option value="year" <?php echo $periodo === 'year' ? 'selected' : ''; ?>>Este Año</option>
                                    <option value="ytd" <?php echo $periodo === 'ytd' ? 'selected' : ''; ?>>Año a la Fecha</option>
                                    <option value="custom" <?php echo $periodo === 'custom' ? 'selected' : ''; ?>>Personalizado</option>
                                </select>
                            </div>
                            
                            <div class="filter-group" id="dayFilterGroup" style="<?php echo $periodo === 'day' ? 'display: flex;' : 'display: none;'; ?>">
                                <label class="filter-label">Seleccionar Día</label>
                                <input type="date" class="filter-input" name="fecha_dia" value="<?php echo $fecha_dia; ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="filter-group" id="customDateRange" style="<?php echo $periodo === 'custom' ? 'display: flex;' : 'display: none;'; ?>">
                                <label class="filter-label">Rango de Fechas</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="date" class="filter-input" name="start_date" value="<?php echo $start_date; ?>">
                                    <input type="date" class="filter-input" name="end_date" value="<?php echo $end_date; ?>">
                                </div>
                            </div>
                            
                            <div class="filter-group" style="justify-content: flex-end;">
                                <button type="submit" class="btn-primary btn-sm">
                                    <i class='bx bx-filter'></i>
                                    Aplicar Filtros
                                </button>
                            </div>
                        </div>
                        
                        <div class="quick-filters">
                            <button type="button" class="quick-filter-btn <?php echo $periodo === 'day' ? 'active' : ''; ?>" onclick="setPeriod('day')">
                                <i class='bx bx-calendar'></i> Hoy
                            </button>
                            <button type="button" class="quick-filter-btn <?php echo $periodo === 'month' ? 'active' : ''; ?>" onclick="setPeriod('month')">
                                <i class='bx bx-calendar'></i> Este Mes
                            </button>
                            <button type="button" class="quick-filter-btn <?php echo $periodo === 'quarter' ? 'active' : ''; ?>" onclick="setPeriod('quarter')">
                                <i class='bx bx-calendar'></i> Trimestre
                            </button>
                            <button type="button" class="quick-filter-btn <?php echo $periodo === 'year' ? 'active' : ''; ?>" onclick="setPeriod('year')">
                                <i class='bx bx-calendar'></i> Año
                            </button>
                            <button type="button" class="quick-filter-btn <?php echo $periodo === 'ytd' ? 'active' : ''; ?>" onclick="setPeriod('ytd')">
                                <i class='bx bx-calendar'></i> YTD
                            </button>
                            
                            <?php if ($periodo === 'day'): ?>
                                <div class="day-navigation">
                                    <button type="button" class="quick-filter-btn" onclick="navigateDay(-1)">
                                        <i class='bx bx-chevron-left'></i> Ayer
                                    </button>
                                    <button type="button" class="quick-filter-btn" onclick="navigateDay(1)">
                                        Mañana <i class='bx bx-chevron-right'></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Estadísticas Principales -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                            <div class="trend-indicator <?php echo ($estadisticas['crecimiento'] ?? 0) >= 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo ($estadisticas['crecimiento'] ?? 0) >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo abs($estadisticas['crecimiento'] ?? 0); ?>%
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ingresos Totales</div>
                        <div class="stat-subtext">
                            <i class='bx bx-receipt'></i> <?php echo $estadisticas['total_facturas'] ?? 0; ?> facturas
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['pendiente_cobro'] ?? 0, 2); ?></div>
                        <div class="stat-label">Pendiente de Cobro</div>
                        <div class="stat-subtext">
                            <i class='bx bx-error-circle'></i> Facturas por cobrar
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-line-chart'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ticket_promedio'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ticket Promedio</div>
                        <div class="stat-subtext">
                            <i class='bx bx-trending-up'></i> Máx: $<?php echo number_format($estadisticas['ticket_maximo'] ?? 0, 2); ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-group'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['clientes_activos'] ?? 0; ?></div>
                        <div class="stat-label">Clientes Activos</div>
                        <div class="stat-subtext">
                            <i class='bx bx-user'></i> En el período
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gráficos -->
            <div class="charts-grid fade-in">
                <!-- Gráfico de Ingresos -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3>
                            <i class='bx bx-line-chart'></i>
                            <?php echo $periodo === 'day' ? 'Distribución por Hora' : 'Tendencia de Ingresos'; ?>
                        </h3>
                        <button class="view-all-btn" onclick="downloadChart('revenueChart')">
                            <i class='bx bx-download'></i> Descargar
                        </button>
                    </div>
                    <div class="chart-body">
                        <div class="chart-container">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Gráfico de Métodos de Pago -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3>
                            <i class='bx bx-credit-card'></i>
                            Métodos de Pago
                        </h3>
                        <button class="view-all-btn" onclick="downloadChart('paymentChart')">
                            <i class='bx bx-download'></i> Descargar
                        </button>
                    </div>
                    <div class="chart-body">
                        <div class="chart-container">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Métricas Clave -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-stats'></i>
                        Métricas Clave
                    </h3>
                </div>
                <div class="metrics-grid">
                    <!-- Eficiencia de Cobranza -->
                    <div class="metric-card">
                        <div class="metric-header">
                            <span>Eficiencia de Cobranza</span>
                            <span class="metric-value"><?php echo $estadisticas['eficiencia_cobranza'] ?? 0; ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min($estadisticas['eficiencia_cobranza'] ?? 0, 100); ?>%; background: <?php echo ($estadisticas['eficiencia_cobranza'] ?? 0) >= 80 ? 'var(--success)' : 'var(--warning)'; ?>;"></div>
                        </div>
                        <div class="metric-footer">
                            <span>Meta: 90%</span>
                            <span>vs meta: <?php echo number_format(($estadisticas['eficiencia_cobranza'] ?? 0) - 90, 1); ?>%</span>
                        </div>
                    </div>

                    <!-- Tasa de Crecimiento -->
                    <div class="metric-card">
                        <div class="metric-header">
                            <span>Tasa de Crecimiento</span>
                            <span class="metric-value" style="color: <?php echo ($estadisticas['crecimiento'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo ($estadisticas['crecimiento'] ?? 0) >= 0 ? '+' : ''; ?><?php echo $estadisticas['crecimiento'] ?? 0; ?>%
                            </span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min(abs($estadisticas['crecimiento'] ?? 0), 100); ?>%; background: <?php echo ($estadisticas['crecimiento'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;"></div>
                        </div>
                        <div class="metric-footer">
                            <span>Meta: +10%</span>
                            <span>vs meta: <?php echo number_format(($estadisticas['crecimiento'] ?? 0) - 10, 1); ?>%</span>
                        </div>
                    </div>

                    <!-- Tasa de Cancelación -->
                    <div class="metric-card">
                        <div class="metric-header">
                            <span>Tasa de Cancelación</span>
                            <span class="metric-value" style="color: <?php echo ($estadisticas['tasa_cancelacion'] ?? 0) <= 5 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo $estadisticas['tasa_cancelacion'] ?? 0; ?>%
                            </span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min($estadisticas['tasa_cancelacion'] ?? 0, 100); ?>%; background: <?php echo ($estadisticas['tasa_cancelacion'] ?? 0) <= 5 ? 'var(--success)' : 'var(--danger)'; ?>;"></div>
                        </div>
                        <div class="metric-footer">
                            <span>Máx: 5%</span>
                            <span>vs límite: <?php echo number_format(($estadisticas['tasa_cancelacion'] ?? 0) - 5, 1); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Servicios y Productos Populares -->
            <div class="charts-grid fade-in">
                <!-- Servicios Populares -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-star'></i>
                            Servicios Más Populares
                        </h3>
                        <a href="servicios.php" class="view-all-btn">
                            Ver todos <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="chart-body">
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="servicesChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Productos Más Vendidos -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-package'></i>
                            Productos Más Vendidos
                        </h3>
                        <a href="productos.php" class="view-all-btn">
                            Ver todos <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <?php if (!empty($productos_populares)): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Cantidad</th>
                                        <th>Ingresos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos_populares as $producto): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                            <td><?php echo $producto['total_vendido']; ?> und</td>
                                            <td>$<?php echo number_format($producto['ingresos_totales'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-package'></i>
                            <h3>No hay ventas de productos</h3>
                            <p>No se han vendido productos en el período seleccionado.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Facturas Pendientes -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-time'></i>
                        Facturas Pendientes de Cobro
                    </h3>
                    <a href="facturaspendientes.php" class="view-all-btn">
                        Ver todas <i class='bx bx-chevron-right'></i>
                    </a>
                </div>
                
                <?php if (!empty($facturas_pendientes)): ?>
                    <ul class="content-list">
                        <?php foreach ($facturas_pendientes as $factura): ?>
                            <li class="list-item">
                                <div class="item-icon">
                                    <i class='bx bx-time'></i>
                                </div>
                                <div class="item-content">
                                    <div class="item-title">
                                        <?php echo htmlspecialchars($factura['numero_factura']); ?> - <?php echo htmlspecialchars($factura['cliente_nombre']); ?>
                                    </div>
                                    <div class="item-subtitle">
                                        <span><i class='bx bx-dollar'></i> $<?php echo number_format($factura['total'], 2); ?></span>
                                        <span><i class='bx bx-calendar'></i> <?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></span>
                                        <?php if (!empty($factura['telefono'])): ?>
                                            <span><i class='bx bx-phone'></i> <?php echo htmlspecialchars($factura['telefono']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="item-badge badge-warning">Pendiente</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-check-circle'></i>
                        <h3>No hay facturas pendientes</h3>
                        <p>Todas las facturas han sido cobradas</p>
                    </div>
                <?php endif; ?>
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
    <script src="assets/vendor/chart.umd.min.js"></script>
    <script>
        // Datos desde PHP
        const chartData = {
            periodo: '<?php echo $periodo; ?>',
            fecha_dia: '<?php echo $fecha_dia; ?>',
            meses: <?php echo json_encode(array_column($ingresos_mensuales, $periodo === 'day' ? 'hora' : 'mes_nombre')); ?>,
            ingresos: <?php echo json_encode(array_column($ingresos_mensuales, 'ingresos')); ?>,
            servicios: <?php echo json_encode($servicios_populares); ?>,
            metodosPago: <?php echo json_encode($metodos_pago); ?>
        };

        // Variables globales para los gráficos
        let revenueChart, servicesChart, paymentChart;

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
            animateStats();
            updateFilters();
            createParticles();
        });

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

        // Actualizar filtros
        function updateFilters() {
            const periodFilter = document.getElementById('periodFilter').value;
            const customRange = document.getElementById('customDateRange');
            const dayFilterGroup = document.getElementById('dayFilterGroup');
            
            customRange.style.display = periodFilter === 'custom' ? 'flex' : 'none';
            dayFilterGroup.style.display = periodFilter === 'day' ? 'flex' : 'none';
        }

        // Establecer período
        function setPeriod(period) {
            document.getElementById('periodFilter').value = period;
            
            if (period === 'day') {
                document.querySelector('input[name="fecha_dia"]').value = '<?php echo date('Y-m-d'); ?>';
            }
            
            document.getElementById('filterForm').submit();
        }

        // Navegar entre días
        function navigateDay(days) {
            const fechaInput = document.querySelector('input[name="fecha_dia"]');
            const currentDate = new Date(fechaInput.value);
            currentDate.setDate(currentDate.getDate() + days);
            
            const formattedDate = currentDate.toISOString().split('T')[0];
            fechaInput.value = formattedDate;
            
            document.getElementById('periodFilter').value = 'day';
            document.getElementById('filterForm').submit();
        }

        // Inicializar gráficos
        function initCharts() {
            // Gráfico de Ingresos
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            const isDay = chartData.periodo === 'day';
            
            revenueChart = new Chart(revenueCtx, {
                type: isDay ? 'bar' : 'line',
                data: {
                    labels: chartData.meses,
                    datasets: [{
                        label: isDay ? 'Ingresos por Hora' : 'Ingresos Mensuales',
                        data: chartData.ingresos,
                        borderColor: 'rgba(46, 125, 50, 1)',
                        backgroundColor: isDay ? 'rgba(46, 125, 50, 0.6)' : 'rgba(46, 125, 50, 0.1)',
                        borderWidth: isDay ? 1 : 3,
                        fill: !isDay,
                        tension: isDay ? 0 : 0.4,
                        pointBackgroundColor: 'rgba(46, 125, 50, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: isDay ? 0 : 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (context) => `Ingresos: $${context.raw?.toLocaleString() ?? 0}`
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => '$' + value.toLocaleString()
                            }
                        },
                        x: {
                            title: {
                                display: isDay,
                                text: isDay ? 'Hora del día' : ''
                            }
                        }
                    }
                }
            });

            // Gráfico de Servicios
            const servicesCtx = document.getElementById('servicesChart').getContext('2d');
            servicesChart = new Chart(servicesCtx, {
                type: 'doughnut',
                data: {
                    labels: chartData.servicios.map(s => s.nombre),
                    datasets: [{
                        data: chartData.servicios.map(s => s.ingresos_totales),
                        backgroundColor: [
                            'rgba(46, 125, 50, 0.9)',
                            'rgba(16, 185, 129, 0.9)',
                            'rgba(245, 158, 11, 0.9)',
                            'rgba(139, 92, 246, 0.9)',
                            'rgba(236, 72, 153, 0.9)',
                            'rgba(59, 130, 246, 0.9)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (context) => {
                                    const s = chartData.servicios[context.dataIndex];
                                    return [
                                        s.nombre,
                                        `Ingresos: $${s.ingresos_totales?.toLocaleString() ?? 0}`,
                                        `Ventas: ${s.total_ventas}`,
                                        `Participación: ${s.porcentaje}%`
                                    ];
                                }
                            }
                        }
                    }
                }
            });

            // Gráfico de Métodos de Pago
            const paymentCtx = document.getElementById('paymentChart').getContext('2d');
            paymentChart = new Chart(paymentCtx, {
                type: 'pie',
                data: {
                    labels: chartData.metodosPago.map(m => getMetodoPagoText(m.metodo_pago)),
                    datasets: [{
                        data: chartData.metodosPago.map(m => m.total_ingresos),
                        backgroundColor: [
                            'rgba(46, 125, 50, 0.9)',
                            'rgba(59, 130, 246, 0.9)',
                            'rgba(139, 92, 246, 0.9)',
                            'rgba(245, 158, 11, 0.9)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (context) => {
                                    const m = chartData.metodosPago[context.dataIndex];
                                    return [
                                        `Ingresos: $${m.total_ingresos?.toLocaleString() ?? 0}`,
                                        `Facturas: ${m.total_facturas}`
                                    ];
                                }
                            }
                        }
                    }
                }
            });
        }

        // Función para obtener nombre del método de pago
        function getMetodoPagoText(metodo) {
            const metodos = {
                'cash': 'Efectivo',
                'card': 'Tarjeta',
                'transfer': 'Transferencia'
            };
            return metodos[metodo] || metodo || 'No especificado';
        }

        // Descargar gráfico
        function downloadChart(chartId) {
            const chart = getChartInstance(chartId);
            if (chart) {
                const link = document.createElement('a');
                link.download = `${chartId}-${new Date().toISOString().split('T')[0]}.png`;
                link.href = chart.toBase64Image();
                link.click();
            }
        }

        function getChartInstance(chartId) {
            const instances = {
                'revenueChart': revenueChart,
                'servicesChart': servicesChart,
                'paymentChart': paymentChart
            };
            return instances[chartId];
        }

        // Crear partículas de fondo
        function createParticles() {
            const particlesContainer = document.querySelector('.particles');
            for (let i = 0; i < 10; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    animation-delay: ${Math.random() * 5}s;
                    opacity: ${Math.random() * 0.5 + 0.1};
                    background: ${Math.random() > 0.5 ? 'rgba(46, 125, 50, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        }

        // Exportar datos
        function exportFinancialData() {
            window.location.href = 'export_financiero.php?periodo=<?php echo $periodo; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&fecha_dia=<?php echo $fecha_dia; ?>';
        }

        // Generar reporte
        function generateFinancialReport() {
            window.location.href = 'reporte_financiero.php?periodo=<?php echo $periodo; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&fecha_dia=<?php echo $fecha_dia; ?>';
        }
    </script>
</body>
</html>