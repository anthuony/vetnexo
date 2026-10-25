<?php
// cliente/mis_citas.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

// Verificar que sea cliente
if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] != 'cliente') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'];

// Token CSRF para acciones que modifican datos
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Obtener datos del cliente
$stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Parámetros de filtrado
$filtro_estado = $_GET['estado'] ?? 'todas';
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';

// Construir consulta base
$sql = "
    SELECT c.*, 
           m.nombre as mascota_nombre, 
           m.especie_id,
           s.nombre as servicio_nombre, 
           s.precio as servicio_precio,
           s.categoria,
           u.nombre as veterinario_nombre
    FROM citas c
    JOIN mascotas m ON c.mascota_id = m.id
    JOIN servicios s ON c.servicio_id = s.id
    LEFT JOIN usuarios u ON c.veterinario_id = u.id
    WHERE c.cliente_id = ?
";

$params = [$cliente_id];

// Aplicar filtros
if ($filtro_estado != 'todas') {
    $sql .= " AND c.estado = ?";
    $params[] = $filtro_estado;
}

if ($filtro_tipo != 'todos') {
    if ($filtro_tipo == 'consulta') {
        $sql .= " AND s.categoria IN ('consultation', 'emergency')";
    } elseif ($filtro_tipo == 'vacunacion') {
        $sql .= " AND s.categoria = 'vaccination'";
    } elseif ($filtro_tipo == 'peluqueria') {
        $sql .= " AND s.categoria = 'grooming'";
    } elseif ($filtro_tipo == 'cirugia') {
        $sql .= " AND s.categoria = 'surgery'";
    }
}

if (!empty($filtro_fecha_desde)) {
    $sql .= " AND DATE(c.fecha_cita) >= ?";
    $params[] = $filtro_fecha_desde;
}

if (!empty($filtro_fecha_hasta)) {
    $sql .= " AND DATE(c.fecha_cita) <= ?";
    $params[] = $filtro_fecha_hasta;
}

$sql .= " ORDER BY c.fecha_cita DESC";

// Ejecutar consulta
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas de citas
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_citas,
        SUM(CASE WHEN c.estado = 'completed' THEN 1 ELSE 0 END) as completadas,
        SUM(CASE WHEN c.estado = 'scheduled' AND c.fecha_cita >= CURDATE() THEN 1 ELSE 0 END) as proximas,
        SUM(CASE WHEN c.estado = 'cancelled' THEN 1 ELSE 0 END) as canceladas,
        COALESCE(SUM(CASE WHEN c.estado = 'completed' THEN c.costo_total ELSE 0 END), 0) as total_gastado
    FROM citas c
    WHERE c.cliente_id = ?
");
$stmt->execute([$cliente_id]);
$estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);

// Contar notificaciones no leídas
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notificaciones_cliente 
    WHERE cliente_id = ? AND leida = 0
");
$stmt->execute([$cliente_id]);
$notificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
$notificaciones_count = $notificaciones['total'] ?? 0;

// Función para obtener el icono del servicio
function getServicioIcono($categoria) {
    switch($categoria) {
        case 'consultation':
        case 'emergency':
            return 'bx-plus-medical';
        case 'vaccination':
            return 'bx-shield-plus';
        case 'grooming':
            return 'bx-cut';
        case 'surgery':
            return 'bx-plus-circle';
        default:
            return 'bx-calendar';
    }
}

// Función para el color del estado
function getEstadoColor($estado) {
    switch($estado) {
        case 'scheduled':
            return 'warning';
        case 'confirmed':
            return 'info';
        case 'in_progress':
            return 'primary';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        case 'no_show':
            return 'secondary';
        default:
            return 'secondary';
    }
}

