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
$error = '';

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
    $comparar_con = $_GET['comparar_con'] ?? 'none';
    
    // Calcular fechas según el período
    switch($periodo) {
        case 'day':
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
            break;
        case 'last-month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            break;
        case 'quarter':
            $start_date = date('Y-m-01', strtotime('-3 months'));
            $end_date = date('Y-m-d');
            break;
        case 'year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-m-d');
            break;
        case 'ytd':
            $start_date = date('Y-01-01');
            $end_date = date('Y-m-d');
            break;
        case 'custom':
            $start_date = $_GET['start_date'] ?? date('Y-m-01');
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            break;
        default: // month
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
    }
    
    // Validar fechas
    if ($start_date > $end_date) {
        $start_date = $end_date;
    }
    
    // Fechas para período anterior (para comparación)
    $periodo_dias = floor((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24));
    $prev_start_date = date('Y-m-d', strtotime($start_date . " -{$periodo_dias} days"));
    $prev_end_date = date('Y-m-d', strtotime($end_date . " -{$periodo_dias} days"));
    
    // ============================================
    // DATOS DEL PERÍODO ACTUAL
    // ============================================
    
    // Ingresos por categoría
    $sql_ingresos_categoria = "
        SELECT 
            COALESCE(SUM(CASE WHEN s.categoria = 'grooming' THEN fd.subtotal ELSE 0 END), 0) as peluqueria,
            COALESCE(SUM(CASE WHEN s.categoria IN ('consultation', 'vaccination', 'surgery', 'emergency', 'consult', 'examen') THEN fd.subtotal ELSE 0 END), 0) as servicios_medicos,
            COALESCE(SUM(CASE WHEN fd.tipo_item = 'producto' OR p.id IS NOT NULL THEN fd.subtotal ELSE 0 END), 0) as productos,
            COALESCE(SUM(CASE WHEN s.categoria = 'laboratory' OR s.categoria = 'laboratorio' THEN fd.subtotal ELSE 0 END), 0) as laboratorio
        FROM factura_detalles fd
        LEFT JOIN servicios s ON fd.servicio_id = s.id
        LEFT JOIN productos p ON fd.producto_id = p.id
        INNER JOIN facturas f ON fd.factura_id = f.id
        WHERE f.estado IN ('paid', 'pagada') AND DATE(f.fecha_emision) BETWEEN ? AND ?
    ";
    $stmt_ingresos = $db->prepare($sql_ingresos_categoria);
    $stmt_ingresos->execute([$start_date, $end_date]);
    $ingresos_categoria = $stmt_ingresos->fetch(PDO::FETCH_ASSOC);
    
    $total_ingresos = array_sum($ingresos_categoria);
    
    // Costos de productos vendidos
    $sql_costos_productos = "
        SELECT COALESCE(SUM(p.precio_compra * fd.cantidad), 0) as total
        FROM factura_detalles fd
        INNER JOIN productos p ON fd.producto_id = p.id
        INNER JOIN facturas f ON fd.factura_id = f.id
        WHERE f.estado IN ('paid', 'pagada') AND DATE(f.fecha_emision) BETWEEN ? AND ?
    ";
    $stmt_costos = $db->prepare($sql_costos_productos);
    $stmt_costos->execute([$start_date, $end_date]);
    $costos_productos = $stmt_costos->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Gastos del período
    $sql_gastos = "
        SELECT 
            COALESCE(SUM(CASE WHEN e.categoria IN ('servicios_veterinarios', 'sueldos', 'salarios') THEN e.monto ELSE 0 END), 0) as sueldos,
            COALESCE(SUM(CASE WHEN e.categoria IN ('alquileres', 'servicios', 'alquiler') THEN e.monto ELSE 0 END), 0) as alquiler_servicios,
            COALESCE(SUM(CASE WHEN e.categoria = 'insumos' THEN e.monto ELSE 0 END), 0) as suministros,
            COALESCE(SUM(CASE WHEN e.categoria = 'publicidad' OR e.categoria = 'marketing' THEN e.monto ELSE 0 END), 0) as marketing,
            COALESCE(SUM(CASE WHEN e.categoria IN ('administrativos', 'gastos_generales', 'oficina') THEN e.monto ELSE 0 END), 0) as administrativos,
            COALESCE(SUM(CASE WHEN e.categoria IN ('intereses', 'financieros', 'prestamos') THEN e.monto ELSE 0 END), 0) as intereses,
            COALESCE(SUM(CASE WHEN e.categoria = 'depreciacion' THEN e.monto ELSE 0 END), 0) as depreciacion,
            COALESCE(SUM(e.monto), 0) as total_gastos
        FROM egresos e
        WHERE e.estado IN ('pagado', 'pagada', 'paid') AND DATE(e.fecha) BETWEEN ? AND ?
    ";
    $stmt_gastos = $db->prepare($sql_gastos);
    $stmt_gastos->execute([$start_date, $end_date]);
    $gastos = $stmt_gastos->fetch(PDO::FETCH_ASSOC);
    
    // Si no hay gastos, inicializar con ceros
    if (!$gastos) {
        $gastos = [
            'sueldos' => 0,
            'alquiler_servicios' => 0,
            'suministros' => 0,
            'marketing' => 0,
            'administrativos' => 0,
            'intereses' => 0,
            'depreciacion' => 0,
            'total_gastos' => 0
        ];
    }
    
    // Cálculos financieros
    $utilidad_bruta = $total_ingresos - $costos_productos;
    $margen_bruto = $total_ingresos > 0 ? round(($utilidad_bruta / $total_ingresos) * 100, 1) : 0;
    
    $gastos_operativos = ($gastos['sueldos'] ?? 0) + 
                         ($gastos['alquiler_servicios'] ?? 0) + 
                         ($gastos['suministros'] ?? 0) + 
                         ($gastos['marketing'] ?? 0) + 
                         ($gastos['administrativos'] ?? 0);
    
    $utilidad_operativa = $utilidad_bruta - $gastos_operativos;
    $margen_operativo = $total_ingresos > 0 ? round(($utilidad_operativa / $total_ingresos) * 100, 1) : 0;
    
    $gastos_no_operativos = ($gastos['intereses'] ?? 0) + ($gastos['depreciacion'] ?? 0);
    $utilidad_neta = $utilidad_operativa - $gastos_no_operativos;
    $margen_neto = $total_ingresos > 0 ? round(($utilidad_neta / $total_ingresos) * 100, 1) : 0;
    
    $gastos_sobre_ingresos = $total_ingresos > 0 ? round(($gastos_operativos / $total_ingresos) * 100, 1) : 0;
    
    // Ticket promedio
    $sql_ticket = "
        SELECT COUNT(*) as total_facturas, COALESCE(AVG(total), 0) as ticket_promedio
        FROM facturas
        WHERE estado IN ('paid', 'pagada') AND DATE(fecha_emision) BETWEEN ? AND ?
    ";
    $stmt_ticket = $db->prepare($sql_ticket);
    $stmt_ticket->execute([$start_date, $end_date]);
    $ticket_data = $stmt_ticket->fetch(PDO::FETCH_ASSOC);
    $total_facturas = $ticket_data['total_facturas'] ?? 0;
    $ticket_promedio = $ticket_data['ticket_promedio'] ?? 0;
    
    // ============================================
    // DATOS DEL PERÍODO ANTERIOR (para comparación)
    // ============================================
    $total_ingresos_prev = 0;
    $utilidad_neta_prev = 0;
    $margen_neto_prev = 0;
    $ticket_promedio_prev = 0;
    $var_ingresos = 0;
    $var_utilidad = 0;
    $var_margen = 0;
    $var_ticket = 0;
    
    if ($comparar_con !== 'none') {
        // Ingresos período anterior
        $stmt_ingresos_prev = $db->prepare($sql_ingresos_categoria);
        $stmt_ingresos_prev->execute([$prev_start_date, $prev_end_date]);
        $ingresos_prev = $stmt_ingresos_prev->fetch(PDO::FETCH_ASSOC);
        $total_ingresos_prev = array_sum($ingresos_prev);
        
        // Costos período anterior
        $stmt_costos_prev = $db->prepare($sql_costos_productos);
        $stmt_costos_prev->execute([$prev_start_date, $prev_end_date]);
        $costos_prev = $stmt_costos_prev->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Utilidad neta período anterior
        $utilidad_bruta_prev = $total_ingresos_prev - $costos_prev;
        
        $stmt_gastos_prev = $db->prepare($sql_gastos);
        $stmt_gastos_prev->execute([$prev_start_date, $prev_end_date]);
        $gastos_prev = $stmt_gastos_prev->fetch(PDO::FETCH_ASSOC);
        
        if (!$gastos_prev) {
            $gastos_prev = [
                'sueldos' => 0,
                'alquiler_servicios' => 0,
                'suministros' => 0,
                'marketing' => 0,
                'administrativos' => 0,
                'intereses' => 0,
                'depreciacion' => 0
            ];
        }
        
        $gastos_operativos_prev = ($gastos_prev['sueldos'] ?? 0) + 
                                   ($gastos_prev['alquiler_servicios'] ?? 0) + 
                                   ($gastos_prev['suministros'] ?? 0) + 
                                   ($gastos_prev['marketing'] ?? 0) + 
                                   ($gastos_prev['administrativos'] ?? 0);
        
        $utilidad_operativa_prev = $utilidad_bruta_prev - $gastos_operativos_prev;
        $gastos_no_operativos_prev = ($gastos_prev['intereses'] ?? 0) + ($gastos_prev['depreciacion'] ?? 0);
        $utilidad_neta_prev = $utilidad_operativa_prev - $gastos_no_operativos_prev;
        
        $margen_neto_prev = $total_ingresos_prev > 0 ? round(($utilidad_neta_prev / $total_ingresos_prev) * 100, 1) : 0;
        
        $stmt_ticket_prev = $db->prepare($sql_ticket);
        $stmt_ticket_prev->execute([$prev_start_date, $prev_end_date]);
        $ticket_data_prev = $stmt_ticket_prev->fetch(PDO::FETCH_ASSOC);
        $ticket_promedio_prev = $ticket_data_prev['ticket_promedio'] ?? 0;
        
        // Cálculo de variaciones
        $var_ingresos = $total_ingresos_prev > 0 ? round((($total_ingresos - $total_ingresos_prev) / $total_ingresos_prev) * 100, 1) : 0;
        $var_utilidad = $utilidad_neta_prev > 0 ? round((($utilidad_neta - $utilidad_neta_prev) / $utilidad_neta_prev) * 100, 1) : 0;
        $var_margen = round($margen_neto - $margen_neto_prev, 1);
        $var_ticket = $ticket_promedio_prev > 0 ? round((($ticket_promedio - $ticket_promedio_prev) / $ticket_promedio_prev) * 100, 1) : 0;
    }
    
    // ============================================
    // DATOS PARA TENDENCIAS (últimos 6 meses)
    // ============================================
    
    $sql_tendencias = "
        SELECT 
            DATE_FORMAT(f.fecha_emision, '%b') as mes,
            DATE_FORMAT(f.fecha_emision, '%m') as mes_num,
            SUM(CASE WHEN f.estado IN ('paid', 'pagada') THEN f.total ELSE 0 END) as ingresos,
            COALESCE((
                SELECT SUM(p.precio_compra * fd.cantidad)
                FROM factura_detalles fd
                INNER JOIN productos p ON fd.producto_id = p.id
                WHERE fd.factura_id = f.id
            ), 0) as costos
        FROM facturas f
        WHERE f.fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(f.fecha_emision, '%Y-%m')
        ORDER BY MIN(f.fecha_emision) ASC
        LIMIT 6
    ";
    $stmt_tendencias = $db->query($sql_tendencias);
    $tendencias_raw = $stmt_tendencias->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar datos para gráficos
    $meses = [];
    $ingresos_trend = [];
    $margen_bruto_trend = [];
    $margen_operativo_trend = [];
    $margen_neto_trend = [];
    
    foreach ($tendencias_raw as $t) {
        $meses[] = $t['mes'];
        $ingresos_trend[] = $t['ingresos'];
        
        // Obtener gastos del mes
        $fecha_mes = date('Y-m', strtotime($t['mes']));
        $inicio_mes = date('Y-m-01', strtotime($t['mes']));
        $fin_mes = date('Y-m-t', strtotime($t['mes']));
        
        $stmt_gastos_mes = $db->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM egresos WHERE estado IN ('pagado', 'pagada', 'paid') AND DATE(fecha) BETWEEN ? AND ?");
        $stmt_gastos_mes->execute([$inicio_mes, $fin_mes]);
        $gastos_mes = $stmt_gastos_mes->fetch(PDO::FETCH_ASSOC)['total'];
        
        $utilidad_b = $t['ingresos'] - $t['costos'];
        $utilidad_o = $utilidad_b - $gastos_mes;
        
        $margen_bruto_trend[] = $t['ingresos'] > 0 ? round(($utilidad_b / $t['ingresos']) * 100, 1) : 0;
        $margen_operativo_trend[] = $t['ingresos'] > 0 ? round(($utilidad_o / $t['ingresos']) * 100, 1) : 0;
        $margen_neto_trend[] = $t['ingresos'] > 0 ? round(($utilidad_o / $t['ingresos']) * 100, 1) : 0;
    }
    
} catch (PDOException $e) {
    error_log("Error en estado_resultados.php: " . $e->getMessage());
    $error = "Error al cargar datos: " . $e->getMessage();
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
function formatDateDisplay($date) {
    return date('d/m/Y', strtotime($date));
}

// Función para obtener clase de variación
function getVariacionClass($variacion) {
    if ($variacion > 0) return 'change-positive';
    if ($variacion < 0) return 'change-negative';
    return '';
}

$page_title = "Estado de Resultados - VetNexo";
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

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: flex-end;
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
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
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

        /* Resumen Ejecutivo */
        .summary-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .summary-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .summary-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .summary-card {
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .summary-value.positive {
            color: var(--success);
        }

        .summary-value.negative {
            color: var(--danger);
        }

        .summary-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .summary-change {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .change-positive {
            color: var(--success);
        }

        .change-negative {
            color: var(--danger);
        }

        /* Estado de Resultados */
        .statement-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .statement-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            text-align: center;
        }

        .statement-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.3rem;
        }

        .statement-header p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .statement-body {
            padding: 0;
        }

        .statement-category {
            border-bottom: 1px solid var(--gray-light);
        }

        .category-header {
            padding: 1rem 1.5rem;
            background: var(--primary-soft);
            border-bottom: 1px solid var(--gray);
        }

        .category-header h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
        }

        .statement-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 1.5rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .statement-row:last-child {
            border-bottom: none;
        }

        .statement-row.subtotal {
            background: var(--primary-soft);
            font-weight: 600;
        }

        .statement-row.total {
            background: var(--accent-soft);
            font-weight: 700;
        }

        .statement-row.grand-total {
            background: var(--primary);
            color: var(--white);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .statement-row.grand-total .account-percent {
            color: rgba(255,255,255,0.9);
        }

        .account-name {
            flex: 1;
        }

        .account-amount {
            min-width: 150px;
            text-align: right;
            font-weight: 600;
        }

        .account-amount.positive {
            color: var(--success);
        }

        .account-amount.negative {
            color: var(--danger);
        }

        .account-percent {
            min-width: 80px;
            text-align: right;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Análisis de Márgenes */
        .margins-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .margins-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .margins-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .margins-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .margin-card {
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 1.2rem;
            text-align: center;
        }

        .margin-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.3rem;
        }

        .margin-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .margin-change {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        /* Gráficos */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .chart-card {
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
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .chart-body {
            padding: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Tendencias */
        .trends-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .trends-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .trends-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .trends-body {
            padding: 1.5rem;
        }

        .trend-chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Badge */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
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
            
            .charts-grid {
                grid-template-columns: 1fr;
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
            
            .page-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .quick-filters {
                justify-content: center;
            }
            
            .statement-row {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .account-amount, .account-percent {
                min-width: auto;
            }
            
            .margins-grid {
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
                        Estado de Resultados
                    </h1>
                    <p>
                        <i class='bx bx-trending-up'></i>
                        Análisis detallado de ingresos, costos y gastos
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
                <button class="btn-secondary btn-sm" onclick="exportarPDF()">
                    <i class='bx bx-download'></i>
                    Exportar PDF
                </button>
                <button class="btn-primary btn-sm" onclick="window.print()">
                    <i class='bx bx-printer'></i>
                    Imprimir
                </button>
            </div>

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
                    <form method="GET" action="" class="filter-form" id="filterForm">
                        <div class="filter-group">
                            <label class="filter-label">Período</label>
                            <select class="filter-select" name="periodo" id="periodFilter" onchange="toggleCustomRange()">
                                <option value="month" <?php echo $periodo === 'month' ? 'selected' : ''; ?>>Este Mes</option>
                                <option value="last-month" <?php echo $periodo === 'last-month' ? 'selected' : ''; ?>>Mes Anterior</option>
                                <option value="quarter" <?php echo $periodo === 'quarter' ? 'selected' : ''; ?>>Últimos 3 Meses</option>
                                <option value="year" <?php echo $periodo === 'year' ? 'selected' : ''; ?>>Este Año</option>
                                <option value="ytd" <?php echo $periodo === 'ytd' ? 'selected' : ''; ?>>Año a la Fecha</option>
                                <option value="custom" <?php echo $periodo === 'custom' ? 'selected' : ''; ?>>Personalizado</option>
                            </select>
                        </div>
                        
                        <div class="filter-group" id="customDateRange" style="<?php echo $periodo === 'custom' ? 'display: flex;' : 'display: none;'; ?>">
                            <label class="filter-label">Rango de Fechas</label>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="date" class="filter-input" name="start_date" value="<?php echo $start_date; ?>">
                                <input type="date" class="filter-input" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Comparar con</label>
                            <select class="filter-select" name="comparar_con">
                                <option value="none" <?php echo $comparar_con === 'none' ? 'selected' : ''; ?>>No comparar</option>
                                <option value="previous-period" <?php echo $comparar_con === 'previous-period' ? 'selected' : ''; ?>>Período Anterior</option>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn-primary btn-sm">
                                <i class='bx bx-filter'></i>
                                Aplicar
                            </button>
                        </div>
                    </form>
                    
                    <div class="quick-filters">
                        <button class="quick-filter-btn <?php echo $periodo === 'month' ? 'active' : ''; ?>" onclick="setPeriod('month')">
                            <i class='bx bx-calendar'></i> Este Mes
                        </button>
                        <button class="quick-filter-btn <?php echo $periodo === 'quarter' ? 'active' : ''; ?>" onclick="setPeriod('quarter')">
                            <i class='bx bx-calendar'></i> Trimestre
                        </button>
                        <button class="quick-filter-btn <?php echo $periodo === 'year' ? 'active' : ''; ?>" onclick="setPeriod('year')">
                            <i class='bx bx-calendar'></i> Año
                        </button>
                        <button class="quick-filter-btn <?php echo $periodo === 'ytd' ? 'active' : ''; ?>" onclick="setPeriod('ytd')">
                            <i class='bx bx-calendar'></i> YTD
                        </button>
                    </div>
                </div>
            </div>

            <!-- Resumen Ejecutivo -->
            <div class="summary-section fade-in">
                <div class="summary-header">
                    <h3>
                        <i class='bx bx-stats'></i>
                        Resumen Ejecutivo
                    </h3>
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-value positive">$<?php echo number_format($total_ingresos, 2); ?></div>
                        <div class="summary-label">Ingresos Totales</div>
                        <?php if ($comparar_con !== 'none' && $total_ingresos_prev > 0): ?>
                            <div class="summary-change <?php echo getVariacionClass($var_ingresos); ?>">
                                <i class='bx bx-<?php echo $var_ingresos >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo abs($var_ingresos); ?>% vs período anterior
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-value <?php echo $utilidad_neta >= 0 ? 'positive' : 'negative'; ?>">
                            $<?php echo number_format($utilidad_neta, 2); ?>
                        </div>
                        <div class="summary-label">Utilidad Neta</div>
                        <?php if ($comparar_con !== 'none' && $utilidad_neta_prev > 0): ?>
                            <div class="summary-change <?php echo getVariacionClass($var_utilidad); ?>">
                                <i class='bx bx-<?php echo $var_utilidad >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo abs($var_utilidad); ?>% vs período anterior
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-value positive"><?php echo $margen_neto; ?>%</div>
                        <div class="summary-label">Margen de Utilidad</div>
                        <?php if ($comparar_con !== 'none'): ?>
                            <div class="summary-change <?php echo getVariacionClass($var_margen); ?>">
                                <i class='bx bx-<?php echo $var_margen >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo abs($var_margen); ?>% vs período anterior
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-value positive">$<?php echo number_format($ticket_promedio, 2); ?></div>
                        <div class="summary-label">Ticket Promedio</div>
                        <?php if ($comparar_con !== 'none' && $ticket_promedio_prev > 0): ?>
                            <div class="summary-change <?php echo getVariacionClass($var_ticket); ?>">
                                <i class='bx bx-<?php echo $var_ticket >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo abs($var_ticket); ?>% vs período anterior
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Estado de Resultados Principal -->
            <div class="statement-section fade-in">
                <div class="statement-header">
                    <h2>ESTADO DE RESULTADOS</h2>
                    <p>Del <?php echo formatDateDisplay($start_date); ?> al <?php echo formatDateDisplay($end_date); ?></p>
                </div>
                
                <div class="statement-body">
                    <!-- Ingresos -->
                    <div class="statement-category">
                        <div class="category-header">
                            <h4>INGRESOS OPERACIONALES</h4>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Servicios Médicos</span>
                            <span class="account-amount positive">$<?php echo number_format($ingresos_categoria['servicios_medicos'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($ingresos_categoria['servicios_medicos'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Productos y Medicamentos</span>
                            <span class="account-amount positive">$<?php echo number_format($ingresos_categoria['productos'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($ingresos_categoria['productos'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Peluquería y Estética</span>
                            <span class="account-amount positive">$<?php echo number_format($ingresos_categoria['peluqueria'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($ingresos_categoria['peluqueria'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Laboratorio</span>
                            <span class="account-amount positive">$<?php echo number_format($ingresos_categoria['laboratorio'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($ingresos_categoria['laboratorio'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row subtotal">
                            <span class="account-name"><strong>TOTAL INGRESOS</strong></span>
                            <span class="account-amount positive"><strong>$<?php echo number_format($total_ingresos, 2); ?></strong></span>
                            <span class="account-percent">100%</span>
                        </div>
                    </div>
                    
                    <!-- Costos -->
                    <div class="statement-category">
                        <div class="category-header">
                            <h4>COSTOS DIRECTOS</h4>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Costo de Productos Vendidos</span>
                            <span class="account-amount negative">$<?php echo number_format($costos_productos, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round(($costos_productos / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row subtotal">
                            <span class="account-name"><strong>TOTAL COSTOS</strong></span>
                            <span class="account-amount negative"><strong>$<?php echo number_format($costos_productos, 2); ?></strong></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round(($costos_productos / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row total">
                            <span class="account-name"><strong>UTILIDAD BRUTA</strong></span>
                            <span class="account-amount positive"><strong>$<?php echo number_format($utilidad_bruta, 2); ?></strong></span>
                            <span class="account-percent"><?php echo $margen_bruto; ?>%</span>
                        </div>
                    </div>
                    
                    <!-- Gastos Operativos -->
                    <div class="statement-category">
                        <div class="category-header">
                            <h4>GASTOS OPERATIVOS</h4>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Sueldos y Salarios</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['sueldos'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['sueldos'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Alquiler y Servicios</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['alquiler_servicios'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['alquiler_servicios'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Suministros</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['suministros'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['suministros'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Marketing y Publicidad</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['marketing'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['marketing'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Gastos Administrativos</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['administrativos'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['administrativos'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row subtotal">
                            <span class="account-name"><strong>TOTAL GASTOS OPERATIVOS</strong></span>
                            <span class="account-amount negative"><strong>$<?php echo number_format($gastos_operativos, 2); ?></strong></span>
                            <span class="account-percent"><?php echo $gastos_sobre_ingresos; ?>%</span>
                        </div>
                        
                        <div class="statement-row total">
                            <span class="account-name"><strong>UTILIDAD OPERATIVA (EBITDA)</strong></span>
                            <span class="account-amount positive"><strong>$<?php echo number_format($utilidad_operativa, 2); ?></strong></span>
                            <span class="account-percent"><?php echo $margen_operativo; ?>%</span>
                        </div>
                    </div>
                    
                    <!-- Gastos No Operativos -->
                    <div class="statement-category">
                        <div class="category-header">
                            <h4>GASTOS NO OPERATIVOS</h4>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Gastos por Intereses</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['intereses'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['intereses'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row">
                            <span class="account-name">Depreciación y Amortización</span>
                            <span class="account-amount negative">$<?php echo number_format($gastos['depreciacion'] ?? 0, 2); ?></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round((($gastos['depreciacion'] ?? 0) / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                        
                        <div class="statement-row subtotal">
                            <span class="account-name"><strong>TOTAL GASTOS NO OPERATIVOS</strong></span>
                            <span class="account-amount negative"><strong>$<?php echo number_format($gastos_no_operativos, 2); ?></strong></span>
                            <span class="account-percent"><?php echo $total_ingresos > 0 ? round(($gastos_no_operativos / $total_ingresos) * 100, 1) : 0; ?>%</span>
                        </div>
                    </div>
                    
                    <!-- Utilidad Neta -->
                    <div class="statement-category">
                        <div class="statement-row grand-total">
                            <span class="account-name"><strong>UTILIDAD NETA DEL PERÍODO</strong></span>
                            <span class="account-amount"><strong>$<?php echo number_format($utilidad_neta, 2); ?></strong></span>
                            <span class="account-percent"><?php echo $margen_neto; ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Análisis de Márgenes -->
            <div class="margins-section fade-in">
                <div class="margins-header">
                    <h3>
                        <i class='bx bx-pie-chart-alt'></i>
                        Análisis de Márgenes
                    </h3>
                </div>
                <div class="margins-grid">
                    <div class="margin-card">
                        <div class="margin-value" style="color: var(--success);"><?php echo $margen_bruto; ?>%</div>
                        <div class="margin-label">Margen Bruto</div>
                        <?php if ($comparar_con !== 'none' && $total_ingresos_prev > 0): ?>
                            <div class="margin-change <?php echo getVariacionClass($var_margen); ?>">
                                <i class='bx bx-<?php echo $var_margen >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo abs($var_margen); ?>% vs anterior
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="margin-card">
                        <div class="margin-value" style="color: var(--info);"><?php echo $margen_operativo; ?>%</div>
                        <div class="margin-label">Margen Operativo</div>
                    </div>
                    
                    <div class="margin-card">
                        <div class="margin-value" style="color: var(--purple);"><?php echo $margen_neto; ?>%</div>
                        <div class="margin-label">Margen Neto</div>
                    </div>
                    
                    <div class="margin-card">
                        <div class="margin-value" style="color: var(--warning);"><?php echo $gastos_sobre_ingresos; ?>%</div>
                        <div class="margin-label">Gastos / Ingresos</div>
                    </div>
                </div>
            </div>

            <!-- Gráficos -->
            <div class="charts-grid fade-in">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>
                            <i class='bx bx-pie-chart'></i>
                            Composición de Ingresos
                        </h3>
                    </div>
                    <div class="chart-body">
                        <div class="chart-container">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>
                            <i class='bx bx-pie-chart'></i>
                            Estructura de Gastos
                        </h3>
                    </div>
                    <div class="chart-body">
                        <div class="chart-container">
                            <canvas id="expensesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tendencias Históricas -->
            <div class="trends-section fade-in">
                <div class="trends-header">
                    <h3>
                        <i class='bx bx-line-chart'></i>
                        Tendencias de Rentabilidad
                    </h3>
                    <span class="badge badge-primary">Últimos 6 meses</span>
                </div>
                <div class="trends-body">
                    <div class="trend-chart-container">
                        <canvas id="trendChart"></canvas>
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
    <script src="assets/vendor/chart.umd.min.js"></script>
    <script>
        // Datos desde PHP
        const ingresosData = {
            servicios_medicos: <?php echo $ingresos_categoria['servicios_medicos'] ?? 0; ?>,
            productos: <?php echo $ingresos_categoria['productos'] ?? 0; ?>,
            peluqueria: <?php echo $ingresos_categoria['peluqueria'] ?? 0; ?>,
            laboratorio: <?php echo $ingresos_categoria['laboratorio'] ?? 0; ?>
        };
        
        const gastosData = {
            sueldos: <?php echo $gastos['sueldos'] ?? 0; ?>,
            alquiler: <?php echo $gastos['alquiler_servicios'] ?? 0; ?>,
            suministros: <?php echo $gastos['suministros'] ?? 0; ?>,
            marketing: <?php echo $gastos['marketing'] ?? 0; ?>,
            administrativos: <?php echo $gastos['administrativos'] ?? 0; ?>
        };
        
        const trendData = {
            meses: <?php echo json_encode($meses); ?>,
            margen_bruto: <?php echo json_encode($margen_bruto_trend); ?>,
            margen_operativo: <?php echo json_encode($margen_operativo_trend); ?>,
            margen_neto: <?php echo json_encode($margen_neto_trend); ?>
        };

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
            animateStats();
            createParticles();
            toggleCustomRange();
        });

        // Toggle para rango personalizado
        function toggleCustomRange() {
            const periodFilter = document.getElementById('periodFilter');
            const customRange = document.getElementById('customDateRange');
            customRange.style.display = periodFilter.value === 'custom' ? 'flex' : 'none';
        }

        // Establecer período rápido
        function setPeriod(period) {
            document.getElementById('periodFilter').value = period;
            document.getElementById('filterForm').submit();
        }

        // Exportar PDF (por ahora solo imprime)
        function exportarPDF() {
            window.print();
        }

        // Inicializar gráficos
        function initCharts() {
            // Gráfico de Ingresos
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            new Chart(revenueCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Servicios Médicos', 'Productos', 'Peluquería', 'Laboratorio'],
                    datasets: [{
                        data: [
                            ingresosData.servicios_medicos,
                            ingresosData.productos,
                            ingresosData.peluqueria,
                            ingresosData.laboratorio
                        ],
                        backgroundColor: [
                            'rgba(46, 125, 50, 0.8)',
                            'rgba(33, 150, 243, 0.8)',
                            'rgba(255, 152, 0, 0.8)',
                            'rgba(156, 39, 176, 0.8)'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: { font: { size: 11 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: (context) => {
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: $${value.toLocaleString()} (${percent}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Gráfico de Gastos (solo mostrar categorías con valores > 0)
            const gastosLabels = [];
            const gastosValues = [];
            const gastosColors = [];
            
            if (gastosData.sueldos > 0) {
                gastosLabels.push('Sueldos');
                gastosValues.push(gastosData.sueldos);
                gastosColors.push('rgba(244, 67, 54, 0.8)');
            }
            if (gastosData.alquiler > 0) {
                gastosLabels.push('Alquiler');
                gastosValues.push(gastosData.alquiler);
                gastosColors.push('rgba(255, 152, 0, 0.8)');
            }
            if (gastosData.suministros > 0) {
                gastosLabels.push('Suministros');
                gastosValues.push(gastosData.suministros);
                gastosColors.push('rgba(33, 150, 243, 0.8)');
            }
            if (gastosData.marketing > 0) {
                gastosLabels.push('Marketing');
                gastosValues.push(gastosData.marketing);
                gastosColors.push('rgba(156, 39, 176, 0.8)');
            }
            if (gastosData.administrativos > 0) {
                gastosLabels.push('Administrativos');
                gastosValues.push(gastosData.administrativos);
                gastosColors.push('rgba(76, 175, 80, 0.8)');
            }
            
            // Si no hay gastos, mostrar un mensaje
            if (gastosValues.length === 0) {
                gastosLabels.push('Sin gastos');
                gastosValues.push(1);
                gastosColors.push('rgba(200,200,200,0.8)');
            }
            
            const expensesCtx = document.getElementById('expensesChart').getContext('2d');
            new Chart(expensesCtx, {
                type: 'pie',
                data: {
                    labels: gastosLabels,
                    datasets: [{
                        data: gastosValues,
                        backgroundColor: gastosColors,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: { font: { size: 11 } }
                        }
                    }
                }
            });

            // Gráfico de Tendencias
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: trendData.meses,
                    datasets: [
                        {
                            label: 'Margen Bruto',
                            data: trendData.margen_bruto,
                            borderColor: '#4CAF50',
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#4CAF50'
                        },
                        {
                            label: 'Margen Operativo',
                            data: trendData.margen_operativo,
                            borderColor: '#2196F3',
                            backgroundColor: 'rgba(33, 150, 243, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#2196F3'
                        },
                        {
                            label: 'Margen Neto',
                            data: trendData.margen_neto,
                            borderColor: '#9C27B0',
                            backgroundColor: 'rgba(156, 39, 176, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#9C27B0'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: { font: { size: 11 } }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: (context) => `${context.dataset.label}: ${context.raw}%`
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { 
                                callback: (value) => value + '%',
                                stepSize: 20
                            },
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        // Animar estadísticas
        function animateStats() {
            const summaryValues = document.querySelectorAll('.summary-value');
            summaryValues.forEach(stat => {
                const text = stat.textContent;
                const numberMatch = text.match(/[\d,.]+/);
                
                if (numberMatch) {
                    const number = parseFloat(numberMatch[0].replace(/,/g, ''));
                    
                    if (!isNaN(number) && number > 0 && !text.includes('/')) {
                        let current = 0;
                        const increment = number / 30;
                        const originalText = text;
                        const timer = setInterval(() => {
                            current += increment;
                            if (current >= number) {
                                current = number;
                                clearInterval(timer);
                            }
                            if (originalText.includes('$')) {
                                stat.textContent = originalText.replace(numberMatch[0], '$' + current.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
                            } else if (originalText.includes('%')) {
                                stat.textContent = originalText.replace(numberMatch[0], current.toFixed(1));
                            } else {
                                stat.textContent = originalText.replace(numberMatch[0], Math.floor(current).toLocaleString());
                            }
                        }, 20);
                    }
                }
            });
        }

        // Crear partículas
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
    </script>
</body>
</html>