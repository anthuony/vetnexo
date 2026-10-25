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

// Obtener ID de la fumigación
$fumigacion_id = $_GET['id'] ?? 0;

if (!$fumigacion_id) {
    header('Location: fumigaciones_activas.php');
    exit();
}

// Obtener datos de la fumigación
$fumigacion = null;
$inspeccion = null;
$sesiones = [];
$pagos = [];

try {
    // Obtener datos de la fumigación con JOIN a clientes y servicios
    $sql = "SELECT fr.*, 
                   c.nombre as nombre_cliente, 
                   c.telefono,
                   c.direccion,
                   c.email,
                   c.documento_identidad,
                   s.nombre_servicio as tipo_servicio,
                   s.descripcion as descripcion_servicio,
                   DATE_FORMAT(fr.fecha_registro, '%d/%m/%Y') as fecha_registro_formateada,
                   DATE_FORMAT(fr.fecha_programada, '%d/%m/%Y') as fecha_programada_formateada,
                   DATE_FORMAT(fr.fecha_ultima_sesion, '%d/%m/%Y') as fecha_ultima_sesion_formateada,
                   u.nombre as usuario_registro
            FROM fumigaciones_realizadas fr
            LEFT JOIN clientes c ON fr.cliente_id = c.id
            LEFT JOIN servicios_fumigacion s ON fr.servicio_id = s.id
            LEFT JOIN usuarios u ON fr.usuario_realizo = u.id
            WHERE fr.id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$fumigacion_id]);
    $fumigacion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fumigacion) {
        header('Location: fumigaciones_activas.php');
        exit();
    }
    
    // Obtener datos de la inspección asociada (si existe)
    if (!empty($fumigacion['inspeccion_id'])) {
        $sql_ins = "SELECT i.*, 
                           DATE_FORMAT(i.fecha_inspeccion, '%d/%m/%Y') as fecha_inspeccion_formateada,
                           DATE_FORMAT(i.fecha_solicitud, '%d/%m/%Y') as fecha_solicitud_formateada
                    FROM inspecciones_fumigacion i
                    WHERE i.id = ?";
        $stmt_ins = $db->prepare($sql_ins);
        $stmt_ins->execute([$fumigacion['inspeccion_id']]);
        $inspeccion = $stmt_ins->fetch(PDO::FETCH_ASSOC);
    }
    
    // Obtener todas las sesiones de esta fumigación
    $sql_sesiones = "SELECT a.*,
                            DATE_FORMAT(a.fecha_programada, '%d/%m/%Y') as fecha_programada_formateada,
                            DATE_FORMAT(a.fecha_completada, '%d/%m/%Y') as fecha_completada_formateada,
                            TIME_FORMAT(a.hora_programada, '%H:%i') as hora_programada_formateada,
                            TIME_FORMAT(a.hora_completada, '%H:%i') as hora_completada_formateada
                     FROM agenda_fumigaciones a
                     WHERE a.fumigacion_id = ?
                     ORDER BY a.numero_sesion ASC, a.fecha_programada ASC";
    $stmt_sesiones = $db->prepare($sql_sesiones);
    $stmt_sesiones->execute([$fumigacion_id]);
    $sesiones = $stmt_sesiones->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error al cargar los datos: " . $e->getMessage();
}

// Calcular progreso
$progreso = 0;
if ($fumigacion && $fumigacion['numero_sesiones'] > 0) {
    $progreso = round(($fumigacion['sesiones_completadas'] / $fumigacion['numero_sesiones']) * 100);
}