// Función para texto del estado
function getEstadoTexto($estado) {
    switch($estado) {
        case 'scheduled':
            return 'Pendiente';
        case 'confirmed':
            return 'Confirmada';
        case 'in_progress':
            return 'En progreso';
        case 'completed':
            return 'Completada';
        case 'cancelled':
            return 'Cancelada';
        case 'no_show':
            return 'No asistió';
        default:
            return ucfirst($estado);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Citas - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
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
            --secondary: #9E9E9E;
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

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--gray);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }

        .logo-img {
            height: 45px;
            width: auto;
        }

        .logo span {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .notifications {
            position: relative;
            cursor: pointer;
        }

        .notifications i {
            font-size: 1.5rem;
            color: var(--text-light);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--accent);
            color: white;
            font-size: 0.7rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 1px solid var(--primary-light);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .user-details h4 {
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .user-details span {
            font-size: 0.75rem;
            color: var(--text-light);
        }

        .logout-btn {
            color: var(--text-light);
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }

        .logout-btn:hover {
            color: var(--danger);
        }

        /* Breadcrumb */
        .breadcrumb {
            max-width: 1400px;
            margin: 1rem auto 0;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header h1 i {
            color: var(--accent);
        }

        .btn-new {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-new:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            background: var(--gray-light);
            border-color: var(--primary);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .stat-info p {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--gray);
            box-shadow: var(--shadow-sm);
        }

        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.6rem 1rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-filter {
            padding: 0.6rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
        }

        .btn-clear {
            padding: 0.6rem 1.5rem;
            background: var(--gray-light);
            color: var(--text-dark);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-clear:hover {
            background: var(--gray);
        }

        /* Pestañas */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.6rem 1.5rem;
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: 50px;
            color: var(--text-dark);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .tab i {
            font-size: 1.1rem;
        }

        /* Grid de citas */
        .citas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .cita-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .cita-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .cita-header {
            padding: 1rem 1.2rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .cita-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: var(--white);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .cita-header-info {
            flex: 1;
        }

        .cita-header-info h3 {
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
        }

        .cita-header-info p {
            color: var(--text-light);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .cita-body {
            padding: 1.2rem;
        }

        .cita-detail {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 0.8rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .cita-detail i {
            width: 20px;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .cita-detail strong {
            font-weight: 600;
            margin-right: 0.3rem;
        }

        .cita-veterinario {
            background: var(--gray-light);
            padding: 0.6rem 1rem;
            border-radius: var(--radius-sm);
            margin: 1rem 0 0.5rem;
            font-size: 0.9rem;
        }

        .cita-veterinario i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        .cita-footer {
            padding: 1rem 1.2rem;
            border-top: 1px solid var(--gray);
            background: var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cita-estado {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .estado-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .estado-scheduled { background: #fff3cd; color: #856404; }
        .estado-confirmed { background: #cce5ff; color: #004085; }
        .estado-in_progress { background: #d1ecf1; color: #0c5460; }
        .estado-completed { background: #d4edda; color: #155724; }
        .estado-cancelled { background: #f8d7da; color: #721c24; }
        .estado-no_show { background: #e2e3e5; color: #383d41; }

        .cita-acciones {
            display: flex;
            gap: 0.5rem;
        }

        .btn-card {
            padding: 0.4rem 0.8rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s ease;
        }

        .btn-view {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
        }

        .btn-cancel {
            background: #f8d7da;
            color: var(--danger);
        }

        .btn-cancel:hover {
            background: var(--danger);
            color: white;
        }

        /* Estado vacío */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 2rem;
        }

        .empty-state .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 2rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
        }

        .empty-state .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        /* Paginación (placeholder) */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-item {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: 10px;
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-item:hover,
        .page-item.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 2rem 0;
            margin-top: 3rem;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
        }

        .footer-content p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .user-menu {
                width: 100%;
                justify-content: space-between;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filters-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .btn-filter,
            .btn-clear {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                width: 100%;
                justify-content: center;
            }
            
            .citas-grid {
                grid-template-columns: 1fr;
            }
            
            .cita-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .cita-acciones {
                width: 100%;
            }
            
            .btn-card {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="dashboard.php" class="logo">
                <img src="../includes/img/logo.svg" alt="VetNexo" class="logo-img">
                <span>Mi Portal</span>
            </a>

            <div class="user-menu">
                <div class="notifications">
                    <i class='bx bx-bell'></i>
                    <?php if ($notificaciones_count > 0): ?>
                        <span class="notification-badge"><?php echo $notificaciones_count; ?></span>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($cliente['nombre'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars(explode(' ', $cliente['nombre'])[0]); ?></h4>
                        <span><i class='bx bx-user-circle'></i> Cliente</span>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn"><i class='bx bx-log-out'></i></a>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="dashboard.php"><i class='bx bx-home'></i> Inicio</a>
        <i class='bx bx-chevron-right'></i>
        <span>Mis Citas</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-calendar'></i>
                Mis Citas
            </h1>
            <div style="display: flex; gap: 1rem;">
                <a href="dashboard.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver
                </a>
                <a href="nueva_cita.php" class="btn-new">
                    <i class='bx bx-plus'></i>
                    Nueva Cita
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-calendar'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $estadisticas['total_citas'] ?? 0; ?></h3>
                    <p>Total de citas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-check-circle'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $estadisticas['completadas'] ?? 0; ?></h3>
                    <p>Completadas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-time'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $estadisticas['proximas'] ?? 0; ?></h3>
                    <p>Próximas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-dollar-circle'></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($estadisticas['total_gastado'] ?? 0, 2); ?></h3>
                    <p>Total gastado</p>
                </div>
            </div>
        </div>

        <!-- Pestañas rápidas -->
        <div class="tabs">
            <a href="mis_citas.php" class="tab <?php echo $filtro_estado == 'todas' ? 'active' : ''; ?>">
                <i class='bx bx-calendar-alt'></i>
                Todas
            </a>
            <a href="mis_citas.php?estado=scheduled" class="tab <?php echo $filtro_estado == 'scheduled' ? 'active' : ''; ?>">
                <i class='bx bx-time'></i>
                Pendientes
            </a>
            <a href="mis_citas.php?estado=confirmed" class="tab <?php echo $filtro_estado == 'confirmed' ? 'active' : ''; ?>">
                <i class='bx bx-check'></i>
                Confirmadas
            </a>
            <a href="mis_citas.php?estado=completed" class="tab <?php echo $filtro_estado == 'completed' ? 'active' : ''; ?>">
                <i class='bx bx-check-circle'></i>
                Completadas
            </a>
            <a href="mis_citas.php?estado=cancelled" class="tab <?php echo $filtro_estado == 'cancelled' ? 'active' : ''; ?>">
                <i class='bx bx-x-circle'></i>
                Canceladas
            </a>
        </div>

        <!-- Filtros -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label><i class='bx bx-filter-alt'></i> Tipo de servicio</label>
                    <select name="tipo">
                        <option value="todos" <?php echo $filtro_tipo == 'todos' ? 'selected' : ''; ?>>Todos los servicios</option>
                        <option value="consulta" <?php echo $filtro_tipo == 'consulta' ? 'selected' : ''; ?>>Consultas</option>
                        <option value="vacunacion" <?php echo $filtro_tipo == 'vacunacion' ? 'selected' : ''; ?>>Vacunación</option>
                        <option value="peluqueria" <?php echo $filtro_tipo == 'peluqueria' ? 'selected' : ''; ?>>Peluquería</option>
                        <option value="cirugia" <?php echo $filtro_tipo == 'cirugia' ? 'selected' : ''; ?>>Cirugías</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class='bx bx-calendar'></i> Desde</label>
                    <input type="date" name="fecha_desde" value="<?php echo htmlspecialchars($filtro_fecha_desde); ?>">
                </div>
                <div class="filter-group">
                    <label><i class='bx bx-calendar'></i> Hasta</label>
                    <input type="date" name="fecha_hasta" value="<?php echo htmlspecialchars($filtro_fecha_hasta); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class='bx bx-filter'></i>
                        Filtrar
                    </button>
                    <a href="mis_citas.php" class="btn-clear">
                        <i class='bx bx-reset'></i>
                        Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Grid de citas -->
        <?php if (!empty($citas)): ?>
            <div class="citas-grid">
                <?php foreach ($citas as $cita): 
                    $icono = getServicioIcono($cita['categoria'] ?? '');
                    $estado_color = getEstadoColor($cita['estado']);
                    $estado_texto = getEstadoTexto($cita['estado']);
                ?>
                    <div class="cita-card">
                        <div class="cita-header">
                            <div class="cita-icon">
                                <i class='bx <?php echo $icono; ?>'></i>
                            </div>
                            <div class="cita-header-info">
                                <h3><?php echo htmlspecialchars($cita['servicio_nombre']); ?></h3>
                                <p><i class='bx bx-calendar'></i> <?php echo date('d/m/Y', strtotime($cita['fecha_cita'])); ?></p>
                            </div>
                        </div>
                        <div class="cita-body">
                            <div class="cita-detail">
                                <i class='bx bx-paw'></i>
                                <div><strong>Mascota:</strong> <?php echo htmlspecialchars($cita['mascota_nombre']); ?></div>
                            </div>
                            <div class="cita-detail">
                                <i class='bx bx-time'></i>
                                <div><strong>Hora:</strong> <?php echo date('H:i', strtotime($cita['fecha_cita'])); ?> hrs</div>
                            </div>
                            <?php if ($cita['veterinario_nombre']): ?>
                                <div class="cita-detail">
                                    <i class='bx bx-user'></i>
                                    <div><strong>Veterinario:</strong> <?php echo htmlspecialchars($cita['veterinario_nombre']); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($cita['costo_total'] > 0): ?>
                                <div class="cita-detail">
                                    <i class='bx bx-dollar-circle'></i>
                                    <div><strong>Costo:</strong> $<?php echo number_format($cita['costo_total'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($cita['notas'])): ?>
                                <div class="cita-veterinario">
                                    <i class='bx bx-note'></i>
                                    <?php echo htmlspecialchars(substr($cita['notas'], 0, 100)) . (strlen($cita['notas']) > 100 ? '...' : ''); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="cita-footer">
                            <div class="cita-estado">
                                <span class="estado-badge estado-<?php echo $cita['estado']; ?>">
                                    <?php echo $estado_texto; ?>
                                </span>
                            </div>
                            <div class="cita-acciones">
                                <a href="ver_cita.php?id=<?php echo $cita['id']; ?>" class="btn-card btn-view" title="Ver detalles">
                                    <i class='bx bx-show'></i>
                                    Ver
                                </a>
                                <?php if ($cita['estado'] == 'scheduled' || $cita['estado'] == 'confirmed'): ?>
                                    <form action="cancelar_cita.php" method="post" style="display:inline; margin:0;" onsubmit="return confirm('¿Estás seguro de cancelar esta cita?')"><?php echo av_csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cita['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn-card btn-cancel" title="Cancelar cita" style="border:0; cursor:pointer;">
                                            <i class='bx bx-x'></i>
                                            Cancelar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- La lista muestra todas las citas filtradas; se retiró la paginación ficticia. -->

        <?php else: ?>
            <!-- Estado vacío -->
            <div class="empty-state">
                <i class='bx bx-calendar-x'></i>
                <h3>No hay citas para mostrar</h3>
                <p>No se encontraron citas con los filtros seleccionados. ¡Agenda una nueva cita para tu mascota!</p>
                <a href="nueva_cita.php" class="btn">
                    <i class='bx bx-plus'></i>
                    Agendar nueva cita
                </a>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>
</body>
</html>