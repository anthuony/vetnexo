<?php
// cliente/nueva_cita.php
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

// Obtener mascotas del cliente
$stmt = $conn->prepare("SELECT id, nombre, especie_id, raza FROM mascotas WHERE cliente_id = ? ORDER BY nombre");
$stmt->execute([$cliente_id]);
$mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener servicios disponibles (activos)
$stmt = $conn->prepare("
    SELECT id, nombre, categoria, precio, duracion, descripcion 
    FROM servicios 
    WHERE activo = 1 
    ORDER BY 
        CASE 
            WHEN categoria = 'consultation' THEN 1
            WHEN categoria = 'vaccination' THEN 2
            WHEN categoria = 'grooming' THEN 3
            WHEN categoria = 'surgery' THEN 4
            ELSE 5
        END, 
        nombre ASC
");
$stmt->execute();
$servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar servicios por categoría
$servicios_por_categoria = [];
foreach ($servicios as $servicio) {
    $categoria = $servicio['categoria'];
    if (!isset($servicios_por_categoria[$categoria])) {
        $servicios_por_categoria[$categoria] = [];
    }
    $servicios_por_categoria[$categoria][] = $servicio;
}

// Obtener horarios disponibles (simulado - en producción sería más complejo)
$horarios_disponibles = [
    '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
    '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'
];

// Procesar el formulario si se envió
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $mascota_id = $_POST['mascota_id'] ?? '';
    $servicio_id = $_POST['servicio_id'] ?? '';
    $fecha = $_POST['fecha'] ?? '';
    $hora = $_POST['hora'] ?? '';
    $notas = trim($_POST['notas'] ?? '');
    
    // Validaciones básicas
    if (empty($mascota_id) || empty($servicio_id) || empty($fecha) || empty($hora)) {
        $error = "Por favor, completa todos los campos obligatorios.";
    } else {
        // Verificar que la mascota pertenezca al cliente
        $stmt = $conn->prepare("SELECT id FROM mascotas WHERE id = ? AND cliente_id = ?");
        $stmt->execute([$mascota_id, $cliente_id]);
        if (!$stmt->fetch()) {
            $error = "La mascota seleccionada no es válida.";
        } else {
            // Verificar que el servicio exista
            $stmt = $conn->prepare("SELECT id, nombre, precio FROM servicios WHERE id = ? AND activo = 1");
            $stmt->execute([$servicio_id]);
            $servicio = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$servicio) {
                $error = "El servicio seleccionado no es válido.";
            } else {
                // Verificar disponibilidad (simplificado - en producción verificar citas existentes)
                $fecha_hora = $fecha . ' ' . $hora . ':00';
                $stmt = $conn->prepare("
                    SELECT id FROM citas 
                    WHERE fecha_cita = ? 
                    AND estado NOT IN ('cancelled', 'completed')
                ");
                $stmt->execute([$fecha_hora]);
                if ($stmt->rowCount() > 0) {
                    $error = "Lo sentimos, ese horario ya no está disponible. Por favor elige otro.";
                } else {
                    // Crear la cita
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO citas 
                            (mascota_id, cliente_id, servicio_id, fecha_cita, hora_cita, notas, estado, costo_total) 
                            VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)
                        ");
                        $stmt->execute([
                            $mascota_id, 
                            $cliente_id, 
                            $servicio_id, 
                            $fecha_hora, 
                            $hora, 
                            $notas,
                            $servicio['precio']
                        ]);
                        
                        $cita_id = $conn->lastInsertId();
                        
                        // Redirigir a mis citas con mensaje de éxito
                        header("Location: mis_citas.php?success=nueva_cita");
                        exit();
                    } catch (PDOException $e) {
                        $error = "Error al agendar la cita: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Obtener fechas mínima y máxima (hoy + 30 días)
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

// Función para obtener nombre amigable de categoría
function getCategoriaNombre($categoria) {
    switch($categoria) {
        case 'consultation':
            return 'Consultas';
        case 'emergency':
            return 'Urgencias';
        case 'vaccination':
            return 'Vacunación';
        case 'grooming':
            return 'Peluquería';
        case 'surgery':
            return 'Cirugías';
        case 'laboratory':
            return 'Laboratorio';
        default:
            return 'Otros servicios';
    }
}

// Función para obtener icono de categoría
function getCategoriaIcono($categoria) {
    switch($categoria) {
        case 'consultation':
        case 'emergency':
            return 'bx-plus-medical';
        case 'vaccination':
            return 'bx-shield-plus';
        case 'grooming':
            return 'bx-cut';
        case 'surgery':
            return 'bx-plus-circle';
        case 'laboratory':
            return 'bx-test-tube';
        default:
            return 'bx-calendar';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Cita - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="../includes/img/logo.svg">
    
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

        /* Formulario */
        .form-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
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
        }

        .form-header p {
            opacity: 0.9;
            font-size: 0.9rem;
            margin-top: 0.3rem;
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
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
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

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Horarios disponibles */
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

        .horario-item input[type="radio"]:disabled + label {
            background: var(--gray-light);
            color: var(--text-lighter);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .horario-item label:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        /* Servicios */
        .servicios-categoria {
            margin-bottom: 2rem;
        }

        .categoria-titulo {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-soft);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .categoria-titulo i {
            color: var(--accent);
        }

        .servicios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .servicio-card {
            position: relative;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .servicio-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        .servicio-card.selected {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        .servicio-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .servicio-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .servicio-nombre {
            font-weight: 600;
            color: var(--text-dark);
        }

        .servicio-precio {
            font-weight: 700;
            color: var(--primary);
            background: var(--white);
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.9rem;
            border: 1px solid var(--primary-light);
        }

        .servicio-descripcion {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
        }

        .servicio-duracion {
            font-size: 0.75rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Botones */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray);
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

        .alert i {
            font-size: 1.3rem;
        }

        /* Info box */
        .info-box {
            background: var(--primary-soft);
            border: 1px solid var(--primary-light);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .info-box i {
            font-size: 2rem;
            color: var(--primary);
        }

        .info-box-content {
            flex: 1;
        }

        .info-box-content h4 {
            font-size: 1rem;
            color: var(--primary-dark);
            margin-bottom: 0.2rem;
        }

        .info-box-content p {
            font-size: 0.9rem;
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
            
            .servicios-grid {
                grid-template-columns: 1fr;
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
        <a href="mis_citas.php">Mis Citas</a>
        <i class='bx bx-chevron-right'></i>
        <span>Nueva Cita</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-calendar-plus'></i>
                Agendar Nueva Cita
            </h1>
            <a href="mis_citas.php" class="btn-back">
                <i class='bx bx-arrow-back'></i>
                Volver a mis citas
            </a>
        </div>

        <!-- Formulario -->
        <div class="form-container">
            <div class="form-header">
                <h2>
                    <i class='bx bx-calendar'></i>
                    Completa los datos para tu cita
                </h2>
                <p>Selecciona la mascota, el servicio, la fecha y hora que prefieras</p>
            </div>
            <div class="form-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error-circle'></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Info box -->
                <div class="info-box">
                    <i class='bx bx-info-circle'></i>
                    <div class="info-box-content">
                        <h4>Información importante</h4>
                        <p>Las citas se agendan de lunes a sábado en horario de 8:00 AM a 5:00 PM. Puedes agendar con hasta 30 días de anticipación.</p>
                    </div>
                </div>

                <form method="POST" action="" id="formCita"><?php echo av_csrf_field(); ?>
                    <!-- Mascota -->
                    <div class="form-group">
                        <label><i class='bx bx-paw'></i> Selecciona tu mascota *</label>
                        <?php if (empty($mascotas)): ?>
                            <div class="alert alert-error" style="margin-bottom: 0;">
                                <i class='bx bx-error-circle'></i>
                                No tienes mascotas registradas. 
                                <a href="mis_mascotas.php?action=registrar" style="color: var(--primary); font-weight: 600;">Registra una aquí</a>
                            </div>
                        <?php else: ?>
                            <select name="mascota_id" class="form-control" required>
                                <option value="">Selecciona una mascota</option>
                                <?php foreach ($mascotas as $mascota): ?>
                                    <option value="<?php echo $mascota['id']; ?>" <?php echo (isset($_POST['mascota_id']) && $_POST['mascota_id'] == $mascota['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mascota['nombre']); ?> 
                                        (<?php echo htmlspecialchars($mascota['raza'] ?? 'Mascota'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <!-- Servicios por categoría -->
                    <div class="form-group full-width">
                        <label><i class='bx bx-category'></i> Selecciona el servicio *</label>
                        
                        <?php foreach ($servicios_por_categoria as $categoria => $servicios_cat): ?>
                            <div class="servicios-categoria">
                                <div class="categoria-titulo">
                                    <i class='bx <?php echo getCategoriaIcono($categoria); ?>'></i>
                                    <?php echo getCategoriaNombre($categoria); ?>
                                </div>
                                <div class="servicios-grid">
                                    <?php foreach ($servicios_cat as $servicio): ?>
                                        <div class="servicio-card <?php echo (isset($_POST['servicio_id']) && $_POST['servicio_id'] == $servicio['id']) ? 'selected' : ''; ?>" onclick="selectServicio(<?php echo $servicio['id']; ?>)">
                                            <input type="radio" name="servicio_id" id="servicio_<?php echo $servicio['id']; ?>" value="<?php echo $servicio['id']; ?>" <?php echo (isset($_POST['servicio_id']) && $_POST['servicio_id'] == $servicio['id']) ? 'checked' : ''; ?> required>
                                            <div class="servicio-header">
                                                <span class="servicio-nombre"><?php echo htmlspecialchars($servicio['nombre']); ?></span>
                                                <span class="servicio-precio">$<?php echo number_format($servicio['precio'], 2); ?></span>
                                            </div>
                                            <?php if (!empty($servicio['descripcion'])): ?>
                                                <div class="servicio-descripcion">
                                                    <?php echo htmlspecialchars(substr($servicio['descripcion'], 0, 80)) . (strlen($servicio['descripcion']) > 80 ? '...' : ''); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($servicio['duracion'])): ?>
                                                <div class="servicio-duracion">
                                                    <i class='bx bx-time'></i>
                                                    Duración aprox: <?php echo htmlspecialchars($servicio['duracion']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Fecha y Hora -->
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class='bx bx-calendar'></i> Fecha *</label>
                            <input type="date" name="fecha" class="form-control" required 
                                   min="<?php echo $fecha_min; ?>" 
                                   max="<?php echo $fecha_max; ?>"
                                   value="<?php echo htmlspecialchars($_POST['fecha'] ?? $fecha_min); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class='bx bx-time'></i> Hora *</label>
                            <div class="horarios-grid">
                                <?php foreach ($horarios_disponibles as $horario): ?>
                                    <div class="horario-item">
                                        <input type="radio" name="hora" id="hora_<?php echo str_replace(':', '', $horario); ?>" 
                                               value="<?php echo $horario; ?>" 
                                               <?php echo (isset($_POST['hora']) && $_POST['hora'] == $horario) ? 'checked' : ''; ?>
                                               required>
                                        <label for="hora_<?php echo str_replace(':', '', $horario); ?>">
                                            <?php echo $horario; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Notas -->
                    <div class="form-group full-width">
                        <label><i class='bx bx-note'></i> Notas adicionales (opcional)</label>
                        <textarea name="notas" class="form-control" placeholder="Ej: Especificaciones sobre el servicio, comportamiento de la mascota, etc."><?php echo htmlspecialchars($_POST['notas'] ?? ''); ?></textarea>
                    </div>

                    <!-- Acciones -->
                    <div class="form-actions">
                        <a href="mis_citas.php" class="btn-secondary">
                            <i class='bx bx-x'></i>
                            Cancelar
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class='bx bx-calendar-check'></i>
                            Agendar Cita
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
        // Función para seleccionar servicio
        function selectServicio(servicioId) {
            // Quitar selected de todos los servicios
            document.querySelectorAll('.servicio-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Seleccionar el servicio clickeado
            const card = document.querySelector(`.servicio-card input[value="${servicioId}"]`).closest('.servicio-card');
            card.classList.add('selected');
            
            // Marcar el radio button
            document.getElementById(`servicio_${servicioId}`).checked = true;
        }

        // Validar que tenga mascotas antes de enviar
        document.getElementById('formCita')?.addEventListener('submit', function(e) {
            <?php if (empty($mascotas)): ?>
                e.preventDefault();
                alert('Debes registrar al menos una mascota antes de agendar una cita.');
            <?php endif; ?>
        });

        // Marcar servicios preseleccionados al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const servicioSeleccionado = document.querySelector('input[name="servicio_id"]:checked');
            if (servicioSeleccionado) {
                selectServicio(servicioSeleccionado.value);
            }
        });
    </script>
</body>
</html>