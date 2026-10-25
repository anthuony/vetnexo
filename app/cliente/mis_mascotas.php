<?php
// cliente/mis_mascotas.php
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

// Obtener datos del cliente
$stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Obtener todas las mascotas del cliente con información adicional
$stmt = $conn->prepare("
    SELECT m.*, 
           e.nombre as especie_nombre,
           (SELECT COUNT(*) FROM vacunas v WHERE v.mascota_id = m.id) as total_vacunas,
           (SELECT COUNT(*) FROM consultas c WHERE c.mascota_id = m.id) as total_consultas,
           (SELECT COUNT(*) FROM citas ct WHERE ct.mascota_id = m.id AND ct.estado = 'scheduled' AND ct.fecha_cita >= CURDATE()) as citas_proximas,
           (SELECT MAX(fecha_cita) FROM citas WHERE mascota_id = m.id AND estado = 'completed') as ultima_visita
    FROM mascotas m
    LEFT JOIN especies e ON m.especie_id = e.id
    WHERE m.cliente_id = ?
    ORDER BY m.nombre ASC
");
$stmt->execute([$cliente_id]);
$mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener lista de especies para el formulario de registro
$stmt = $conn->query("SELECT id, nombre FROM especies WHERE activo = 1 ORDER BY nombre");
$especies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener lista de razas (para el formulario)
$stmt = $conn->query("SELECT id, nombre, especie_id FROM razas WHERE activo = 1 ORDER BY nombre");
$razas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$razas_por_especie = [];
foreach ($razas as $raza) {
    $razas_por_especie[$raza['especie_id']][] = $raza;
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

// Función para obtener icono según especie
function getEspecieIcono($especie) {
    switch(strtolower($especie)) {
        case 'perro':
        case 'dog':
            return 'bxs-dog';
        case 'gato':
        case 'cat':
            return 'bxs-cat';
        default:
            return 'bxs-dog';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Mascotas - VetNexo</title>
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

        /* Grid de mascotas */
        .mascotas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .mascota-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .mascota-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .mascota-header {
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            padding: 1.5rem 1.2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray);
        }

        .mascota-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            box-shadow: var(--shadow);
        }

        .mascota-header-info {
            flex: 1;
        }

        .mascota-header-info h2 {
            font-size: 1.4rem;
            margin-bottom: 0.3rem;
            color: var(--primary-dark);
        }

        .mascota-header-info p {
            color: var(--text-light);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mascota-badge {
            display: inline-block;
            padding: 0.2rem 1rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .mascota-body {
            padding: 1.2rem;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 0.8rem;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .info-row i {
            width: 20px;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .info-row strong {
            font-weight: 600;
            margin-right: 0.3rem;
            color: var(--text-dark);
        }

        .info-tag {
            background: var(--gray-light);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .stats-mini {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px dashed var(--gray);
            border-bottom: 1px dashed var(--gray);
        }

        .stat-mini-item {
            text-align: center;
        }

        .stat-mini-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-mini-label {
            font-size: 0.7rem;
            color: var(--text-light);
        }

        .mascota-footer {
            padding: 1rem 1.2rem;
            border-top: 1px solid var(--gray);
            background: var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mascota-footer-left {
            color: var(--text-light);
            font-size: 0.8rem;
        }

        .mascota-footer-left i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        .mascota-acciones {
            display: flex;
            gap: 0.5rem;
        }

        .btn-card {
            padding: 0.5rem 1rem;
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

        .btn-edit {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .btn-edit:hover {
            background: var(--accent);
            color: white;
        }

        /* Modal para registro de mascota */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--radius-lg);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .modal-close:hover {
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        select.form-control {
            cursor: pointer;
        }

        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
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

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert i {
            font-size: 1.3rem;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .mascotas-grid {
                grid-template-columns: 1fr;
            }
            
            .mascota-header {
                flex-direction: column;
                text-align: center;
            }
            
            .mascota-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .mascota-acciones {
                width: 100%;
            }
            
            .btn-card {
                flex: 1;
                justify-content: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
        <span>Mis Mascotas</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Mensajes de sesión (para mostrar después de registro/edición) -->
        <?php if (isset($_GET['success']) && $_GET['success'] == 'registrada'): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <span>¡Mascota registrada exitosamente!</span>
            </div>
        <?php elseif (isset($_GET['success']) && $_GET['success'] == 'actualizada'): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <span>¡Mascota actualizada exitosamente!</span>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle'></i>
                <span>Ha ocurrido un error. Por favor intenta de nuevo.</span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-paw'></i>
                Mis Mascotas
            </h1>
            <div style="display: flex; gap: 1rem;">
                <a href="dashboard.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver
                </a>
                <button class="btn-new" onclick="openModal()">
                    <i class='bx bx-plus'></i>
                    Registrar Mascota
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-paw'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($mascotas); ?></h3>
                    <p>Total mascotas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-syringe'></i>
                </div>
                <div class="stat-info">
                    <h3><?php 
                        $total_vacunas = array_sum(array_column($mascotas, 'total_vacunas'));
                        echo $total_vacunas;
                    ?></h3>
                    <p>Vacunas aplicadas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-calendar'></i>
                </div>
                <div class="stat-info">
                    <h3><?php 
                        $citas_proximas = array_sum(array_column($mascotas, 'citas_proximas'));
                        echo $citas_proximas;
                    ?></h3>
                    <p>Citas próximas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-time'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($mascotas); ?></h3>
                    <p>Pendientes vacuna</p>
                </div>
            </div>
        </div>

        <!-- Grid de mascotas -->
        <?php if (!empty($mascotas)): ?>
            <div class="mascotas-grid">
                <?php foreach ($mascotas as $mascota): 
                    $edad = calcularEdad($mascota['fecha_nacimiento']);
                    $icono = getEspecieIcono($mascota['especie_nombre'] ?? '');
                ?>
                    <div class="mascota-card">
                        <div class="mascota-header">
                            <div class="mascota-avatar">
                                <i class='bx <?php echo $icono; ?>'></i>
                            </div>
                            <div class="mascota-header-info">
                                <h2><?php echo htmlspecialchars($mascota['nombre']); ?></h2>
                                <p>
                                    <span class="mascota-badge">
                                        <i class='bx bx-category'></i>
                                        <?php echo htmlspecialchars($mascota['especie_nombre'] ?? 'Mascota'); ?>
                                    </span>
                                    <?php if ($mascota['sexo']): ?>
                                        <span>
                                            <i class='bx bx-<?php echo $mascota['sexo'] == 'macho' ? 'male' : 'female'; ?>'></i>
                                            <?php echo ucfirst($mascota['sexo']); ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="mascota-body">
                            <div class="info-row">
                                <i class='bx bx-calendar'></i>
                                <div><strong>Edad:</strong> <?php echo $edad; ?></div>
                            </div>
                            <?php if (!empty($mascota['raza'])): ?>
                                <div class="info-row">
                                    <i class='bx bx-dna'></i>
                                    <div><strong>Raza:</strong> <?php echo htmlspecialchars($mascota['raza']); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($mascota['color'])): ?>
                                <div class="info-row">
                                    <i class='bx bx-palette'></i>
                                    <div><strong>Color:</strong> <?php echo htmlspecialchars($mascota['color']); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($mascota['peso'])): ?>
                                <div class="info-row">
                                    <i class='bx bx-weight'></i>
                                    <div><strong>Peso:</strong> <?php echo htmlspecialchars($mascota['peso']); ?> kg</div>
                                </div>
                            <?php endif; ?>

                            <div class="stats-mini">
                                <div class="stat-mini-item">
                                    <div class="stat-mini-value"><?php echo $mascota['total_vacunas']; ?></div>
                                    <div class="stat-mini-label">Vacunas</div>
                                </div>
                                <div class="stat-mini-item">
                                    <div class="stat-mini-value"><?php echo $mascota['total_consultas']; ?></div>
                                    <div class="stat-mini-label">Consultas</div>
                                </div>
                                <div class="stat-mini-item">
                                    <div class="stat-mini-value"><?php echo $mascota['citas_proximas']; ?></div>
                                    <div class="stat-mini-label">Próximas</div>
                                </div>
                            </div>

                            <?php if (!empty($mascota['notas'])): ?>
                                <div class="info-row">
                                    <i class='bx bx-note'></i>
                                    <div><small><?php echo htmlspecialchars(substr($mascota['notas'], 0, 80)) . (strlen($mascota['notas']) > 80 ? '...' : ''); ?></small></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mascota-footer">
                            <div class="mascota-footer-left">
                                <?php if ($mascota['ultima_visita']): ?>
                                    <i class='bx bx-history'></i> Última visita: <?php echo date('d/m/Y', strtotime($mascota['ultima_visita'])); ?>
                                <?php else: ?>
                                    <i class='bx bx-calendar'></i> Sin visitas previas
                                <?php endif; ?>
                            </div>
                            <div class="mascota-acciones">
                                <a href="ver_mascota.php?id=<?php echo $mascota['id']; ?>" class="btn-card btn-view" title="Ver historial">
                                    <i class='bx bx-show'></i>
                                    Ver
                                </a>
                                <a href="editar_mascota.php?id=<?php echo $mascota['id']; ?>" class="btn-card btn-edit" title="Editar">
                                    <i class='bx bx-edit'></i>
                                    Editar
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Estado vacío -->
            <div class="empty-state">
                <i class='bx bx-plus-circle'></i>
                <h3>No tienes mascotas registradas</h3>
                <p>Registra a tu primera mascota para comenzar a gestionar sus citas, vacunas y más.</p>
                <button class="btn" onclick="openModal()">
                    <i class='bx bx-plus'></i>
                    Registrar mi primera mascota
                </button>
            </div>
        <?php endif; ?>
    </main>

    <!-- Modal para registrar mascota -->
    <div class="modal" id="registroMascotaModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class='bx bx-paw'></i>
                    Registrar Nueva Mascota
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body">
                <form action="procesar_registro_mascota.php" method="POST"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="cliente_id" value="<?php echo $cliente_id; ?>">
                    
                    <div class="form-group">
                        <label><i class='bx bx-tag'></i> Nombre *</label>
                        <input type="text" name="nombre" class="form-control" required placeholder="Ej: Luna, Max, etc.">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class='bx bx-category'></i> Especie *</label>
                            <select name="especie_id" class="form-control" required id="especieSelect">
                                <option value="">Selecciona una especie</option>
                                <?php foreach ($especies as $especie): ?>
                                    <option value="<?php echo $especie['id']; ?>"><?php echo htmlspecialchars($especie['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class='bx bx-dna'></i> Raza</label>
                            <select name="raza_id" class="form-control" id="razaSelect">
                                <option value="">Selecciona una raza</option>
                                <?php foreach ($razas as $raza): ?>
                                    <option value="<?php echo $raza['id']; ?>" data-especie="<?php echo $raza['especie_id']; ?>" style="display:none;">
                                        <?php echo htmlspecialchars($raza['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class='bx bx-calendar'></i> Fecha nacimiento</label>
                            <input type="date" name="fecha_nacimiento" class="form-control">
                        </div>
                        <div class="form-group">
                            <label><i class='bx bx-male-female'></i> Sexo *</label>
                            <select name="sexo" class="form-control" required>
                                <option value="">Selecciona</option>
                                <option value="macho">Macho</option>
                                <option value="hembra">Hembra</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class='bx bx-palette'></i> Color</label>
                            <input type="text" name="color" class="form-control" placeholder="Ej: Marrón, Blanco">
                        </div>
                        <div class="form-group">
                            <label><i class='bx bx-weight'></i> Peso (kg)</label>
                            <input type="number" step="0.01" name="peso" class="form-control" placeholder="Ej: 5.5">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class='bx bx-note'></i> Notas adicionales</label>
                        <textarea name="notas" class="form-control" rows="3" placeholder="Alergias, cuidados especiales, etc."></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class='bx bx-save'></i>
                        Registrar Mascota
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        // Función para abrir modal
        function openModal() {
            document.getElementById('registroMascotaModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Función para cerrar modal
        function closeModal() {
            document.getElementById('registroMascotaModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('registroMascotaModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Filtrar razas según especie seleccionada
        document.getElementById('especieSelect').addEventListener('change', function() {
            const especieId = this.value;
            const razaOptions = document.querySelectorAll('#razaSelect option');
            
            razaOptions.forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    const optionEspecie = option.getAttribute('data-especie');
                    if (optionEspecie == especieId) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });
            
            // Resetear selección de raza
            document.getElementById('razaSelect').value = '';
        });

        // Si hay parámetro para abrir el modal automáticamente
        <?php if (isset($_GET['action']) && $_GET['action'] == 'registrar'): ?>
            openModal();
        <?php endif; ?>
    </script>
</body>
</html>