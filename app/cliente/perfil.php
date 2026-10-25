<?php
// cliente/perfil.php
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
$user_id = $_SESSION['user_id'];

// Obtener datos del cliente y su usuario asociado
$stmt = $conn->prepare("
    SELECT c.*, u.username, u.email as user_email, u.telefono as user_telefono, u.estado 
    FROM clientes c
    JOIN usuarios u ON c.id = u.cliente_id
    WHERE c.id = ? AND u.id = ?
");
$stmt->execute([$cliente_id, $user_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: dashboard.php");
    exit();
}

// Procesar actualización de perfil
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion == 'actualizar_perfil') {
        // Actualizar datos personales
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $documento = trim($_POST['documento'] ?? '');
        
        if (empty($nombre) || empty($email)) {
            $error = "El nombre y email son obligatorios.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "El email no es válido.";
        } else {
            try {
                // Actualizar tabla clientes
                $stmt = $conn->prepare("
                    UPDATE clientes 
                    SET nombre = ?, email = ?, telefono = ?, direccion = ?, documento_identidad = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $email, $telefono, $direccion, $documento, $cliente_id]);
                
                // Actualizar también en usuarios (nombre y email para mantener consistencia)
                $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $user_id]);
                
                // Actualizar variable de sesión
                $_SESSION['nombre'] = $nombre;
                
                $success = "Perfil actualizado exitosamente.";
                
                // Recargar datos
                $stmt = $conn->prepare("
                    SELECT c.*, u.username, u.email as user_email, u.telefono as user_telefono, u.estado 
                    FROM clientes c
                    JOIN usuarios u ON c.id = u.cliente_id
                    WHERE c.id = ? AND u.id = ?
                ");
                $stmt->execute([$cliente_id, $user_id]);
                $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                $error = "Error al actualizar el perfil: " . $e->getMessage();
            }
        }
    } elseif ($accion == 'cambiar_password') {
        // Cambiar contraseña
        $password_actual = $_POST['password_actual'] ?? '';
        $password_nueva = $_POST['password_nueva'] ?? '';
        $password_confirmar = $_POST['password_confirmar'] ?? '';
        
        if (empty($password_actual) || empty($password_nueva) || empty($password_confirmar)) {
            $error = "Todos los campos de contraseña son obligatorios.";
        } elseif ($password_nueva !== $password_confirmar) {
            $error = "Las contraseñas nuevas no coinciden.";
        } elseif (strlen($password_nueva) < 6) {
            $error = "La contraseña debe tener al menos 6 caracteres.";
        } else {
            // Verificar contraseña actual
            $stmt = $conn->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password_actual, $usuario['password_hash'])) {
                // Actualizar contraseña
                $password_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $user_id]);
                
                $success = "Contraseña actualizada exitosamente.";
            } else {
                $error = "La contraseña actual es incorrecta.";
            }
        }
    }
}

// Obtener estadísticas del cliente
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM mascotas WHERE cliente_id = ?) as total_mascotas,
        (SELECT COUNT(*) FROM citas WHERE cliente_id = ? AND estado = 'completed') as citas_completadas,
        (SELECT COUNT(*) FROM pedidos WHERE cliente_id = ? AND estado = 'completado') as compras_realizadas,
        (SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE cliente_id = ? AND estado = 'completado') as total_gastado
");
$stmt->execute([$cliente_id, $cliente_id, $cliente_id, $cliente_id]);
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

