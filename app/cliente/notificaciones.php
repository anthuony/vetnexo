<?php
// cliente/notificaciones.php
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

// Marcar notificaciones como leídas si se solicita
if (isset($_GET['marcar_leida']) && is_numeric($_GET['marcar_leida'])) {
    $notif_id = (int)$_GET['marcar_leida'];
    $stmt = $conn->prepare("UPDATE notificaciones_cliente SET leida = 1, fecha_leida = NOW() WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$notif_id, $cliente_id]);
    header("Location: notificaciones.php");
    exit();
}

if (isset($_GET['marcar_todas'])) {
    $stmt = $conn->prepare("UPDATE notificaciones_cliente SET leida = 1, fecha_leida = NOW() WHERE cliente_id = ? AND leida = 0");
    $stmt->execute([$cliente_id]);
    header("Location: notificaciones.php");
    exit();
}

// Obtener notificaciones del cliente
$stmt = $conn->prepare("
    SELECT * FROM notificaciones_cliente 
    WHERE cliente_id = ? 
    ORDER BY 
        CASE WHEN leida = 0 THEN 0 ELSE 1 END,
        fecha_creacion DESC
");
$stmt->execute([$cliente_id]);
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar no leídas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM notificaciones_cliente WHERE cliente_id = ? AND leida = 0");
$stmt->execute([$cliente_id]);
$no_leidas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Obtener datos del cliente
$stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Notificaciones - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Mismos estilos que en las otras páginas de cliente */
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

        .main-content {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

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

        .btn-primary {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .notificaciones-list {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .notificacion-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray);
            transition: background 0.3s ease;
        }

        .notificacion-item:last-child {
            border-bottom: none;
        }

        .notificacion-item.no-leida {
            background: var(--primary-soft);
        }

        .notificacion-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .notificacion-contenido {
            flex: 1;
        }

        .notificacion-titulo {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .notificacion-mensaje {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .notificacion-fecha {
            font-size: 0.8rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .notificacion-accion {
            margin-left: auto;
        }

        .btn-marcar {
            padding: 0.4rem 0.8rem;
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .btn-marcar:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-light);
        }

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
            
            .notificacion-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .notificacion-accion {
                margin-left: 0;
                width: 100%;
            }
            
            .btn-marcar {
                width: 100%;
                text-align: center;
            }
        }
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="dashboard.php" class="logo">
                <img src="../includes/img/logo.svg" alt="VetNexo" class="logo-img">
                <span>Mi Portal</span>
            </a>

            <div class="user-menu">
                <div class="notifications" onclick="window.location.href='notificaciones.php'">
                    <i class='bx bx-bell'></i>
                    <?php if ($no_leidas > 0): ?>
                        <span class="notification-badge"><?php echo $no_leidas; ?></span>
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

    <div class="breadcrumb">
        <a href="dashboard.php"><i class='bx bx-home'></i> Inicio</a>
        <i class='bx bx-chevron-right'></i>
        <span>Notificaciones</span>
    </div>

    <main class="main-content">
        <div class="page-header">
            <h1>
                <i class='bx bx-bell'></i>
                Mis Notificaciones
            </h1>
            <div style="display: flex; gap: 1rem;">
                <?php if ($no_leidas > 0): ?>
                    <a href="?marcar_todas=1" class="btn-primary" onclick="return confirm('¿Marcar todas como leídas?')">
                        <i class='bx bx-check-double'></i>
                        Marcar todas como leídas
                    </a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver
                </a>
            </div>
        </div>

        <div class="notificaciones-list">
            <?php if (empty($notificaciones)): ?>
                <div class="empty-state">
                    <i class='bx bx-bell-off'></i>
                    <h3>No hay notificaciones</h3>
                    <p>Cuando tengas notificaciones, aparecerán aquí.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notificaciones as $notif): ?>
                    <div class="notificacion-item <?php echo $notif['leida'] ? '' : 'no-leida'; ?>">
                        <div class="notificacion-icon">
                            <i class='bx bx-<?php 
                                echo $notif['tipo'] == 'cita_recordatorio' ? 'calendar' : 
                                     ($notif['tipo'] == 'vacuna_recordatorio' ? 'syringe' : 
                                     ($notif['tipo'] == 'pedido_estado' ? 'package' : 'gift')); 
                            ?>'></i>
                        </div>
                        <div class="notificacion-contenido">
                            <div class="notificacion-titulo"><?php echo htmlspecialchars($notif['titulo']); ?></div>
                            <div class="notificacion-mensaje"><?php echo nl2br(htmlspecialchars($notif['mensaje'])); ?></div>
                            <div class="notificacion-fecha">
                                <i class='bx bx-time'></i>
                                <?php echo date('d/m/Y H:i', strtotime($notif['fecha_creacion'])); ?>
                            </div>
                        </div>
                        <?php if (!$notif['leida']): ?>
                            <div class="notificacion-accion">
                                <a href="?marcar_leida=<?php echo $notif['id']; ?>" class="btn-marcar">
                                    <i class='bx bx-check'></i>
                                    Marcar como leída
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>
</body>
</html>