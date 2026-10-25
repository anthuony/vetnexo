<?php
// cliente/ver_mascota.php
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
$mascota_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($mascota_id <= 0) {
    header("Location: mis_mascotas.php");
    exit();
}

// Obtener datos de la mascota verificando que pertenezca al cliente
$stmt = $conn->prepare("
    SELECT m.*, 
           e.nombre as especie_nombre,
           (SELECT COUNT(*) FROM vacunas v WHERE v.mascota_id = m.id) as total_vacunas,
           (SELECT COUNT(*) FROM consultas c WHERE c.mascota_id = m.id) as total_consultas,
           (SELECT COUNT(*) FROM citas ct WHERE ct.mascota_id = m.id AND ct.estado = 'completed') as citas_completadas,
           (SELECT COUNT(*) FROM citas ct WHERE ct.mascota_id = m.id AND ct.estado = 'scheduled' AND ct.fecha_cita >= CURDATE()) as citas_proximas
    FROM mascotas m
    LEFT JOIN especies e ON m.especie_id = e.id
    WHERE m.id = ? AND m.cliente_id = ?
");
$stmt->execute([$mascota_id, $cliente_id]);
$mascota = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mascota) {
    header("Location: mis_mascotas.php");
    exit();
}

// Obtener historial de vacunas
$stmt = $conn->prepare("
    SELECT v.*, 
           DATEDIFF(v.proxima_dosis, CURDATE()) as dias_restantes
    FROM vacunas v
    WHERE v.mascota_id = ?
    ORDER BY v.fecha_aplicacion DESC
");
$stmt->execute([$mascota_id]);
$vacunas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener historial de consultas
$stmt = $conn->prepare("
    SELECT c.*, 
           u.nombre as veterinario_nombre,
           s.nombre as servicio_nombre
    FROM consultas c
    LEFT JOIN usuarios u ON c.veterinario_id = u.id
    LEFT JOIN servicios s ON c.id = s.id
    WHERE c.mascota_id = ?
    ORDER BY c.fecha_consulta DESC
");
$stmt->execute([$mascota_id]);
$consultas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener historial de citas
$stmt = $conn->prepare("
    SELECT ct.*, 
           s.nombre as servicio_nombre,
           u.nombre as veterinario_nombre,
           CASE 
               WHEN ct.estado = 'scheduled' AND ct.fecha_cita >= CURDATE() THEN 'proxima'
               ELSE ct.estado
           END as estado_actual
    FROM citas ct
    LEFT JOIN servicios s ON ct.servicio_id = s.id
    LEFT JOIN usuarios u ON ct.veterinario_id = u.id
    WHERE ct.mascota_id = ?
    ORDER BY ct.fecha_cita DESC
");
$stmt->execute([$mascota_id]);
$citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar notificaciones no leídas
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notificaciones_cliente 
    WHERE cliente_id = ? AND leida = 0
");
$stmt->execute([$cliente_id]);
$notificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
$notificaciones_count = $notificaciones['total'] ?? 0;

// Función para calcular edad
function calcularEdad($fecha_nacimiento) {
    if (!$fecha_nacimiento) return 'No registrada';
    
    $nacimiento = new DateTime($fecha_nacimiento);
    $hoy = new DateTime();
    $diferencia = $hoy->diff($nacimiento);
    
    if ($diferencia->y > 0) {
        return $diferencia->y . ' año' . ($diferencia->y != 1 ? 's' : '');
    } elseif ($diferencia->m > 0) {
        return $diferencia->m . ' mes' . ($diferencia->m != 1 ? 'es' : '');
    } else {
        return $diferencia->d . ' día' . ($diferencia->d != 1 ? 's' : '');
    }
}

// Función para obtener clase de estado
function getEstadoCitaClass($estado) {
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
        case 'proxima':
            return 'info';
        default:
            return 'secondary';
    }
}

// Función para texto del estado
function getEstadoCitaTexto($estado) {
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
        case 'proxima':
            return 'Próxima';
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
    <title><?php echo htmlspecialchars($mascota['nombre']); ?> - Historial Médico</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
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
            max-width: 1200px;
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

        .btn-edit {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background: var(--accent);
            color: white;
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-edit:hover {
            background: var(--accent-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Profile Banner */
        .profile-banner {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
            box-shadow: var(--shadow-lg);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: var(--primary);
            box-shadow: var(--shadow-lg);
            border: 4px solid rgba(255,255,255,0.3);
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h2 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
        }

        .profile-tags {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .profile-tag {
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.2rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--gray);
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.6rem 1.5rem;
            background: transparent;
            border: none;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            color: var(--primary);
            background: var(--primary-soft);
        }

        .tab.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: var(--primary-soft);
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards Grid */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .info-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .card-header i {
            font-size: 1.3rem;
            color: var(--primary);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Info rows */
        .info-row {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .info-icon {
            width: 30px;
            color: var(--primary);
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--text-light);
            text-transform: uppercase;
        }

        .info-value {
            font-weight: 500;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary-soft);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2rem;
            top: 0.3rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid var(--white);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .timeline-date {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.2rem;
        }

        .timeline-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-dark);
        }

        .timeline-desc {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .timeline-vet {
            font-size: 0.8rem;
            color: var(--primary);
            margin-top: 0.2rem;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-primary { background: #d1ecf1; color: #0c5460; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 1.5rem;
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
            
            .profile-banner {
                flex-direction: column;
                text-align: center;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                width: 100%;
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
                        <?php echo strtoupper(substr($_SESSION['nombre'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars(explode(' ', $_SESSION['nombre'])[0]); ?></h4>
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
        <a href="mis_mascotas.php">Mis Mascotas</a>
        <i class='bx bx-chevron-right'></i>
        <span><?php echo htmlspecialchars($mascota['nombre']); ?></span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-paw'></i>
                Historial Médico
            </h1>
            <div style="display: flex; gap: 1rem;">
                <a href="editar_mascota.php?id=<?php echo $mascota_id; ?>" class="btn-edit">
                    <i class='bx bx-edit'></i>
                    Editar información
                </a>
                <a href="mis_mascotas.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver
                </a>
            </div>
        </div>

        <!-- Profile Banner -->
        <div class="profile-banner">
            <div class="profile-avatar">
                <i class='bx bxs-dog'></i>
            </div>
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($mascota['nombre']); ?></h2>
                <div class="profile-tags">
                    <span class="profile-tag">
                        <i class='bx bx-category'></i>
                        <?php echo htmlspecialchars($mascota['especie_nombre'] ?? 'Mascota'); ?>
                    </span>
                    <?php if ($mascota['raza']): ?>
                        <span class="profile-tag">
                            <i class='bx bx-dna'></i>
                            <?php echo htmlspecialchars($mascota['raza']); ?>
                        </span>
                    <?php endif; ?>
                    <span class="profile-tag">
                        <i class='bx bx-<?php echo $mascota['sexo'] == 'macho' ? 'male' : 'female'; ?>'></i>
                        <?php echo ucfirst($mascota['sexo']); ?>
                    </span>
                    <?php if ($mascota['color']): ?>
                        <span class="profile-tag">
                            <i class='bx bx-palette'></i>
                            <?php echo htmlspecialchars($mascota['color']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $mascota['total_vacunas']; ?></div>
                <div class="stat-label">Vacunas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $mascota['total_consultas']; ?></div>
                <div class="stat-label">Consultas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $mascota['citas_completadas']; ?></div>
                <div class="stat-label">Citas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $mascota['citas_proximas']; ?></div>
                <div class="stat-label">Próximas</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('info')">
                <i class='bx bx-info-circle'></i>
                Información General
            </button>
            <button class="tab" onclick="showTab('vacunas')">
                <i class='bx bx-syringe'></i>
                Vacunas (<?php echo count($vacunas); ?>)
            </button>
            <button class="tab" onclick="showTab('consultas')">
                <i class='bx bx-clinic'></i>
                Consultas (<?php echo count($consultas); ?>)
            </button>
            <button class="tab" onclick="showTab('citas')">
                <i class='bx bx-calendar'></i>
                Historial de Citas (<?php echo count($citas); ?>)
            </button>
        </div>

        <!-- Tab: Información General -->
        <div id="tab-info" class="tab-content active">
            <div class="cards-grid">
                <!-- Datos básicos -->
                <div class="info-card">
                    <div class="card-header">
                        <i class='bx bx-info-circle'></i>
                        <h3>Datos Básicos</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <div class="info-icon">
                                <i class='bx bx-calendar'></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Fecha de nacimiento</div>
                                <div class="info-value">
                                    <?php echo $mascota['fecha_nacimiento'] ? date('d/m/Y', strtotime($mascota['fecha_nacimiento'])) : 'No registrada'; ?>
                                    <?php if ($mascota['fecha_nacimiento']): ?>
                                        <br>
                                        <small>(<?php echo calcularEdad($mascota['fecha_nacimiento']); ?>)</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-icon">
                                <i class='bx bx-weight'></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Peso</div>
                                <div class="info-value">
                                    <?php echo $mascota['peso'] ? $mascota['peso'] . ' kg' : 'No registrado'; ?>
                                </div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-icon">
                                <i class='bx bx-calendar-check'></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Registrado desde</div>
                                <div class="info-value">
                                    <?php echo date('d/m/Y', strtotime($mascota['fecha_registro'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notas y observaciones -->
                <?php if (!empty($mascota['notas']) || !empty($mascota['alergias']) || !empty($mascota['historial_medico'])): ?>
                <div class="info-card">
                    <div class="card-header">
                        <i class='bx bx-note'></i>
                        <h3>Notas y Observaciones</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($mascota['alergias'])): ?>
                            <div class="info-row">
                                <div class="info-icon">
                                    <i class='bx bx-x-circle' style="color: var(--danger);"></i>
                                </div>
                                <div class="info-content">
                                    <div class="info-label">Alergias</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($mascota['alergias'])); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($mascota['historial_medico'])): ?>
                            <div class="info-row">
                                <div class="info-icon">
                                    <i class='bx bx-history'></i>
                                </div>
                                <div class="info-content">
                                    <div class="info-label">Historial médico</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($mascota['historial_medico'])); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($mascota['notas'])): ?>
                            <div class="info-row">
                                <div class="info-icon">
                                    <i class='bx bx-note'></i>
                                </div>
                                <div class="info-content">
                                    <div class="info-label">Notas adicionales</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($mascota['notas'])); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Última actividad -->
                <div class="info-card">
                    <div class="card-header">
                        <i class='bx bx-trending-up'></i>
                        <h3>Resumen de Actividad</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <div class="info-icon">
                                <i class='bx bx-syringe'></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Vacunas aplicadas</div>
                                <div class="info-value"><?php echo $mascota['total_vacunas']; ?></div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-icon">
                                <i class='bx bx-clinic'></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Consultas realizadas</div>
                                <div class="info-value"><?php echo $mascota['total_consultas']; ?></div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-icon">
                                <i class='bx bx-calendar-check'></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Citas completadas</div>
                                <div class="info-value"><?php echo $mascota['citas_completadas']; ?></div>
                            </div>
                        </div>

                        <?php if ($mascota['citas_proximas'] > 0): ?>
                            <div class="info-row">
                                <div class="info-icon">
                                    <i class='bx bx-calendar-exclamation' style="color: var(--accent);"></i>
                                </div>
                                <div class="info-content">
                                    <div class="info-label">Próximas citas</div>
                                    <div class="info-value"><?php echo $mascota['citas_proximas']; ?> programadas</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Vacunas -->
        <div id="tab-vacunas" class="tab-content">
            <?php if (!empty($vacunas)): ?>
                <div class="cards-grid">
                    <?php foreach ($vacunas as $vacuna): ?>
                        <div class="info-card">
                            <div class="card-header">
                                <i class='bx bx-syringe'></i>
                                <h3><?php echo htmlspecialchars($vacuna['nombre_vacuna']); ?></h3>
                            </div>
                            <div class="card-body">
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-date">Aplicación: <?php echo date('d/m/Y', strtotime($vacuna['fecha_aplicacion'])); ?></div>
                                        <div class="timeline-title">Vacuna aplicada</div>
                                        <?php if ($vacuna['veterinario']): ?>
                                            <div class="timeline-vet">
                                                <i class='bx bx-user'></i> Dr. <?php echo htmlspecialchars($vacuna['veterinario']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($vacuna['proxima_dosis']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-date">Próxima: <?php echo date('d/m/Y', strtotime($vacuna['proxima_dosis'])); ?></div>
                                            <div class="timeline-title">
                                                Próxima dosis
                                                <?php
                                                if ($vacuna['dias_restantes'] !== null) {
                                                    if ($vacuna['dias_restantes'] < 0) {
                                                        echo '<span class="badge badge-danger">Vencida</span>';
                                                    } elseif ($vacuna['dias_restantes'] <= 7) {
                                                        echo '<span class="badge badge-warning">Próximamente</span>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($vacuna['observaciones'])): ?>
                                        <div class="timeline-desc" style="margin-top: 0.5rem;">
                                            <i class='bx bx-note'></i>
                                            <?php echo nl2br(htmlspecialchars($vacuna['observaciones'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-syringe'></i>
                    <h3>No hay vacunas registradas</h3>
                    <p>Aún no se ha registrado ninguna vacuna para <?php echo htmlspecialchars($mascota['nombre']); ?>.</p>
                    <a href="ver_mascota.php?id=<?php echo $mascota_id; ?>#vacunas" title="Las vacunas son registradas por el personal veterinario" class="btn-primary" style="display: inline-block; padding: 0.8rem 1.5rem; background: var(--primary); color: white; text-decoration: none; border-radius: 50px;">
                        <i class='bx bx-plus'></i>
                        Registrar vacuna
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Consultas -->
        <div id="tab-consultas" class="tab-content">
            <?php if (!empty($consultas)): ?>
                <div class="cards-grid">
                    <?php foreach ($consultas as $consulta): ?>
                        <div class="info-card">
                            <div class="card-header">
                                <i class='bx bx-clinic'></i>
                                <h3>Consulta del <?php echo date('d/m/Y', strtotime($consulta['fecha_consulta'])); ?></h3>
                            </div>
                            <div class="card-body">
                                <?php if ($consulta['veterinario_nombre']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-user'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Veterinario</div>
                                            <div class="info-value"><?php echo htmlspecialchars($consulta['veterinario_nombre']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($consulta['servicio_nombre']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-category'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Servicio</div>
                                            <div class="info-value"><?php echo htmlspecialchars($consulta['servicio_nombre']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($consulta['motivo']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-question-mark'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Motivo</div>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($consulta['motivo'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($consulta['diagnostico']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-clinic'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Diagnóstico</div>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($consulta['diagnostico'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($consulta['tratamiento']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-health'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Tratamiento</div>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($consulta['tratamiento'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($consulta['observaciones']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-note'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Observaciones</div>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($consulta['observaciones'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-clinic'></i>
                    <h3>No hay consultas registradas</h3>
                    <p><?php echo htmlspecialchars($mascota['nombre']); ?> no ha tenido consultas aún.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Historial de Citas -->
        <div id="tab-citas" class="tab-content">
            <?php if (!empty($citas)): ?>
                <div class="cards-grid">
                    <?php foreach ($citas as $cita): ?>
                        <div class="info-card">
                            <div class="card-header">
                                <i class='bx bx-calendar'></i>
                                <h3><?php echo date('d/m/Y', strtotime($cita['fecha_cita'])); ?> - <?php echo date('H:i', strtotime($cita['fecha_cita'])); ?> hrs</h3>
                                <span class="badge badge-<?php echo getEstadoCitaClass($cita['estado_actual']); ?>" style="margin-left: auto;">
                                    <?php echo getEstadoCitaTexto($cita['estado_actual']); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="info-row">
                                    <div class="info-icon">
                                        <i class='bx bx-category'></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Servicio</div>
                                        <div class="info-value"><?php echo htmlspecialchars($cita['servicio_nombre']); ?></div>
                                    </div>
                                </div>

                                <?php if ($cita['veterinario_nombre']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-user'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Veterinario</div>
                                            <div class="info-value"><?php echo htmlspecialchars($cita['veterinario_nombre']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($cita['costo_total'] > 0): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-dollar-circle'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Costo</div>
                                            <div class="info-value">$<?php echo number_format($cita['costo_total'], 2); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($cita['notas']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class='bx bx-note'></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Notas</div>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($cita['notas'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: 1rem;">
                                    <a href="ver_cita.php?id=<?php echo $cita['id']; ?>" class="btn-primary" style="display: inline-block; padding: 0.5rem 1rem; background: var(--primary); color: white; text-decoration: none; border-radius: var(--radius-sm); font-size: 0.85rem;">
                                        <i class='bx bx-show'></i>
                                        Ver detalles
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-calendar-x'></i>
                    <h3>No hay citas registradas</h3>
                    <p><?php echo htmlspecialchars($mascota['nombre']); ?> no tiene citas en su historial.</p>
                    <a href="nueva_cita.php?mascota_id=<?php echo $mascota_id; ?>" class="btn-primary" style="display: inline-block; padding: 0.8rem 1.5rem; background: var(--primary); color: white; text-decoration: none; border-radius: 50px;">
                        <i class='bx bx-plus'></i>
                        Agendar cita
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        function showTab(tabName) {
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Desactivar todas las pestañas
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Activar la pestaña seleccionada
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Si hay un hash en la URL, activar esa pestaña
        window.addEventListener('load', function() {
            const hash = window.location.hash.substring(1);
            if (hash && ['info', 'vacunas', 'consultas', 'citas'].includes(hash)) {
                showTab(hash);
            }
        });
    </script>
</body>
</html>