// Fecha de registro formateada
$fecha_registro = new DateTime($cliente['fecha_registro']);
$fecha_registro_formateada = $fecha_registro->format('d/m/Y');
$miembro_desde = $fecha_registro->diff(new DateTime())->y;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - VetNexo</title>
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

        /* Profile Container */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
        }

        @media (max-width: 968px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        /* Profile Sidebar */
        .profile-sidebar {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
            height: fit-content;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 600;
            color: var(--primary);
            box-shadow: var(--shadow-lg);
            border: 4px solid rgba(255,255,255,0.3);
        }

        .profile-header h2 {
            font-size: 1.3rem;
            margin-bottom: 0.3rem;
        }

        .profile-header p {
            opacity: 0.9;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .profile-stats {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            border-bottom: 1px solid var(--gray);
        }

        .profile-stat {
            text-align: center;
        }

        .profile-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .profile-stat-label {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .profile-info {
            padding: 1.5rem;
        }

        .profile-info-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .profile-info-item:last-child {
            border-bottom: none;
        }

        .profile-info-icon {
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

        .profile-info-content {
            flex: 1;
        }

        .profile-info-label {
            font-size: 0.75rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-info-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-dark);
            word-break: break-word;
        }

        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .profile-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: var(--primary-soft);
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h2 i {
            color: var(--accent);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.2rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 1rem;
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

        .form-control[readonly] {
            background: var(--gray-light);
            cursor: not-allowed;
        }

        .btn-submit {
            padding: 0.8rem 2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-submit i {
            font-size: 1.1rem;
        }

        .btn-secondary {
            padding: 0.8rem 2rem;
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--gray);
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-secondary:hover {
            background: var(--gray-light);
            border-color: var(--primary);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
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

        /* Password strength */
        .password-strength {
            margin-top: 0.5rem;
            height: 5px;
            border-radius: 3px;
            background: var(--gray-light);
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
        }

        .strength-weak { background: var(--danger); }
        .strength-medium { background: var(--warning); }
        .strength-strong { background: var(--success); }

        .strength-text {
            font-size: 0.7rem;
            margin-top: 0.3rem;
            color: var(--text-light);
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-submit,
            .btn-secondary {
                width: 100%;
                justify-content: center;
            }
            
            .profile-stats {
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
        <span>Mi Perfil</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-user-circle'></i>
                Mi Perfil
            </h1>
            <a href="dashboard.php" class="btn-back">
                <i class='bx bx-arrow-back'></i>
                Volver al Dashboard
            </a>
        </div>

        <!-- Profile Container -->
        <div class="profile-container">
            <!-- Profile Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($cliente['nombre'], 0, 1)); ?>
                    </div>
                    <h2><?php echo htmlspecialchars($cliente['nombre']); ?></h2>
                    <p>
                        <i class='bx bx-calendar'></i>
                        Miembro desde <?php echo $fecha_registro_formateada; ?>
                        <?php if ($miembro_desde > 0): ?>
                            (<?php echo $miembro_desde; ?> año<?php echo $miembro_desde != 1 ? 's' : ''; ?>)
                        <?php endif; ?>
                    </p>
                </div>

                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $estadisticas['total_mascotas']; ?></div>
                        <div class="profile-stat-label">Mascotas</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $estadisticas['citas_completadas']; ?></div>
                        <div class="profile-stat-label">Citas</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $estadisticas['compras_realizadas']; ?></div>
                        <div class="profile-stat-label">Compras</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value">$<?php echo number_format($estadisticas['total_gastado'], 0); ?></div>
                        <div class="profile-stat-label">Gastado</div>
                    </div>
                </div>

                <div class="profile-info">
                    <div class="profile-info-item">
                        <div class="profile-info-icon">
                            <i class='bx bx-envelope'></i>
                        </div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Email</div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($cliente['email']); ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon">
                            <i class='bx bx-phone'></i>
                        </div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Teléfono</div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($cliente['telefono'] ?? 'No registrado'); ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon">
                            <i class='bx bx-map'></i>
                        </div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Dirección</div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($cliente['direccion'] ?? 'No registrada'); ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon">
                            <i class='bx bx-id-card'></i>
                        </div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Documento</div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($cliente['documento_identidad'] ?? 'No registrado'); ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon">
                            <i class='bx bx-user'></i>
                        </div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Usuario</div>
                            <div class="profile-info-value"><?php echo htmlspecialchars($cliente['username']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Content -->
            <div class="profile-content">
                <!-- Mensajes -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class='bx bx-check-circle'></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error-circle'></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Editar Perfil -->
                <div class="profile-card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-edit'></i>
                            Editar Información Personal
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action=""><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="accion" value="actualizar_perfil">
                            
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label><i class='bx bx-user'></i> Nombre completo *</label>
                                    <input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($cliente['nombre']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label><i class='bx bx-envelope'></i> Email *</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($cliente['email']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label><i class='bx bx-phone'></i> Teléfono</label>
                                    <input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($cliente['telefono'] ?? ''); ?>">
                                </div>

                                <div class="form-group full-width">
                                    <label><i class='bx bx-map'></i> Dirección</label>
                                    <input type="text" name="direccion" class="form-control" value="<?php echo htmlspecialchars($cliente['direccion'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label><i class='bx bx-id-card'></i> Documento de identidad</label>
                                    <input type="text" name="documento" class="form-control" value="<?php echo htmlspecialchars($cliente['documento_identidad'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label><i class='bx bx-user-circle'></i> Nombre de usuario</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($cliente['username']); ?>" readonly>
                                    <small style="color: var(--text-lighter);">El nombre de usuario no se puede cambiar</small>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn-submit">
                                    <i class='bx bx-save'></i>
                                    Guardar Cambios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Cambiar Contraseña -->
                <div class="profile-card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-lock'></i>
                            Cambiar Contraseña
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="passwordForm"><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="accion" value="cambiar_password">
                            
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label><i class='bx bx-lock-alt'></i> Contraseña actual *</label>
                                    <input type="password" name="password_actual" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label><i class='bx bx-lock'></i> Nueva contraseña *</label>
                                    <input type="password" name="password_nueva" id="newPassword" class="form-control" required minlength="6">
                                    <div class="password-strength">
                                        <div class="strength-bar" id="strengthBar"></div>
                                    </div>
                                    <div class="strength-text" id="strengthText"></div>
                                </div>

                                <div class="form-group">
                                    <label><i class='bx bx-lock-alt'></i> Confirmar contraseña *</label>
                                    <input type="password" name="password_confirmar" id="confirmPassword" class="form-control" required>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn-submit">
                                    <i class='bx bx-key'></i>
                                    Cambiar Contraseña
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Actividad Reciente (Resumen) 
                <div class="profile-card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-history'></i>
                            Actividad Reciente
                        </h2>
                        <a href="mis_citas.php" class="btn-secondary" style="padding: 0.4rem 1rem; font-size: 0.85rem;">
                            Ver todo
                        </a>
                    </div>
                    <div class="card-body">
                        <?php
                        // Obtener últimas 3 actividades
                        $stmt = $conn->prepare("
                            (SELECT 'cita' as tipo, c.id, CONCAT('Cita de ', s.nombre) as descripcion, fecha_cita as fecha
                             FROM citas c
                             JOIN servicios s ON c.servicio_id = s.id
                             WHERE c.cliente_id = ?
                             ORDER BY fecha_cita DESC
                             LIMIT 2)
                            UNION ALL
                            (SELECT 'compra' as tipo, id, CONCAT('Compra #', numero_pedido) as descripcion, fecha_pedido as fecha
                             FROM pedidos
                             WHERE cliente_id = ?
                             ORDER BY fecha_pedido DESC
                             LIMIT 2)
                            ORDER BY fecha DESC
                            LIMIT 3
                        ");
                        $stmt->execute([$cliente_id, $cliente_id]);
                        $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (!empty($actividades)): ?>
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <?php foreach ($actividades as $actividad): ?>
                                    <div style="display: flex; align-items: center; gap: 1rem; padding: 0.5rem; background: var(--gray-light); border-radius: var(--radius-sm);">
                                        <div style="width: 35px; height: 35px; border-radius: 10px; background: <?php echo $actividad['tipo'] == 'cita' ? 'var(--primary-soft)' : 'var(--accent-soft)'; ?>; color: <?php echo $actividad['tipo'] == 'cita' ? 'var(--primary)' : 'var(--accent)'; ?>; display: flex; align-items: center; justify-content: center;">
                                            <i class='bx <?php echo $actividad['tipo'] == 'cita' ? 'bx-calendar' : 'bx-package'; ?>'></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; font-size: 0.95rem;"><?php echo htmlspecialchars($actividad['descripcion']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-light);">
                                                <?php echo date('d/m/Y H:i', strtotime($actividad['fecha'])); ?>
                                            </div>
                                        </div>
                                        <a href="<?php echo $actividad['tipo'] == 'cita' ? 'ver_cita.php?id=' . $actividad['id'] : 'ver_pedido.php?id=' . $actividad['id']; ?>" style="color: var(--primary);">
                                            <i class='bx bx-chevron-right'></i>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                <i class='bx bx-history' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                <p>No hay actividad reciente para mostrar</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>-->
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        // Medidor de fortaleza de contraseña
        document.getElementById('newPassword')?.addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            if (password.length === 0) {
                strengthBar.style.width = '0';
                strengthBar.className = 'strength-bar';
                strengthText.textContent = '';
                return;
            }
            
            // Calcular fortaleza
            let strength = 0;
            
            // Longitud
            if (password.length >= 8) strength += 1;
            else if (password.length >= 6) strength += 0.5;
            
            // Mayúsculas
            if (/[A-Z]/.test(password)) strength += 1;
            
            // Números
            if (/\d/.test(password)) strength += 1;
            
            // Caracteres especiales
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            // Determinar nivel
            let level = 'weak';
            let width = 0;
            
            if (strength >= 3) {
                level = 'strong';
                width = 100;
                strengthText.textContent = 'Contraseña fuerte';
            } else if (strength >= 1.5) {
                level = 'medium';
                width = 66;
                strengthText.textContent = 'Contraseña media';
            } else {
                level = 'weak';
                width = 33;
                strengthText.textContent = 'Contraseña débil';
            }
            
            strengthBar.style.width = width + '%';
            strengthBar.className = 'strength-bar strength-' + level;
        });

        // Validar que las contraseñas coincidan
        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('Las contraseñas no coinciden');
            }
        });
    </script>
</body>
</html>