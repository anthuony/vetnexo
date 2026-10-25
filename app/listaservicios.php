<?php
// listaservicios.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Conexión centralizada para hosting
try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    die('Error de conexión a la base de datos. Revise config/env.php.');
}

// Procesar eliminación de servicio
if (isset($_POST['eliminar_servicio'])) {
    $servicio_id = $_POST['servicio_id'];
    
    try {
        $pdo->prepare('UPDATE servicios SET activo=0 WHERE id=?')->execute([$servicio_id]);
        $_SESSION['success']='Servicio desactivado correctamente.';
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al eliminar el servicio: " . $e->getMessage();
    }
    
    header('Location: listaservicios.php');
    exit();
}

// Procesar edición de servicio
if (isset($_POST['editar_servicio'])) {
    $servicio_id = $_POST['servicio_id'];
    $nombre = $_POST['nombre'];
    $categoria = $_POST['categoria'];
    $precio = $_POST['precio'];
    $duracion = $_POST['duracion'];
    $descripcion = $_POST['descripcion'];
    $especie = $_POST['especie'];
    $activo = isset($_POST['activo']) ? 1 : 0;
    $destacado = isset($_POST['destacado']) ? 1 : 0;
    
    try {
        $sql_editar = "UPDATE servicios SET 
                        nombre = ?, 
                        categoria = ?, 
                        precio = ?, 
                        duracion = ?, 
                        descripcion = ?, 
                        especie = ?, 
                        activo = ?, 
                        destacado = ?,
                        updated_at = CURRENT_TIMESTAMP 
                      WHERE id = ?";
        
        $stmt_editar = $pdo->prepare($sql_editar);
        $stmt_editar->execute([$nombre, $categoria, $precio, $duracion, $descripcion, $especie, $activo, $destacado, $servicio_id]);
        
        $_SESSION['success'] = "Servicio actualizado correctamente.";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al actualizar el servicio: " . $e->getMessage();
    }
    
    header('Location: listaservicios.php');
    exit();
}

// Procesar nuevo servicio
if (isset($_POST['nuevo_servicio'])) {
    $nombre = $_POST['nombre'];
    $categoria = $_POST['categoria'];
    $precio = $_POST['precio'];
    $duracion = $_POST['duracion'];
    $descripcion = $_POST['descripcion'];
    $especie = $_POST['especie'];
    $activo = isset($_POST['activo']) ? 1 : 0;
    $destacado = isset($_POST['destacado']) ? 1 : 0;
    
    try {
        $sql_nuevo = "INSERT INTO servicios (nombre, categoria, precio, duracion, descripcion, especie, activo, destacado) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_nuevo = $pdo->prepare($sql_nuevo);
        $stmt_nuevo->execute([$nombre, $categoria, $precio, $duracion, $descripcion, $especie, $activo, $destacado]);
        
        $_SESSION['success'] = "Servicio creado correctamente.";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al crear el servicio: " . $e->getMessage();
    }
    
    header('Location: listaservicios.php');
    exit();
}

// Obtener datos de un servicio para editar (sin AJAX)
$servicio_editar = null;
if (isset($_GET['editar'])) {
    $servicio_id = $_GET['editar'];
    try {
        $sql_editar = "SELECT * FROM servicios WHERE id = ?";
        $stmt_editar = $pdo->prepare($sql_editar);
        $stmt_editar->execute([$servicio_id]);
        $servicio_editar = $stmt_editar->fetch();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al cargar el servicio: " . $e->getMessage();
    }
}

// Obtener lista de servicios
try {
    $sql_servicios = "SELECT * FROM servicios ORDER BY categoria, nombre";
    $stmt_servicios = $pdo->query($sql_servicios);
    $servicios = $stmt_servicios->fetchAll();
    
    // Contar servicios por categoría
    $sql_stats = "SELECT 
                    categoria,
                    COUNT(*) as total,
                    SUM(activo = 1) as activos,
                    AVG(precio) as precio_promedio
                  FROM servicios 
                  GROUP BY categoria";
    $stmt_stats = $pdo->query($sql_stats);
    $estadisticas = $stmt_stats->fetchAll();
    
} catch (PDOException $e) {
    error_log("[VetNexo][listaservicios] " . $e->getMessage()); die("No se pudieron cargar los servicios.");
}

