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
// Incluir funciones comunes
include 'config/funciones.php';

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
    
    // Estadísticas principales con datos dinámicos
    $stats_sql = "
        SELECT 
            (SELECT COUNT(*) FROM mascotas) as total_pacientes,
            (SELECT COUNT(*) FROM citas WHERE DATE(fecha_cita) = CURDATE()) as citas_hoy,
            (SELECT COUNT(*) FROM productos WHERE stock_actual <= stock_minimo) as stock_bajo,
            (SELECT COUNT(*) FROM citas WHERE estado = 'scheduled') as citas_pendientes,
            (SELECT COUNT(*) FROM clientes WHERE DATE(fecha_registro) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as nuevos_clientes_mes,
            (SELECT COALESCE(SUM(total), 0) FROM facturas WHERE DATE(fecha_emision) = CURDATE()) as ingresos_hoy,
            (SELECT COALESCE(SUM(total), 0) FROM facturas WHERE DATE(fecha_emision) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) as ingresos_ayer,
            (SELECT COUNT(*) FROM servicios WHERE activo = 1) as total_servicios,
            (SELECT COUNT(*) FROM mascotas WHERE fecha_nacimiento >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) as mascotas_jovenes,
            (SELECT COUNT(*) FROM consultas WHERE DATE(fecha_consulta) = CURDATE()) as consultas_hoy
    ";
    $stats_stmt = $db->query($stats_sql);
    $estadisticas = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular crecimiento de ingresos
    $crecimiento_ingresos = 0;
    if ($estadisticas['ingresos_ayer'] > 0) {
        $crecimiento_ingresos = (($estadisticas['ingresos_hoy'] - $estadisticas['ingresos_ayer']) / $estadisticas['ingresos_ayer']) * 100;
    }
    
    // Citas de hoy con más detalle
    $citas_sql = "
        SELECT c.*, m.nombre as mascota_nombre, m.especie_id, s.nombre as servicio_nombre, 
               cl.nombre as cliente_nombre, cl.telefono as cliente_telefono,
               CASE 
                   WHEN TIME(c.fecha_cita) < CURTIME() AND c.estado = 'scheduled' THEN 'atrasada'
                   ELSE c.estado
               END as estado_real
        FROM citas c
        LEFT JOIN mascotas m ON c.mascota_id = m.id
        LEFT JOIN servicios s ON c.servicio_id = s.id
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        WHERE DATE(c.fecha_cita) = CURDATE()
        ORDER BY c.fecha_cita ASC
        LIMIT 8
    ";
    $citas_stmt = $db->query($citas_sql);
    $citas_hoy = $citas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Productos con stock crítico
    $stock_sql = "
        SELECT p.*, c.nombre as categoria_nombre,
               ROUND((p.stock_actual / p.stock_minimo) * 100, 1) as porcentaje_stock,
               CASE 
                   WHEN p.stock_actual = 0 THEN 'critica'
                   WHEN (p.stock_actual / p.stock_minimo) <= 0.3 THEN 'alta'
                   WHEN (p.stock_actual / p.stock_minimo) <= 0.6 THEN 'media'
                   ELSE 'ok'
               END as nivel_urgencia
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        WHERE (p.stock_actual <= p.stock_minimo OR p.stock_actual = 0) AND p.activo = 1
        ORDER BY p.stock_actual ASC, p.stock_minimo DESC
        LIMIT 6
    ";
    $stock_stmt = $db->query($stock_sql);
    $alertas_stock = $stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Últimas mascotas registradas
    $mascotas_sql = "
        SELECT m.*, cl.nombre as cliente_nombre, e.nombre as especie_nombre,
               DATE_FORMAT(m.fecha_registro, '%d/%m/%Y') as fecha_registro_formato
        FROM mascotas m
        LEFT JOIN clientes cl ON m.cliente_id = cl.id
        LEFT JOIN especies e ON m.especie_id = e.id
        ORDER BY m.fecha_registro DESC
        LIMIT 6
    ";
    $mascotas_stmt = $db->query($mascotas_sql);
    $ultimas_mascotas = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Últimos clientes registrados (NUEVO)
    $clientes_sql = "
        SELECT c.*, 
               DATE_FORMAT(c.fecha_registro, '%d/%m/%Y') as fecha_registro_formato,
               (SELECT COUNT(*) FROM mascotas WHERE cliente_id = c.id) as total_mascotas
        FROM clientes c
        ORDER BY c.fecha_registro DESC
        LIMIT 5
    ";
    $clientes_stmt = $db->query($clientes_sql);
    $ultimos_clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Próximas citas (próximos 3 días)
    $proximas_citas_sql = "
        SELECT c.*, m.nombre as mascota_nombre, s.nombre as servicio_nombre, 
               cl.nombre as cliente_nombre, cl.telefono,
               DATEDIFF(DATE(c.fecha_cita), CURDATE()) as dias_restantes
        FROM citas c
        LEFT JOIN mascotas m ON c.mascota_id = m.id
        LEFT JOIN servicios s ON c.servicio_id = s.id
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        WHERE DATE(c.fecha_cita) > CURDATE()
          AND DATE(c.fecha_cita) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND c.estado = 'scheduled'
        ORDER BY c.fecha_cita ASC
        LIMIT 6
    ";
    $proximas_citas_stmt = $db->query($proximas_citas_sql);
    $proximas_citas = $proximas_citas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Servicios más populares este mes
    $servicios_sql = "
        SELECT s.nombre, COUNT(fd.id) as cantidad, s.precio
        FROM factura_detalles fd
        JOIN servicios s ON fd.servicio_id = s.id
        JOIN facturas f ON fd.factura_id = f.id
        WHERE MONTH(f.fecha_emision) = MONTH(CURDATE())
          AND YEAR(f.fecha_emision) = YEAR(CURDATE())
          AND fd.tipo_item = 'servicio'
        GROUP BY s.id, s.nombre, s.precio
        ORDER BY cantidad DESC
        LIMIT 5
    ";
    $servicios_stmt = $db->query($servicios_sql);
    $servicios_populares = $servicios_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ingresos por día (últimos 7 días)
    $ingresos_sql = "
        SELECT 
            DATE(fecha_emision) as fecha,
            SUM(total) as total_dia,
            COUNT(*) as facturas_dia
        FROM facturas 
        WHERE fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND estado = 'paid'
        GROUP BY DATE(fecha_emision)
        ORDER BY fecha ASC
    ";
    $ingresos_stmt = $db->query($ingresos_sql);
    $ingresos_7dias = $ingresos_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar notificaciones (para el badge)
    $notificaciones_count = count($alertas_stock) + count($proximas_citas);
    
} catch (PDOException $e) {
    error_log("[VetNexo][dashboard] " . $e->getMessage()); die("No se pudieron cargar los datos del panel.");
}



