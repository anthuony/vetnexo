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

$mensaje = '';
$tipo_mensaje = '';
$db = null;

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
    
} catch (PDOException $e) {
    error_log("Error en registrarcliente.php: " . $e->getMessage());
    $mensaje = "Error de conexión: " . $e->getMessage();
    $tipo_mensaje = "error";
}

// Procesar el formulario solo si hay conexión a la base de datos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $documento_identidad = trim($_POST['documento_identidad'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
        
        // Validaciones básicas
        if (empty($nombre)) {
            throw new Exception('El campo nombre es obligatorio');
        }
        
        // Validar email si se proporciona
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El formato del email no es válido');
        }
        
        // Verificar si ya existe un cliente con el mismo email
        if (!empty($email)) {
            $check_email_sql = "SELECT id FROM clientes WHERE email = ?";
            $check_email_stmt = $db->prepare($check_email_sql);
            $check_email_stmt->execute([$email]);
            if ($check_email_stmt->fetch()) {
                throw new Exception('Ya existe un cliente registrado con este email');
            }
        }
        
        // Verificar si ya existe un cliente con el mismo documento de identidad
        if (!empty($documento_identidad)) {
            $check_documento_sql = "SELECT id FROM clientes WHERE documento_identidad = ?";
            $check_documento_stmt = $db->prepare($check_documento_sql);
            $check_documento_stmt->execute([$documento_identidad]);
            if ($check_documento_stmt->fetch()) {
                throw new Exception('Ya existe un cliente registrado con este documento de identidad');
            }
        }
        
        // Insertar cliente
        $insert_sql = "INSERT INTO clientes (
            nombre, email, telefono, direccion, documento_identidad, notas, fecha_registro
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([
            $nombre, 
            $email ?: null, 
            $telefono ?: null, 
            $direccion ?: null, 
            $documento_identidad ?: null, 
            $notas ?: null
        ]);
        
        $cliente_id = $db->lastInsertId();
        
        $_SESSION['success'] = "Cliente registrado exitosamente.";
        
        // Redirigir según el contexto
        if (isset($_GET['from']) && $_GET['from'] === 'mascota') {
            header("Location: registrarmascota.php?cliente_id={$cliente_id}");
            exit();
        } else {
            header('Location: listadeclientes.php');
            exit();
        }
        
    } catch (Exception $e) {
        $mensaje = "Error al registrar cliente: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
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

$page_title = "Registrar Cliente - VetNexo";
$viene_de_mascota = isset($_GET['from']) && $_GET['from'] === 'mascota';
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

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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

        .btn-reset {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
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

        .alert-info {
            background: #e3f2fd;
            color: var(--info);
            border: 1px solid #bbdefb;
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

        /* Form Section */
        .form-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .form-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
        }

        .form-header i {
            font-size: 1.5rem;
            color: var(--accent);
        }

        .form-body {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .required::after {
            content: " *";
            color: var(--danger);
            font-weight: 600;
        }

        .form-control {
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

        .form-control:disabled {
            background: var(--gray-light);
            cursor: not-allowed;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .help-text {
            font-size: 0.8rem;
            color: var(--text-lighter);
            margin-top: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .help-text i {
            font-size: 0.9rem;
            color: var(--info);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-light);
        }

        /* Quick Actions Section */
        .quick-actions-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .quick-actions-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .quick-actions-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .action-card {
            background: var(--primary-soft);
            border: 1px solid var(--primary-light);
            border-radius: var(--radius-sm);
            padding: 1.2rem;
            text-align: center;
            text-decoration: none;
            color: var(--text-dark);
            transition: all 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            background: var(--white);
        }

        .action-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .action-title {
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.2rem;
        }

        .action-description {
            font-size: 0.8rem;
            color: var(--text-light);
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

        .footer-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .footer-logo i {
            font-size: 1.5rem;
            color: var(--accent);
        }

        .footer-logo span {
            font-weight: 600;
        }

        .heart {
            color: #ff6b6b;
            animation: heartbeat 1.5s ease-in-out infinite;
        }

        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
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
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(180deg); }
        }

        /* Loading State */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
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
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-primary, .btn-secondary, .btn-reset {
                width: 100%;
                justify-content: center;
            }
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
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
                        <i class='bx bx-user-plus'></i>
                        Registrar Nuevo Cliente
                    </h1>
                    <p>
                        <i class='bx bx-user'></i>
                        Complete la información del propietario
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
                <a href="<?php echo $viene_de_mascota ? 'registrarmascota.php' : 'listadeclientes.php'; ?>" class="btn-secondary btn-sm">
                    <i class='bx bx-arrow-back'></i>
                    <?php echo $viene_de_mascota ? 'Volver a Registro de Mascota' : 'Volver a Lista de Clientes'; ?>
                </a>
            </div>

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> fade-in">
                    <i class='bx bx-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'error-circle'; ?>'></i>
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <?php if ($db === null): ?>
                <div class="alert alert-error fade-in">
                    <i class='bx bx-error-circle'></i>
                    Error de conexión a la base de datos. Por favor, contacte al administrador.
                </div>
            <?php endif; ?>

            <!-- Mensaje informativo si viene desde mascotas -->
            <?php if ($viene_de_mascota && $db !== null): ?>
                <div class="alert alert-info fade-in">
                    <i class='bx bx-info-circle'></i>
                    Está registrando un nuevo cliente desde el formulario de mascotas. Al guardar, será redirigido automáticamente para continuar con el registro de la mascota.
                </div>
            <?php endif; ?>

            <!-- Formulario de Registro -->
            <div class="form-section fade-in">
                <div class="form-header">
                    <i class='bx bx-user-detail'></i>
                    <h2>Información del Cliente</h2>
                </div>
                
                <div class="form-body">
                    <form id="clienteForm" method="POST" action=""><?php echo av_csrf_field(); ?>
                        <div class="form-grid">
                            <!-- Información Personal -->
                            <div class="form-group">
                                <label for="nombre" class="form-label required">Nombre Completo</label>
                                <input type="text" 
                                       id="nombre" 
                                       name="nombre" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                                       required
                                       placeholder="Ej: Juan Pérez García"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                            </div>

                            <div class="form-group">
                                <label for="documento_identidad" class="form-label">Documento de Identidad</label>
                                <input type="text" 
                                       id="documento_identidad" 
                                       name="documento_identidad" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['documento_identidad'] ?? ''); ?>"
                                       placeholder="Ej: 1234567890"
                                       maxlength="20"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                                <div class="help-text">
                                    <i class='bx bx-info-circle'></i>
                                    Cédula, RUC o documento de identificación
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="email" class="form-label">Correo Electrónico</label>
                                <input type="email" 
                                       id="email" 
                                       name="email" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="Ej: juan@email.com"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                            </div>

                            <div class="form-group">
                                <label for="telefono" class="form-label">Teléfono</label>
                                <input type="tel" 
                                       id="telefono" 
                                       name="telefono" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"
                                       placeholder="Ej: 0987654321"
                                       maxlength="20"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                                <div class="help-text">
                                    <i class='bx bx-info-circle'></i>
                                    Número de contacto del cliente
                                </div>
                            </div>

                            <div class="form-group full-width">
                                <label for="direccion" class="form-label">Dirección</label>
                                <textarea id="direccion" 
                                          name="direccion" 
                                          class="form-control" 
                                          placeholder="Dirección completa del cliente"
                                          rows="3"
                                          <?php echo $db === null ? 'disabled' : ''; ?>><?php echo htmlspecialchars($_POST['direccion'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="notas" class="form-label">Notas Adicionales</label>
                                <textarea id="notas" 
                                          name="notas" 
                                          class="form-control" 
                                          placeholder="Información adicional sobre el cliente (preferencias, observaciones, etc.)"
                                          rows="4"
                                          <?php echo $db === null ? 'disabled' : ''; ?>><?php echo htmlspecialchars($_POST['notas'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Acciones del Formulario -->
                        <div class="form-actions">
                            <button type="reset" class="btn-reset" <?php echo $db === null ? 'disabled' : ''; ?>>
                                <i class='bx bx-reset'></i>
                                Limpiar
                            </button>
                            <a href="<?php echo $viene_de_mascota ? 'registrarmascota.php' : 'listadeclientes.php'; ?>" class="btn-secondary">
                                <i class='bx bx-x'></i>
                                Cancelar
                            </a>
                            <button type="submit" class="btn-primary" <?php echo $db === null ? 'disabled' : ''; ?>>
                                <i class='bx bx-save'></i>
                                <?php echo $viene_de_mascota ? 'Registrar y Continuar' : 'Registrar Cliente'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Acciones Rápidas (solo si hay conexión) -->
            <?php if ($db !== null): ?>
            <div class="quick-actions-section fade-in">
                <div class="quick-actions-header">
                    <h3>
                        <i class='bx bx-zap'></i>
                        Acciones Relacionadas
                    </h3>
                </div>
                <div class="quick-actions-grid">
                    <a href="registrarmascota.php" class="action-card">
                        <div class="action-icon">
                            <i class='bx bx-paw'></i>
                        </div>
                        <div class="action-title">Registrar Mascota</div>
                        <div class="action-description">Agregar una nueva mascota al sistema</div>
                    </a>
                    <a href="listadeclientes.php" class="action-card">
                        <div class="action-icon">
                            <i class='bx bx-list-ul'></i>
                        </div>
                        <div class="action-title">Ver Todos los Clientes</div>
                        <div class="action-description">Lista completa de clientes registrados</div>
                    </a>
                    <a href="agendarcita.php" class="action-card">
                        <div class="action-icon">
                            <i class='bx bx-calendar-plus'></i>
                        </div>
                        <div class="action-title">Agendar Cita</div>
                        <div class="action-description">Programar una nueva cita médica</div>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-content">
                    <div class="footer-logo">
                        <i class='bx bx-plus-medical'></i>
                        <span>VetNexo</span>
                    </div>
                    <p>© <?php echo date('Y'); ?> VetNexo | Desarrollado con <span class="heart">❤️</span> por <strong>VetNexo Product Team</strong></p>
                </div>
            </footer>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            setupFormValidation();
            setupInputFormatting();
            createParticles();
        });

        // Validación del formulario
        function setupFormValidation() {
            const form = document.getElementById('clienteForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const nombre = document.getElementById('nombre').value.trim();
                    const email = document.getElementById('email').value.trim();
                    const telefono = document.getElementById('telefono').value.trim();
                    const documento = document.getElementById('documento_identidad').value.trim();
                    
                    if (!nombre) {
                        e.preventDefault();
                        showNotification('Por favor, complete el campo nombre', 'error');
                        return false;
                    }
                    
                    if (email && !isValidEmail(email)) {
                        e.preventDefault();
                        showNotification('Por favor, ingrese un email válido', 'error');
                        return false;
                    }
                    
                    if (telefono && !isValidPhone(telefono)) {
                        e.preventDefault();
                        showNotification('Por favor, ingrese un teléfono válido (solo números)', 'error');
                        return false;
                    }
                    
                    if (documento && !isValidDocument(documento)) {
                        e.preventDefault();
                        showNotification('Por favor, ingrese un documento válido (solo letras y números)', 'error');
                        return false;
                    }
                    
                    // Mostrar estado de carga
                    this.classList.add('loading');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Procesando...';
                    submitBtn.disabled = true;
                });
            }
        }

        // Formateo automático de inputs
        function setupInputFormatting() {
            // Teléfono: solo números
            const telefono = document.getElementById('telefono');
            if (telefono) {
                telefono.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length <= 20) {
                        e.target.value = value;
                    } else {
                        e.target.value = value.slice(0, 20);
                    }
                });
            }

            // Documento: solo letras y números
            const documento = document.getElementById('documento_identidad');
            if (documento) {
                documento.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/[^a-zA-Z0-9]/g, '');
                    if (value.length <= 20) {
                        e.target.value = value;
                    } else {
                        e.target.value = value.slice(0, 20);
                    }
                });
            }
        }

        // Validaciones
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        function isValidPhone(phone) {
            const phoneRegex = /^\d+$/;
            return phoneRegex.test(phone);
        }

        function isValidDocument(doc) {
            const docRegex = /^[a-zA-Z0-9]+$/;
            return docRegex.test(doc);
        }

        // Sistema de notificaciones
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: var(--radius-sm);
                background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#F44336' : type === 'warning' ? '#FFC107' : '#2196F3'};
                color: white;
                font-weight: 600;
                z-index: 10000;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 400px;
                box-shadow: var(--shadow-lg);
            `;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Crear partículas de fondo
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

        // Limpiar formulario con confirmación
        document.querySelector('button[type="reset"]')?.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('¿Está seguro de que desea limpiar todos los campos?')) {
                document.getElementById('clienteForm').reset();
            }
        });
    </script>
</body>
</html>