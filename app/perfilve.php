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

// Inicializar variables
$db = null;
$error = '';
$success = '';

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
    
    // Obtener información de la veterinaria
    $sql_veterinaria = "SELECT * FROM veterinarias WHERE id = 1 LIMIT 1";
    $stmt_veterinaria = $db->query($sql_veterinaria);
    $veterinaria = $stmt_veterinaria->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe, crear un array con datos por defecto
    if (!$veterinaria) {
        $veterinaria = [
            'nombre' => 'VetNexo',
            'direccion' => 'Clinic address not configured',
            'telefono' => '[Clinic phone not configured]',
            'email' => 'hello@vetnexo.example',
            'horario' => 'Lunes a Sábado: 8:00 AM - 5:00 PM',
            'descripcion' => 'Clínica veterinaria integral con servicios de consulta y peluquería canina.',
            'servicios' => 'Consulta Veterinaria, Peluquería Canina, Vacunación, Desparasitación, Cirugías'
        ];
    }
    
    // Procesar actualización del perfil de la veterinaria
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_veterinaria') {
        $nombre = trim($_POST['nombre']);
        $direccion = trim($_POST['direccion']);
        $telefono = trim($_POST['telefono']);
        $email = trim($_POST['email']);
        $horario = trim($_POST['horario']);
        $descripcion = trim($_POST['descripcion']);
        $servicios = trim($_POST['servicios']);
        
        // Validar datos
        if (empty($nombre) || empty($direccion) || empty($telefono) || empty($email)) {
            $_SESSION['error'] = "Los campos obligatorios deben ser completados";
        } else {
            // Verificar si ya existe información de la veterinaria
            $check_sql = "SELECT id FROM veterinarias WHERE id = 1";
            $check_stmt = $db->query($check_sql);
            $exists = $check_stmt->fetch();
            
            if ($exists) {
                // Actualizar
                $sql = "UPDATE veterinarias SET nombre = ?, direccion = ?, telefono = ?, email = ?, horario = ?, descripcion = ?, servicios = ?, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = 1";
                $stmt = $db->prepare($sql);
                if ($stmt->execute([$nombre, $direccion, $telefono, $email, $horario, $descripcion, $servicios])) {
                    $_SESSION['success'] = "Perfil de la veterinaria actualizado exitosamente";
                } else {
                    $_SESSION['error'] = "Error al actualizar el perfil";
                }
            } else {
                // Insertar
                $sql = "INSERT INTO veterinarias (id, nombre, direccion, telefono, email, horario, descripcion, servicios, fecha_actualizacion) VALUES (1, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                $stmt = $db->prepare($sql);
                if ($stmt->execute([$nombre, $direccion, $telefono, $email, $horario, $descripcion, $servicios])) {
                    $_SESSION['success'] = "Perfil de la veterinaria creado exitosamente";
                } else {
                    $_SESSION['success'] = "Error al crear el perfil";
                }
            }
        }
        
        header('Location: perfilve.php');
        exit();
    }
    
    // Procesar cambio de contraseña del usuario
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cambiar_password') {
        $password_actual = $_POST['password_actual'];
        $nueva_password = $_POST['nueva_password'];
        $confirmar_password = $_POST['confirmar_password'];
        
        // Obtener el hash actual
        $stmt = $db->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (empty($password_actual) || empty($nueva_password) || empty($confirmar_password)) {
            $_SESSION['error_password'] = "Todos los campos son obligatorios";
        } elseif ($nueva_password !== $confirmar_password) {
            $_SESSION['error_password'] = "Las contraseñas no coinciden";
        } elseif (!password_verify($password_actual, $usuario['password_hash'])) {
            $_SESSION['error_password'] = "La contraseña actual es incorrecta";
        } else {
            $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
            $sql = "UPDATE usuarios SET password_hash = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$password_hash, $user_id])) {
                $_SESSION['success_password'] = "Contraseña actualizada exitosamente";
            } else {
                $_SESSION['error_password'] = "Error al cambiar la contraseña";
            }
        }
        
        header('Location: perfilve.php');
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Error en perfilve.php: " . $e->getMessage());
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Función para obtener color del rol
function getRolColor($rol) {
    switch ($rol) {
        case 'admin':
            return '#F44336';
        case 'veterinarian':
            return '#2196F3';
        case 'reception':
            return '#FF9800';
        case 'assistant':
            return '#9C27B0';
        default:
            return '#4CAF50';
    }
}

// Función para obtener texto del rol
function getRolTexto($rol) {
    switch ($rol) {
        case 'admin':
            return 'Administrador';
        case 'veterinarian':
            return 'Veterinario';
        case 'reception':
            return 'Recepcionista';
        case 'assistant':
            return 'Asistente';
        default:
            return 'Usuario';
    }
}

