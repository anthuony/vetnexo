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

// Obtener lista de fumigaciones activas para el selector
$fumigaciones_activas = [];
try {
    // CORREGIDO: Usamos los campos que existen en tu tabla fumigaciones_realizadas
    $sql = "SELECT id, 
                   cliente_id, 
                   servicio_id,
                   fecha_programada,
                   numero_sesiones, 
                   sesiones_completadas,
                   precio_sesion,
                   costo_total
            FROM fumigaciones_realizadas 
            WHERE sesiones_completadas < numero_sesiones 
            ORDER BY id DESC";
    $stmt = $db->query($sql);
    $fumigaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada fumigación, obtenemos el nombre del cliente de la tabla clientes
    foreach ($fumigaciones as $fum) {
        if ($fum['cliente_id']) {
            $stmt_cliente = $db->prepare("SELECT nombre, telefono FROM clientes WHERE id = ?");
            $stmt_cliente->execute([$fum['cliente_id']]);
            $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
            
            $fumigaciones_activas[] = [
                'id' => $fum['id'],
                'cliente_nombre' => $cliente['nombre'] ?? 'Cliente #' . $fum['cliente_id'],
                'cliente_telefono' => $cliente['telefono'] ?? '',
                'servicio_id' => $fum['servicio_id'],
                'fecha_programada' => $fum['fecha_programada'],
                'numero_sesiones' => $fum['numero_sesiones'],
                'sesiones_completadas' => $fum['sesiones_completadas'],
                'precio_sesion' => $fum['precio_sesion'],
                'costo_total' => $fum['costo_total']
            ];
        }
    }
} catch (PDOException $e) {
    $error = "Error al cargar fumigaciones: " . $e->getMessage();
}

// Obtener lista de servicios de fumigación
$servicios = [];
try {
    $sql = "SELECT id, nombre_servicio, precio_base FROM servicios_fumigacion WHERE activo = 1 ORDER BY nombre_servicio";
    $stmt = $db->query($sql);
    $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si no existe la tabla, continuamos sin servicios
}

