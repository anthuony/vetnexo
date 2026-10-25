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

// Obtener filtros
$filtro_fecha = $_GET['fecha'] ?? 'todas';
$busqueda = $_GET['busqueda'] ?? '';

// Construir consulta base
$query = "SELECT id, nombre_cliente, telefono, direccion, urbanizacion, fecha_inspeccion, hora_inspeccion, observaciones 
          FROM inspecciones_fumigacion 
          WHERE estado = 'Pendiente'";

$params = [];

// Aplicar filtro de fecha
if ($filtro_fecha == 'hoy') {
    $query .= " AND fecha_inspeccion = CURDATE()";
} elseif ($filtro_fecha == 'semana') {
    $query .= " AND fecha_inspeccion BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filtro_fecha == 'mes') {
    $query .= " AND fecha_inspeccion BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($filtro_fecha == 'atrasadas') {
    $query .= " AND fecha_inspeccion < CURDATE()";
}

// Aplicar búsqueda
if (!empty($busqueda)) {
    $query .= " AND (nombre_cliente LIKE ? OR telefono LIKE ? OR direccion LIKE ? OR urbanizacion LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$query .= " ORDER BY 
            CASE 
                WHEN fecha_inspeccion < CURDATE() THEN 1
                WHEN fecha_inspeccion = CURDATE() THEN 2
                ELSE 3
            END,
            fecha_inspeccion ASC, 
            hora_inspeccion ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$inspecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar inspecciones para hoy
$stmt_hoy = $db->prepare("SELECT COUNT(*) FROM inspecciones_fumigacion WHERE estado = 'Pendiente' AND fecha_inspeccion = CURDATE()");
$stmt_hoy->execute();
$inspecciones_hoy = $stmt_hoy->fetchColumn();

// Contar inspecciones atrasadas
$stmt_atrasadas = $db->prepare("SELECT COUNT(*) FROM inspecciones_fumigacion WHERE estado = 'Pendiente' AND fecha_inspeccion < CURDATE()");
$stmt_atrasadas->execute();
$inspecciones_atrasadas = $stmt_atrasadas->fetchColumn();

// Contar total de inspecciones pendientes
$stmt_total = $db->prepare("SELECT COUNT(*) FROM inspecciones_fumigacion WHERE estado = 'Pendiente'");
$stmt_total->execute();
$total_pendientes = $stmt_total->fetchColumn();

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
    <title>Inspecciones Pendientes - Fumigación - VetNexo</title>
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

        .header-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: var(--primary-soft);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            border: 1px solid var(--primary-light);
        }

        .stat-badge i {
            color: var(--primary);
        }

        .stat-badge.hoy {
            background: var(--accent-soft);
            border-color: var(--accent);
        }

        .stat-badge.hoy i {
            color: var(--accent);
        }

        .stat-badge.atrasadas {
            background: var(--emergency-soft);
            border-color: var(--emergency);
        }

        .stat-badge.atrasadas i {
            color: var(--emergency);
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

        .btn-warning {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--accent-dark), #d88206);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, var(--emergency-dark), #a50c01);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
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
            align-items: center;
            justify-content: space-between;
        }

        .filters-left {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray);
            border-radius: 20px;
            background: var(--white);
            color: var(--text-light);
            cursor: pointer;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .filter-btn:hover {
            background: var(--primary-soft);
            border-color: var(--primary);
            color: var(--primary);
        }

        .filter-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .filter-btn.active i {
            color: var(--white);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .form-control {
            padding: 0.6rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            width: 250px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.1);
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

        .section-body {
            padding: 1.5rem;
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
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tbody tr:hover {
            background: var(--primary-soft);
        }

        .data-table tbody tr.hoy {
            background: var(--accent-soft);
            border-left: 4px solid var(--accent);
        }

        .data-table tbody tr.atrasada {
            background: var(--emergency-soft);
            border-left: 4px solid var(--emergency);
        }

        .cliente-info {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .cliente-nombre {
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cliente-telefono {
            font-size: 0.85rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .direccion-info {
            max-width: 250px;
        }

        .direccion {
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .urbanizacion {
            font-size: 0.8rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .fecha-info {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .fecha {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .hora {
            font-size: 0.85rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-hoy {
            background: var(--accent);
            color: var(--white);
        }

        .badge-manana {
            background: var(--amarillo);
            color: var(--text-dark);
        }

        .badge-atrasada {
            background: var(--emergency);
            color: var(--white);
        }

        .badge-programada {
            background: var(--primary);
            color: var(--white);
        }

        .acciones {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            margin-bottom: 1.5rem;
        }

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .legend {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 1rem;
        }

        .legend-items {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            justify-content: center;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .legend-color.accent { background: var(--accent); }
        .legend-color.amarillo { background: var(--amarillo); }
        .legend-color.emergency { background: var(--emergency); }
        .legend-color.primary { background: var(--primary); }

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
            
            .filters-body {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filters-left {
                justify-content: center;
            }
            
            .search-box {
                width: 100%;
            }
            
            .form-control {
                width: 100%;
            }
            
            .acciones {
                flex-direction: column;
            }
            
            .btn-sm {
                width: 100%;
                justify-content: center;
            }
            
            .legend-items {
                flex-direction: column;
                align-items: center;
                gap: 0.5rem;
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
                        <i class='bx bx-calendar'></i>
                        Inspecciones Pendientes
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestiona las inspecciones de fumigación pendientes
                    </p>
                    <div class="header-stats">
                        <span class="stat-badge">
                            <i class='bx bx-list-ul'></i>
                            Total: <?php echo $total_pendientes; ?>
                        </span>
                        <span class="stat-badge hoy">
                            <i class='bx bx-calendar'></i>
                            Hoy: <?php echo $inspecciones_hoy; ?>
                        </span>
                        <?php if ($inspecciones_atrasadas > 0): ?>
                        <span class="stat-badge atrasadas">
                            <i class='bx bx-error'></i>
                            Atrasadas: <?php echo $inspecciones_atrasadas; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="index.php" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver
                    </a>
                    <a href="nueva_inspeccion.php" class="btn btn-primary">
                        <i class='bx bx-plus'></i> Nueva Inspección
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

            <!-- Filtros y Búsqueda -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter-alt'></i>
                        Filtrar Inspecciones
                    </h2>
                </div>
                <div class="filters-body">
                    <div class="filters-left">
                        <a href="?fecha=todas<?php echo !empty($busqueda) ? '&busqueda='.$busqueda : ''; ?>" 
                           class="filter-btn <?php echo $filtro_fecha == 'todas' ? 'active' : ''; ?>">
                            <i class='bx bx-list-ul'></i>
                            Todas
                        </a>
                        <a href="?fecha=hoy<?php echo !empty($busqueda) ? '&busqueda='.$busqueda : ''; ?>" 
                           class="filter-btn <?php echo $filtro_fecha == 'hoy' ? 'active' : ''; ?>">
                            <i class='bx bx-calendar'></i>
                            Hoy
                        </a>
                        <a href="?fecha=semana<?php echo !empty($busqueda) ? '&busqueda='.$busqueda : ''; ?>" 
                           class="filter-btn <?php echo $filtro_fecha == 'semana' ? 'active' : ''; ?>">
                            <i class='bx bx-calendar-week'></i>
                            Esta Semana
                        </a>
                        <a href="?fecha=mes<?php echo !empty($busqueda) ? '&busqueda='.$busqueda : ''; ?>" 
                           class="filter-btn <?php echo $filtro_fecha == 'mes' ? 'active' : ''; ?>">
                            <i class='bx bx-calendar-month'></i>
                            Este Mes
                        </a>
                        <a href="?fecha=atrasadas<?php echo !empty($busqueda) ? '&busqueda='.$busqueda : ''; ?>" 
                           class="filter-btn <?php echo $filtro_fecha == 'atrasadas' ? 'active' : ''; ?>">
                            <i class='bx bx-error'></i>
                            Atrasadas
                        </a>
                    </div>
                    
                    <form method="GET" class="search-box">
                        <?php if ($filtro_fecha != 'todas'): ?>
                            <input type="hidden" name="fecha" value="<?php echo $filtro_fecha; ?>">
                        <?php endif; ?>
                        <input type="text" 
                               name="busqueda" 
                               class="form-control" 
                               placeholder="Buscar cliente, teléfono o dirección..."
                               value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <i class='bx bx-search'></i>
                        </button>
                        <?php if (!empty($busqueda)): ?>
                            <a href="?fecha=<?php echo $filtro_fecha; ?>" class="btn btn-danger btn-sm">
                                <i class='bx bx-x'></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Tabla de Inspecciones -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-ul'></i>
                        Listado de Inspecciones Pendientes
                    </h3>
                    <span class="badge badge-programada"><?php echo count($inspecciones); ?> inspecciones</span>
                </div>
                
                <div class="section-body">
                    <div class="table-responsive">
                        <?php if (count($inspecciones) > 0): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Dirección</th>
                                        <th>Fecha/Hora</th>
                                        <th>Observaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inspecciones as $inspeccion): 
                                        $fecha_actual = strtotime(date('Y-m-d'));
                                        $fecha_inspeccion = strtotime($inspeccion['fecha_inspeccion']);
                                        $es_hoy = $fecha_inspeccion == $fecha_actual;
                                        $es_atrasada = $fecha_inspeccion < $fecha_actual;
                                        $es_manana = $fecha_inspeccion == strtotime('+1 day');
                                        
                                        $fecha_formateada = date('d/m/Y', $fecha_inspeccion);
                                        $dias_restantes = round(($fecha_inspeccion - $fecha_actual) / 86400);
                                    ?>
                                    <tr class="<?php echo $es_hoy ? 'hoy' : ($es_atrasada ? 'atrasada' : ''); ?>">
                                        <td>
                                            <div class="cliente-info">
                                                <div class="cliente-nombre">
                                                    <i class='bx bx-user'></i>
                                                    <?php echo htmlspecialchars($inspeccion['nombre_cliente']); ?>
                                                </div>
                                                <div class="cliente-telefono">
                                                    <i class='bx bx-phone'></i>
                                                    <?php echo htmlspecialchars($inspeccion['telefono']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div class="direccion-info">
                                                <div class="direccion">
                                                    <i class='bx bx-map'></i>
                                                    <?php echo htmlspecialchars($inspeccion['direccion']); ?>
                                                </div>
                                                <?php if (!empty($inspeccion['urbanizacion'])): ?>
                                                    <div class="urbanizacion">
                                                        <i class='bx bx-building-house'></i>
                                                        <?php echo htmlspecialchars($inspeccion['urbanizacion']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div class="fecha-info">
                                                <div class="fecha">
                                                    <i class='bx bx-calendar'></i>
                                                    <?php echo $fecha_formateada; ?>
                                                    <?php if ($es_hoy): ?>
                                                        <span class="badge badge-hoy">
                                                            <i class='bx bx-time'></i>
                                                            HOY
                                                        </span>
                                                    <?php elseif ($es_manana): ?>
                                                        <span class="badge badge-manana">
                                                            <i class='bx bx-time'></i>
                                                            MAÑANA
                                                        </span>
                                                    <?php elseif ($es_atrasada): ?>
                                                        <span class="badge badge-atrasada">
                                                            <i class='bx bx-error'></i>
                                                            ATRASADA
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($inspeccion['hora_inspeccion'])): ?>
                                                    <div class="hora">
                                                        <i class='bx bx-time'></i>
                                                        <?php echo $inspeccion['hora_inspeccion']; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <?php if (!empty($inspeccion['observaciones'])): ?>
                                                <div style="max-width: 200px; font-size: 0.85rem; color: var(--text-light);">
                                                    <i class='bx bx-note'></i>
                                                    <?php echo htmlspecialchars(substr($inspeccion['observaciones'], 0, 50)) . (strlen($inspeccion['observaciones']) > 50 ? '...' : ''); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-lighter); font-size: 0.85rem;">
                                                    <i class='bx bx-minus'></i>
                                                    Sin observaciones
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <div class="acciones">
                                                <a href="realizar_inspeccion.php?id=<?php echo $inspeccion['id']; ?>" 
                                                   class="btn btn-warning btn-sm"
                                                   title="Realizar inspección">
                                                    <i class='bx bx-check-circle'></i>
                                                    Realizar
                                                </a>
                                                <a href="ver_inspeccion.php?id=<?php echo $inspeccion['id']; ?>" 
                                                   class="btn btn-secondary btn-sm"
                                                   title="Ver detalles">
                                                    <i class='bx bx-show'></i>
                                                    Ver
                                                </a>
                                                <?php if ($es_atrasada): ?>
                                                    <a href="reprogramar_inspeccion.php?id=<?php echo $inspeccion['id']; ?>" 
                                                       class="btn btn-info btn-sm"
                                                       title="Reprogramar">
                                                        <i class='bx bx-calendar'></i>
                                                        Reprogramar
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-check'></i>
                                <h3>No hay inspecciones pendientes</h3>
                                <p>
                                    <?php if (!empty($busqueda) || $filtro_fecha != 'todas'): ?>
                                        No se encontraron resultados con los filtros aplicados.
                                    <?php else: ?>
                                        Todas las inspecciones han sido realizadas o canceladas.
                                    <?php endif; ?>
                                </p>
                                <div class="empty-actions">
                                    <?php if (!empty($busqueda) || $filtro_fecha != 'todas'): ?>
                                        <a href="inspecciones_pendientes.php" class="btn btn-secondary">
                                            <i class='bx bx-reset'></i>
                                            Limpiar filtros
                                        </a>
                                    <?php endif; ?>
                                    <a href="nueva_inspeccion.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i>
                                        Agendar inspección
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Leyenda -->
            <div class="legend fade-in">
                <div class="legend-items">
                    <div class="legend-item">
                        <div class="legend-color accent"></div>
                        <span><i class='bx bx-time'></i> HOY - Inspecciones para hoy</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color amarillo"></div>
                        <span><i class='bx bx-time'></i> MAÑANA - Inspecciones para mañana</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color emergency"></div>
                        <span><i class='bx bx-error'></i> ATRASADA - Inspecciones vencidas</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color primary"></div>
                        <span><i class='bx bx-calendar'></i> Programada - Inspección agendada</span>
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
        // Confirmación antes de realizar acciones (opcional)
        document.querySelectorAll('.btn-danger, .btn-warning').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.classList.contains('btn-danger') || this.classList.contains('btn-warning')) {
                    if (!confirm('¿Estás seguro de realizar esta acción?')) {
                        e.preventDefault();
                    }
                }
            });
        });

        // Crear partículas dinámicas
        document.addEventListener('DOMContentLoaded', function() {
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