$page_title = "Perfil de la Veterinaria - VetNexo";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
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
            --purple: #9C27B0;
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

        /* Page Actions */
        .page-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            justify-content: flex-end;
        }

        .btn-primary, .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid var(--primary-light);
        }

        .alert-error {
            background: #ffebee;
            color: var(--danger);
            border: 1px solid #ffcdd2;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-lg);
            padding: 3rem;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none" opacity="0.1"><path d="M0,50 Q25,40 50,50 T100,50 L100,100 L0,100 Z" fill="white"/></svg>');
            background-size: cover;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .hero-content h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .hero-content p {
            font-size: 1.1rem;
            opacity: 0.95;
            max-width: 800px;
            margin: 0 auto 1rem;
            line-height: 1.6;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
            margin-top: 1rem;
        }

        /* Grid Principal */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
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
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Formulario */
        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--text-lighter);
            margin-top: 0.2rem;
        }

        /* Info Cards */
        .info-card {
            background: var(--white);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .info-card h4 {
            font-size: 1rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card p {
            color: var(--text-dark);
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0;
        }

        .info-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        /* Servicios Tags */
        .servicios-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .servicio-tag {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Grid de información */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
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

        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
            background: rgba(46, 125, 50, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px) rotate(0deg);
            }
            50% {
                transform: translateY(-10px) rotate(180deg);
            }
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
            
            .page-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .hero-section {
                padding: 2rem 1rem;
            }
            
            .hero-content h1 {
                font-size: 1.8rem;
            }
            
            .hero-content p {
                font-size: 1rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Partículas de fondo -->
    <div class="particles">
        <div class="particle" style="top: 20%; left: 10%; width: 8px; height: 8px; animation-delay: 0s;"></div>
        <div class="particle" style="top: 60%; left: 80%; width: 12px; height: 12px; animation-delay: 1s;"></div>
        <div class="particle" style="top: 40%; left: 90%; width: 6px; height: 6px; animation-delay: 2s;"></div>
        <div class="particle" style="top: 80%; left: 20%; width: 10px; height: 10px; animation-delay: 3s;"></div>
        <div class="particle" style="top: 30%; left: 70%; width: 8px; height: 8px; animation-delay: 4s;"></div>
    </div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bxs-clinic'></i>
                        Perfil de la Veterinaria
                    </h1>
                    <p>
                        <i class='bx bx-info-circle'></i>
                        Gestiona la información de tu clínica veterinaria
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

            <!-- Page Actions -->
            <div class="page-actions fade-in">
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class='bx bx-arrow-back'></i>
                    Volver al Dashboard
                </a>
            </div>

            <!-- Alertas de sesión -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success fade-in">
                    <i class='bx bx-check-circle'></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error fade-in">
                    <i class='bx bx-error-circle'></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_password'])): ?>
                <div class="alert alert-success fade-in">
                    <i class='bx bx-check-circle'></i>
                    <?php echo $_SESSION['success_password']; unset($_SESSION['success_password']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_password'])): ?>
                <div class="alert alert-error fade-in">
                    <i class='bx bx-error-circle'></i>
                    <?php echo $_SESSION['error_password']; unset($_SESSION['error_password']); ?>
                </div>
            <?php endif; ?>

            <!-- Hero Section -->
            <div class="hero-section fade-in">
                <div class="hero-content">
                    <h1><?php echo htmlspecialchars($veterinaria['nombre']); ?></h1>
                    <p><?php echo htmlspecialchars($veterinaria['descripcion']); ?></p>
                    <span class="hero-badge">
                        <i class='bx bx-star'></i>
                        Alta calidad y profesionalismo
                    </span>
                </div>
            </div>

            <!-- Grid Principal -->
            <div class="dashboard-grid">
                <!-- Columna Izquierda - Formularios -->
                <div>
                    <!-- Editar Veterinaria -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h3>
                                <i class='bx bx-edit'></i>
                                Editar Información
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action=""><?php echo av_csrf_field(); ?>
                                <input type="hidden" name="accion" value="actualizar_veterinaria">
                                
                                <div class="form-group">
                                    <label class="form-label">Nombre de la Veterinaria *</label>
                                    <input type="text" class="form-control" name="nombre" 
                                           value="<?php echo htmlspecialchars($veterinaria['nombre']); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Descripción *</label>
                                    <textarea class="form-control" name="descripcion" rows="3" required><?php echo htmlspecialchars($veterinaria['descripcion']); ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Dirección *</label>
                                    <textarea class="form-control" name="direccion" rows="2" required><?php echo htmlspecialchars($veterinaria['direccion']); ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Teléfono *</label>
                                    <input type="text" class="form-control" name="telefono" 
                                           value="<?php echo htmlspecialchars($veterinaria['telefono']); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Email *</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($veterinaria['email']); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Horario de Atención *</label>
                                    <input type="text" class="form-control" name="horario" 
                                           value="<?php echo htmlspecialchars($veterinaria['horario']); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Servicios *</label>
                                    <textarea class="form-control" name="servicios" rows="3" required><?php echo htmlspecialchars($veterinaria['servicios']); ?></textarea>
                                    <div class="form-text">Separar los servicios por comas</div>
                                </div>
                                
                                <button type="submit" class="btn-primary btn-sm">
                                    <i class='bx bx-save'></i>
                                    Actualizar Información
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Cambiar Contraseña -->
                    <div class="card fade-in" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3>
                                <i class='bx bx-key'></i>
                                Cambiar Contraseña
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action=""><?php echo av_csrf_field(); ?>
                                <input type="hidden" name="accion" value="cambiar_password">
                                
                                <div class="form-group">
                                    <label class="form-label">Contraseña Actual *</label>
                                    <input type="password" class="form-control" name="password_actual" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Nueva Contraseña *</label>
                                    <input type="password" class="form-control" name="nueva_password" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Confirmar Nueva Contraseña *</label>
                                    <input type="password" class="form-control" name="confirmar_password" required>
                                </div>
                                
                                <button type="submit" class="btn-primary btn-sm">
                                    <i class='bx bx-key'></i>
                                    Cambiar Contraseña
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha - Información -->
                <div>
                    <!-- Información de Contacto -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h3>
                                <i class='bx bx-info-circle'></i>
                                Información de Contacto
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-grid">
                                <div class="info-card">
                                    <h4><i class='bx bx-map' style="color: var(--primary);"></i> Dirección</h4>
                                    <p><?php echo htmlspecialchars($veterinaria['direccion']); ?></p>
                                </div>
                                
                                <div class="info-card">
                                    <h4><i class='bx bx-phone' style="color: var(--primary);"></i> Teléfono</h4>
                                    <p><?php echo htmlspecialchars($veterinaria['telefono']); ?></p>
                                    <div class="info-detail">
                                        <i class='bx bx-time'></i>
                                        Disponible en horario de atención
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <h4><i class='bx bx-envelope' style="color: var(--primary);"></i> Email</h4>
                                    <p><?php echo htmlspecialchars($veterinaria['email']); ?></p>
                                    <div class="info-detail">
                                        <i class='bx bx-send'></i>
                                        Respuesta en 24 horas
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <h4><i class='bx bx-time' style="color: var(--primary);"></i> Horario</h4>
                                    <p><?php echo htmlspecialchars($veterinaria['horario']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Servicios -->
                    <div class="card fade-in" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3>
                                <i class='bx bx-plus-medical'></i>
                                Nuestros Servicios
                            </h3>
                        </div>
                        <div class="card-body">
                            <p style="color: var(--text-light); margin-bottom: 1rem;">Ofrecemos servicios especializados con los más altos estándares de calidad:</p>
                            <div class="servicios-tags">
                                <?php 
                                $servicios_array = explode(',', $veterinaria['servicios']);
                                foreach ($servicios_array as $servicio):
                                    $servicio_trim = trim($servicio);
                                    if (!empty($servicio_trim)):
                                ?>
                                    <span class="servicio-tag"><?php echo htmlspecialchars($servicio_trim); ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tu Información -->
                    <div class="card fade-in" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3>
                                <i class='bx bx-user'></i>
                                Tu Información
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-card">
                                <h4><i class='bx bx-user-circle'></i> Nombre</h4>
                                <p><?php echo htmlspecialchars($usuario_nombre); ?></p>
                            </div>
                            
                            <div class="info-card">
                                <h4><i class='bx bx-envelope'></i> Email</h4>
                                <p><?php echo htmlspecialchars($usuario_email); ?></p>
                            </div>
                            
                            <div class="info-card">
                                <h4><i class='bx bx-calendar'></i> Rol</h4>
                                <p>
                                    <span class="badge badge-primary">
                                        <?php echo getRolTexto($usuario_rol); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
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
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Crear partículas de fondo
            createParticles();
            
            // Formatear servicios al enviar formulario
            const serviciosTextarea = document.querySelector('textarea[name="servicios"]');
            if (serviciosTextarea) {
                const form = serviciosTextarea.closest('form');
                form.addEventListener('submit', function() {
                    const servicios = serviciosTextarea.value.split(',')
                        .map(s => s.trim())
                        .filter(s => s !== '')
                        .join(', ');
                    serviciosTextarea.value = servicios;
                });
            }
        });

        function createParticles() {
            const particlesContainer = document.querySelector('.particles');
            for (let i = 0; i < 10; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    animation-delay: ${Math.random() * 5}s;
                    opacity: ${Math.random() * 0.5 + 0.1};
                    background: ${Math.random() > 0.5 ? 'rgba(46, 125, 50, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        }
    </script>
</body>
</html>