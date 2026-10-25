<?php
// cliente/ver_cita.php
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
$cita_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($cita_id <= 0) {
    header("Location: mis_citas.php");
    exit();
}

// Obtener datos de la cita verificando que pertenezca al cliente
$stmt = $conn->prepare("
    SELECT c.*, 
           m.nombre as mascota_nombre, 
           m.especie_id,
           m.raza as mascota_raza,
           m.fecha_nacimiento as mascota_fecha_nacimiento,
           m.color as mascota_color,
           m.peso as mascota_peso,
           s.nombre as servicio_nombre,
           s.descripcion as servicio_descripcion,
           s.categoria as servicio_categoria,
           s.precio as servicio_precio,
           s.duracion as servicio_duracion,
           u.nombre as veterinario_nombre,
           u.email as veterinario_email,
           cl.nombre as cliente_nombre,
           cl.telefono as cliente_telefono,
           cl.email as cliente_email
    FROM citas c
    JOIN mascotas m ON c.mascota_id = m.id
    JOIN servicios s ON c.servicio_id = s.id
    LEFT JOIN usuarios u ON c.veterinario_id = u.id
    JOIN clientes cl ON c.cliente_id = cl.id
    WHERE c.id = ? AND c.cliente_id = ?
");
$stmt->execute([$cita_id, $cliente_id]);
$cita = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cita) {
    header("Location: mis_citas.php");
    exit();
}

