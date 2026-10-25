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

// Porcentajes de ganancia (ajustables)
$porcentaje_tecnico = 60;
$porcentaje_empresa = 40;

// Inicializar variables
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$estadisticas = [];
$servicios_detallados = [];
$tecnicos_ganancias = [];
$ganancias_por_tecnico = [];

// Obtener estadísticas de fumigaciones completadas
try {
    // Estadísticas generales
    $sql = "
        SELECT 
            COUNT(*) as total_fumigaciones,
            SUM(fr.sesiones_completadas) as total_sesiones,
            SUM(fr.costo_total) as ingresos_totales,
            AVG(fr.costo_total) as promedio_por_fumigacion,
            MIN(fr.costo_total) as fumigacion_minima,
            MAX(fr.costo_total) as fumigacion_maxima,
            COUNT(DISTINCT fr.cliente_id) as clientes_unicos
        FROM fumigaciones_realizadas fr
        WHERE DATE(fr.fecha_registro) BETWEEN ? AND ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$fecha_inicio, $fecha_fin]);
    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular ganancias distribuidas
    if ($estadisticas['ingresos_totales']) {
        $estadisticas['ganancia_tecnico'] = $estadisticas['ingresos_totales'] * ($porcentaje_tecnico / 100);
        $estadisticas['ganancia_empresa'] = $estadisticas['ingresos_totales'] * ($porcentaje_empresa / 100);
    } else {
        $estadisticas['ganancia_tecnico'] = 0;
        $estadisticas['ganancia_empresa'] = 0;
        $estadisticas['total_fumigaciones'] = 0;
        $estadisticas['total_sesiones'] = 0;
        $estadisticas['ingresos_totales'] = 0;
    }
    
    // Obtener servicios detallados con información de cliente y técnico
    $sql_detalles = "
        SELECT 
            fr.id as fumigacion_id,
            fr.fecha_registro,
            fr.fecha_programada,
            fr.fecha_realizada,
            fr.costo_total,
            fr.precio_sesion,
            fr.numero_sesiones,
            fr.sesiones_completadas,
            fr.tecnico_asignado,
            c.nombre as nombre_cliente,
            c.telefono,
            s.nombre_servicio as servicio_nombre,
            s.descripcion,
            DATE_FORMAT(fr.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
            CASE 
                WHEN fr.sesiones_completadas = fr.numero_sesiones THEN 'Completada'
                ELSE 'En Proceso'
            END as estado_servicio,
            (fr.costo_total / NULLIF(fr.numero_sesiones, 0)) as precio_promedio_sesion
        FROM fumigaciones_realizadas fr
        LEFT JOIN clientes c ON fr.cliente_id = c.id
        LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
        WHERE DATE(fr.fecha_registro) BETWEEN ? AND ?
        ORDER BY fr.fecha_registro DESC
    ";
    
    $stmt_detalles = $db->prepare($sql_detalles);
    $stmt_detalles->execute([$fecha_inicio, $fecha_fin]);
    $servicios_detallados = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular ganancias por técnico
    $sql_tecnicos = "
        SELECT 
            fr.tecnico_asignado,
            COUNT(*) as total_fumigaciones,
            SUM(fr.sesiones_completadas) as total_sesiones,
            SUM(fr.costo_total) as ingresos_totales,
            AVG(fr.costo_total) as promedio_por_fumigacion
        FROM fumigaciones_realizadas fr
        WHERE DATE(fr.fecha_registro) BETWEEN ? AND ?
        AND fr.tecnico_asignado IS NOT NULL
        AND fr.tecnico_asignado != ''
        GROUP BY fr.tecnico_asignado
        ORDER BY ingresos_totales DESC
    ";
    
    $stmt_tecnicos = $db->prepare($sql_tecnicos);
    $stmt_tecnicos->execute([$fecha_inicio, $fecha_fin]);
    $tecnicos_ganancias = $stmt_tecnicos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular ganancias por técnico según porcentaje
    foreach ($tecnicos_ganancias as $tecnico) {
        $ganancias_por_tecnico[$tecnico['tecnico_asignado']] = [
            'total_fumigaciones' => $tecnico['total_fumigaciones'],
            'total_sesiones' => $tecnico['total_sesiones'],
            'ingresos_totales' => $tecnico['ingresos_totales'],
            'ganancia_tecnico' => $tecnico['ingresos_totales'] * ($porcentaje_tecnico / 100),
            'ganancia_empresa' => $tecnico['ingresos_totales'] * ($porcentaje_empresa / 100),
            'promedio_por_fumigacion' => $tecnico['promedio_por_fumigacion']
        ];
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
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
    <title>Ganancias de Fumigación - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <!-- Incluir las bibliotecas para PDF -->
    <script src="../assets/vendor/html2canvas.min.js"></script>
    <script src="../assets/vendor/jspdf.umd.min.js"></script>
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
            --emergency-soft: #ffebee;    /* Rojo suave */
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            --amarillo-soft: #fff9c4;     /* Amarillo suave */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            --marron-soft: #f5f0e8;       /* Marrón suave */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            --azul-soft: #e3f2fd;         /* Azul suave */
            
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

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .section-body {
            padding: 1.5rem;
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
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .stat-card.tecnico::before {
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
        }

        .stat-card.empresa::before {
            background: linear-gradient(90deg, var(--success), var(--primary));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.tecnico .stat-icon {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-card.empresa .stat-icon {
            background: var(--primary-soft);
            color: var(--success);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.tecnico .stat-number {
            color: var(--accent-dark);
        }

        .stat-card.empresa .stat-number {
            color: var(--primary-dark);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
            color: var(--text-lighter);
        }

        .distribution-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .distribution-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .distribution-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .distribution-body {
            padding: 1.5rem;
        }

        .distribution-title {
            text-align: center;
            margin-bottom: 2rem;
        }

        .distribution-title h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .distribution-bars {
            display: flex;
            height: 60px;
            border-radius: 30px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .bar-tecnico {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .bar-empresa {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .distribution-labels {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .distribution-label {
            text-align: center;
            flex: 1;
            padding: 1.5rem;
            background: var(--primary-soft);
            border-radius: var(--radius);
            border: 1px solid var(--primary-light);
        }

        .label-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .label-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .label-tecnico .label-amount {
            color: var(--accent-dark);
        }

        .label-percentage {
            font-size: 0.9rem;
            color: var(--text-light);
            font-weight: 600;
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

        .badge-info {
            background: #e3f2fd;
            color: var(--azul);
        }

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
            border-bottom: 2px solid var(--primary-light);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-light);
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .data-table tr:hover {
            background: var(--primary-soft);
        }

        .data-table tfoot td {
            background: var(--primary-soft);
            font-weight: 600;
            border-top: 2px solid var(--primary-light);
        }

        .tecnico-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .tecnico-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            border-left: 4px solid var(--accent);
        }

        .tecnico-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .tecnico-nombre {
            font-weight: 600;
            color: var(--accent-dark);
            font-size: 1rem;
        }

        .tecnico-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .tecnico-stat-item {
            font-size: 0.85rem;
        }

        .tecnico-stat-label {
            color: var(--text-light);
        }

        .tecnico-stat-value {
            font-weight: 600;
            color: var(--text-dark);
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
            margin: 0 0 0.5rem 0;
            color: var(--text-dark);
            font-size: 1.2rem;
        }

        .empty-state p {
            margin: 0 0 1.5rem 0;
            font-size: 0.9rem;
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
            
            .dashboard-header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
                padding: 1.5rem;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .back-btn,
            .header-actions .btn-primary {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .distribution-labels {
                flex-direction: column;
            }
            
            .tecnico-stats {
                grid-template-columns: 1fr;
            }
            
            .empty-actions {
                flex-direction: column;
            }
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
                        <i class='bx bx-spray-can'></i>
                        Ganancias de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Distribución de ganancias del módulo de fumigación
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="index.php" class="btn btn-info">
                        <i class='bx bx-home'></i> Dashboard
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
            <?php if (isset($error)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div style="background: #ffebee; color: var(--emergency); padding: 1rem; border-radius: var(--radius-sm);">
                            <i class='bx bx-error-circle'></i> <?php echo $error; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter-alt'></i>
                        Filtrar por Período
                    </h2>
                </div>
                <div class="filters-body">
                    <form method="GET" action="" class="filter-form">
                        <div class="form-group">
                            <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" 
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($fecha_inicio); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="fecha_fin" class="form-label">Fecha Fin</label>
                            <input type="date" id="fecha_fin" name="fecha_fin" 
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($fecha_fin); ?>" required>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-filter'></i>
                                Aplicar Filtros
                            </button>
                        </div>
                        <div class="form-group">
                            <a href="ganancias.php" class="btn btn-secondary">
                                <i class='bx bx-reset'></i>
                                Limpiar
                            </a>
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
                                <i class='bx bx-calendar'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['total_fumigaciones'] ?? 0; ?></div>
                        <div class="stat-label">Fumigaciones Totales</div>
                        <div class="stat-trend">
                            <i class='bx bx-time'></i>
                            <?php echo $estadisticas['total_sesiones'] ?? 0; ?> sesiones realizadas
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ingresos Totales</div>
                        <div class="stat-trend">
                            <i class='bx bx-trending-up'></i>
                            Promedio: $<?php echo number_format($estadisticas['promedio_por_fumigacion'] ?? 0, 2); ?>
                        </div>
                    </div>
                    
                    <div class="stat-card tecnico">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-user'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ganancia_tecnico'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ganancia de Técnicos</div>
                        <div class="stat-trend">
                            <i class='bx bx-time'></i>
                            <?php echo $porcentaje_tecnico; ?>% del total
                        </div>
                    </div>
                    
                    <div class="stat-card empresa">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-building'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ganancia_empresa'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ganancia de la Empresa</div>
                        <div class="stat-trend">
                            <i class='bx bx-time'></i>
                            <?php echo $porcentaje_empresa; ?>% del total
                        </div>
                    </div>
                </div>
            </div>

            <!-- Distribución de Ganancias -->
            <div class="distribution-card fade-in">
                <div class="distribution-header">
                    <h2>
                        <i class='bx bx-pie-chart-alt'></i>
                        Distribución de Ganancias
                    </h2>
                </div>
                <div class="distribution-body">
                    <div class="distribution-title">
                        <h3>Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></h3>
                    </div>
                    
                    <div class="distribution-bars">
                        <div class="bar-tecnico" style="width: <?php echo $porcentaje_tecnico; ?>%">
                            <?php echo $porcentaje_tecnico; ?>%
                        </div>
                        <div class="bar-empresa" style="width: <?php echo $porcentaje_empresa; ?>%">
                            <?php echo $porcentaje_empresa; ?>%
                        </div>
                    </div>
                    
                    <div class="distribution-labels">
                        <div class="distribution-label label-tecnico">
                            <div class="label-title">Técnicos</div>
                            <div class="label-amount">$<?php echo number_format($estadisticas['ganancia_tecnico'] ?? 0, 2); ?></div>
                            <div class="label-percentage"><?php echo $porcentaje_tecnico; ?>% del total</div>
                        </div>
                        <div class="distribution-label">
                            <div class="label-title">Empresa</div>
                            <div class="label-amount">$<?php echo number_format($estadisticas['ganancia_empresa'] ?? 0, 2); ?></div>
                            <div class="label-percentage"><?php echo $porcentaje_empresa; ?>% del total</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ganancias por Técnico -->
            <?php if (!empty($tecnicos_ganancias)): ?>
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-group'></i>
                        Ganancias por Técnico
                    </h2>
                </div>
                
                <div class="section-body">
                    <div class="tecnico-stats">
                        <?php foreach ($ganancias_por_tecnico as $tecnico => $datos): ?>
                        <div class="tecnico-card">
                            <div class="tecnico-header">
                                <span class="tecnico-nombre">
                                    <i class='bx bx-user'></i>
                                    <?php echo htmlspecialchars($tecnico); ?>
                                </span>
                                <span class="badge badge-info"><?php echo $datos['total_fumigaciones']; ?> servicios</span>
                            </div>
                            <div class="tecnico-stats-grid">
                                <div class="tecnico-stat-item">
                                    <div class="tecnico-stat-label">Sesiones</div>
                                    <div class="tecnico-stat-value"><?php echo $datos['total_sesiones']; ?></div>
                                </div>
                                <div class="tecnico-stat-item">
                                    <div class="tecnico-stat-label">Ingresos</div>
                                    <div class="tecnico-stat-value">$<?php echo number_format($datos['ingresos_totales'], 2); ?></div>
                                </div>
                                <div class="tecnico-stat-item">
                                    <div class="tecnico-stat-label">Ganancia Técnico</div>
                                    <div class="tecnico-stat-value" style="color: var(--accent-dark);">
                                        $<?php echo number_format($datos['ganancia_tecnico'], 2); ?>
                                    </div>
                                </div>
                                <div class="tecnico-stat-item">
                                    <div class="tecnico-stat-label">Ganancia Empresa</div>
                                    <div class="tecnico-stat-value" style="color: var(--primary-dark);">
                                        $<?php echo number_format($datos['ganancia_empresa'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Servicios Detallados -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-list-ul'></i>
                        Servicios Realizados
                    </h2>
                    <div class="export-options" style="display: flex; gap: 0.5rem;">
                        <button class="btn-outline btn-sm" onclick="exportToPDF()">
                            <i class='bx bx-download'></i> PDF
                        </button>
                        <button class="btn-outline btn-sm" onclick="window.print()">
                            <i class='bx bx-printer'></i> Imprimir
                        </button>
                    </div>
                </div>
                
                <div class="section-body">
                    <div class="table-responsive">
                        <?php if (!empty($servicios_detallados)): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Cliente</th>
                                        <th>Servicio</th>
                                        <th>Técnico</th>
                                        <th>Sesiones</th>
                                        <th>Total</th>
                                        <th>Técnico (<?php echo $porcentaje_tecnico; ?>%)</th>
                                        <th>Empresa (<?php echo $porcentaje_empresa; ?>%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($servicios_detallados as $servicio): 
                                        $ganancia_tecnico = $servicio['costo_total'] * ($porcentaje_tecnico / 100);
                                        $ganancia_empresa = $servicio['costo_total'] * ($porcentaje_empresa / 100);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $servicio['fecha_registro_formateada']; ?></strong>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($servicio['nombre_cliente'] ?? 'Cliente'); ?></strong>
                                            <?php if (!empty($servicio['telefono'])): ?>
                                                <br><small style="color: var(--text-light);">📞 <?php echo htmlspecialchars($servicio['telefono']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($servicio['servicio_nombre'] ?? 'Servicio'); ?>
                                            <br><small style="color: var(--text-light);"><?php echo $servicio['estado_servicio']; ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($servicio['tecnico_asignado'] ?? 'No asignado'); ?></td>
                                        <td><?php echo $servicio['sesiones_completadas']; ?>/<?php echo $servicio['numero_sesiones']; ?></td>
                                        <td><strong>$<?php echo number_format($servicio['costo_total'], 2); ?></strong></td>
                                        <td style="color: var(--accent-dark); font-weight: 600;">
                                            $<?php echo number_format($ganancia_tecnico, 2); ?>
                                        </td>
                                        <td style="color: var(--primary-dark); font-weight: 600;">
                                            $<?php echo number_format($ganancia_empresa, 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="5" style="text-align: right; font-weight: 600;">TOTALES</td>
                                        <td><strong>$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></strong></td>
                                        <td><strong>$<?php echo number_format($estadisticas['ganancia_tecnico'] ?? 0, 2); ?></strong></td>
                                        <td><strong>$<?php echo number_format($estadisticas['ganancia_empresa'] ?? 0, 2); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-info-circle'></i>
                                <h3>No hay servicios registrados</h3>
                                <p>No se encontraron servicios de fumigación en el período seleccionado.</p>
                                <div class="empty-actions">
                                    <a href="nueva_inspeccion.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i> Nueva Inspección
                                    </a>
                                    <a href="fumigaciones_activas.php" class="btn btn-secondary">
                                        <i class='bx bx-list-ul'></i> Ver Activas
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
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
        // Validación de fechas
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            const fechaFin = document.getElementById('fecha_fin');
            if (fechaFin.value && this.value > fechaFin.value) {
                fechaFin.value = this.value;
            }
        });

        document.getElementById('fecha_fin').addEventListener('change', function() {
            const fechaInicio = document.getElementById('fecha_inicio');
            if (fechaInicio.value && this.value < fechaInicio.value) {
                fechaInicio.value = this.value;
            }
        });

        // Animación de contadores
        document.addEventListener('DOMContentLoaded', function() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
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

        // Función para exportar a PDF
        function exportToPDF() {
            // Mostrar mensaje de carga
            const loadingMessage = document.createElement('div');
            loadingMessage.innerHTML = '<div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(15,108,51,0.9); color:white; padding:20px; border-radius:10px; z-index:9999;">Generando PDF, por favor espere...</div>';
            document.body.appendChild(loadingMessage);
            
            // Usar html2canvas para capturar el contenido
            html2canvas(document.querySelector('.main-content'), {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#F8FDF8'
            }).then(canvas => {
                // Crear PDF
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = 210;
                const pageHeight = 295;
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;
                
                // Agregar la primera página
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                // Agregar páginas adicionales si es necesario
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                // Guardar el PDF
                const fileName = `Ganancias_Fumigacion_${new Date().toISOString().slice(0,10)}.pdf`;
                pdf.save(fileName);
                
                // Eliminar mensaje de carga
                document.body.removeChild(loadingMessage);
            }).catch(error => {
                console.error('Error al generar PDF:', error);
                alert('Error al generar el PDF. Por favor, intente nuevamente.');
                document.body.removeChild(loadingMessage);
            });
        }
    </script>
</body>
</html>