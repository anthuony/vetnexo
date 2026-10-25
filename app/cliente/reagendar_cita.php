<?php
// cliente/reagendar_cita.php
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
$cita_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($cita_id <= 0) {
    header("Location: mis_citas.php");
    exit();
}

// Obtener datos de la cita original verificando que pertenezca al cliente y sea reagendable
$stmt = $conn->prepare("
    SELECT c.*, 
           m.nombre as mascota_nombre, 
           m.id as mascota_id,
           s.nombre as servicio_nombre,
           s.id as servicio_id,
           s.precio,
           s.duracion
    FROM citas c
    JOIN mascotas m ON c.mascota_id = m.id
    JOIN servicios s ON c.servicio_id = s.id
    WHERE c.id = ? AND c.cliente_id = ? 
    AND c.estado IN ('scheduled', 'confirmed')
");
$stmt->execute([$cita_id, $cliente_id]);
$cita_original = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cita_original) {
    header("Location: mis_citas.php?error=no_reagendable");
    exit();
}

// Obtener mascotas del cliente (para posible cambio)
$stmt = $conn->prepare("SELECT id, nombre, raza FROM mascotas WHERE cliente_id = ? ORDER BY nombre");
$stmt->execute([$cliente_id]);
$mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener servicios disponibles
$stmt = $conn->prepare("
    SELECT id, nombre, precio, duracion, descripcion 
    FROM servicios 
    WHERE activo = 1 
    ORDER BY nombre
");
$stmt->execute();
$servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener horarios disponibles
$horarios_disponibles = [
    '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
    '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'
];

// Procesar el formulario si se envió
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nueva_mascota_id = (int)($_POST['mascota_id'] ?? $cita_original['mascota_id']);
    $nuevo_servicio_id = (int)($_POST['servicio_id'] ?? $cita_original['servicio_id']);
    $nueva_fecha = $_POST['fecha'] ?? '';
    $nueva_hora = $_POST['hora'] ?? '';
    $notas = trim($_POST['notas'] ?? '');
    $motivo_reagendamiento = trim($_POST['motivo'] ?? 'Cambio de horario por solicitud del cliente');
    
    if (empty($nueva_fecha) || empty($nueva_hora)) {
        $error = "Debes seleccionar fecha y hora para la nueva cita.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Verificar disponibilidad del nuevo horario
            $fecha_hora_nueva = $nueva_fecha . ' ' . $nueva_hora . ':00';
            $stmt = $conn->prepare("
                SELECT id FROM citas 
                WHERE fecha_cita = ? 
                AND id != ?
                AND estado NOT IN ('cancelled', 'completed')
            ");
            $stmt->execute([$fecha_hora_nueva, $cita_id]);
            
            if ($stmt->rowCount() > 0) {
                $error = "Lo sentimos, ese horario ya no está disponible. Por favor elige otro.";
            } else {
                // Guardar datos originales para referencia
                $fecha_original = $cita_original['fecha_cita'];
                $mascota_original = $cita_original['mascota_id'];
                $servicio_original = $cita_original['servicio_id'];
                
                // Construir notas con información del reagendamiento
                $notas_adicionales = "\n\n[REAGENDADA - " . date('d/m/Y H:i') . "]";
                $notas_adicionales .= "\nFecha original: " . date('d/m/Y H:i', strtotime($fecha_original));
                $notas_adicionales .= "\nMotivo: " . $motivo_reagendamiento;
                
                // Actualizar la cita existente con los nuevos datos
                $stmt = $conn->prepare("
                    UPDATE citas 
                    SET mascota_id = ?,
                        servicio_id = ?,
                        fecha_cita = ?,
                        hora_cita = ?,
                        notas = CONCAT(IFNULL(notas, ''), ?),
                        estado = 'scheduled',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $nueva_mascota_id,
                    $nuevo_servicio_id,
                    $fecha_hora_nueva,
                    $nueva_hora,
                    $notas_adicionales,
                    $cita_id
                ]);
                
                // Crear notificación para el cliente
                $stmt_notif = $conn->prepare("
                    INSERT INTO notificaciones_cliente (
                        cliente_id, 
                        tipo, 
                        titulo, 
                        mensaje
                    ) VALUES (?, 'cita_recordatorio', ?, ?)
                ");
                
                $titulo = "Cita reagendada exitosamente";
                $mensaje = "Tu cita ha sido reagendada para el " . date('d/m/Y', strtotime($nueva_fecha)) . " a las " . $nueva_hora . " hrs.";
                
                $stmt_notif->execute([$cliente_id, $titulo, $mensaje]);
                
                $conn->commit();
                
                header("Location: ver_cita.php?id=" . $cita_id . "&success=reagendada");
                exit();
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error al reagendar la cita: " . $e->getMessage();
            error_log("Error al reagendar cita: " . $e->getMessage());
        }
    }
}

// Obtener fechas mínima y máxima
$fecha_min = date('Y-m-d');
$fecha_max = date('Y-m-d', strtotime('+30 days'));

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
    <title>Reagendar Cita - VetNexo</title>
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
            max-width: 800px;
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

        /* Form Container */
        .form-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.5rem 2rem;
        }

        .form-header h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.3rem;
        }

        .form-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .form-body {
            padding: 2rem;
        }

        /* Cita Original */
        .original-cita {
            background: var(--primary-soft);
            border: 1px solid var(--primary-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .original-cita h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            color: var(--primary-dark);
            margin-bottom: 1rem;
        }

        .original-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .original-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .original-item i {
            color: var(--primary);
            width: 20px;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.95rem;
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
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235A5A5A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Horarios */
        .horarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .horario-item {
            position: relative;
        }

        .horario-item input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .horario-item label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.6rem 0.3rem;
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0;
            text-align: center;
        }

        .horario-item input[type="radio"]:checked + label {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .horario-item label:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .alert i {
            font-size: 1.3rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn-primary {
            flex: 1;
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
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-secondary {
            padding: 1rem 2rem;
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-secondary:hover {
            background: var(--gray-light);
            border-color: var(--primary);
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-primary,
            .btn-secondary {
                width: 100%;
            }
            
            .horarios-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 480px) {
            .horarios-grid {
                grid-template-columns: repeat(2, 1fr);
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
                        <?php echo strtoupper(substr($_SESSION['nombre'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars(explode(' ', $_SESSION['nombre'])[0]); ?></h4>
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
        <a href="ver_cita.php?id=<?php echo $cita_id; ?>">Detalle de Cita</a>
        <i class='bx bx-chevron-right'></i>
        <span>Reagendar</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-calendar-edit'></i>
                Reagendar Cita
            </h1>
            <a href="ver_cita.php?id=<?php echo $cita_id; ?>" class="btn-back">
                <i class='bx bx-arrow-back'></i>
                Volver
            </a>
        </div>

        <!-- Form Container -->
        <div class="form-container">
            <div class="form-header">
                <h2>
                    <i class='bx bx-calendar'></i>
                    Selecciona nueva fecha y hora
                </h2>
                <p>Puedes cambiar la mascota o el servicio si lo deseas</p>
            </div>

            <div class="form-body">
                <!-- Cita Original -->
                <div class="original-cita">
                    <h3>
                        <i class='bx bx-info-circle'></i>
                        Cita original
                    </h3>
                    <div class="original-details">
                        <div class="original-item">
                            <i class='bx bx-calendar'></i>
                            <span><?php echo date('d/m/Y', strtotime($cita_original['fecha_cita'])); ?> a las <?php echo date('H:i', strtotime($cita_original['fecha_cita'])); ?> hrs</span>
                        </div>
                        <div class="original-item">
                            <i class='bx bx-paw'></i>
                            <span><?php echo htmlspecialchars($cita_original['mascota_nombre']); ?></span>
                        </div>
                        <div class="original-item">
                            <i class='bx bx-category'></i>
                            <span><?php echo htmlspecialchars($cita_original['servicio_nombre']); ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error-circle'></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="alert alert-warning">
                    <i class='bx bx-info-circle'></i>
                    Al reagendar, la cita será modificada con la nueva información. Se mantendrá un registro del cambio en las notas.
                </div>

                <form method="POST" action="" id="formReagendar"><?php echo av_csrf_field(); ?>
                    <!-- Mascota (opcional cambiar) -->
                    <div class="form-group">
                        <label><i class='bx bx-paw'></i> Mascota</label>
                        <select name="mascota_id" class="form-control">
                            <option value="<?php echo $cita_original['mascota_id']; ?>">
                                <?php echo htmlspecialchars($cita_original['mascota_nombre']); ?> (actual)
                            </option>
                            <?php foreach ($mascotas as $mascota): ?>
                                <?php if ($mascota['id'] != $cita_original['mascota_id']): ?>
                                    <option value="<?php echo $mascota['id']; ?>">
                                        <?php echo htmlspecialchars($mascota['nombre']); ?> 
                                        <?php echo $mascota['raza'] ? '(' . htmlspecialchars($mascota['raza']) . ')' : ''; ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Servicio (opcional cambiar) -->
                    <div class="form-group">
                        <label><i class='bx bx-category'></i> Servicio</label>
                        <select name="servicio_id" class="form-control">
                            <option value="<?php echo $cita_original['servicio_id']; ?>">
                                <?php echo htmlspecialchars($cita_original['servicio_nombre']); ?> (actual)
                            </option>
                            <?php foreach ($servicios as $servicio): ?>
                                <?php if ($servicio['id'] != $cita_original['servicio_id']): ?>
                                    <option value="<?php echo $servicio['id']; ?>">
                                        <?php echo htmlspecialchars($servicio['nombre']); ?> - $<?php echo number_format($servicio['precio'], 2); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Fecha y Hora -->
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class='bx bx-calendar'></i> Nueva fecha *</label>
                            <input type="date" name="fecha" class="form-control" required 
                                   min="<?php echo $fecha_min; ?>" 
                                   max="<?php echo $fecha_max; ?>"
                                   value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class='bx bx-time'></i> Nueva hora *</label>
                            <div class="horarios-grid">
                                <?php foreach ($horarios_disponibles as $horario): ?>
                                    <div class="horario-item">
                                        <input type="radio" name="hora" id="hora_<?php echo str_replace(':', '', $horario); ?>" 
                                               value="<?php echo $horario; ?>" required>
                                        <label for="hora_<?php echo str_replace(':', '', $horario); ?>">
                                            <?php echo $horario; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Motivo del reagendamiento -->
                    <div class="form-group">
                        <label><i class='bx bx-message-rounded'></i> Motivo del cambio (opcional)</label>
                        <textarea name="motivo" class="form-control" rows="2" placeholder="Ej: Problemas de horario, emergencia, etc."></textarea>
                    </div>

                    <!-- Notas adicionales -->
                    <div class="form-group">
                        <label><i class='bx bx-note'></i> Notas adicionales (opcional)</label>
                        <textarea name="notas" class="form-control" rows="2" placeholder="Información adicional para la nueva cita"></textarea>
                    </div>

                    <!-- Acciones -->
                    <div class="form-actions">
                        <a href="ver_cita.php?id=<?php echo $cita_id; ?>" class="btn-secondary">
                            <i class='bx bx-x'></i>
                            Cancelar
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class='bx bx-calendar-check'></i>
                            Confirmar reagendamiento
                        </button>
                    </div>
                </form>
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
        // Confirmación antes de enviar
        document.getElementById('formReagendar')?.addEventListener('submit', function(e) {
            if (!confirm('¿Estás seguro de reagendar esta cita? La cita será modificada con la nueva información.')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>