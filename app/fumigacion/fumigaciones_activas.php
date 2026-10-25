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
$filtro_estado = $_GET['estado'] ?? 'activas';
$filtro_tecnico = $_GET['tecnico'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Construir consulta SQL con filtros
$sql_where = "WHERE 1=1";
$params = [];

if ($filtro_estado == 'activas') {
    $sql_where .= " AND fr.sesiones_completadas < fr.numero_sesiones";
} elseif ($filtro_estado == 'completadas') {
    $sql_where .= " AND fr.sesiones_completadas = fr.numero_sesiones";
}

if (!empty($filtro_tecnico)) {
    $sql_where .= " AND fr.tecnico_asignado LIKE ?";
    $params[] = "%$filtro_tecnico%";
}

if (!empty($busqueda)) {
    $sql_where .= " AND (c.nombre LIKE ? 
                       OR c.telefono LIKE ? 
                       OR c.direccion LIKE ?
                       OR s.nombre_servicio LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

// Obtener fumigaciones con JOIN a clientes y servicios
$fumigaciones = [];
$totales = [
    'activas' => 0,
    'completadas' => 0,
    'sesiones_pendientes' => 0,
    'ingresos_pendientes' => 0.00
];

try {
    // Consulta principal con JOINs
    $sql = "SELECT fr.*, 
                   c.nombre as nombre_cliente, 
                   c.telefono,
                   c.direccion,
                   s.nombre_servicio as tipo_servicio,
                   s.precio_base,
                   DATE_FORMAT(fr.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
                   (SELECT MIN(af.fecha_programada) 
                    FROM agenda_fumigaciones af 
                    WHERE af.fumigacion_id = fr.id 
                      AND af.estado = 'Programada') as proxima_fecha,
                   (SELECT COUNT(*) 
                    FROM agenda_fumigaciones af 
                    WHERE af.fumigacion_id = fr.id 
                      AND af.estado = 'Programada') as sesiones_pendientes_count,
                   (SELECT COUNT(*) 
                    FROM agenda_fumigaciones af 
                    WHERE af.fumigacion_id = fr.id) as total_sesiones_agendadas
            FROM fumigaciones_realizadas fr
            LEFT JOIN clientes c ON fr.cliente_id = c.id
            LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
            $sql_where
            ORDER BY 
                CASE 
                    WHEN fr.sesiones_completadas < fr.numero_sesiones THEN 1
                    ELSE 2
                END,
                proxima_fecha ASC,
                fr.fecha_registro DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $fumigaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular totales
    $sql_totales = "SELECT 
                        SUM(CASE WHEN sesiones_completadas < numero_sesiones THEN 1 ELSE 0 END) as activas,
                        SUM(CASE WHEN sesiones_completadas = numero_sesiones THEN 1 ELSE 0 END) as completadas,
                        SUM(CASE WHEN sesiones_completadas < numero_sesiones THEN (numero_sesiones - sesiones_completadas) ELSE 0 END) as sesiones_pendientes,
                        SUM(CASE WHEN sesiones_completadas < numero_sesiones THEN (numero_sesiones - sesiones_completadas) * precio_sesion ELSE 0 END) as ingresos_pendientes
                    FROM fumigaciones_realizadas";
    $stmt_totales = $db->query($sql_totales);
    $result_totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
    
    // Asegurar valores por defecto
    $totales = [
        'activas' => isset($result_totales['activas']) ? (int)$result_totales['activas'] : 0,
        'completadas' => isset($result_totales['completadas']) ? (int)$result_totales['completadas'] : 0,
        'sesiones_pendientes' => isset($result_totales['sesiones_pendientes']) ? (int)$result_totales['sesiones_pendientes'] : 0,
        'ingresos_pendientes' => isset($result_totales['ingresos_pendientes']) ? (float)$result_totales['ingresos_pendientes'] : 0.00
    ];

} catch (PDOException $e) {
    $error_msg = "Error al cargar las fumigaciones: " . $e->getMessage();
}

// Obtener lista de técnicos para el filtro
$tecnicos = [];
try {
    $sql_tecnicos = "SELECT DISTINCT tecnico_asignado FROM fumigaciones_realizadas WHERE tecnico_asignado IS NOT NULL AND tecnico_asignado != '' ORDER BY tecnico_asignado";
    $stmt_tecnicos = $db->query($sql_tecnicos);
    $tecnicos = $stmt_tecnicos->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Silenciar error
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
    <title>Fumigaciones Activas - VetNexo</title>
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
            min-width: 200px;
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
            min-width: 300px;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-left: auto;
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
            animation: fadeIn 0.9s ease;
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
            vertical-align: middle;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
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

        .badge-activa {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-completada {
            background: #e3f2fd;
            color: var(--azul);
        }

        .progress-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .progress-text {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
            min-width: 60px;
        }

        .action-buttons {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 0.3rem 0.6rem;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            text-decoration: none;
        }

        .btn-view {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .btn-view:hover {
            background: var(--primary);
            color: var(--white);
        }

        .btn-session {
            background: #fff3e0;
            color: var(--accent-dark);
        }

        .btn-session:hover {
            background: var(--accent);
            color: var(--white);
        }

        .btn-history {
            background: #e3f2fd;
            color: var(--azul);
        }

        .btn-history:hover {
            background: var(--azul);
            color: var(--white);
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
            .action-buttons { flex-direction: column; }
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
                        <i class='bx bx-spray-can'></i>
                        Fumigaciones Activas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestiona las fumigaciones en curso y programadas
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
                        <i class='bx bx-play-circle'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $totales['activas']; ?></div>
                        <div class="stat-label">Fumigaciones Activas</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-calendar-check'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $totales['sesiones_pendientes']; ?></div>
                        <div class="stat-label">Sesiones Pendientes</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-dollar-circle'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">$<?php echo number_format($totales['ingresos_pendientes'], 2); ?></div>
                        <div class="stat-label">Ingresos Pendientes</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class='bx bx-check-circle'></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $totales['completadas']; ?></div>
                        <div class="stat-label">Completadas</div>
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
                        <label class="filter-label">Estado</label>
                        <select id="filtro_estado" name="estado" class="form-control select" onchange="aplicarFiltros()">
                            <option value="activas" <?php echo $filtro_estado == 'activas' ? 'selected' : ''; ?>>Activas (en curso)</option>
                            <option value="completadas" <?php echo $filtro_estado == 'completadas' ? 'selected' : ''; ?>>Completadas</option>
                            <option value="todas" <?php echo $filtro_estado == 'todas' ? 'selected' : ''; ?>>Todas</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Técnico Asignado</label>
                        <select id="filtro_tecnico" name="tecnico" class="form-control select" onchange="aplicarFiltros()">
                            <option value="">Todos los técnicos</option>
                            <?php foreach ($tecnicos as $tecnico): ?>
                                <option value="<?php echo htmlspecialchars($tecnico ?? ''); ?>" <?php echo $filtro_tecnico == $tecnico ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tecnico ?? ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="search-group">
                        <label class="filter-label">Buscar</label>
                        <input type="text" id="busqueda" name="busqueda" class="form-control" 
                               placeholder="Cliente, teléfono, dirección, servicio..." 
                               value="<?php echo htmlspecialchars($busqueda ?? ''); ?>">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="aplicarFiltros()">
                            <i class='bx bx-filter-alt'></i> Filtrar
                        </button>
                        <a href="fumigaciones_activas.php" class="btn btn-secondary">
                            <i class='bx bx-reset'></i> Limpiar
                        </a>
                    </div>
                </div>
            </div>

            <!-- Listado de Fumigaciones -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-ul'></i>
                        Listado de Fumigaciones
                    </h3>
                    <span class="badge badge-info">Total: <?php echo count($fumigaciones); ?></span>
                </div>
                
                <div class="section-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th>Servicio</th>
                                    <th>Progreso</th>
                                    <th>Próxima Sesión</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($fumigaciones) > 0): ?>
                                    <?php foreach ($fumigaciones as $fum): 
                                        $porcentaje = ($fum['numero_sesiones'] > 0) 
                                            ? round(($fum['sesiones_completadas'] / $fum['numero_sesiones']) * 100) 
                                            : 0;
                                        $activa = $fum['sesiones_completadas'] < $fum['numero_sesiones'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($fum['nombre_cliente'] ?? 'Cliente no especificado'); ?></strong>
                                                <br><small style="color: var(--text-light);">Desde: <?php echo $fum['fecha_registro_formateada'] ?? ''; ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($fum['telefono'])): ?>
                                                    <i class='bx bx-phone'></i> <?php echo htmlspecialchars($fum['telefono']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-lighter);">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($fum['tipo_servicio'] ?? 'Servicio'); ?></strong>
                                                <br><small>$<?php echo number_format((float)($fum['precio_sesion'] ?? 0), 2); ?> por sesión</small>
                                            </td>
                                            <td>
                                                <div class="progress-container">
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%;"></div>
                                                    </div>
                                                    <span class="progress-text">
                                                        <?php echo $fum['sesiones_completadas']; ?>/<?php echo $fum['numero_sesiones']; ?>
                                                    </span>
                                                </div>
                                                <?php if ($activa && ($fum['sesiones_pendientes_count'] ?? 0) > 0): ?>
                                                    <small style="color: var(--accent);">
                                                        <?php echo $fum['sesiones_pendientes_count']; ?> sesión(es) pendiente(s)
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($fum['proxima_fecha'])): ?>
                                                    <?php 
                                                    $fecha_prox = new DateTime($fum['proxima_fecha']);
                                                    $hoy = new DateTime();
                                                    $dias = $hoy->diff($fecha_prox)->days;
                                                    $es_hoy = $fecha_prox->format('Y-m-d') == $hoy->format('Y-m-d');
                                                    ?>
                                                    <strong><?php echo $fecha_prox->format('d/m/Y'); ?></strong>
                                                    <br>
                                                    <?php if ($es_hoy): ?>
                                                        <small class="badge badge-danger">HOY</small>
                                                    <?php elseif ($fecha_prox < $hoy): ?>
                                                        <small class="badge badge-danger">Atrasada</small>
                                                    <?php elseif ($dias == 1): ?>
                                                        <small class="badge badge-warning">Mañana</small>
                                                    <?php else: ?>
                                                        <small style="color: var(--text-lighter);">En <?php echo $dias; ?> días</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($activa): ?>
                                                        <span class="badge badge-warning">Por agendar</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-success">Completada</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $activa ? 'badge-activa' : 'badge-completada'; ?>">
                                                    <?php echo $activa ? 'Activa' : 'Completada'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($activa && ($fum['sesiones_pendientes_count'] ?? 0) > 0): ?>
                                                        <a href="realizar_sesion.php?fumigacion_id=<?php echo $fum['id']; ?>" class="btn-action btn-session" title="Realizar próxima sesión">
                                                            <i class='bx bx-play'></i> Realizar
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="ver_fumigacion.php?id=<?php echo $fum['id']; ?>" class="btn-action btn-view" title="Ver detalles">
                                                        <i class='bx bx-show'></i> Ver
                                                    </a>
                                                    <a href="historial.php?cliente=<?php echo urlencode($fum['nombre_cliente'] ?? ''); ?>" class="btn-action btn-history" title="Ver historial">
                                                        <i class='bx bx-history'></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class='bx bx-calendar-x'></i>
                                                <h3>No hay fumigaciones registradas</h3>
                                                <p>No se encontraron fumigaciones con los filtros seleccionados.</p>
                                                <div class="empty-actions">
                                                    <a href="nueva_inspeccion.php" class="btn btn-primary">
                                                        <i class='bx bx-plus'></i> Nueva Inspección
                                                    </a>
                                                    <a href="fumigaciones_activas.php" class="btn btn-secondary">
                                                        <i class='bx bx-reset'></i> Ver todas
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
            const estado = document.getElementById('filtro_estado').value;
            const tecnico = document.getElementById('filtro_tecnico').value;
            const busqueda = document.getElementById('busqueda').value;
            
            let url = 'fumigaciones_activas.php?';
            const params = [];
            
            if (estado && estado !== 'activas') params.push(`estado=${encodeURIComponent(estado)}`);
            if (tecnico) params.push(`tecnico=${encodeURIComponent(tecnico)}`);
            if (busqueda) params.push(`busqueda=${encodeURIComponent(busqueda)}`);
            
            window.location.href = url + params.join('&');
        }

        document.getElementById('busqueda').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                aplicarFiltros();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
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