?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    
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

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
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

        /* Grid Principal */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .content-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
            height: fit-content;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 i {
            color: var(--accent);
        }

        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            background: var(--white);
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .view-all:hover {
            background: var(--primary);
            color: white;
        }

        .card-body {
            padding: 1.2rem;
        }

        /* Listas */
        .item-list {
            list-style: none;
        }

        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .item-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .item-info {
            flex: 1;
        }

        .item-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
        }

        .item-subtitle {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .item-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-client {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .badge-mascota {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        /* Badges de estado */
        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        /* Grid de mascotas pequeño */
        .mascotas-mini-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
        }

        .mascota-mini-card {
            background: var(--gray-light);
            padding: 0.8rem;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mascota-mini-avatar {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .mascota-mini-info {
            flex: 1;
            overflow: hidden;
        }

        .mascota-mini-nombre {
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mascota-mini-dueno {
            font-size: 0.7rem;
            color: var(--text-light);
        }

        /* Progreso de stock */
        .stock-progress {
            height: 4px;
            background: var(--gray-light);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 2px;
        }

        .progress-critical { background: var(--danger); }
        .progress-high { background: var(--warning); }
        .progress-medium { background: var(--accent); }

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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
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
            
            .mascotas-mini-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Animaciones */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.5s ease forwards;
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
                        <i class='bx bx-home-heart'></i>
                        ¡Bienvenido, <?php echo htmlspecialchars(explode(' ', $usuario_nombre)[0]); ?>!
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Panel de Administración
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

            <!-- Estadísticas -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-group'></i>
                            </div>
                            <div class="trend-indicator <?php echo ($estadisticas['nuevos_clientes_mes'] ?? 0) > 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo ($estadisticas['nuevos_clientes_mes'] ?? 0) > 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                +<?php echo $estadisticas['nuevos_clientes_mes'] ?? 0; ?> este mes
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_pacientes'] ?? 0); ?></div>
                        <div class="stat-label">Pacientes registrados</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--info);">
                                <i class='bx bx-calendar-check'></i>
                            </div>
                            <div class="trend-indicator">
                                <i class='bx bx-calendar'></i>
                                <?php echo $estadisticas['citas_hoy'] ?? 0; ?> hoy
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['citas_pendientes'] ?? 0; ?></div>
                        <div class="stat-label">Citas pendientes</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--warning);">
                                <i class='bx bx-package'></i>
                            </div>
                            <div class="trend-indicator negative">
                                <i class='bx bx-error-alt'></i>
                                Requiere atención
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['stock_bajo'] ?? 0; ?></div>
                        <div class="stat-label">Productos con stock bajo</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--success);">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                            <div class="trend-indicator <?php echo $crecimiento_ingresos >= 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo $crecimiento_ingresos >= 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo number_format(abs($crecimiento_ingresos), 1); ?>%
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ingresos_hoy'] ?? 0, 0); ?></div>
                        <div class="stat-label">Ingresos hoy</div>
                    </div>
                </div>
            </div>

            <!-- Grid Principal -->
            <div class="dashboard-grid">
                <!-- Citas de Hoy -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class='bx bx-calendar'></i>
                            Citas de Hoy
                        </h3>
                        <a href="citasdeldia.php" class="view-all">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($citas_hoy)): ?>
                            <ul class="item-list">
                                <?php foreach (array_slice($citas_hoy, 0, 5) as $cita): ?>
                                    <li class="list-item">
                                        <div class="item-avatar">
                                            <i class='bx bx-calendar-alt'></i>
                                        </div>
                                        <div class="item-info">
                                            <div class="item-title">
                                                <?php echo htmlspecialchars($cita['mascota_nombre']); ?>
                                            </div>
                                            <div class="item-subtitle">
                                                <i class='bx bx-time'></i> <?php echo date('H:i', strtotime($cita['fecha_cita'])); ?>
                                                • <i class='bx bx-user'></i> <?php echo htmlspecialchars($cita['cliente_nombre']); ?>
                                            </div>
                                        </div>
                                        <span class="item-badge badge-<?php 
                                            echo $cita['estado_real'] === 'completed' ? 'success' : 
                                                 ($cita['estado_real'] === 'atrasada' ? 'danger' : 
                                                 ($cita['estado_real'] === 'confirmed' ? 'info' : 'warning')); 
                                        ?>">
                                            <?php 
                                            echo $cita['estado_real'] === 'completed' ? 'Completada' : 
                                                 ($cita['estado_real'] === 'atrasada' ? 'Atrasada' : 
                                                 ($cita['estado_real'] === 'confirmed' ? 'Confirmada' : 'Pendiente')); 
                                            ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-calendar-x' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                <p>No hay citas programadas para hoy</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Últimos Clientes Registrados (NUEVO) -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class='bx bx-user-plus'></i>
                            Nuevos Clientes
                        </h3>
                        <a href="listadeclientes.php" class="view-all">
                            Ver todos <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($ultimos_clientes)): ?>
                            <ul class="item-list">
                                <?php foreach ($ultimos_clientes as $cliente): ?>
                                    <li class="list-item">
                                        <div class="item-avatar">
                                            <i class='bx bx-user'></i>
                                        </div>
                                        <div class="item-info">
                                            <div class="item-title">
                                                <?php echo htmlspecialchars($cliente['nombre']); ?>
                                            </div>
                                            <div class="item-subtitle">
                                                <i class='bx bx-calendar'></i> <?php echo $cliente['fecha_registro_formato']; ?>
                                                • <i class='bx bx-paw'></i> <?php echo $cliente['total_mascotas']; ?> mascota(s)
                                            </div>
                                        </div>
                                        <span class="item-badge badge-client">
                                            Nuevo
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-user-x' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                <p>No hay clientes registrados</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Últimas Mascotas -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class='bx bx-paw'></i>
                            Últimas Mascotas
                        </h3>
                        <a href="listademascotas.php" class="view-all">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($ultimas_mascotas)): ?>
                            <div class="mascotas-mini-grid">
                                <?php foreach ($ultimas_mascotas as $mascota): ?>
                                    <div class="mascota-mini-card">
                                        <div class="mascota-mini-avatar">
                                            <i class='bx bxs-dog'></i>
                                        </div>
                                        <div class="mascota-mini-info">
                                            <div class="mascota-mini-nombre">
                                                <?php echo htmlspecialchars($mascota['nombre']); ?>
                                            </div>
                                            <div class="mascota-mini-dueno">
                                                <i class='bx bx-user'></i>
                                                <?php echo htmlspecialchars(explode(' ', $mascota['cliente_nombre'] ?? '')[0]); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-paw' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                <p>No hay mascotas registradas</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Alertas de Stock -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class='bx bx-error-circle'></i>
                            Stock Crítico
                        </h3>
                        <a href="stock.php" class="view-all">
                            Gestionar <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($alertas_stock)): ?>
                            <ul class="item-list">
                                <?php foreach (array_slice($alertas_stock, 0, 4) as $producto): ?>
                                    <li class="list-item">
                                        <div class="item-avatar" style="background: <?php 
                                            echo $producto['nivel_urgencia'] === 'critica' ? '#ffebee' : '#fff3e0'; 
                                        ?>; color: <?php 
                                            echo $producto['nivel_urgencia'] === 'critica' ? 'var(--danger)' : 'var(--warning)'; 
                                        ?>;">
                                            <i class='bx bx-package'></i>
                                        </div>
                                        <div class="item-info">
                                            <div class="item-title">
                                                <?php echo htmlspecialchars($producto['nombre']); ?>
                                            </div>
                                            <div class="item-subtitle">
                                                Stock: <?php echo $producto['stock_actual']; ?> unidades
                                            </div>
                                            <div class="stock-progress">
                                                <div class="progress-bar progress-<?php echo $producto['nivel_urgencia'] === 'critica' ? 'critical' : 'high'; ?>" 
                                                     style="width: <?php echo min(($producto['stock_actual'] / $producto['stock_minimo']) * 100, 100); ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-check-circle' style="font-size: 3rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                                <p>Inventario en orden</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Próximas Citas -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class='bx bx-calendar-week'></i>
                            Próximos Días
                        </h3>
                        <a href="historialcitas.php" class="view-all">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($proximas_citas)): ?>
                            <ul class="item-list">
                                <?php foreach (array_slice($proximas_citas, 0, 4) as $cita): ?>
                                    <li class="list-item">
                                        <div class="item-avatar">
                                            <i class='bx bx-calendar-star'></i>
                                        </div>
                                        <div class="item-info">
                                            <div class="item-title">
                                                <?php echo htmlspecialchars($cita['mascota_nombre']); ?>
                                            </div>
                                            <div class="item-subtitle">
                                                <i class='bx bx-calendar'></i> <?php echo date('d/m', strtotime($cita['fecha_cita'])); ?>
                                                • <i class='bx bx-time'></i> <?php echo date('H:i', strtotime($cita['fecha_cita'])); ?>
                                            </div>
                                        </div>
                                        <span class="item-badge badge-info">
                                            <?php 
                                            if ($cita['dias_restantes'] == 1) echo 'Mañana';
                                            elseif ($cita['dias_restantes'] == 0) echo 'Hoy';
                                            else echo "En {$cita['dias_restantes']} días";
                                            ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-calendar-check' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                <p>No hay citas programadas</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Servicios Populares -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class='bx bx-trending-up'></i>
                            Servicios del Mes
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($servicios_populares)): ?>
                            <ul class="item-list">
                                <?php foreach ($servicios_populares as $servicio): ?>
                                    <li class="list-item">
                                        <div class="item-avatar">
                                            <i class='bx bx-category'></i>
                                        </div>
                                        <div class="item-info">
                                            <div class="item-title">
                                                <?php echo htmlspecialchars($servicio['nombre']); ?>
                                            </div>
                                            <div class="item-subtitle">
                                                <?php echo $servicio['cantidad']; ?> realizados • $<?php echo number_format($servicio['precio'], 2); ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-data' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                <p>No hay datos suficientes</p>
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

    <script src="js/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de contadores
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseInt(text.replace(/[^0-9]/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = text.replace(number, Math.floor(current).toLocaleString());
                    }, 30);
                }
            });

            // Efecto fade para las cards
            const cards = document.querySelectorAll('.content-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100 + 200);
            });
        });
    </script>
</body>
</html>