// Obtener técnicos disponibles (puedes personalizar esta lista)
$tecnicos = ['Romer Mariño', 'Carlos Rodríguez', 'Héctor', 'Leonel', 'María Pérez', 'José González', 'Luis Martínez'];
asort($tecnicos);

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fumigacion_id = $_POST['fumigacion_id'] ?? '';
    $numero_sesion = $_POST['numero_sesion'] ?? 1;
    $fecha_programada = $_POST['fecha_programada'] ?? '';
    $hora_programada = $_POST['hora_programada'] ?? '';
    $tecnico = $_POST['tecnico'] ?? '';
    $notas = trim($_POST['notas'] ?? '');
    
    // Validar campos obligatorios
    if (empty($fumigacion_id)) {
        $error = 'Por favor seleccione una fumigación.';
    } elseif (empty($fecha_programada)) {
        $error = 'Por favor seleccione la fecha de la sesión.';
    } elseif (empty($tecnico)) {
        $error = 'Por favor seleccione el técnico asignado.';
    } else {
        try {
            // Verificar que la fumigación existe y tiene sesiones pendientes
            $sql_check = "SELECT id, cliente_id, numero_sesiones, sesiones_completadas 
                         FROM fumigaciones_realizadas 
                         WHERE id = ? AND sesiones_completadas < numero_sesiones";
            $stmt_check = $db->prepare($sql_check);
            $stmt_check->execute([$fumigacion_id]);
            $fumigacion = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$fumigacion) {
                $error = 'La fumigación seleccionada no existe o ya completó todas sus sesiones.';
            } else {
                // Verificar que no exista ya una sesión programada para la misma fecha/hora
                $sql_duplicado = "SELECT id FROM agenda_fumigaciones 
                                 WHERE fumigacion_id = ? AND fecha_programada = ? AND hora_programada = ? AND estado = 'Programada'";
                $stmt_duplicado = $db->prepare($sql_duplicado);
                $stmt_duplicado->execute([$fumigacion_id, $fecha_programada, $hora_programada]);
                
                if ($stmt_duplicado->rowCount() > 0) {
                    $error = 'Ya existe una sesión programada para esta fecha y hora.';
                } else {
                    // Insertar la sesión
                    $sql_insert = "INSERT INTO agenda_fumigaciones 
                                  (fumigacion_id, numero_sesion, fecha_programada, hora_programada, tecnico, notas, estado, notificacion_enviada) 
                                  VALUES (?, ?, ?, ?, ?, ?, 'Programada', 0)";
                    $stmt_insert = $db->prepare($sql_insert);
                    $resultado = $stmt_insert->execute([
                        $fumigacion_id, 
                        $numero_sesion, 
                        $fecha_programada, 
                        $hora_programada, 
                        $tecnico,
                        $notas
                    ]);
                    
                    if ($resultado) {
                        $mensaje = 'Sesión agendada correctamente.';
                    } else {
                        $error = 'Error al agendar la sesión.';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Error en la base de datos: ' . $e->getMessage();
        }
    }
}

// Obtener próximas sesiones programadas
$proximas_sesiones = [];
try {
    $sql = "SELECT a.*, 
                   f.cliente_id, f.servicio_id,
                   DATE_FORMAT(a.fecha_programada, '%d/%m/%Y') as fecha_formateada
            FROM agenda_fumigaciones a
            JOIN fumigaciones_realizadas f ON a.fumigacion_id = f.id
            WHERE a.estado = 'Programada' AND a.fecha_programada >= CURDATE()
            ORDER BY a.fecha_programada ASC, a.hora_programada ASC
            LIMIT 10";
    $stmt = $db->query($sql);
    $sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada sesión, obtenemos el nombre del cliente
    foreach ($sesiones as $sesion) {
        if ($sesion['cliente_id']) {
            $stmt_cliente = $db->prepare("SELECT nombre, telefono FROM clientes WHERE id = ?");
            $stmt_cliente->execute([$sesion['cliente_id']]);
            $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
            
            $proximas_sesiones[] = [
                'id' => $sesion['id'],
                'fumigacion_id' => $sesion['fumigacion_id'],
                'numero_sesion' => $sesion['numero_sesion'],
                'fecha_programada' => $sesion['fecha_programada'],
                'fecha_formateada' => $sesion['fecha_formateada'],
                'hora_programada' => $sesion['hora_programada'],
                'tecnico' => $sesion['tecnico'],
                'notas' => $sesion['notas'],
                'cliente_nombre' => $cliente['nombre'] ?? 'Cliente #' . $sesion['cliente_id'],
                'cliente_telefono' => $cliente['telefono'] ?? ''
            ];
        }
    }
} catch (PDOException $e) {
    // Silenciar error
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
    <title>Agendar Sesiones - Fumigación - VetNexo</title>
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
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            
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

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            animation: fadeIn 0.7s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }

        .section-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .section-header h3 i {
            color: var(--accent);
        }

        .section-body {
            padding: 1.5rem;
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

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-success .alert-icon {
            color: var(--success);
        }

        .alert-error .alert-icon {
            color: var(--emergency);
        }

        .info-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .info-card p {
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-dark);
        }

        .info-card i {
            color: var(--primary);
            font-size: 1.2rem;
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

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
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

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-hoy {
            background: var(--emergency);
            color: white;
        }

        .badge-manana {
            background: var(--accent);
            color: white;
        }

        .badge-programada {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .badge-info {
            background: #e3f2fd;
            color: var(--azul);
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
            margin-bottom: 0.5rem;
        }

        .legend {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 1rem;
        }

        .legend-items {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            justify-content: center;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .legend-color.emergency { background: var(--emergency); }
        .legend-color.accent { background: var(--accent); }
        .legend-color.primary { background: var(--primary); }

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
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1rem; }
            .dashboard-header { flex-direction: column; gap: 1.5rem; text-align: center; }
            .header-actions { flex-direction: column; width: 100%; }
            .header-actions .back-btn { width: 100%; justify-content: center; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .legend-items { flex-direction: column; align-items: center; gap: 0.5rem; }
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
                        <i class='bx bx-calendar-plus'></i>
                        Agendar Sesiones de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Programa las sesiones pendientes para cada cliente
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="index.php" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver al Dashboard
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

            <!-- Contenido principal -->
            <div class="content-grid">
                <!-- Formulario para agendar -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-calendar-plus'></i>
                            Programar Nueva Sesión
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <div class="info-card">
                            <p><i class='bx bx-info-circle'></i> Las sesiones se agendan para fumigaciones activas que tienen sesiones pendientes.</p>
                            <p><i class='bx bx-time'></i> Puedes programar varias sesiones para un mismo cliente si es necesario.</p>
                        </div>

                        <form method="POST" id="formAgendar"><?php echo av_csrf_field(); ?>
                            <div class="form-group">
                                <label class="form-label required">Seleccionar Fumigación</label>
                                <select name="fumigacion_id" id="fumigacion_id" class="form-control select" required>
                                    <option value="">-- Seleccione una fumigación --</option>
                                    <?php foreach ($fumigaciones_activas as $fum): ?>
                                        <option value="<?php echo $fum['id']; ?>">
                                            <?php echo htmlspecialchars($fum['cliente_nombre']); ?> - 
                                            Sesiones: <?php echo $fum['sesiones_completadas']; ?>/<?php echo $fum['numero_sesiones']; ?> 
                                            ($<?php echo number_format($fum['precio_sesion'] ?? 0, 2); ?>/sesión)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($fumigaciones_activas)): ?>
                                    <small style="color: var(--warning); display: block; margin-top: 0.5rem;">
                                        <i class='bx bx-info-circle'></i> No hay fumigaciones activas con sesiones pendientes.
                                    </small>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Número de Sesión</label>
                                <select name="numero_sesion" id="numero_sesion" class="form-control select">
                                    <option value="1">Sesión 1</option>
                                    <option value="2">Sesión 2</option>
                                    <option value="3">Sesión 3</option>
                                    <option value="4">Sesión 4</option>
                                    <option value="5">Sesión 5</option>
                                </select>
                                <small class="form-text" style="color: var(--text-lighter);">Indica qué número de sesión estás agendando</small>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label required">Fecha</label>
                                    <input type="date" name="fecha_programada" id="fecha_programada" 
                                           class="form-control" required
                                           min="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Hora</label>
                                    <input type="time" name="hora_programada" id="hora_programada" 
                                           class="form-control"
                                           value="09:00">
                                    <small class="form-text" style="color: var(--text-lighter);">Opcional</small>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Técnico Asignado</label>
                                <select name="tecnico" id="tecnico" class="form-control select" required>
                                    <option value="">-- Seleccione un técnico --</option>
                                    <?php foreach ($tecnicos as $tecnico): ?>
                                        <option value="<?php echo htmlspecialchars($tecnico); ?>">
                                            <?php echo htmlspecialchars($tecnico); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Notas Adicionales</label>
                                <textarea name="notas" id="notas" class="form-control" 
                                          placeholder="Instrucciones especiales, observaciones, etc."></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-calendar-check'></i>
                                    Agendar Sesión
                                </button>
                                <button type="reset" class="btn btn-secondary" onclick="return confirm('¿Limpiar formulario?')">
                                    <i class='bx bx-reset'></i>
                                    Limpiar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Próximas sesiones -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-calendar'></i>
                            Próximas Sesiones
                        </h3>
                        <a href="fumigaciones_activas.php" class="btn btn-secondary btn-sm">
                            Ver todas
                        </a>
                    </div>
                    
                    <div class="section-body">
                        <?php if (count($proximas_sesiones) > 0): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Cliente</th>
                                            <th>Técnico</th>
                                            <th>Sesión</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($proximas_sesiones as $sesion): 
                                            $fecha_sesion = new DateTime($sesion['fecha_programada']);
                                            $hoy = new DateTime();
                                            $es_hoy = $fecha_sesion->format('Y-m-d') == $hoy->format('Y-m-d');
                                            $manana = (clone $hoy)->modify('+1 day');
                                            $es_manana = $fecha_sesion->format('Y-m-d') == $manana->format('Y-m-d');
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $sesion['fecha_formateada']; ?></strong>
                                                <?php if (!empty($sesion['hora_programada'])): ?>
                                                    <br><small style="color: var(--text-light);"><?php echo $sesion['hora_programada']; ?></small>
                                                <?php endif; ?>
                                                <?php if ($es_hoy): ?>
                                                    <br><span class="badge badge-hoy">HOY</span>
                                                <?php elseif ($es_manana): ?>
                                                    <br><span class="badge badge-manana">MAÑANA</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($sesion['cliente_nombre']); ?></strong>
                                                <?php if (!empty($sesion['cliente_telefono'])): ?>
                                                    <br><small style="color: var(--text-light);">📞 <?php echo htmlspecialchars($sesion['cliente_telefono']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($sesion['tecnico'] ?? 'No asignado'); ?></td>
                                            <td>
                                                <span class="badge badge-programada">
                                                    Sesión <?php echo $sesion['numero_sesion']; ?>
                                                </span>
                                                <?php if (!empty($sesion['notas'])): ?>
                                                    <br><small style="color: var(--text-light);" title="<?php echo htmlspecialchars($sesion['notas']); ?>">
                                                        <i class='bx bx-note'></i> Nota
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar'></i>
                                <p>No hay sesiones programadas próximamente</p>
                                <small style="color: var(--text-lighter);">
                                    Usa el formulario para agendar nuevas sesiones
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Leyenda -->
            <div class="legend">
                <div class="legend-items">
                    <div class="legend-item">
                        <div class="legend-color emergency"></div>
                        <span><i class='bx bx-time'></i> HOY - Sesiones para hoy</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color accent"></div>
                        <span><i class='bx bx-time'></i> MAÑANA - Sesiones para mañana</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color primary"></div>
                        <span><i class='bx bx-calendar-check'></i> Programada - Sesión agendada</span>
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

    <script src="../js/sidebar.js"></script>
    <script>
        // Validar que la fecha no sea anterior a hoy
        document.getElementById('fecha_programada').addEventListener('change', function() {
            const fechaSeleccionada = new Date(this.value);
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            
            if (fechaSeleccionada < hoy) {
                alert('La fecha no puede ser anterior a hoy.');
                this.value = '<?php echo date('Y-m-d'); ?>';
            }
        });

        // Validar formulario
        document.getElementById('formAgendar').addEventListener('submit', function(e) {
            const fumigacionId = document.getElementById('fumigacion_id').value;
            const fecha = document.getElementById('fecha_programada').value;
            const tecnico = document.getElementById('tecnico').value;
            
            if (!fumigacionId) {
                e.preventDefault();
                alert('Por favor seleccione una fumigación.');
                document.getElementById('fumigacion_id').focus();
                return false;
            }
            
            if (!fecha) {
                e.preventDefault();
                alert('Por favor seleccione la fecha de la sesión.');
                document.getElementById('fecha_programada').focus();
                return false;
            }
            
            if (!tecnico) {
                e.preventDefault();
                alert('Por favor seleccione el técnico asignado.');
                document.getElementById('tecnico').focus();
                return false;
            }
            
            // Mostrar estado de carga
            this.classList.add('loading');
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Agendando...';
            submitBtn.disabled = true;
        });

        // Reset con confirmación
        document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
            if (!confirm('¿Está seguro de que desea limpiar todos los campos del formulario?')) {
                e.preventDefault();
            }
        });

        // Partículas dinámicas
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