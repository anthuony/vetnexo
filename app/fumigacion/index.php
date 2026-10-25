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

// Variables para estadísticas
$total_inspecciones_pendientes = 0;
$inspecciones_hoy = 0;
$fumigaciones_activas = 0;
$sesiones_hoy = 0;
$ingresos_hoy = 0;
$ingresos_pendientes = 0;
$fumigaciones_completadas_hoy = 0;
$proximas_inspecciones = [];
$proximas_sesiones = [];
$fumigaciones_recientes = [];

try {
    $hoy = date('Y-m-d');
    
    // Total inspecciones pendientes
    $stmt = $db->prepare("SELECT COUNT(*) FROM inspecciones_fumigacion WHERE estado = 'Pendiente'");
    $stmt->execute();
    $total_inspecciones_pendientes = $stmt->fetchColumn();
    
    // Inspecciones programadas para hoy
    $stmt = $db->prepare("SELECT COUNT(*) FROM inspecciones_fumigacion WHERE fecha_inspeccion = ? AND estado = 'Pendiente'");
    $stmt->execute([$hoy]);
    $inspecciones_hoy = $stmt->fetchColumn();
    
    // Fumigaciones activas (con sesiones pendientes)
    $stmt = $db->prepare("SELECT COUNT(*) FROM fumigaciones_realizadas WHERE sesiones_completadas < numero_sesiones");
    $stmt->execute();
    $fumigaciones_activas = $stmt->fetchColumn();
    
    // Sesiones de fumigación programadas para hoy
    $stmt = $db->prepare("SELECT COUNT(*) FROM agenda_fumigaciones WHERE fecha_programada = ? AND estado = 'Programada'");
    $stmt->execute([$hoy]);
    $sesiones_hoy = $stmt->fetchColumn();
    
    // INGRESOS DE HOY: Sumar costo_total de fumigaciones que tengan sesiones completadas hoy
    $stmt = $db->prepare("
        SELECT DISTINCT fr.id, fr.costo_total, fr.precio_sesion
        FROM fumigaciones_realizadas fr
        INNER JOIN agenda_fumigaciones a ON fr.id = a.fumigacion_id
        WHERE a.fecha_completada = ? AND a.estado = 'Completada'
    ");
    $stmt->execute([$hoy]);
    $fumigaciones_con_sesiones_hoy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular ingresos de hoy (sumando el precio de las sesiones completadas hoy)
    $ingresos_hoy = 0;
    $fumigaciones_completadas_hoy = count($fumigaciones_con_sesiones_hoy);
    
    foreach ($fumigaciones_con_sesiones_hoy as $fum) {
        // Contar cuántas sesiones completó hoy esta fumigación
        $stmt_sesiones = $db->prepare("
            SELECT COUNT(*) FROM agenda_fumigaciones 
            WHERE fumigacion_id = ? AND fecha_completada = ? AND estado = 'Completada'
        ");
        $stmt_sesiones->execute([$fum['id'], $hoy]);
        $sesiones_completadas_hoy = $stmt_sesiones->fetchColumn();
        
        // Sumar al ingreso del día (precio_sesion * sesiones_completadas_hoy)
        $ingresos_hoy += ($fum['precio_sesion'] * $sesiones_completadas_hoy);
    }
    
    // Ingresos pendientes (sesiones programadas pero no realizadas)
    $stmt = $db->prepare("
        SELECT SUM(fr.precio_sesion) 
        FROM agenda_fumigaciones a
        JOIN fumigaciones_realizadas fr ON a.fumigacion_id = fr.id
        WHERE a.estado = 'Programada'
    ");
    $stmt->execute();
    $ingresos_pendientes = $stmt->fetchColumn() ?? 0;
    
    // Obtener próximas inspecciones
    $stmt = $db->prepare("
        SELECT id, nombre_cliente, telefono, direccion, fecha_inspeccion, hora_inspeccion 
        FROM inspecciones_fumigacion 
        WHERE estado = 'Pendiente' AND fecha_inspeccion >= CURDATE()
        ORDER BY fecha_inspeccion ASC, hora_inspeccion ASC
        LIMIT 5
    ");
    $stmt->execute();
    $proximas_inspecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener próximas sesiones de fumigación
    $stmt = $db->prepare("
        SELECT a.id, a.fecha_programada, a.hora_programada, a.numero_sesion, a.tecnico,
               fr.cliente_id, c.nombre as nombre_cliente, c.telefono
        FROM agenda_fumigaciones a
        JOIN fumigaciones_realizadas fr ON a.fumigacion_id = fr.id
        LEFT JOIN clientes c ON fr.cliente_id = c.id
        WHERE a.estado = 'Programada' AND a.fecha_programada >= CURDATE()
        ORDER BY a.fecha_programada ASC, a.hora_programada ASC
        LIMIT 5
    ");
    $stmt->execute();
    $proximas_sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener fumigaciones activas recientes
    $stmt = $db->prepare("
        SELECT fr.id, fr.fecha_registro, fr.numero_sesiones, fr.sesiones_completadas, 
               fr.precio_sesion, fr.costo_total,
               c.nombre as nombre_cliente, c.telefono,
               s.nombre_servicio as tipo_servicio,
               DATE_FORMAT(fr.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
               (SELECT MIN(fecha_programada) FROM agenda_fumigaciones 
                WHERE fumigacion_id = fr.id AND estado = 'Programada') as proxima_fecha
        FROM fumigaciones_realizadas fr
        LEFT JOIN clientes c ON fr.cliente_id = c.id
        LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
        WHERE fr.sesiones_completadas < fr.numero_sesiones
        ORDER BY fr.fecha_registro DESC
        LIMIT 5
    ");
    $stmt->execute();
    $fumigaciones_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Silenciar errores para no romper la página
    error_log("Error en módulo fumigación: " . $e->getMessage());
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
    <title>Módulo de Fumigación - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="../includes/img/logo.svg">
    
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

        .date-badge {
            background: var(--primary-soft);
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-dark);
            font-weight: 600;
            border: 1px solid var(--gray);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
            gap: 0.25rem;
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .trend-warning {
            color: var(--accent);
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
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
            margin: 0;
        }

        .section-header h3 i {
            color: var(--accent);
        }

        .section-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.3s ease;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            background: var(--white);
        }

        .section-link:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(3px);
        }

        .section-body {
            padding: 1.5rem;
        }

        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.85rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .activity-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-scheduled {
            background: #e3f2fd;
            color: var(--azul);
        }

        .status-warning {
            background: #ffebee;
            color: var(--emergency);
        }

        .status-pending {
            background: #fff3e0;
            color: var(--accent);
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .action-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1.25rem;
            text-align: center;
            text-decoration: none;
            color: var(--primary-dark);
            transition: all 0.3s ease;
            border: 1px solid var(--gray);
        }

        .action-card:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .action-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .action-card span {
            font-size: 0.85rem;
            font-weight: 600;
            display: block;
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
            padding: 0.75rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background: var(--primary-soft);
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

        .badge-hoy {
            background: var(--accent);
            color: white;
        }

        .btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.7rem;
        }

        /* Resumen de Hoy */
        .resumen-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .resumen-item {
            background: var(--primary-soft);
            padding: 1rem;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--primary-light);
        }

        .resumen-item span {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .resumen-item strong {
            display: block;
            font-size: 1.5rem;
            color: var(--primary-dark);
            margin: 0.5rem 0;
        }

        .resumen-full {
            grid-column: span 2;
            background: var(--primary-soft);
            padding: 1rem;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--primary-light);
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
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .footer-simple {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.9);
        }

        .footer-simple i {
            color: var(--emergency);
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
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1rem; }
            .dashboard-header { flex-direction: column; gap: 1.5rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
            .actions-grid { grid-template-columns: repeat(2, 1fr); }
            .resumen-grid { grid-template-columns: 1fr; }
            .resumen-full { grid-column: span 1; }
            .footer-content { padding: 0 1rem; }
            .footer-simple { font-size: 0.8rem; }
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
                        Módulo de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestiona tus inspecciones y servicios de fumigación
                    </p>
                </div>
                
                <div class="header-actions">
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

            <!-- Estadísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-search-alt'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $total_inspecciones_pendientes; ?></div>
                        <div class="stat-label">Inspecciones Pendientes</div>
                        <div class="stat-trend <?php echo $inspecciones_hoy > 0 ? 'trend-warning' : 'trend-up'; ?>">
                            <i class='bx bx-calendar'></i>
                            <span><?php echo $inspecciones_hoy; ?> para hoy</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-calendar-check'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $fumigaciones_activas; ?></div>
                        <div class="stat-label">Fumigaciones Activas</div>
                        <div class="stat-trend <?php echo $sesiones_hoy > 0 ? 'trend-warning' : 'trend-up'; ?>">
                            <i class='bx bx-time'></i>
                            <span><?php echo $sesiones_hoy; ?> sesiones hoy</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-dollar-circle'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">$<?php echo number_format($ingresos_hoy, 2); ?></div>
                        <div class="stat-label">Ingresos de Hoy</div>
                        <div class="stat-trend trend-up">
                            <i class='bx bx-trending-up'></i>
                            <span><?php echo $fumigaciones_completadas_hoy; ?> fumigación(es)</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-wallet'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">$<?php echo number_format($ingresos_pendientes, 2); ?></div>
                        <div class="stat-label">Ingresos Pendientes</div>
                        <div class="stat-trend trend-warning">
                            <i class='bx bx-time'></i>
                            <span>Por sesiones programadas</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Acciones Rápidas -->
            <div class="content-section">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-bolt'></i>
                        Acciones Rápidas
                    </h3>
                </div>
                
                <div class="section-body">
                    <div class="actions-grid">
                        <a href="nueva_inspeccion.php" class="action-card">
                            <i class='bx bx-search-alt'></i>
                            <span>Nueva Inspección</span>
                        </a>
                        <a href="inspecciones_pendientes.php" class="action-card">
                            <i class='bx bx-calendar'></i>
                            <span>Inspecciones Pendientes</span>
                        </a>
                        <a href="fumigaciones_activas.php" class="action-card">
                            <i class='bx bx-spray-can'></i>
                            <span>Fumigaciones Activas</span>
                        </a>
                        <a href="agendar_sesiones.php" class="action-card">
                            <i class='bx bx-calendar-plus'></i>
                            <span>Agendar Sesiones</span>
                        </a>
                        <a href="historial.php" class="action-card">
                            <i class='bx bx-history'></i>
                            <span>Historial</span>
                        </a>
                        <a href="ganancias.php" class="action-card">
                            <i class='bx bx-trending-up'></i>
                            <span>Ganancias</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Grid de Contenido -->
            <div class="content-grid">
                <!-- Próximas Inspecciones -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-search-alt'></i>
                            Próximas Inspecciones
                        </h3>
                        <a href="inspecciones_pendientes.php" class="section-link">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    
                    <div class="section-body">
                        <div class="activity-list">
                            <?php if (count($proximas_inspecciones) > 0): ?>
                                <?php foreach ($proximas_inspecciones as $inspeccion): 
                                    $fecha_inspeccion = new DateTime($inspeccion['fecha_inspeccion']);
                                    $hoy = new DateTime();
                                    $es_hoy = $fecha_inspeccion->format('Y-m-d') == $hoy->format('Y-m-d');
                                ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class='bx bx-calendar'></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($inspeccion['nombre_cliente']); ?>
                                        </div>
                                        <div class="activity-time">
                                            <i class='bx bx-time'></i>
                                            <?php echo date('d/m/Y', strtotime($inspeccion['fecha_inspeccion'])); ?>
                                            <?php if (!empty($inspeccion['hora_inspeccion'])): ?>
                                                - <?php echo $inspeccion['hora_inspeccion']; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($inspeccion['telefono'])): ?>
                                                · <i class='bx bx-phone'></i> <?php echo htmlspecialchars($inspeccion['telefono']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="activity-status <?php echo $es_hoy ? 'status-warning' : 'status-scheduled'; ?>">
                                        <?php echo $es_hoy ? 'Hoy' : 'Programada'; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class='bx bx-check-circle'></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">No hay inspecciones pendientes</div>
                                        <div class="activity-time">Las próximas inspecciones aparecerán aquí</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Próximas Sesiones -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-spray-can'></i>
                            Próximas Sesiones
                        </h3>
                        <a href="fumigaciones_activas.php" class="section-link">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    
                    <div class="section-body">
                        <div class="activity-list">
                            <?php if (count($proximas_sesiones) > 0): ?>
                                <?php foreach ($proximas_sesiones as $sesion): 
                                    $fecha_sesion = new DateTime($sesion['fecha_programada']);
                                    $hoy = new DateTime();
                                    $es_hoy = $fecha_sesion->format('Y-m-d') == $hoy->format('Y-m-d');
                                ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class='bx bx-calendar-check'></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($sesion['nombre_cliente'] ?? 'Cliente'); ?>
                                        </div>
                                        <div class="activity-time">
                                            <i class='bx bx-time'></i>
                                            Sesión · <?php echo date('d/m/Y', strtotime($sesion['fecha_programada'])); ?>
                                            <?php if (!empty($sesion['hora_programada'])): ?>
                                                - <?php echo $sesion['hora_programada']; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($sesion['tecnico'])): ?>
                                                · <i class='bx bx-user'></i> <?php echo htmlspecialchars($sesion['tecnico']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="activity-status <?php echo $es_hoy ? 'status-warning' : 'status-scheduled'; ?>">
                                        <?php echo $es_hoy ? 'Hoy' : 'Programada'; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class='bx bx-check-circle'></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">No hay sesiones programadas</div>
                                        <div class="activity-time">Las próximas sesiones aparecerán aquí</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Fumigaciones Activas Recientes -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-spray-can'></i>
                            Fumigaciones Activas
                        </h3>
                        <a href="fumigaciones_activas.php" class="section-link">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    
                    <div class="section-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Servicio</th>
                                        <th>Progreso</th>
                                        <th>Próxima</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($fumigaciones_recientes) > 0): ?>
                                        <?php foreach ($fumigaciones_recientes as $fum): 
                                            $progreso = ($fum['numero_sesiones'] > 0) 
                                                ? round(($fum['sesiones_completadas'] / $fum['numero_sesiones']) * 100) 
                                                : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($fum['nombre_cliente'] ?? 'Cliente'); ?></strong>
                                                <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($fum['telefono'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($fum['tipo_servicio'] ?? 'Servicio'); ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <span><?php echo $fum['sesiones_completadas']; ?>/<?php echo $fum['numero_sesiones']; ?></span>
                                                    <div style="width: 50px; height: 4px; background: var(--gray-light); border-radius: 2px;">
                                                        <div style="width: <?php echo $progreso; ?>%; height: 100%; background: var(--primary); border-radius: 2px;"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($fum['proxima_fecha']): ?>
                                                    <span class="badge badge-info">
                                                        <?php echo date('d/m/Y', strtotime($fum['proxima_fecha'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Por agendar</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--text-lighter); padding: 2rem;">
                                                No hay fumigaciones activas
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Resumen de Hoy -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-calendar'></i>
                            Resumen de Hoy
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <div class="resumen-grid">
                            <div class="resumen-item">
                                <span>Inspecciones</span>
                                <strong><?php echo $inspecciones_hoy; ?></strong>
                                <span>programadas</span>
                            </div>
                            <div class="resumen-item">
                                <span>Sesiones</span>
                                <strong><?php echo $sesiones_hoy; ?></strong>
                                <span>programadas</span>
                            </div>
                            <div class="resumen-full">
                                <span>Ingresos del Día</span>
                                <strong>$<?php echo number_format($ingresos_hoy, 2); ?></strong>
                                <?php if ($fumigaciones_completadas_hoy > 0): ?>
                                    <div style="font-size: 0.85rem; color: var(--success); margin-top: 0.5rem;">
                                        <i class='bx bx-check-circle'></i> 
                                        <?php echo $fumigaciones_completadas_hoy; ?> fumigación(es) completada(s)
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-content">
                    <div class="footer-simple">
                        © <?php echo date('Y'); ?> VetNexo | 
                        Módulo de Fumigación | 
                        Desarrollado con <i class='bx bx-heart' style="color: var(--emergency);"></i> por <strong>VetNexo Product Team</strong>
                    </div>
                </div>
            </footer>
        </main>
    </div>

    <script src="../js/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            // Animación de contadores
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
        });
    </script>
</body>
</html>