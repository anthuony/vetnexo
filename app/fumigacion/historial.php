<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_staff();


// Verificar si el usuario está logueado (versión compatible con ambos formatos)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit();
}

// Definir variables de sesión de forma consistente
$user_id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? 'usuario';
$usuario_email = $_SESSION['email'] ?? $_SESSION['usuario_email'] ?? '';

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas');

// Incluir configuración de base de datos
include_once '../config/database.php';

$database = new Database();
$db = $database->conectar();

// Variables para filtros
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$filtro_hasta = $_GET['hasta'] ?? date('Y-m-d');
$busqueda = $_GET['busqueda'] ?? '';

// Estadísticas generales
$estadisticas = [
    'total_inspecciones' => 0,
    'inspecciones_realizadas' => 0,
    'inspecciones_canceladas' => 0,
    'total_fumigaciones' => 0,
    'fumigaciones_completadas' => 0,
    'fumigaciones_activas' => 0,
    'total_sesiones' => 0,
    'ingresos_totales' => 0,
    'clientes_atendidos' => 0
];

// Obtener historial de inspecciones
$inspecciones = [];
$fumigaciones = [];

try {
    // Estadísticas de inspecciones
    $sql_inspecciones_stats = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN estado = 'Realizada' THEN 1 ELSE 0 END) as realizadas,
                                SUM(CASE WHEN estado = 'Cancelada' THEN 1 ELSE 0 END) as canceladas
                              FROM inspecciones_fumigacion
                              WHERE fecha_inspeccion BETWEEN ? AND ?";
    $stmt = $db->prepare($sql_inspecciones_stats);
    $stmt->execute([$filtro_desde, $filtro_hasta]);
    $stats_inspecciones = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $estadisticas['total_inspecciones'] = (int)($stats_inspecciones['total'] ?? 0);
    $estadisticas['inspecciones_realizadas'] = (int)($stats_inspecciones['realizadas'] ?? 0);
    $estadisticas['inspecciones_canceladas'] = (int)($stats_inspecciones['canceladas'] ?? 0);
    
    // Estadísticas de fumigaciones con JOIN para obtener datos correctos
    $sql_fumigaciones_stats = "SELECT 
                                COUNT(DISTINCT fr.id) as total,
                                SUM(CASE WHEN fr.sesiones_completadas = fr.numero_sesiones THEN 1 ELSE 0 END) as completadas,
                                SUM(CASE WHEN fr.sesiones_completadas < fr.numero_sesiones THEN 1 ELSE 0 END) as activas,
                                SUM(fr.sesiones_completadas) as total_sesiones,
                                SUM(fr.costo_total) as ingresos,
                                COUNT(DISTINCT fr.cliente_id) as clientes
                              FROM fumigaciones_realizadas fr
                              WHERE DATE(fr.fecha_registro) BETWEEN ? AND ?";
    $stmt = $db->prepare($sql_fumigaciones_stats);
    $stmt->execute([$filtro_desde, $filtro_hasta]);
    $stats_fumigaciones = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $estadisticas['total_fumigaciones'] = (int)($stats_fumigaciones['total'] ?? 0);
    $estadisticas['fumigaciones_completadas'] = (int)($stats_fumigaciones['completadas'] ?? 0);
    $estadisticas['fumigaciones_activas'] = (int)($stats_fumigaciones['activas'] ?? 0);
    $estadisticas['total_sesiones'] = (int)($stats_fumigaciones['total_sesiones'] ?? 0);
    $estadisticas['ingresos_totales'] = (float)($stats_fumigaciones['ingresos'] ?? 0);
    $estadisticas['clientes_atendidos'] = (int)($stats_fumigaciones['clientes'] ?? 0);
    
    // Obtener historial de inspecciones
    $sql_inspecciones = "SELECT 
                            i.*,
                            DATE_FORMAT(i.fecha_inspeccion, '%d/%m/%Y') as fecha_formateada,
                            DATE_FORMAT(i.fecha_solicitud, '%d/%m/%Y') as solicitud_formateada,
                            CASE 
                                WHEN f.id IS NOT NULL THEN 'Aceptada'
                                ELSE 'Sin servicio'
                            END as resultado
                         FROM inspecciones_fumigacion i
                         LEFT JOIN fumigaciones_realizadas f ON i.id = f.inspeccion_id
                         WHERE i.fecha_inspeccion BETWEEN ? AND ?
                         ORDER BY i.fecha_inspeccion DESC, i.fecha_solicitud DESC";
    
    $params_inspecciones = [$filtro_desde, $filtro_hasta];
    
    if (!empty($busqueda)) {
        $sql_inspecciones = "SELECT 
                                i.*,
                                DATE_FORMAT(i.fecha_inspeccion, '%d/%m/%Y') as fecha_formateada,
                                DATE_FORMAT(i.fecha_solicitud, '%d/%m/%Y') as solicitud_formateada,
                                CASE 
                                    WHEN f.id IS NOT NULL THEN 'Aceptada'
                                    ELSE 'Sin servicio'
                                END as resultado
                             FROM inspecciones_fumigacion i
                             LEFT JOIN fumigaciones_realizadas f ON i.id = f.inspeccion_id
                             WHERE i.fecha_inspeccion BETWEEN ? AND ?
                               AND (i.nombre_cliente LIKE ? OR i.telefono LIKE ? OR i.direccion LIKE ?)
                             ORDER BY i.fecha_inspeccion DESC, i.fecha_solicitud DESC";
        $params_inspecciones[] = "%$busqueda%";
        $params_inspecciones[] = "%$busqueda%";
        $params_inspecciones[] = "%$busqueda%";
    }
    
    $stmt = $db->prepare($sql_inspecciones);
    $stmt->execute($params_inspecciones);
    $inspecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener historial de fumigaciones con JOIN a clientes y servicios
    $sql_fumigaciones = "SELECT 
                            fr.*,
                            c.nombre as nombre_cliente,
                            c.telefono,
                            c.direccion,
                            s.nombre_servicio as tipo_servicio,
                            s.precio_base,
                            DATE_FORMAT(fr.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
                            DATE_FORMAT(fr.fecha_programada, '%d/%m/%Y') as fecha_programada_formateada,
                            i.fecha_inspeccion,
                            DATE_FORMAT(i.fecha_inspeccion, '%d/%m/%Y') as fecha_inspeccion_formateada,
                            ROUND((fr.sesiones_completadas * 100 / NULLIF(fr.numero_sesiones, 0)), 1) as porcentaje_completado,
                            (SELECT COUNT(*) FROM agenda_fumigaciones WHERE fumigacion_id = fr.id) as total_sesiones_agendadas
                         FROM fumigaciones_realizadas fr
                         LEFT JOIN clientes c ON fr.cliente_id = c.id
                         LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
                         LEFT JOIN inspecciones_fumigacion i ON fr.inspeccion_id = i.id
                         WHERE DATE(fr.fecha_registro) BETWEEN ? AND ?
                         ORDER BY fr.fecha_registro DESC";
    
    $params_fumigaciones = [$filtro_desde, $filtro_hasta];
    
    if (!empty($busqueda)) {
        $sql_fumigaciones = "SELECT 
                                fr.*,
                                c.nombre as nombre_cliente,
                                c.telefono,
                                c.direccion,
                                s.nombre_servicio as tipo_servicio,
                                s.precio_base,
                                DATE_FORMAT(fr.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
                                DATE_FORMAT(fr.fecha_programada, '%d/%m/%Y') as fecha_programada_formateada,
                                i.fecha_inspeccion,
                                DATE_FORMAT(i.fecha_inspeccion, '%d/%m/%Y') as fecha_inspeccion_formateada,
                                ROUND((fr.sesiones_completadas * 100 / NULLIF(fr.numero_sesiones, 0)), 1) as porcentaje_completado,
                                (SELECT COUNT(*) FROM agenda_fumigaciones WHERE fumigacion_id = fr.id) as total_sesiones_agendadas
                             FROM fumigaciones_realizadas fr
                             LEFT JOIN clientes c ON fr.cliente_id = c.id
                             LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
                             LEFT JOIN inspecciones_fumigacion i ON fr.inspeccion_id = i.id
                             WHERE DATE(fr.fecha_registro) BETWEEN ? AND ?
                               AND (c.nombre LIKE ? OR c.telefono LIKE ? OR c.direccion LIKE ? OR s.nombre_servicio LIKE ?)
                             ORDER BY fr.fecha_registro DESC";
        $params_fumigaciones[] = "%$busqueda%";
        $params_fumigaciones[] = "%$busqueda%";
        $params_fumigaciones[] = "%$busqueda%";
        $params_fumigaciones[] = "%$busqueda%";
    }
    
    $stmt = $db->prepare($sql_fumigaciones);
    $stmt->execute($params_fumigaciones);
    $fumigaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_msg = "Error al cargar el historial: " . $e->getMessage();
}

// Función helper para obtener color según rol
function getRolColor($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return '#F99B07';
        case 'veterinario':
        case 'veterinarian':
            return '#0F6C33';
        case 'asistente':
            return '#2261BD';
        default:
            return '#5A4B37';
    }
}