// Determinar si está activa
$activa = $fumigacion && ($fumigacion['sesiones_completadas'] < $fumigacion['numero_sesiones']);

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
    <title>Detalles de Fumigación - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
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

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .badge-activa {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-completada {
            background: #e3f2fd;
            color: var(--azul);
        }

        .badge-pendiente {
            background: #fff3e0;
            color: var(--accent);
        }

        .badge-id {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid var(--primary-light);
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
            flex-wrap: wrap;
            gap: 1rem;
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

        .client-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .client-header h2 {
            font-size: 1.5rem;
            color: var(--primary-dark);
            margin-bottom: 0.3rem;
        }

        .client-header p {
            color: var(--text-light);
        }

        .progress-container {
            margin: 1.5rem 0;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .progress-title {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .progress-percent {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1rem;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: var(--gray-light);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .progress-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
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

        .info-label {
            font-size: 0.8rem;
            color: var(--text-lighter);
            margin-bottom: 0.2rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .info-value small {
            font-weight: normal;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 0.75rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: var(--primary-soft);
        }

        .session-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-programada {
            background: #fff3e0;
            color: var(--accent);
        }

        .badge-completada {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-cancelada {
            background: #ffebee;
            color: var(--emergency);
        }

        .badge-enproceso {
            background: #e3f2fd;
            color: var(--azul);
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
            color: var(--white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, var(--azul-light), #3a6ab8);
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

        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
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
                text-align: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .empty-actions {
                flex-direction: column;
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
                        <i class='bx bx-detail'></i>
                        Detalles de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Información completa del servicio de fumigación
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver
                    </a>
                    <a href="fumigaciones_activas.php" class="btn btn-info">
                        <i class='bx bx-list-ul'></i> Ver todas
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

            <!-- Mensaje de error -->
            <?php if (isset($error)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div style="background: #ffebee; color: var(--emergency); padding: 1rem; border-radius: var(--radius-sm);">
                            <i class='bx bx-error-circle'></i> <?php echo $error; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($fumigacion): ?>
                <!-- Cabecera con estado y progreso -->
                <div class="content-section fade-in">
                    <div class="section-body">
                        <div class="client-header">
                            <div>
                                <h2><?php echo htmlspecialchars($fumigacion['nombre_cliente'] ?? 'Cliente no especificado'); ?></h2>
                                <p><?php echo htmlspecialchars($fumigacion['tipo_servicio'] ?? 'Servicio no especificado'); ?></p>
                            </div>
                            <div style="display: flex; gap: 1rem; align-items: center;">
                                <span class="badge <?php echo $activa ? 'badge-activa' : 'badge-completada'; ?>">
                                    <?php echo $activa ? 'ACTIVA' : 'COMPLETADA'; ?>
                                </span>
                                <span class="badge badge-id">
                                    ID: #<?php echo str_pad($fumigacion['id'], 4, '0', STR_PAD_LEFT); ?>
                                </span>
                            </div>
                        </div>

                        <div class="progress-container">
                            <div class="progress-header">
                                <span class="progress-title">Progreso del servicio</span>
                                <span class="progress-percent"><?php echo $progreso; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progreso; ?>%;"></div>
                            </div>
                            <div class="progress-stats">
                                <span><?php echo $fumigacion['sesiones_completadas']; ?> sesiones completadas</span>
                                <span><?php echo $fumigacion['numero_sesiones']; ?> sesiones totales</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Información principal en grid -->
                <div class="content-grid fade-in">
                    <!-- Datos del cliente -->
                    <div class="content-section">
                        <div class="section-header">
                            <h3>
                                <i class='bx bx-user'></i>
                                Información del Cliente
                            </h3>
                        </div>
                        
                        <div class="section-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class='bx bx-user'></i>
                                    <div>
                                        <div class="info-label">Nombre</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['nombre_cliente'] ?? 'No especificado'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-phone'></i>
                                    <div>
                                        <div class="info-label">Teléfono</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['telefono'] ?? 'No especificado'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-envelope'></i>
                                    <div>
                                        <div class="info-label">Email</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['email'] ?? 'No especificado'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-id-card'></i>
                                    <div>
                                        <div class="info-label">Documento</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['documento_identidad'] ?? 'No especificado'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item" style="grid-column: 1 / -1;">
                                    <i class='bx bx-map'></i>
                                    <div style="flex:1;">
                                        <div class="info-label">Dirección</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['direccion'] ?? 'No especificada'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Datos del servicio -->
                    <div class="content-section">
                        <div class="section-header">
                            <h3>
                                <i class='bx bx-spray-can'></i>
                                Detalles del Servicio
                            </h3>
                        </div>
                        
                        <div class="section-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class='bx bx-category'></i>
                                    <div>
                                        <div class="info-label">Tipo de Servicio</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['tipo_servicio'] ?? 'No especificado'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-calendar'></i>
                                    <div>
                                        <div class="info-label">Fecha de Registro</div>
                                        <div class="info-value"><?php echo $fumigacion['fecha_registro_formateada'] ?? 'N/A'; ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-user-circle'></i>
                                    <div>
                                        <div class="info-label">Técnico Asignado</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['tecnico_asignado'] ?? 'No asignado'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-user-check'></i>
                                    <div>
                                        <div class="info-label">Registrado por</div>
                                        <div class="info-value"><?php echo htmlspecialchars($fumigacion['usuario_registro'] ?? 'Sistema'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-dollar-circle'></i>
                                    <div>
                                        <div class="info-label">Precio por Sesión</div>
                                        <div class="info-value">$<?php echo number_format((float)($fumigacion['precio_sesion'] ?? 0), 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-wallet'></i>
                                    <div>
                                        <div class="info-label">Costo Total</div>
                                        <div class="info-value">$<?php echo number_format((float)($fumigacion['costo_total'] ?? 0), 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <i class='bx bx-credit-card'></i>
                                    <div>
                                        <div class="info-label">Estado de Pago</div>
                                        <div class="info-value">
                                            <span class="badge <?php echo ($fumigacion['estado_pago'] ?? 'Pendiente') == 'Pagado' ? 'badge-completada' : 'badge-pendiente'; ?>" style="font-size: 0.7rem;">
                                                <?php echo $fumigacion['estado_pago'] ?? 'Pendiente'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($fumigacion['fecha_ultima_sesion_formateada'])): ?>
                                <div class="info-item">
                                    <i class='bx bx-check-circle'></i>
                                    <div>
                                        <div class="info-label">Última sesión realizada</div>
                                        <div class="info-value"><?php echo $fumigacion['fecha_ultima_sesion_formateada']; ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($fumigacion['descripcion_servicio'])): ?>
                            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--gray-light);">
                                <div class="info-label" style="margin-bottom: 0.5rem;">Descripción del servicio</div>
                                <p style="color: var(--text-light);"><?php echo nl2br(htmlspecialchars($fumigacion['descripcion_servicio'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Inspección asociada -->
                <?php if ($inspeccion): ?>
                <div class="content-section fade-in">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-search-alt'></i>
                            Inspección Asociada
                        </h3>
                        <a href="ver_inspeccion.php?id=<?php echo $inspeccion['id']; ?>" class="btn btn-info btn-sm">
                            <i class='bx bx-show'></i> Ver detalles
                        </a>
                    </div>
                    
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <i class='bx bx-calendar'></i>
                                <div>
                                    <div class="info-label">Fecha de Inspección</div>
                                    <div class="info-value"><?php echo $inspeccion['fecha_inspeccion_formateada'] ?? 'N/A'; ?></div>
                                </div>
                            </div>
                            
                            <?php if (!empty($inspeccion['hora_inspeccion'])): ?>
                            <div class="info-item">
                                <i class='bx bx-time'></i>
                                <div>
                                    <div class="info-label">Hora</div>
                                    <div class="info-value"><?php echo $inspeccion['hora_inspeccion']; ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-item">
                                <i class='bx bx-calendar-check'></i>
                                <div>
                                    <div class="info-label">Fecha de Solicitud</div>
                                    <div class="info-value"><?php echo $inspeccion['fecha_solicitud_formateada'] ?? 'N/A'; ?></div>
                                </div>
                            </div>
                            
                            <?php if (!empty($inspeccion['observaciones'])): ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <i class='bx bx-note'></i>
                                <div style="flex:1;">
                                    <div class="info-label">Observaciones de la inspección</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($inspeccion['observaciones'])); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Sesiones -->
                <div class="content-section fade-in">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-calendar'></i>
                            Historial de Sesiones
                        </h3>
                        <?php if ($activa): ?>
                        <a href="agendar_sesiones.php?fumigacion_id=<?php echo $fumigacion['id']; ?>" class="btn btn-primary btn-sm">
                            <i class='bx bx-plus'></i> Agendar Sesión
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section-body">
                        <?php if (count($sesiones) > 0): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Sesión</th>
                                            <th>Fecha Programada</th>
                                            <th>Hora</th>
                                            <th>Técnico</th>
                                            <th>Estado</th>
                                            <th>Fecha Realizada</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sesiones as $sesion): 
                                            $estado_class = 'badge-programada';
                                            $estado_text = 'Programada';
                                            
                                            if ($sesion['estado'] == 'Completada') {
                                                $estado_class = 'badge-completada';
                                                $estado_text = 'Completada';
                                            } elseif ($sesion['estado'] == 'Cancelada') {
                                                $estado_class = 'badge-cancelada';
                                                $estado_text = 'Cancelada';
                                            } elseif ($sesion['estado'] == 'En Proceso') {
                                                $estado_class = 'badge-enproceso';
                                                $estado_text = 'En Proceso';
                                            }
                                        ?>
                                        <tr>
                                            <td><strong>Sesión <?php echo $sesion['numero_sesion']; ?></strong></td>
                                            <td><?php echo $sesion['fecha_programada_formateada']; ?></td>
                                            <td><?php echo $sesion['hora_programada_formateada'] ?? '--:--'; ?></td>
                                            <td><?php echo htmlspecialchars($sesion['tecnico'] ?? 'No asignado'); ?></td>
                                            <td>
                                                <span class="session-badge <?php echo $estado_class; ?>">
                                                    <?php echo $estado_text; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $sesion['fecha_completada_formateada'] ?? '--'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <p>No hay sesiones registradas para esta fumigación.</p>
                                <?php if ($activa): ?>
                                <div class="empty-actions">
                                    <a href="agendar_sesiones.php?fumigacion_id=<?php echo $fumigacion['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class='bx bx-plus'></i> Agendar primera sesión
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Observaciones y recomendaciones -->
                <?php if (!empty($fumigacion['observaciones']) || !empty($fumigacion['recomendaciones_post']) || 
                          !empty($fumigacion['area_tratada']) || !empty($fumigacion['productos_aplicados']) || 
                          !empty($fumigacion['dosis_aplicada'])): ?>
                <div class="content-section fade-in">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-note'></i>
                            Notas y Recomendaciones
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <div class="info-grid">
                            <?php if (!empty($fumigacion['observaciones'])): ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <i class='bx bx-message-rounded-dots'></i>
                                <div style="flex:1;">
                                    <div class="info-label">Observaciones generales</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($fumigacion['observaciones'])); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($fumigacion['recomendaciones_post'])): ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <i class='bx bx-bulb'></i>
                                <div style="flex:1;">
                                    <div class="info-label">Recomendaciones post-fumigación</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($fumigacion['recomendaciones_post'])); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($fumigacion['area_tratada'])): ?>
                            <div class="info-item">
                                <i class='bx bx-area'></i>
                                <div>
                                    <div class="info-label">Área tratada</div>
                                    <div class="info-value"><?php echo htmlspecialchars($fumigacion['area_tratada']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($fumigacion['productos_aplicados'])): ?>
                            <div class="info-item">
                                <i class='bx bx-flask'></i>
                                <div>
                                    <div class="info-label">Productos aplicados</div>
                                    <div class="info-value"><?php echo htmlspecialchars($fumigacion['productos_aplicados']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($fumigacion['dosis_aplicada'])): ?>
                            <div class="info-item">
                                <i class='bx bx-test-tube'></i>
                                <div>
                                    <div class="info-label">Dosis aplicada</div>
                                    <div class="info-value"><?php echo htmlspecialchars($fumigacion['dosis_aplicada']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Acciones -->
                <div class="content-section fade-in">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-cog'></i>
                            Acciones
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <div class="action-buttons">
                            <?php if ($activa): ?>
                                <a href="realizar_sesion.php?fumigacion_id=<?php echo $fumigacion['id']; ?>" class="btn btn-success">
                                    <i class='bx bx-play'></i> Realizar próxima sesión
                                </a>
                                <a href="agendar_sesiones.php?fumigacion_id=<?php echo $fumigacion['id']; ?>" class="btn btn-primary">
                                    <i class='bx bx-calendar-plus'></i> Agendar sesión
                                </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($fumigacion['inspeccion_id'])): ?>
                            <a href="ver_inspeccion.php?id=<?php echo $fumigacion['inspeccion_id']; ?>" class="btn btn-info">
                                <i class='bx bx-search-alt'></i> Ver inspección
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($fumigacion['estado_pago'] == 'Pendiente'): ?>
                            <a href="registrar_pago.php?fumigacion_id=<?php echo $fumigacion['id']; ?>" class="btn btn-warning">
                                <i class='bx bx-credit-card'></i> Registrar pago
                            </a>
                            <?php endif; ?>
                        </div>
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