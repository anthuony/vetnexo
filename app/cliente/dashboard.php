<?php
// cliente/dashboard.php
require_once __DIR__ . '/../config/bootstrap.php';

// Incluir configuración de base de datos (una sola vez, con la ruta correcta)
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

// Incluir funciones comunes
require_once '../config/funciones.php';

// Verificar que sea cliente
if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] != 'cliente') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'] ?? null;

// Si no hay cliente_id en sesión, intentar obtenerlo
if (!$cliente_id && isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $cliente_id = $usuario['cliente_id'] ?? null;
    $_SESSION['cliente_id'] = $cliente_id;
}

// Si aún no hay cliente_id, redirigir
if (!$cliente_id) {
    header("Location: ../login.php?error=cliente_no_encontrado");
    exit();
}

// Obtener datos del cliente
$stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no se encuentra el cliente, redirigir
if (!$cliente) {
    header("Location: ../login.php?error=cliente_no_existe");
    exit();
}

// Obtener mascotas del cliente
$stmt = $conn->prepare("SELECT * FROM mascotas WHERE cliente_id = ? ORDER BY nombre");
$stmt->execute([$cliente_id]);
$mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener próximas citas
$stmt = $conn->prepare("
    SELECT c.*, m.nombre as mascota_nombre, s.nombre as servicio_nombre, s.precio
    FROM citas c
    JOIN mascotas m ON c.mascota_id = m.id
    JOIN servicios s ON c.servicio_id = s.id
    WHERE c.cliente_id = ? AND c.fecha_cita >= CURDATE()
    ORDER BY c.fecha_cita ASC
    LIMIT 5
");
$stmt->execute([$cliente_id]);
$citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener historial de compras/pedidos
$stmt = $conn->prepare("
    SELECT * FROM pedidos 
    WHERE cliente_id = ? 
    ORDER BY fecha_pedido DESC 
    LIMIT 5
");
$stmt->execute([$cliente_id]);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener vacunas próximas (próximos 30 días)
$stmt = $conn->prepare("
    SELECT v.*, m.nombre as mascota_nombre 
    FROM vacunas v
    JOIN mascotas m ON v.mascota_id = m.id
    WHERE m.cliente_id = ? 
      AND v.proxima_dosis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY v.proxima_dosis ASC
");
$stmt->execute([$cliente_id]);
$vacunas_proximas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar notificaciones no leídas
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notificaciones_cliente 
    WHERE cliente_id = ? AND leida = 0
");
$stmt->execute([$cliente_id]);
$notificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
$notificaciones_count = $notificaciones['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Portal - VetNexo</title>
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

        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-lg);
        }

        .welcome-text h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 1rem;
        }

        .welcome-banner .btn {
            background: white;
            color: var(--primary);
            padding: 0.8rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .welcome-banner .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .stat-info p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 968px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 2px solid var(--primary-soft);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h2 i {
            color: var(--primary);
        }

        .card-header .btn-small {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.4rem 0.8rem;
            background: var(--primary-soft);
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .card-header .btn-small:hover {
            background: var(--primary);
            color: white;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Mascotas Grid */
        .mascotas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .mascota-card {
            background: var(--gray-light);
            border-radius: var(--radius);
            padding: 1.2rem;
            text-align: center;
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
        }

        .mascota-card:hover {
            transform: translateY(-3px);
            border-color: var(--primary-light);
            box-shadow: var(--shadow);
        }

        .mascota-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }

        .mascota-card h3 {
            font-size: 1.1rem;
            margin-bottom: 0.3rem;
        }

        .mascota-card p {
            color: var(--text-light);
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        .mascota-card .btn-small {
            display: inline-block;
            padding: 0.4rem 1rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 20px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .mascota-card .btn-small:hover {
            background: var(--primary-dark);
        }

        /* Lista de citas */
        .citas-list {
            list-style: none;
        }

        .cita-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray);
        }

        .cita-item:last-child {
            border-bottom: none;
        }

        .cita-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .cita-info {
            flex: 1;
        }

        .cita-info h4 {
            font-size: 1rem;
            margin-bottom: 0.2rem;
        }

        .cita-info p {
            color: var(--text-light);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cita-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-pendiente {
            background: #fff3cd;
            color: #856404;
        }

        .badge-confirmada {
            background: #d4edda;
            color: #155724;
        }

        .badge-completada {
            background: #cce5ff;
            color: #004085;
        }

        /* Vacunas próximas */
        .vacunas-list {
            list-style: none;
        }

        .vacuna-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--primary-soft);
            border-radius: var(--radius);
            margin-bottom: 0.8rem;
        }

        .vacuna-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--white);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .vacuna-info {
            flex: 1;
        }

        .vacuna-info strong {
            display: block;
            font-size: 0.95rem;
        }

        .vacuna-info span {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        /* Estado vacío */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .empty-state .btn {
            display: inline-block;
            padding: 0.6rem 1.5rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-size: 0.9rem;
        }

        /* Acciones rápidas */
        .acciones-rapidas {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .accion-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            text-decoration: none;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.8rem;
            transition: all 0.3s ease;
        }

        .accion-card:hover {
            transform: translateY(-3px);
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }

        .accion-card i {
            font-size: 2.5rem;
            color: var(--primary);
        }

        .accion-card span {
            font-weight: 500;
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
            
            .welcome-banner {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .mascotas-grid {
                grid-template-columns: 1fr;
            }
            
            .acciones-rapidas {
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
                <div class="notifications" onclick="window.location.href='notificaciones.php'">
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

    <!-- Main Content -->
    <main class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1>¡Bienvenido, <?php echo htmlspecialchars(explode(' ', $cliente['nombre'])[0]); ?>!</h1>
                <p>Desde aquí puedes gestionar tus mascotas, citas y compras</p>
            </div>
            <a href="nueva_cita.php" class="btn">
                <i class='bx bx-calendar-plus'></i>
                Agendar Nueva Cita
            </a>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-paw'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($mascotas); ?></h3>
                    <p>Mascotas registradas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-calendar'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($citas); ?></h3>
                    <p>Próximas citas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-syringe'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($vacunas_proximas); ?></h3>
                    <p>Vacunas próximas</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-package'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($pedidos); ?></h3>
                    <p>Pedidos recientes</p>
                </div>
            </div>
        </div>

        <!-- Acciones Rápidas -->
        <div class="acciones-rapidas">
            <a href="nueva_cita.php" class="accion-card">
                <i class='bx bx-calendar-plus'></i>
                <span>Agendar Cita</span>
            </a>
            <a href="../tienda/" class="accion-card">
                <i class='bx bx-cart'></i>
                <span>Ir a la Tienda</span>
            </a>
            <a href="mis_mascotas.php" class="accion-card">
                <i class='bx bx-dog'></i>
                <span>Mis Mascotas</span>
            </a>
            <a href="historial_compras.php" class="accion-card">
                <i class='bx bx-receipt'></i>
                <span>Mis Compras</span>
            </a>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Columna Izquierda -->
            <div class="left-column">
                <!-- Mis Mascotas -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class='bx bx-paw'></i> Mis Mascotas</h2>
                        <a href="mis_mascotas.php" class="btn-small">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($mascotas)): ?>
                            <div class="mascotas-grid">
                                <?php foreach (array_slice($mascotas, 0, 4) as $mascota): ?>
                                    <div class="mascota-card">
                                        <div class="mascota-avatar">
                                            <i class='bx bxs-dog'></i>
                                        </div>
                                        <h3><?php echo htmlspecialchars($mascota['nombre']); ?></h3>
                                        <p><?php echo htmlspecialchars($mascota['raza'] ?? 'Sin raza'); ?></p>
                                        <a href="ver_mascota.php?id=<?php echo $mascota['id']; ?>" class="btn-small">Ver detalle</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-plus-circle'></i>
                                <p>Aún no has registrado ninguna mascota</p>
                                <a href="mis_mascotas.php?action=registrar" class="btn">Registrar mascota</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Próximas Citas -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class='bx bx-calendar'></i> Próximas Citas</h2>
                        <a href="mis_citas.php" class="btn-small">
                            Ver todas <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($citas)): ?>
                            <ul class="citas-list">
                                <?php foreach ($citas as $cita): ?>
                                    <li class="cita-item">
                                        <div class="cita-icon">
                                            <i class='bx bx-calendar-alt'></i>
                                        </div>
                                        <div class="cita-info">
                                            <h4><?php echo htmlspecialchars($cita['mascota_nombre']); ?> - <?php echo htmlspecialchars($cita['servicio_nombre']); ?></h4>
                                            <p>
                                                <i class='bx bx-calendar'></i> <?php echo date('d/m/Y', strtotime($cita['fecha_cita'])); ?>
                                                <i class='bx bx-time'></i> <?php echo date('H:i', strtotime($cita['fecha_cita'])); ?>
                                            </p>
                                        </div>
                                        <span class="cita-badge badge-<?php 
                                            echo $cita['estado'] == 'confirmed' ? 'confirmada' : 
                                                 ($cita['estado'] == 'completed' ? 'completada' : 'pendiente'); 
                                        ?>">
                                            <?php 
                                            echo $cita['estado'] == 'confirmed' ? 'Confirmada' : 
                                                 ($cita['estado'] == 'completed' ? 'Completada' : 'Pendiente'); 
                                            ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <p>No tienes citas programadas</p>
                                <a href="nueva_cita.php" class="btn">Agendar cita</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha -->
            <div class="right-column">
                <!-- Vacunas Próximas -->
                <?php if (!empty($vacunas_proximas)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class='bx bx-syringe'></i> Vacunas Próximas</h2>
                    </div>
                    <div class="card-body">
                        <ul class="vacunas-list">
                            <?php foreach ($vacunas_proximas as $vacuna): ?>
                                <li class="vacuna-item">
                                    <div class="vacuna-icon">
                                        <i class='bx bx-calendar-exclamation'></i>
                                    </div>
                                    <div class="vacuna-info">
                                        <strong><?php echo htmlspecialchars($vacuna['mascota_nombre']); ?></strong>
                                        <span><?php echo htmlspecialchars($vacuna['nombre_vacuna']); ?></span>
                                    </div>
                                    <span class="cita-badge badge-warning">
                                        <?php echo date('d/m', strtotime($vacuna['proxima_dosis'])); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pedidos Recientes -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class='bx bx-package'></i> Pedidos Recientes</h2>
                        <a href="historial_compras.php" class="btn-small">
                            Ver todos <i class='bx bx-chevron-right'></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pedidos)): ?>
                            <ul class="citas-list">
                                <?php foreach ($pedidos as $pedido): ?>
                                    <li class="cita-item">
                                        <div class="cita-icon">
                                            <i class='bx bx-receipt'></i>
                                        </div>
                                        <div class="cita-info">
                                            <h4>Pedido #<?php echo $pedido['numero_pedido']; ?></h4>
                                            <p>
                                                <i class='bx bx-calendar'></i> <?php echo date('d/m/Y', strtotime($pedido['fecha_pedido'])); ?>
                                                • Total: $<?php echo number_format($pedido['total'], 2); ?>
                                            </p>
                                        </div>
                                        <span class="cita-badge badge-<?php 
                                            echo $pedido['estado'] == 'completado' ? 'success' : 
                                                 ($pedido['estado'] == 'cancelado' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($pedido['estado']); ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-cart'></i>
                                <p>No has realizado compras aún</p>
                                <a href="../tienda/" class="btn">Ir a la tienda</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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