// Función helper para texto del rol
function getRolTexto($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return 'Administrador';
        case 'veterinario':
        case 'veterinarian':
            return 'Veterinario';
        case 'asistente':
            return 'Asistente';
        default:
            return ucfirst($rol);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial - Fumigación - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="../css/sidebar.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0F6C33;        /* Verde Bosque */
            --primary-dark: #0a4f25;    /* Verde Bosque Oscuro */
            --primary-light: #2e8b57;   /* Verde más claro */
            --primary-soft: #e8f5e9;    /* Verde muy suave */
            
            --accent: #F99B07;           /* Naranja Vibrante */
            --accent-light: #ffb84d;     /* Naranja claro */
            --accent-soft: #fff3e0;      /* Naranja suave */
            --accent-dark: #d88206;       /* Naranja oscuro */
            
            --emergency: #F91302;         /* Rojo Corazón */
            --emergency-dark: #c00f02;    /* Rojo oscuro */
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            
            --blanco: #FFFFFF;             /* Blanco Puro */
            
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --text-lighter: #888888;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --danger: #F91302;
            --warning: #FBEF16;
            --success: #0F6C33;
            --info: #2261BD;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow: 0 5px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
            --radius-sm: 8px;
            --radius: 16px;
            --radius-lg: 24px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--background);
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
            min-height: 100vh;
        }

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

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            background: var(--primary-soft);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            animation: fadeIn 0.7s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            transition: width 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover::before {
            width: 6px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--primary);
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-dark);
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            flex-wrap: wrap;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .filters-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .filters-body {
            padding: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--white);
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.1);
        }

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .search-group {
            flex: 2;
            min-width: 250px;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(15, 108, 51, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, var(--azul-light), #3a6ab8);
            transform: translateY(-2px);
        }

        .tabs-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.9s ease;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid var(--primary-soft);
            background: var(--white);
        }

        .tab-btn {
            padding: 1rem 2rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 3px solid transparent;
        }

        .tab-btn:hover {
            color: var(--primary);
            background: var(--primary-soft);
        }

        .tab-btn.active {
            color: var(--primary-dark);
            border-bottom-color: var(--primary);
            background: var(--primary-soft);
        }

        .tab-pane {
            padding: 1.5rem;
            display: none;
        }

        .tab-pane.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-warning {
            background: #fff3e0;
            color: var(--accent);
        }

        .badge-danger {
            background: #ffebee;
            color: var(--emergency);
        }

        .badge-info {
            background: #e3f2fd;
            color: var(--azul);
        }

        .badge-realizada {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-cancelada {
            background: #ffebee;
            color: var(--emergency);
        }

        .badge-pendiente {
            background: #fff3e0;
            color: var(--accent);
        }

        .badge-activa {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-completada {
            background: #e3f2fd;
            color: var(--azul);
        }

        .badge-aceptada {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-sin-servicio {
            background: #f5f5f5;
            color: var(--text-lighter);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 1rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: var(--primary-soft);
        }

        .progress-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 120px;
        }

        .progress-bar {
            flex: 1;
            height: 6px;
            background: var(--gray-light);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .btn-view {
            padding: 0.3rem 0.6rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
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
            background: rgba(15, 108, 51, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(180deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content { margin-left: 250px; padding: 1.5rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1rem; }
            .dashboard-header { flex-direction: column; gap: 1.5rem; text-align: center; }
            .header-actions { flex-direction: column; width: 100%; }
            .header-actions .back-btn { width: 100%; justify-content: center; }
            .stats-grid { grid-template-columns: 1fr; }
            .filters-body { flex-direction: column; }
            .filter-group, .search-group { width: 100%; }
            .filter-actions { width: 100%; justify-content: stretch; }
            .btn { flex: 1; justify-content: center; }
            .tabs-header { flex-direction: column; }
            .tab-btn { width: 100%; justify-content: center; }
            .empty-actions { flex-direction: column; }
        }
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Partículas de fondo -->
    <div class="particles">
        <div class="particle" style="top: 20%; left: 10%; width: 8px; height: 8px; animation-delay: 0s;"></div>
        <div class="particle" style="top: 60%; left: 80%; width: 12px; height: 12px; animation-delay: 1s;"></div>
        <div class="particle" style="top: 40%; left: 90%; width: 6px; height: 6px; animation-delay: 2s;"></div>
        <div class="particle" style="top: 80%; left: 20%; width: 10px; height: 10px; animation-delay: 3s;"></div>
    </div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-history'></i>
                        Historial de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Consulta el historial completo de inspecciones y fumigaciones
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="index.php" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver al Dashboard
                    </a>
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
                </div>
            </header>

            <!-- Mensaje de error -->
            <?php if (isset($error_msg)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div style="background: #ffebee; color: var(--emergency); padding: 1rem; border-radius: var(--radius-sm);">
                            <i class='bx bx-error-circle'></i> <?php echo $error_msg; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-search-alt'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $estadisticas['total_inspecciones']; ?></div>
                        <div class="stat-label">Inspecciones Totales</div>
                        <div class="stat-trend">
                            <span style="color: var(--success);"><?php echo $estadisticas['inspecciones_realizadas']; ?> realizadas</span>
                            <span style="color: var(--danger);"><?php echo $estadisticas['inspecciones_canceladas']; ?> canceladas</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-spray-can'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $estadisticas['total_fumigaciones']; ?></div>
                        <div class="stat-label">Fumigaciones Totales</div>
                        <div class="stat-trend">
                            <span style="color: var(--success);"><?php echo $estadisticas['fumigaciones_completadas']; ?> completadas</span>
                            <span style="color: var(--accent);"><?php echo $estadisticas['fumigaciones_activas']; ?> activas</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-calendar-check'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $estadisticas['total_sesiones']; ?></div>
                        <div class="stat-label">Sesiones Realizadas</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-dollar-circle'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">$<?php echo number_format($estadisticas['ingresos_totales'], 2); ?></div>
                        <div class="stat-label">Ingresos Totales</div>
                        <div class="stat-trend">
                            <span style="color: var(--info);"><?php echo $estadisticas['clientes_atendidos']; ?> clientes</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Búsqueda
                    </h2>
                </div>
                <div class="filters-body">
                    <div class="filter-group">
                        <label class="filter-label">Desde</label>
                        <input type="date" id="desde" name="desde" class="form-control" value="<?php echo $filtro_desde; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Hasta</label>
                        <input type="date" id="hasta" name="hasta" class="form-control" value="<?php echo $filtro_hasta; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Tipo</label>
                        <select id="tipo" name="tipo" class="form-control select">
                            <option value="todos" <?php echo $filtro_tipo == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="inspecciones" <?php echo $filtro_tipo == 'inspecciones' ? 'selected' : ''; ?>>Solo Inspecciones</option>
                            <option value="fumigaciones" <?php echo $filtro_tipo == 'fumigaciones' ? 'selected' : ''; ?>>Solo Fumigaciones</option>
                        </select>
                    </div>
                    
                    <div class="search-group">
                        <label class="filter-label">Buscar</label>
                        <input type="text" id="busqueda" name="busqueda" class="form-control" 
                               placeholder="Cliente, teléfono, dirección..." 
                               value="<?php echo htmlspecialchars($busqueda); ?>">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="aplicarFiltros()">
                            <i class='bx bx-filter-alt'></i> Filtrar
                        </button>
                        <a href="historial.php" class="btn btn-secondary">
                            <i class='bx bx-reset'></i> Limpiar
                        </a>
                        <button type="button" class="btn btn-info" onclick="exportarHistorial()">
                            <i class='bx bx-download'></i> Exportar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs de Historial -->
            <div class="tabs-container fade-in">
                <div class="tabs-header">
                    <button class="tab-btn <?php echo $filtro_tipo == 'todos' || $filtro_tipo == 'inspecciones' ? 'active' : ''; ?>" onclick="cambiarTab('inspecciones')">
                        <i class='bx bx-search-alt'></i>
                        Inspecciones (<?php echo count($inspecciones); ?>)
                    </button>
                    <button class="tab-btn <?php echo $filtro_tipo == 'fumigaciones' ? 'active' : ''; ?>" onclick="cambiarTab('fumigaciones')">
                        <i class='bx bx-spray-can'></i>
                        Fumigaciones (<?php echo count($fumigaciones); ?>)
                    </button>
                </div>

                <!-- Tab de Inspecciones -->
                <div class="tab-pane <?php echo $filtro_tipo == 'todos' || $filtro_tipo == 'inspecciones' ? 'active' : ''; ?>" id="tab-inspecciones">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class='bx bx-search-alt'></i>
                            Historial de Inspecciones
                        </h3>
                        <span class="badge badge-info">Total: <?php echo count($inspecciones); ?></span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th>Dirección</th>
                                    <th>Estado</th>
                                    <th>Resultado</th>
                                    <th>Observaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($inspecciones) > 0): ?>
                                    <?php foreach ($inspecciones as $ins): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $ins['fecha_formateada']; ?></strong>
                                                <?php if (!empty($ins['hora_inspeccion'])): ?>
                                                    <br><small style="color: var(--text-light);"><?php echo $ins['hora_inspeccion']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ins['nombre_cliente'] ?? ''); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($ins['telefono'] ?? ''); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($ins['direccion'] ?? ''); ?>
                                                <?php if (!empty($ins['urbanizacion'])): ?>
                                                    <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($ins['urbanizacion']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = 'badge-pendiente';
                                                $estado_text = 'Pendiente';
                                                
                                                if ($ins['estado'] == 'Realizada') {
                                                    $badge_class = 'badge-realizada';
                                                    $estado_text = 'Realizada';
                                                } elseif ($ins['estado'] == 'Cancelada') {
                                                    $badge_class = 'badge-cancelada';
                                                    $estado_text = 'Cancelada';
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $estado_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($ins['resultado'] == 'Aceptada'): ?>
                                                    <span class="badge badge-aceptada">
                                                        <i class='bx bx-check'></i> Aceptada
                                                    </span>
                                                <?php elseif ($ins['estado'] == 'Realizada'): ?>
                                                    <span class="badge badge-sin-servicio">
                                                        <i class='bx bx-x'></i> Sin servicio
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-pendiente">Pendiente</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($ins['observaciones'])): ?>
                                                    <small><?php echo htmlspecialchars(substr($ins['observaciones'], 0, 30)) . (strlen($ins['observaciones']) > 30 ? '...' : ''); ?></small>
                                                <?php else: ?>
                                                    <small style="color: var(--text-lighter);">-</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class='bx bx-search-alt'></i>
                                                <h3>No hay inspecciones</h3>
                                                <p>No se encontraron inspecciones en el período seleccionado.</p>
                                                <div class="empty-actions">
                                                    <a href="nueva_inspeccion.php" class="btn btn-primary">
                                                        <i class='bx bx-plus'></i> Nueva Inspección
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab de Fumigaciones -->
                <div class="tab-pane <?php echo $filtro_tipo == 'fumigaciones' ? 'active' : ''; ?>" id="tab-fumigaciones">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class='bx bx-spray-can'></i>
                            Historial de Fumigaciones
                        </h3>
                        <span class="badge badge-info">Total: <?php echo count($fumigaciones); ?></span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha Inicio</th>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th>Servicio</th>
                                    <th>Progreso</th>
                                    <th>Sesiones</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($fumigaciones) > 0): ?>
                                    <?php foreach ($fumigaciones as $fum): 
                                        $activa = ($fum['sesiones_completadas'] ?? 0) < ($fum['numero_sesiones'] ?? 0);
                                        $porcentaje = isset($fum['porcentaje_completado']) ? round($fum['porcentaje_completado']) : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $fum['fecha_registro_formateada'] ?? ''; ?></strong>
                                                <?php if (!empty($fum['fecha_inspeccion_formateada'])): ?>
                                                    <br><small style="color: var(--text-light);">Inspección: <?php echo $fum['fecha_inspeccion_formateada']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($fum['nombre_cliente'] ?? 'Cliente'); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($fum['telefono'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($fum['tipo_servicio'] ?? 'Servicio'); ?>
                                                <br><small style="color: var(--text-light);">$<?php echo number_format((float)($fum['precio_sesion'] ?? 0), 2); ?>/sesión</small>
                                            </td>
                                            <td>
                                                <div class="progress-container">
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%;"></div>
                                                    </div>
                                                    <span class="progress-text">
                                                        <?php echo $porcentaje; ?>%
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo ($fum['sesiones_completadas'] ?? 0); ?>/<?php echo ($fum['numero_sesiones'] ?? 0); ?>
                                                <?php if (($fum['total_sesiones_agendadas'] ?? 0) > 0): ?>
                                                    <br><small style="color: var(--text-light);"><?php echo $fum['total_sesiones_agendadas']; ?> agendadas</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong>$<?php echo number_format((float)($fum['costo_total'] ?? 0), 2); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $activa ? 'badge-activa' : 'badge-completada'; ?>">
                                                    <?php echo $activa ? 'Activa' : 'Completada'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="ver_fumigacion.php?id=<?php echo $fum['id']; ?>" class="btn-view" title="Ver detalles">
                                                    <i class='bx bx-show'></i> Ver
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i class='bx bx-spray-can'></i>
                                                <h3>No hay fumigaciones</h3>
                                                <p>No se encontraron fumigaciones en el período seleccionado.</p>
                                                <div class="empty-actions">
                                                    <a href="nueva_inspeccion.php" class="btn btn-primary">
                                                        <i class='bx bx-plus'></i> Nueva Inspección
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

    <script src="../js/sidebar.js"></script>
    <script>
        function aplicarFiltros() {
            const desde = document.getElementById('desde').value;
            const hasta = document.getElementById('hasta').value;
            const tipo = document.getElementById('tipo').value;
            const busqueda = document.getElementById('busqueda').value;
            
            let url = 'historial.php?';
            const params = [];
            
            if (desde) params.push(`desde=${desde}`);
            if (hasta) params.push(`hasta=${hasta}`);
            if (tipo && tipo !== 'todos') params.push(`tipo=${tipo}`);
            if (busqueda) params.push(`busqueda=${encodeURIComponent(busqueda)}`);
            
            window.location.href = url + params.join('&');
        }

        function cambiarTab(tab) {
            const tipo = document.getElementById('tipo');
            if (tab === 'inspecciones') {
                tipo.value = 'inspecciones';
            } else {
                tipo.value = 'fumigaciones';
            }
            aplicarFiltros();
        }

        function exportarHistorial() {
            const desde = document.getElementById('desde').value;
            const hasta = document.getElementById('hasta').value;
            const tipo = document.getElementById('tipo').value;
            const busqueda = document.getElementById('busqueda').value;
            
            // Aquí puedes implementar la lógica para exportar a Excel/PDF
            window.print();
        }

        document.getElementById('busqueda').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                aplicarFiltros();
            }
        });

        // Validar fechas
        document.getElementById('desde').addEventListener('change', function() {
            const hasta = document.getElementById('hasta');
            if (this.value && hasta.value && this.value > hasta.value) {
                hasta.value = this.value;
            }
        });

        document.getElementById('hasta').addEventListener('change', function() {
            const desde = document.getElementById('desde');
            if (this.value && desde.value && this.value < desde.value) {
                desde.value = this.value;
            }
        });

        // Animación de contadores
        document.addEventListener('DOMContentLoaded', function() {
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.]/g, ''));
                
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
                            stat.textContent = '$' + current.toFixed(2);
                        } else {
                            stat.textContent = Math.floor(current);
                        }
                    }, 30);
                }
            });

            // Crear partículas dinámicas
            const particlesContainer = document.querySelector('.particles');
            if (particlesContainer) {
                for (let i = 0; i < 15; i++) {
                    const particle = document.createElement('div');
                    particle.className = 'particle';
                    particle.style.cssText = `
                        top: ${Math.random() * 100}%;
                        left: ${Math.random() * 100}%;
                        width: ${Math.random() * 10 + 5}px;
                        height: ${Math.random() * 10 + 5}px;
                        animation-delay: ${Math.random() * 5}s;
                        opacity: ${Math.random() * 0.5 + 0.1};
                        background: ${Math.random() > 0.5 ? 'rgba(15, 108, 51, 0.05)' : 'rgba(249, 155, 7, 0.05)'};
                    `;
                    particlesContainer.appendChild(particle);
                }
            }
        });
    </script>
</body>
</html>