// Obtener datos del usuario de la sesión
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_email = $_SESSION['usuario_email'] ?? 'usuario@ejemplo.com';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'usuario';
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Servicios - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #2E7D32;
            --primary-light: #81C784;
            --primary-very-light: #E8F5E9;
            --accent: #FF9800;
            --accent-dark: #F57C00;
            --secondary: #2196F3;
            --secondary-dark: #1976D2;
            --danger: #F44336;
            --warning: #FFC107;
            --success: #4CAF50;
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --text-lighter: #888888;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --border: #E0E0E0;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 40px rgba(0, 0, 0, 0.12);
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
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text-dark);
            overflow-x: hidden;
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

        /* Header Mejorado */
        .dashboard-header {
            background: var(--white);
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
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
            color: var(--primary);
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
            background: var(--primary-very-light);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.2rem;
        }

        .user-info h3 {
            font-size: 0.95rem;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
        }

        .user-info span {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Botón de Atrás */
        .back-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            background: var(--primary-very-light);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
        }

        /* Estadísticas Mejoradas */
        .stats-section {
            margin-bottom: 2rem;
        }

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
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-very-light);
            color: var(--primary);
        }

        .trend-indicator {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);
        }

        .trend-indicator.negative {
            background: rgba(244, 67, 54, 0.1);
            color: var(--danger);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-subtext {
            color: var(--text-lighter);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Secciones de Contenido */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            height: fit-content;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--white);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #D32F2F);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #D32F2F, #C62828);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-weight: 500;
            border: 1px solid;
        }

        .alert-success {
            background: var(--primary-very-light);
            border-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .alert-error {
            background: #FFEBEE;
            border-color: #FFCDD2;
            color: #C62828;
        }

        /* Table Styles Mejorados */
        .table-responsive {
            overflow-x: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background-color: var(--primary-very-light);
            font-weight: 600;
            color: var(--primary-dark);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr:hover {
            background-color: var(--primary-very-light);
        }

        /* Badges Mejorados */
        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-active {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #E0E0E0, #BDBDBD);
            color: #757575;
        }

        .badge-featured {
            background: linear-gradient(135deg, var(--warning), #FFB300);
            color: #5D4037;
        }

        .badge-category {
            background: linear-gradient(135deg, #E3F2FD, #90CAF9);
            color: #1565C0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--radius);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-lg);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .close {
            position: absolute;
            right: 1rem;
            top: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--text-dark);
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .checkbox-group input {
            width: auto;
        }

        /* Estados Vacíos */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            text-align: center;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Footer Minimalista */
        .main-footer {
            background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e9 100%);
            color: var(--text-dark);
            padding: 1.5rem 0;
            margin-top: auto;
            border-top: 1px solid rgba(76, 175, 80, 0.2);
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .footer-content {
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .footer-simple {
            font-size: 0.9rem;
            color: var(--text-light);
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 25px;
            border: 1px solid rgba(76, 175, 80, 0.2);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-sm);
        }

        .footer-text {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .footer-text strong {
            color: var(--primary-dark);
            font-weight: 600;
        }

        /* Animación de partículas */
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
            background: rgba(76, 175, 80, 0.05);
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
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
                padding: 1.5rem;
            }
            
            .user-profile {
                align-self: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .section-title {
                font-size: 1.1rem;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            th, td {
                padding: 0.75rem 0.5rem;
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
                    <h1><i class='bx bx-list-check'></i> Gestión de Servicios</h1>
                    <p>Administra los servicios veterinarios de VetNexo</p>
                </div>
                
                <div class="header-actions">
                    <!-- Botón de Atrás -->
                    <a href="dashboard.php" class="back-button">
                        <i class='bx bx-arrow-back'></i>
                        Volver al Dashboard
                    </a>
                    
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <h3><?php echo htmlspecialchars($usuario_nombre); ?></h3>
                            <span><i class='bx bx-user-circle'></i> <?php echo htmlspecialchars($usuario_rol); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Alertas -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-list-ul'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo count($servicios); ?></div>
                        <div class="stat-label">Total de Servicios</div>
                        <div class="stat-subtext">
                            <i class='bx bx-check-circle'></i> 
                            <?php echo array_sum(array_column($servicios, 'activo')); ?> activos
                        </div>
                    </div>
                    
                    <?php foreach ($estadisticas as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--secondary);">
                                <i class='bx bx-category'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $stat['total']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['categoria']); ?></div>
                        <div class="stat-subtext">
                            <i class='bx bx-dollar-circle'></i> 
                            $<?php echo number_format($stat['precio_promedio'], 2); ?> promedio
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tabla de Servicios -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class='bx bx-list-ul'></i>
                        Lista de Servicios
                    </h3>
                    <div class="header-actions">
                        <div class="search-box">
                            <input type="text" id="searchInput" placeholder="Buscar servicios..." 
                                   style="padding: 0.6rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border); width: 250px;">
                        </div>
                        <button class="btn btn-primary" onclick="openModal('modalNuevoServicio')">
                            <i class='bx bx-plus'></i>
                            Nuevo Servicio
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($servicios)): ?>
                    <div class="table-responsive">
                        <table id="serviciosTable">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Categoría</th>
                                    <th>Precio</th>
                                    <th>Duración</th>
                                    <th>Especie</th>
                                    <th>Estado</th>
                                    <th>Destacado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($servicios as $servicio): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($servicio['nombre']); ?></strong>
                                            <?php if ($servicio['descripcion']): ?>
                                                <br><small style="color: var(--text-light);"><?php echo htmlspecialchars(substr($servicio['descripcion'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-category">
                                                <?php echo htmlspecialchars($servicio['categoria']); ?>
                                            </span>
                                        </td>
                                        <td><strong>$<?php echo number_format($servicio['precio'], 2); ?></strong></td>
                                        <td><?php echo htmlspecialchars($servicio['duracion'] ?? 'No especificada'); ?></td>
                                        <td><?php echo htmlspecialchars($servicio['especie']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $servicio['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo $servicio['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($servicio['destacado']): ?>
                                                <span class="badge badge-featured">
                                                    <i class='bx bx-star'></i> Destacado
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-light);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <a href="listaservicios.php?editar=<?php echo $servicio['id']; ?>" 
                                                   class="btn btn-outline btn-sm">
                                                    <i class='bx bx-edit'></i> Editar
                                                </a>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('¿Estás seguro de eliminar este servicio?');"><?php echo av_csrf_field(); ?>
                                                    <input type="hidden" name="servicio_id" value="<?php echo $servicio['id']; ?>">
                                                    <button type="submit" name="eliminar_servicio" class="btn btn-danger btn-sm">
                                                        <i class='bx bx-trash'></i> Eliminar
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-list-ul'></i>
                        <h4>No hay servicios registrados</h4>
                        <p>Comienza creando tu primer servicio haciendo clic en "Nuevo Servicio"</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Minimalista -->
            <footer class="main-footer">
                <div class="footer-content">
                    <div class="footer-simple">
                        <span class="footer-text">© 2026 VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></span>
                    </div>
                </div>
            </footer>
        </main>
    </div>

    <!-- Modal Nuevo Servicio -->
    <div id="modalNuevoServicio" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('modalNuevoServicio')">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: var(--primary-dark);">Nuevo Servicio</h2>
            
            <form method="POST"><?php echo av_csrf_field(); ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre">Nombre del Servicio *</label>
                        <input type="text" id="nombre" name="nombre" required>
                    </div>
                    <div class="form-group">
                        <label for="categoria">Categoría *</label>
                        <select id="categoria" name="categoria" required>
                            <option value="consultation">Consulta</option>
                            <option value="vaccination">Vacunación</option>
                            <option value="surgery">Cirugía</option>
                            <option value="emergency">Urgencia</option>
                            <option value="grooming">Peluquería</option>
                            <option value="laboratory">Laboratorio</option>
                            <option value="other">Otro</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="precio">Precio ($) *</label>
                        <input type="number" id="precio" name="precio" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="duracion">Duración</label>
                        <input type="text" id="duracion" name="duracion" placeholder="Ej: 30 minutos">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" placeholder="Descripción del servicio..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="especie">Especie</label>
                        <select id="especie" name="especie">
                            <option value="all">Todas</option>
                            <option value="dog">Perro</option>
                            <option value="cat">Gato</option>
                            <option value="other">Otra</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="activo" name="activo" value="1" checked>
                            <label for="activo">Servicio Activo</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="destacado" name="destacado" value="1">
                            <label for="destacado">Servicio Destacado</label>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevoServicio')">Cancelar</button>
                    <button type="submit" name="nuevo_servicio" class="btn btn-primary">Crear Servicio</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Editar Servicio -->
    <?php if ($servicio_editar): ?>
    <div id="modalEditarServicio" class="modal" style="display: block;">
        <div class="modal-content">
            <span class="close" onclick="closeModalAndRedirect()">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: var(--primary-dark);">Editar Servicio</h2>
            
            <form method="POST"><?php echo av_csrf_field(); ?>
                <input type="hidden" name="servicio_id" value="<?php echo $servicio_editar['id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editar_nombre">Nombre del Servicio *</label>
                        <input type="text" id="editar_nombre" name="nombre" value="<?php echo htmlspecialchars($servicio_editar['nombre']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="editar_categoria">Categoría *</label>
                        <select id="editar_categoria" name="categoria" required>
                            <option value="consultation" <?php echo $servicio_editar['categoria'] == 'consultation' ? 'selected' : ''; ?>>Consulta</option>
                            <option value="vaccination" <?php echo $servicio_editar['categoria'] == 'vaccination' ? 'selected' : ''; ?>>Vacunación</option>
                            <option value="surgery" <?php echo $servicio_editar['categoria'] == 'surgery' ? 'selected' : ''; ?>>Cirugía</option>
                            <option value="emergency" <?php echo $servicio_editar['categoria'] == 'emergency' ? 'selected' : ''; ?>>Urgencia</option>
                            <option value="grooming" <?php echo $servicio_editar['categoria'] == 'grooming' ? 'selected' : ''; ?>>Peluquería</option>
                            <option value="laboratory" <?php echo $servicio_editar['categoria'] == 'laboratory' ? 'selected' : ''; ?>>Laboratorio</option>
                            <option value="other" <?php echo $servicio_editar['categoria'] == 'other' ? 'selected' : ''; ?>>Otro</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editar_precio">Precio ($) *</label>
                        <input type="number" id="editar_precio" name="precio" step="0.01" min="0" value="<?php echo $servicio_editar['precio']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="editar_duracion">Duración</label>
                        <input type="text" id="editar_duracion" name="duracion" value="<?php echo htmlspecialchars($servicio_editar['duracion'] ?? ''); ?>" placeholder="Ej: 30 minutos">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="editar_descripcion">Descripción</label>
                    <textarea id="editar_descripcion" name="descripcion" placeholder="Descripción del servicio..."><?php echo htmlspecialchars($servicio_editar['descripcion'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editar_especie">Especie</label>
                        <select id="editar_especie" name="especie">
                            <option value="all" <?php echo $servicio_editar['especie'] == 'all' ? 'selected' : ''; ?>>Todas</option>
                            <option value="dog" <?php echo $servicio_editar['especie'] == 'dog' ? 'selected' : ''; ?>>Perro</option>
                            <option value="cat" <?php echo $servicio_editar['especie'] == 'cat' ? 'selected' : ''; ?>>Gato</option>
                            <option value="other" <?php echo $servicio_editar['especie'] == 'other' ? 'selected' : ''; ?>>Otra</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="editar_activo" name="activo" value="1" <?php echo $servicio_editar['activo'] ? 'checked' : ''; ?>>
                            <label for="editar_activo">Servicio Activo</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="editar_destacado" name="destacado" value="1" <?php echo $servicio_editar['destacado'] ? 'checked' : ''; ?>>
                            <label for="editar_destacado">Servicio Destacado</label>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" class="btn btn-outline" onclick="closeModalAndRedirect()">Cancelar</button>
                    <button type="submit" name="editar_servicio" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="js/sidebar.js"></script>
    <script>
        // Funciones para modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function closeModalAndRedirect() {
            window.location.href = 'listaservicios.php';
        }

        // Búsqueda en tiempo real
        document.getElementById('searchInput').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#serviciosTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target == modal) {
                    if (modal.id === 'modalEditarServicio') {
                        closeModalAndRedirect();
                    } else {
                        modal.style.display = 'none';
                    }
                }
            }
        }

        // Auto-abrir modal de nuevo servicio si hay error en la creación
        <?php if (isset($_SESSION['error']) && strpos($_SESSION['error'], 'crear') !== false): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openModal('modalNuevoServicio');
            });
        <?php endif; ?>

        // Animación de contadores para estadísticas
        document.addEventListener('DOMContentLoaded', function() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseInt(text.replace(/[^0-9]/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = text.replace(number, Math.floor(current).toLocaleString());
                    }, 30);
                }
            });

            // Crear partículas dinámicas
            const particlesContainer = document.querySelector('.particles');
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
                    background: ${Math.random() > 0.5 ? 'rgba(76, 175, 80, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }

            // Animación de entrada para las secciones
            const sections = document.querySelectorAll('.content-section');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100 + 300);
            });
        });
    </script>
</body>
</html>