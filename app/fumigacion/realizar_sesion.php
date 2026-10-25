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

$mensaje = '';
$error = '';

// Obtener ID de la fumigación
$fumigacion_id = $_GET['fumigacion_id'] ?? 0;

if (!$fumigacion_id) {
    header('Location: fumigaciones_activas.php');
    exit();
}

// Obtener datos de la fumigación y cliente
$fumigacion = null;
$sesion_pendiente = null;

try {
    // Obtener datos de la fumigación con JOIN a clientes y servicios
    $sql = "SELECT fr.*, 
                   c.nombre as nombre_cliente, 
                   c.telefono,
                   c.direccion,
                   s.nombre_servicio as tipo_servicio
            FROM fumigaciones_realizadas fr
            LEFT JOIN clientes c ON fr.cliente_id = c.id
            LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
            WHERE fr.id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$fumigacion_id]);
    $fumigacion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fumigacion) {
        header('Location: fumigaciones_activas.php');
        exit();
    }
    
    // Verificar si hay sesiones pendientes
    if ($fumigacion['sesiones_completadas'] >= $fumigacion['numero_sesiones']) {
        $error = "Esta fumigación ya tiene todas las sesiones completadas.";
    }
    
    // Obtener la próxima sesión pendiente
    $sql_sesion = "SELECT * FROM agenda_fumigaciones 
                   WHERE fumigacion_id = ? AND estado = 'Programada' 
                   ORDER BY fecha_programada ASC, hora_programada ASC 
                   LIMIT 1";
    $stmt_sesion = $db->prepare($sql_sesion);
    $stmt_sesion->execute([$fumigacion_id]);
    $sesion_pendiente = $stmt_sesion->fetch(PDO::FETCH_ASSOC);
    
    // Si no hay sesión pendiente, mostrar error
    if (!$sesion_pendiente) {
        $error = "No hay sesiones pendientes para esta fumigación.";
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar los datos: " . $e->getMessage();
}

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['realizar_sesion'])) {
    $sesion_id = $_POST['sesion_id'] ?? 0;
    $fecha_realizada = $_POST['fecha_realizada'] ?? date('Y-m-d');
    $hora_realizada = $_POST['hora_realizada'] ?? date('H:i');
    $area_tratada = trim($_POST['area_tratada'] ?? '');
    $productos_aplicados = trim($_POST['productos_aplicados'] ?? '');
    $dosis_aplicada = trim($_POST['dosis_aplicada'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $recomendaciones = trim($_POST['recomendaciones'] ?? '');
    
    try {
        // Iniciar transacción
        $db->beginTransaction();
        
        // Verificar que la sesión existe y está programada
        $sql_check = "SELECT id, fumigacion_id FROM agenda_fumigaciones 
                      WHERE id = ? AND fumigacion_id = ? AND estado = 'Programada' FOR UPDATE";
        $stmt_check = $db->prepare($sql_check);
        $stmt_check->execute([$sesion_id, $fumigacion_id]);
        
        if ($stmt_check->rowCount() == 0) {
            throw new Exception("La sesión no existe o ya fue realizada.");
        }
        
        // Validar que la fecha no sea futura
        $fecha_actual = date('Y-m-d');
        if ($fecha_realizada > $fecha_actual) {
            throw new Exception("La fecha de realización no puede ser futura.");
        }
        
        // Actualizar la sesión en agenda_fumigaciones
        $sql_update_sesion = "UPDATE agenda_fumigaciones 
                              SET estado = 'Completada', 
                                  fecha_completada = ?,
                                  hora_completada = ?
                              WHERE id = ? AND fumigacion_id = ?";
        $stmt_update = $db->prepare($sql_update_sesion);
        $stmt_update->execute([$fecha_realizada, $hora_realizada, $sesion_id, $fumigacion_id]);
        
        // Actualizar la fumigación (incrementar sesiones_completadas)
        $sql_update_fum = "UPDATE fumigaciones_realizadas 
                           SET sesiones_completadas = sesiones_completadas + 1,
                               fecha_ultima_sesion = ?,
                               hora_ultima_sesion = ?,
                               area_tratada = ?,
                               productos_aplicados = ?,
                               dosis_aplicada = ?,
                               observaciones = ?,
                               recomendaciones_post = ?
                           WHERE id = ?";
        $stmt_update_fum = $db->prepare($sql_update_fum);
        $stmt_update_fum->execute([
            $fecha_realizada,
            $hora_realizada,
            $area_tratada,
            $productos_aplicados,
            $dosis_aplicada,
            $observaciones,
            $recomendaciones,
            $fumigacion_id
        ]);
        
        // Verificar si es la última sesión
        $sql_check_completadas = "SELECT sesiones_completadas, numero_sesiones 
                                   FROM fumigaciones_realizadas 
                                   WHERE id = ?";
        $stmt_check_completadas = $db->prepare($sql_check_completadas);
        $stmt_check_completadas->execute([$fumigacion_id]);
        $estado = $stmt_check_completadas->fetch(PDO::FETCH_ASSOC);
        
        // Si se completaron todas las sesiones, actualizar estado
        if ($estado && $estado['sesiones_completadas'] >= $estado['numero_sesiones']) {
            $sql_completar = "UPDATE fumigaciones_realizadas 
                              SET estado_completado = 'Completada',
                                  fecha_completado = NOW()
                              WHERE id = ?";
            $stmt_completar = $db->prepare($sql_completar);
            $stmt_completar->execute([$fumigacion_id]);
        }
        
        // Confirmar transacción
        $db->commit();
        
        $mensaje = '¡Sesión realizada y registrada exitosamente!';
        
        // Redirigir después de 2 segundos
        header("refresh:2;url=fumigaciones_activas.php");
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $db->rollBack();
        $error = 'Error al registrar la sesión: ' . $e->getMessage();
    }
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
    <title>Realizar Sesión - Fumigación - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="../includes/img/logo.svg">
    
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
            --emergency-soft: #ffebee;    /* Rojo suave */
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            --amarillo-soft: #fff9c4;     /* Amarillo suave */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            --marron-soft: #f5f0e8;       /* Marrón suave */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            --azul-soft: #e3f2fd;         /* Azul suave */
            
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

        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
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

        .section-body {
            padding: 1.5rem;
        }

        .client-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .client-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .client-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .client-body {
            padding: 1.5rem;
        }

        .client-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .info-item i {
            font-size: 1.2rem;
            color: var(--primary);
            width: 24px;
            margin-top: 0.2rem;
        }

        .info-item strong {
            display: block;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }

        .info-item span {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .session-badge {
            background: var(--accent-soft);
            color: var(--accent-dark);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid var(--accent);
        }

        .session-info {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-top: 1rem;
            border-left: 4px solid var(--primary);
        }

        .session-info p {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .session-info i {
            color: var(--primary);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: var(--success);
            color: #1e4620;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--emergency);
            color: #601e1e;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-color: var(--accent);
            color: #663c00;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-success .alert-icon {
            color: var(--success);
        }

        .alert-error .alert-icon {
            color: var(--emergency);
        }

        .alert-warning .alert-icon {
            color: var(--accent);
        }

        .form-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .form-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .form-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .form-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .required::after {
            content: " *";
            color: var(--emergency);
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

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-soft);
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(15, 108, 51, 0.4);
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

        .btn-danger {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, var(--emergency-dark), #a50c01);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1rem; }
            
            .dashboard-header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
                padding: 1.5rem;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .back-btn,
            .header-actions .btn-primary {
                width: 100%;
                justify-content: center;
            }
            
            .client-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .client-info {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
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
                        <i class='bx bx-play-circle'></i>
                        Realizar Sesión de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Registra la ejecución de una sesión de fumigación
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="fumigaciones_activas.php" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver a Activas
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

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $mensaje; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $error; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($fumigacion && $sesion_pendiente): ?>
                <!-- Datos del Cliente y Servicio -->
                <div class="client-card fade-in">
                    <div class="client-header">
                        <h3>
                            <i class='bx bx-user'></i>
                            Datos del Cliente
                        </h3>
                        <span class="session-badge">
                            <i class='bx bx-calendar'></i>
                            Sesión <?php echo ($fumigacion['sesiones_completadas'] ?? 0) + 1; ?> de <?php echo $fumigacion['numero_sesiones'] ?? 0; ?>
                        </span>
                    </div>
                    
                    <div class="client-body">
                        <div class="client-info">
                            <div class="info-item">
                                <i class='bx bx-user'></i>
                                <div>
                                    <strong>Cliente</strong>
                                    <span><?php echo htmlspecialchars($fumigacion['nombre_cliente'] ?? 'No especificado'); ?></span>
                                </div>
                            </div>
                            <div class="info-item">
                                <i class='bx bx-phone'></i>
                                <div>
                                    <strong>Teléfono</strong>
                                    <span><?php echo htmlspecialchars($fumigacion['telefono'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                            <div class="info-item">
                                <i class='bx bx-map'></i>
                                <div>
                                    <strong>Dirección</strong>
                                    <span><?php echo htmlspecialchars($fumigacion['direccion'] ?? 'No especificada'); ?></span>
                                </div>
                            </div>
                            <div class="info-item">
                                <i class='bx bx-spray-can'></i>
                                <div>
                                    <strong>Servicio</strong>
                                    <span><?php echo htmlspecialchars($fumigacion['tipo_servicio'] ?? 'No especificado'); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="session-info">
                            <p><i class='bx bx-info-circle'></i> <strong>Sesión pendiente:</strong> Programada para el 
                                <?php echo date('d/m/Y', strtotime($sesion_pendiente['fecha_programada'])); ?>
                                <?php if (!empty($sesion_pendiente['hora_programada'])): ?>
                                    a las <?php echo $sesion_pendiente['hora_programada']; ?>
                                <?php endif; ?>
                            </p>
                            <p><i class='bx bx-user-circle'></i> <strong>Técnico asignado:</strong> <?php echo htmlspecialchars($sesion_pendiente['tecnico'] ?? 'No asignado'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Formulario para registrar la sesión -->
                <div class="form-container fade-in">
                    <div class="form-header">
                        <h2>
                            <i class='bx bx-check-circle'></i>
                            Registrar Sesión Realizada
                        </h2>
                    </div>
                    
                    <div class="form-body">
                        <form method="POST" id="formRealizar"><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="sesion_id" value="<?php echo $sesion_pendiente['id'] ?? 0; ?>">
                            <input type="hidden" name="realizar_sesion" value="1">

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label required">Fecha de Realización</label>
                                    <input type="date" name="fecha_realizada" class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>" required
                                           max="<?php echo date('Y-m-d'); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Hora de Realización</label>
                                    <input type="time" name="hora_realizada" class="form-control" 
                                           value="<?php echo date('H:i'); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Área Tratada</label>
                                <input type="text" name="area_tratada" class="form-control" 
                                       placeholder="Ej: Sala, cocina, jardín, habitaciones...">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Productos Aplicados</label>
                                <textarea name="productos_aplicados" class="form-control" 
                                          placeholder="Lista de productos utilizados..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Dosis Aplicada</label>
                                <input type="text" name="dosis_aplicada" class="form-control" 
                                       placeholder="Ej: 10ml por litro de agua">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Observaciones</label>
                                <textarea name="observaciones" class="form-control" 
                                          placeholder="Observaciones sobre la sesión..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Recomendaciones para el Cliente</label>
                                <textarea name="recomendaciones" class="form-control" 
                                          placeholder="Recomendaciones post-fumigación..."></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-success">
                                    <i class='bx bx-check'></i>
                                    Confirmar Sesión Realizada
                                </button>
                                <a href="fumigaciones_activas.php" class="btn btn-secondary">
                                    <i class='bx bx-x'></i>
                                    Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

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
        // Validar formulario
        document.getElementById('formRealizar')?.addEventListener('submit', function(e) {
            const fecha = document.querySelector('input[name="fecha_realizada"]').value;
            const hoy = new Date().toISOString().split('T')[0];
            
            if (fecha > hoy) {
                e.preventDefault();
                alert('La fecha de realización no puede ser futura.');
                return false;
            }
            
            if (!confirm('¿Estás seguro de marcar esta sesión como realizada?')) {
                e.preventDefault();
                return false;
            }
            
            // Mostrar estado de carga
            this.classList.add('loading');
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Procesando...';
            submitBtn.disabled = true;
        });

        // Crear partículas dinámicas
        document.addEventListener('DOMContentLoaded', function() {
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