// Obtener factura asociada si existe
$stmt = $conn->prepare("
    SELECT f.*
    FROM facturas f
    WHERE f.cita_id = ? AND f.cliente_id = ?
    ORDER BY f.id DESC
    LIMIT 1
");
$stmt->execute([$cita_id, $cliente_id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

// Función para obtener clase de estado
function getEstadoClass($estado) {
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

// Función para icono de categoría
function getCategoriaIcono($categoria) {
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
        case 'laboratory':
            return 'bx-test-tube';
        default:
            return 'bx-calendar';
    }
}

// Contar notificaciones no leídas
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notificaciones_cliente 
    WHERE cliente_id = ? AND leida = 0
");
$stmt->execute([$cliente_id]);
$notificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
$notificaciones_count = $notificaciones['total'] ?? 0;

// Calcular edad de la mascota si tiene fecha de nacimiento
$edad_mascota = '';
if ($cita['mascota_fecha_nacimiento']) {
    $nacimiento = new DateTime($cita['mascota_fecha_nacimiento']);
    $hoy = new DateTime();
    $diferencia = $hoy->diff($nacimiento);
    
    if ($diferencia->y > 0) {
        $edad_mascota = $diferencia->y . ' año' . ($diferencia->y != 1 ? 's' : '');
    } elseif ($diferencia->m > 0) {
        $edad_mascota = $diferencia->m . ' mes' . ($diferencia->m != 1 ? 'es' : '');
    } else {
        $edad_mascota = $diferencia->d . ' día' . ($diferencia->d != 1 ? 's' : '');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de Cita - VetNexo</title>
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

        /* Estado Badge */
        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.4rem 1.2rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .estado-scheduled {
            background: #fff3cd;
            color: #856404;
        }

        .estado-confirmed {
            background: #cce5ff;
            color: #004085;
        }

        .estado-in_progress {
            background: #d1ecf1;
            color: #0c5460;
        }

        .estado-completed {
            background: #d4edda;
            color: #155724;
        }

        .estado-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .estado-no_show {
            background: #e2e3e5;
            color: #383d41;
        }

        /* Cards Grid */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
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
            font-size: 1.5rem;
            color: var(--primary);
        }

        .card-header h2 {
            font-size: 1.1rem;
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
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .info-value small {
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: normal;
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--gray-light);
            border-color: var(--primary);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Invoice card */
        .invoice-card {
            background: var(--primary-soft);
            border: 2px dashed var(--primary-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .invoice-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .invoice-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--white);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .invoice-details h4 {
            font-size: 1rem;
            margin-bottom: 0.2rem;
        }

        .invoice-details p {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .invoice-amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        /* Notes section */
        .notes-section {
            background: var(--gray-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 4px solid var(--accent);
        }

        .notes-section h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            margin-bottom: 0.8rem;
            color: var(--text-dark);
        }

        .notes-section p {
            color: var(--text-light);
            line-height: 1.6;
            font-size: 0.95rem;
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
            
            .action-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .invoice-card {
                flex-direction: column;
                text-align: center;
            }
            
            .invoice-info {
                flex-direction: column;
                text-align: center;
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
                        <?php echo strtoupper(substr($cita['cliente_nombre'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars(explode(' ', $cita['cliente_nombre'])[0]); ?></h4>
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
        <a href="mis_citas.php">Mis Citas</a>
        <i class='bx bx-chevron-right'></i>
        <span>Detalle de Cita</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-calendar-detail'></i>
                Detalle de Cita
            </h1>
            <div style="display: flex; gap: 1rem;">
                <span class="estado-badge estado-<?php echo $cita['estado']; ?>">
                    <i class='bx bx-<?php 
                        echo $cita['estado'] == 'completed' ? 'check-circle' : 
                             ($cita['estado'] == 'cancelled' ? 'x-circle' : 
                             ($cita['estado'] == 'in_progress' ? 'time' : 'calendar')); 
                    ?>'></i>
                    <?php echo getEstadoTexto($cita['estado']); ?>
                </span>
                <a href="mis_citas.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver
                </a>
            </div>
        </div>

        <!-- Details Grid -->
        <div class="details-grid">
            <!-- Información de la Cita -->
            <div class="detail-card">
                <div class="card-header">
                    <i class='bx bx-calendar'></i>
                    <h2>Información de la Cita</h2>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-calendar'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Fecha y hora</div>
                            <div class="info-value">
                                <?php echo date('d/m/Y', strtotime($cita['fecha_cita'])); ?> 
                                a las <?php echo date('H:i', strtotime($cita['fecha_cita'])); ?> hrs
                            </div>
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-category'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Servicio</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($cita['servicio_nombre']); ?>
                                <br>
                                <small>
                                    <i class='bx bx-time'></i> Duración: <?php echo htmlspecialchars($cita['servicio_duracion'] ?? 'No especificada'); ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-dollar-circle'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Costo</div>
                            <div class="info-value">
                                <?php if ($cita['costo_total'] > 0): ?>
                                    $<?php echo number_format($cita['costo_total'], 2); ?>
                                <?php else: ?>
                                    <span class="text-muted">Por confirmar</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($cita['veterinario_nombre']): ?>
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-user'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Veterinario asignado</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($cita['veterinario_nombre']); ?>
                                <?php if ($cita['veterinario_email']): ?>
                                    <br>
                                    <small><?php echo htmlspecialchars($cita['veterinario_email']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Información de la Mascota -->
            <div class="detail-card">
                <div class="card-header">
                    <i class='bx bx-paw'></i>
                    <h2>Mascota</h2>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-dog'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Nombre</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['mascota_nombre']); ?></div>
                        </div>
                    </div>

                    <?php if ($cita['mascota_raza']): ?>
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-dna'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Raza</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['mascota_raza']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($edad_mascota): ?>
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-calendar'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Edad</div>
                            <div class="info-value"><?php echo $edad_mascota; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($cita['mascota_color']): ?>
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-palette'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Color</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['mascota_color']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($cita['mascota_peso']): ?>
                    <div class="info-row">
                        <div class="info-icon">
                            <i class='bx bx-weight'></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Peso</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['mascota_peso']); ?> kg</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top: 1rem;">
                        <a href="ver_mascota.php?id=<?php echo $cita['mascota_id']; ?>" class="btn-outline" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: var(--radius-sm); text-decoration: none;">
                            <i class='bx bx-show'></i>
                            Ver perfil completo de <?php echo htmlspecialchars($cita['mascota_nombre']); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Timeline de la cita (si tiene historial) -->
        <?php if ($cita['estado'] == 'completed' || $cita['estado'] == 'cancelled' || $cita['estado'] == 'no_show'): ?>
        <div class="detail-card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <i class='bx bx-history'></i>
                <h2>Historial de la cita</h2>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-date"><?php echo date('d/m/Y H:i', strtotime($cita['fecha_creacion'])); ?></div>
                        <div class="timeline-title">Cita programada</div>
                        <div class="timeline-desc">Se agendó la cita para <?php echo date('d/m/Y', strtotime($cita['fecha_cita'])); ?></div>
                    </div>
                    
                    <?php if ($cita['estado'] == 'confirmed'): ?>
                    <div class="timeline-item">
                        <div class="timeline-date"><?php echo date('d/m/Y H:i', strtotime($cita['updated_at'])); ?></div>
                        <div class="timeline-title">Cita confirmada</div>
                        <div class="timeline-desc">La cita ha sido confirmada por el veterinario</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($cita['estado'] == 'in_progress'): ?>
                    <div class="timeline-item">
                        <div class="timeline-date"><?php echo date('d/m/Y H:i', strtotime($cita['updated_at'])); ?></div>
                        <div class="timeline-title">Cita en progreso</div>
                        <div class="timeline-desc">La atención ha comenzado</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($cita['estado'] == 'completed'): ?>
                    <div class="timeline-item">
                        <div class="timeline-date"><?php echo date('d/m/Y H:i', strtotime($cita['updated_at'])); ?></div>
                        <div class="timeline-title">Cita completada</div>
                        <div class="timeline-desc">La cita ha sido finalizada exitosamente</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($cita['estado'] == 'cancelled'): ?>
                    <div class="timeline-item">
                        <div class="timeline-date"><?php echo date('d/m/Y H:i', strtotime($cita['updated_at'])); ?></div>
                        <div class="timeline-title">Cita cancelada</div>
                        <div class="timeline-desc">La cita ha sido cancelada</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($cita['estado'] == 'no_show'): ?>
                    <div class="timeline-item">
                        <div class="timeline-date"><?php echo date('d/m/Y H:i', strtotime($cita['updated_at'])); ?></div>
                        <div class="timeline-title">No asistió</div>
                        <div class="timeline-desc">El cliente no se presentó a la cita</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notas de la cita -->
        <?php if (!empty($cita['notas'])): ?>
        <div class="notes-section">
            <h3>
                <i class='bx bx-note'></i>
                Notas adicionales
            </h3>
            <p><?php echo nl2br(htmlspecialchars($cita['notas'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Diagnóstico y tratamiento (si está completada) -->
        <?php if ($cita['estado'] == 'completed' && (!empty($cita['diagnostico']) || !empty($cita['tratamiento']))): ?>
        <div class="details-grid" style="margin-top: 1.5rem;">
            <?php if (!empty($cita['diagnostico'])): ?>
            <div class="detail-card">
                <div class="card-header">
                    <i class='bx bx-clinic'></i>
                    <h2>Diagnóstico</h2>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(htmlspecialchars($cita['diagnostico'])); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($cita['tratamiento'])): ?>
            <div class="detail-card">
                <div class="card-header">
                    <i class='bx bx-health'></i>
                    <h2>Tratamiento</h2>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(htmlspecialchars($cita['tratamiento'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Factura asociada -->
        <?php if ($factura): ?>
        <div class="invoice-card">
            <div class="invoice-info">
                <div class="invoice-icon">
                    <i class='bx bx-receipt'></i>
                </div>
                <div class="invoice-details">
                    <h4>Factura <?php echo htmlspecialchars($factura['numero_factura']); ?></h4>
                    <p>Emitida el <?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></p>
                </div>
            </div>
            <div class="invoice-amount">
                $<?php echo number_format($factura['total'], 2); ?>
            </div>
            <a href="ver_factura.php?id=<?php echo $factura['id']; ?>" class="btn-primary" style="padding: 0.6rem 1.2rem;">
                <i class='bx bx-show'></i>
                Ver Factura
            </a>
        </div>
        <?php endif; ?>

        <!-- Acciones -->
        <div class="action-buttons">
            <?php if ($cita['estado'] == 'scheduled' || $cita['estado'] == 'confirmed'): ?>
                <form action="cancelar_cita.php" method="post" style="display:inline; margin:0;" onsubmit="return confirm('¿Estás seguro de cancelar esta cita?')"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$cita['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-danger" style="border:0; cursor:pointer;">
                        <i class='bx bx-x-circle'></i>
                        Cancelar Cita
                    </button>
                </form>
                <a href="reagendar_cita.php?id=<?php echo $cita['id']; ?>" class="btn btn-outline">
                    <i class='bx bx-calendar-edit'></i>
                    Reagendar
                </a>
            <?php endif; ?>
            
            <?php if ($cita['estado'] == 'completed' && !$factura): ?>
                <form action="solicitar_factura.php" method="post" style="display:inline; margin:0;"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="cita_id" value="<?php echo (int)$cita['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-outline" style="border:0; cursor:pointer;">
                        <i class='bx bx-receipt'></i>
                    Solicitar Factura
                    </button>
                </form>
            <?php endif; ?>
            
            <a href="nueva_cita.php" class="btn btn-primary">
                <i class='bx bx-plus'></i>
                Agendar Nueva Cita
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>
</body>
</html>