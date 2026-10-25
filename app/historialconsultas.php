<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Incluir configuración de base de datos
include 'config/database.php';

$mensaje = '';
$tipo_mensaje = '';
$mascota = null;
$consultas = [];
$cliente = null;

// Obtener ID de la mascota desde la URL
$mascota_id = $_GET['id'] ?? null;

if (!$mascota_id) {
    $mensaje = "No se ha especificado una mascota";
    $tipo_mensaje = "error";
} else {
    try {
        $db = getDBConnection();
        
        // Obtener información de la mascota
        $mascota_sql = "SELECT m.*, e.nombre as especie_nombre, c.nombre as cliente_nombre, 
                               c.telefono, c.email, c.direccion, c.documento_identidad
                        FROM mascotas m
                        LEFT JOIN especies e ON m.especie_id = e.id
                        LEFT JOIN clientes c ON m.cliente_id = c.id
                        WHERE m.id = ?";
        $mascota_stmt = $db->prepare($mascota_sql);
        $mascota_stmt->execute([$mascota_id]);
        $mascota = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mascota) {
            $mensaje = "Mascota no encontrada";
            $tipo_mensaje = "error";
        } else {
            // Obtener el cliente
            $cliente_sql = "SELECT * FROM clientes WHERE id = ?";
            $cliente_stmt = $db->prepare($cliente_sql);
            $cliente_stmt->execute([$mascota['cliente_id']]);
            $cliente = $cliente_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Obtener todas las consultas de la mascota
            $consultas_sql = "SELECT c.*, u.nombre as veterinario_nombre
                             FROM consultas c
                             LEFT JOIN usuarios u ON c.veterinario_id = u.id
                             WHERE c.mascota_id = ?
                             ORDER BY c.fecha_consulta DESC, c.created_at DESC";
            $consultas_stmt = $db->prepare($consultas_sql);
            $consultas_stmt->execute([$mascota_id]);
            $consultas = $consultas_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener estadísticas
            $total_consultas = count($consultas);
            
            // Obtener vacunas de la mascota
            $vacunas_sql = "SELECT * FROM vacunas WHERE mascota_id = ? ORDER BY fecha_aplicacion DESC";
            $vacunas_stmt = $db->prepare($vacunas_sql);
            $vacunas_stmt->execute([$mascota_id]);
            $vacunas = $vacunas_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener citas de la mascota
            $citas_sql = "SELECT ct.*, s.nombre as servicio_nombre
                         FROM citas ct
                         LEFT JOIN servicios s ON ct.servicio_id = s.id
                         WHERE ct.mascota_id = ?
                         ORDER BY ct.fecha_cita DESC";
            $citas_stmt = $db->prepare($citas_sql);
            $citas_stmt->execute([$mascota_id]);
            $citas = $citas_stmt->fetchAll(PDO::FETCH_ASSOC);
            
        }
    } catch (PDOException $e) {
        $mensaje = "Error al cargar datos: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Consultas - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link href="css/sidebar.css" rel="stylesheet">
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

        /* Botones */
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
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #2E7D32);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--text-light), var(--text-lighter));
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, var(--text-lighter), var(--text-light));
            transform: translateY(-2px);
        }

        .btn-back {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--accent-dark), #E65100);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, var(--secondary-dark), #0d6efd);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #FFA000);
            color: var(--white);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #FFA000, #FF8F00);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #D32F2F);
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #D32F2F, #B71C1C);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Contenedor de Contenido */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
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

        /* Información de la Mascota */
        .mascota-info-card {
            background: linear-gradient(135deg, var(--primary-very-light), #c8e6c9);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 2px solid var(--primary);
            box-shadow: var(--shadow);
        }

        .mascota-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .mascota-details h3 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 1.3rem;
        }

        .mascota-details p {
            margin: 0.25rem 0;
            color: var(--text-dark);
        }

        .mascota-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(76, 175, 80, 0.3);
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.7);
            border-radius: var(--radius-sm);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        /* Información del Cliente */
        .cliente-info-card {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 2px solid var(--secondary);
            box-shadow: var(--shadow);
        }

        .cliente-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .cliente-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.5rem;
        }

        .cliente-details h4 {
            margin: 0 0 0.25rem 0;
            color: var(--secondary-dark);
            font-weight: 600;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        /* Lista de Consultas */
        .consultas-container {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .consulta-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .consulta-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .consulta-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .consulta-fecha {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 1.1rem;
        }

        .consulta-veterinario {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .consulta-body {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .consulta-section {
            background: var(--primary-very-light);
            padding: 1rem;
            border-radius: var(--radius-sm);
            border-left: 3px solid var(--primary);
        }

        .consulta-section-title {
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .consulta-section-content {
            color: var(--text-dark);
            line-height: 1.5;
            white-space: pre-line;
        }

        .consulta-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .consulta-valor {
            font-weight: 600;
            color: var(--primary-dark);
        }

        /* Estado Vacío */
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

        .empty-state h4 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 2rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: var(--primary);
            color: #2e7d32;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--danger);
            color: #c62828;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-color: var(--accent);
            color: #e65100;
        }

        /* Tabs para navegación */
        .tabs-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid var(--primary-very-light);
            overflow-x: auto;
        }

        .tab-button {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }

        .tab-button:hover {
            color: var(--primary);
        }

        .tab-button.active {
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary);
        }

        .tab-content {
            padding: 1.5rem;
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Footer */
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

        /* Responsive */
        @media (max-width: 1200px) {
            .consulta-body {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .mascota-header {
                flex-direction: column;
                gap: 1rem;
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
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .consulta-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .consulta-meta {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }
            
            .tabs-header {
                flex-wrap: wrap;
            }
            
            .tab-button {
                flex: 1;
                min-width: 120px;
                text-align: center;
            }
            
            .btn {
                justify-content: center;
                width: 100%;
            }
            
            .footer-content {
                padding: 0 1rem;
            }
            
            .footer-simple {
                font-size: 0.8rem;
                padding: 0.6rem 1rem;
            }
            
            .footer-text {
                flex-direction: column;
                gap: 0.25rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-clipboard'></i>
                        Historial de Consultas
                    </h1>
                    <p>
                        <?php if ($mascota): ?>
                            Consultas médicas de <?php echo htmlspecialchars($mascota['nombre']); ?>
                        <?php else: ?>
                            Visualice el historial médico completo de la mascota
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="listademascotas.php" class="btn btn-back">
                        <i class='bx bx-arrow-back'></i>
                        Volver a Mascotas
                    </a>
                    <?php if ($mascota): ?>
                        <a href="nueva_consulta.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-primary">
                            <i class='bx bx-plus'></i>
                            Nueva Consulta
                        </a>
                        <a href="agendarcita.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-success">
                            <i class='bx bx-calendar-plus'></i>
                            Nueva Cita
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="content-section">
                    <div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($mensaje); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($mascota): ?>
                <!-- Información de la Mascota -->
                <div class="content-section">
                    <div class="mascota-info-card">
                        <div class="mascota-header">
                            <div class="mascota-details">
                                <h3>
                                    <i class='bx bx-paw'></i>
                                    <?php echo htmlspecialchars($mascota['nombre']); ?>
                                </h3>
                                <p>
                                    <strong>Especie:</strong> <?php echo htmlspecialchars($mascota['especie_nombre']); ?>
                                    <?php if (!empty($mascota['raza'])): ?>
                                        | <strong>Raza:</strong> <?php echo htmlspecialchars($mascota['raza']); ?>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($mascota['color'])): ?>
                                    <p><strong>Color:</strong> <?php echo htmlspecialchars($mascota['color']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($mascota['peso'])): ?>
                                    <p><strong>Peso:</strong> <?php echo htmlspecialchars($mascota['peso']); ?> kg</p>
                                <?php endif; ?>
                                <?php if (!empty($mascota['fecha_nacimiento'])): ?>
                                    <p><strong>Fecha Nacimiento:</strong> <?php echo date('d/m/Y', strtotime($mascota['fecha_nacimiento'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="btn btn-info btn-sm">
                                    <i class='bx bx-id-card'></i>
                                    ID: <?php echo htmlspecialchars($mascota_id); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mascota-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo count($consultas); ?></div>
                                <div class="stat-label">Consultas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo count($vacunas); ?></div>
                                <div class="stat-label">Vacunas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo count($citas); ?></div>
                                <div class="stat-label">Citas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">
                                    <?php 
                                    $edad = '';
                                    if (!empty($mascota['fecha_nacimiento'])) {
                                        $nacimiento = new DateTime($mascota['fecha_nacimiento']);
                                        $hoy = new DateTime();
                                        $diferencia = $hoy->diff($nacimiento);
                                        if ($diferencia->y > 0) {
                                            $edad = $diferencia->y . ' año' . ($diferencia->y > 1 ? 's' : '');
                                            if ($diferencia->m > 0) {
                                                $edad .= ', ' . $diferencia->m . ' mes' . ($diferencia->m > 1 ? 'es' : '');
                                            }
                                        } elseif ($diferencia->m > 0) {
                                            $edad = $diferencia->m . ' mes' . ($diferencia->m > 1 ? 'es' : '');
                                            if ($diferencia->d > 0) {
                                                $edad .= ', ' . $diferencia->d . ' día' . ($diferencia->d > 1 ? 's' : '');
                                            }
                                        } else {
                                            $edad = $diferencia->d . ' día' . ($diferencia->d > 1 ? 's' : '');
                                        }
                                    }
                                    echo $edad ?: 'Desconocida';
                                    ?>
                                </div>
                                <div class="stat-label">Edad</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Información del Cliente -->
                <div class="content-section">
                    <div class="cliente-info-card">
                        <div class="cliente-header">
                            <div class="cliente-avatar">
                                <?php echo strtoupper(substr($cliente['nombre'], 0, 1)); ?>
                            </div>
                            <div>
                                <h4><i class='bx bx-user'></i> Propietario</h4>
                                <p><?php echo htmlspecialchars($cliente['nombre']); ?></p>
                            </div>
                        </div>
                        <div class="contact-info">
                            <?php if (!empty($cliente['telefono'])): ?>
                                <div class="contact-item">
                                    <i class='bx bx-phone'></i>
                                    <span><?php echo htmlspecialchars($cliente['telefono']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cliente['email'])): ?>
                                <div class="contact-item">
                                    <i class='bx bx-envelope'></i>
                                    <span><?php echo htmlspecialchars($cliente['email']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cliente['documento_identidad'])): ?>
                                <div class="contact-item">
                                    <i class='bx bx-id-card'></i>
                                    <span><?php echo htmlspecialchars($cliente['documento_identidad']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cliente['direccion'])): ?>
                                <div class="contact-item">
                                    <i class='bx bx-map'></i>
                                    <span><?php echo htmlspecialchars($cliente['direccion']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tabs de Navegación -->
                <div class="tabs-container">
                    <div class="tabs-header">
                        <button class="tab-button active" data-tab="consultas">
                            <i class='bx bx-clipboard'></i>
                            Consultas (<?php echo count($consultas); ?>)
                        </button>
                        <button class="tab-button" data-tab="vacunas">
                            <i class='bx bx-syringe'></i>
                            Vacunas (<?php echo count($vacunas); ?>)
                        </button>
                        <button class="tab-button" data-tab="citas">
                            <i class='bx bx-calendar'></i>
                            Citas (<?php echo count($citas); ?>)
                        </button>
                        <button class="tab-button" data-tab="info">
                            <i class='bx bx-info-circle'></i>
                            Información General
                        </button>
                    </div>
                    
                    <!-- Tab: Consultas -->
                    <div id="tab-consultas" class="tab-content active">
                        <?php if (empty($consultas)): ?>
                            <div class="empty-state">
                                <i class='bx bx-clipboard'></i>
                                <h4>No hay consultas registradas</h4>
                                <p>Esta mascota no tiene consultas médicas registradas en el sistema.</p>
                                <a href="nueva_consulta.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class='bx bx-plus'></i>
                                    Registrar Primera Consulta
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="consultas-container">
                                <?php foreach ($consultas as $consulta): ?>
                                    <div class="consulta-card">
                                        <div class="consulta-header">
                                            <div class="consulta-fecha">
                                                <i class='bx bx-calendar'></i>
                                                <?php echo date('d/m/Y', strtotime($consulta['fecha_consulta'])); ?>
                                            </div>
                                            <?php if (!empty($consulta['veterinario_nombre'])): ?>
                                                <div class="consulta-veterinario">
                                                    <i class='bx bx-user-circle'></i>
                                                    Dr. <?php echo htmlspecialchars($consulta['veterinario_nombre']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="consulta-body">
                                            <!-- Motivo y Signos -->
                                            <div class="consulta-section">
                                                <div class="consulta-section-title">
                                                    <i class='bx bx-clipboard'></i>
                                                    Motivo y Signos Clínicos
                                                </div>
                                                <div class="consulta-section-content">
                                                    <p><strong>Motivo:</strong> <?php echo nl2br(htmlspecialchars($consulta['motivo'])); ?></p>
                                                    <?php if (!empty($consulta['signos_clinicos'])): ?>
                                                        <p><strong>Signos Clínicos:</strong> <?php echo nl2br(htmlspecialchars($consulta['signos_clinicos'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Diagnóstico y Tratamiento -->
                                            <div class="consulta-section">
                                                <div class="consulta-section-title">
                                                    <i class='bx bx-first-aid'></i>
                                                    Diagnóstico y Tratamiento
                                                </div>
                                                <div class="consulta-section-content">
                                                    <p><strong>Diagnóstico:</strong> <?php echo nl2br(htmlspecialchars($consulta['diagnostico'])); ?></p>
                                                    <?php if (!empty($consulta['tratamiento'])): ?>
                                                        <p><strong>Tratamiento:</strong> <?php echo nl2br(htmlspecialchars($consulta['tratamiento'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Información Adicional -->
                                            <?php if (!empty($consulta['procedimiento']) || !empty($consulta['pendientes']) || !empty($consulta['observaciones'])): ?>
                                            <div class="consulta-section">
                                                <div class="consulta-section-title">
                                                    <i class='bx bx-detail'></i>
                                                    Información Adicional
                                                </div>
                                                <div class="consulta-section-content">
                                                    <?php if (!empty($consulta['procedimiento'])): ?>
                                                        <p><strong>Procedimiento:</strong> <?php echo nl2br(htmlspecialchars($consulta['procedimiento'])); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($consulta['pendientes'])): ?>
                                                        <p><strong>Pendientes:</strong> <?php echo nl2br(htmlspecialchars($consulta['pendientes'])); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($consulta['observaciones'])): ?>
                                                        <p><strong>Observaciones:</strong> <?php echo nl2br(htmlspecialchars($consulta['observaciones'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="consulta-meta">
                                            <div>
                                                <?php if (!empty($consulta['edad_mascota'])): ?>
                                                    <span><i class='bx bx-time'></i> Edad: <?php echo htmlspecialchars($consulta['edad_mascota']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($consulta['valor_total'])): ?>
                                                <div class="consulta-valor">
                                                    <i class='bx bx-dollar'></i>
                                                    Total: $<?php echo number_format($consulta['valor_total'], 2, ',', '.'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Tab: Vacunas -->
                    <div id="tab-vacunas" class="tab-content">
                        <?php if (empty($vacunas)): ?>
                            <div class="empty-state">
                                <i class='bx bx-syringe'></i>
                                <h4>No hay vacunas registradas</h4>
                                <p>Esta mascota no tiene vacunas registradas en el sistema.</p>
                            </div>
                        <?php else: ?>
                            <div class="consultas-container">
                                <?php foreach ($vacunas as $vacuna): ?>
                                    <div class="consulta-card">
                                        <div class="consulta-header">
                                            <div class="consulta-fecha">
                                                <i class='bx bx-calendar'></i>
                                                <?php echo date('d/m/Y', strtotime($vacuna['fecha_aplicacion'])); ?>
                                            </div>
                                            <?php if (!empty($vacuna['proxima_dosis'])): ?>
                                                <div class="consulta-veterinario">
                                                    <i class='bx bx-calendar-check'></i>
                                                    Próxima: <?php echo date('d/m/Y', strtotime($vacuna['proxima_dosis'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="consulta-body">
                                            <div class="consulta-section">
                                                <div class="consulta-section-title">
                                                    <i class='bx bx-syringe'></i>
                                                    Vacuna Aplicada
                                                </div>
                                                <div class="consulta-section-content">
                                                    <p><strong>Nombre:</strong> <?php echo htmlspecialchars($vacuna['nombre_vacuna']); ?></p>
                                                    <?php if (!empty($vacuna['veterinario'])): ?>
                                                        <p><strong>Aplicada por:</strong> <?php echo htmlspecialchars($vacuna['veterinario']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($vacuna['observaciones'])): ?>
                                                        <p><strong>Observaciones:</strong> <?php echo nl2br(htmlspecialchars($vacuna['observaciones'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="consulta-meta">
                                            <div>
                                                <span><i class='bx bx-time'></i> Registrada: <?php echo date('d/m/Y H:i', strtotime($vacuna['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Tab: Citas -->
                    <div id="tab-citas" class="tab-content">
                        <?php if (empty($citas)): ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar'></i>
                                <h4>No hay citas registradas</h4>
                                <p>Esta mascota no tiene citas registradas en el sistema.</p>
                                <a href="agendarcita.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class='bx bx-plus'></i>
                                    Agendar Primera Cita
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="consultas-container">
                                <?php foreach ($citas as $cita): ?>
                                    <div class="consulta-card">
                                        <div class="consulta-header">
                                            <div class="consulta-fecha">
                                                <i class='bx bx-calendar'></i>
                                                <?php echo date('d/m/Y H:i', strtotime($cita['fecha_cita'] . ' ' . $cita['hora_cita'])); ?>
                                            </div>
                                            <div class="consulta-veterinario">
                                                <?php 
                                                $estado_class = '';
                                                switch($cita['estado']) {
                                                    case 'scheduled': $estado_text = 'Programada'; $estado_class = 'btn-secondary'; break;
                                                    case 'confirmed': $estado_text = 'Confirmada'; $estado_class = 'btn-success'; break;
                                                    case 'in_progress': $estado_text = 'En Progreso'; $estado_class = 'btn-info'; break;
                                                    case 'completed': $estado_text = 'Completada'; $estado_class = 'btn-primary'; break;
                                                    case 'cancelled': $estado_text = 'Cancelada'; $estado_class = 'btn-danger'; break;
                                                    case 'no_show': $estado_text = 'No Presentado'; $estado_class = 'btn-warning'; break;
                                                    default: $estado_text = $cita['estado']; $estado_class = 'btn-secondary';
                                                }
                                                ?>
                                                <span class="btn btn-sm <?php echo $estado_class; ?>">
                                                    <?php echo $estado_text; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="consulta-body">
                                            <div class="consulta-section">
                                                <div class="consulta-section-title">
                                                    <i class='bx bx-calendar-check'></i>
                                                    Detalles de la Cita
                                                </div>
                                                <div class="consulta-section-content">
                                                    <p><strong>Servicio:</strong> <?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'No especificado'); ?></p>
                                                    <?php if (!empty($cita['notas'])): ?>
                                                        <p><strong>Notas:</strong> <?php echo nl2br(htmlspecialchars($cita['notas'])); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($cita['diagnostico'])): ?>
                                                        <p><strong>Diagnóstico:</strong> <?php echo nl2br(htmlspecialchars($cita['diagnostico'])); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($cita['tratamiento'])): ?>
                                                        <p><strong>Tratamiento:</strong> <?php echo nl2br(htmlspecialchars($cita['tratamiento'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="consulta-meta">
                                            <div>
                                                <span><i class='bx bx-time'></i> Creada: <?php echo date('d/m/Y H:i', strtotime($cita['fecha_creacion'])); ?></span>
                                            </div>
                                            <?php if (!empty($cita['costo_total'])): ?>
                                                <div class="consulta-valor">
                                                    <i class='bx bx-dollar'></i>
                                                    Total: $<?php echo number_format($cita['costo_total'], 2, ',', '.'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Tab: Información General -->
                    <div id="tab-info" class="tab-content">
                        <div class="consultas-container">
                            <!-- Información de la Mascota -->
                            <div class="consulta-card">
                                <div class="consulta-header">
                                    <div class="consulta-fecha">
                                        <i class='bx bx-paw'></i>
                                        Información General
                                    </div>
                                </div>
                                
                                <div class="consulta-body">
                                    <div class="consulta-section">
                                        <div class="consulta-section-title">
                                            <i class='bx bx-info-circle'></i>
                                            Datos Básicos
                                        </div>
                                        <div class="consulta-section-content">
                                            <p><strong>Nombre:</strong> <?php echo htmlspecialchars($mascota['nombre']); ?></p>
                                            <p><strong>Especie:</strong> <?php echo htmlspecialchars($mascota['especie_nombre']); ?></p>
                                            <?php if (!empty($mascota['raza'])): ?>
                                                <p><strong>Raza:</strong> <?php echo htmlspecialchars($mascota['raza']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['sexo'])): ?>
                                                <p><strong>Sexo:</strong> <?php echo in_array(strtolower((string)$mascota['sexo']), ['macho','m'], true) ? 'Macho' : 'Hembra'; ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['color'])): ?>
                                                <p><strong>Color:</strong> <?php echo htmlspecialchars($mascota['color']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['peso'])): ?>
                                                <p><strong>Peso:</strong> <?php echo htmlspecialchars($mascota['peso']); ?> kg</p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['fecha_nacimiento'])): ?>
                                                <p><strong>Fecha Nacimiento:</strong> <?php echo date('d/m/Y', strtotime($mascota['fecha_nacimiento'])); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['fecha_registro'])): ?>
                                                <p><strong>Registro en Sistema:</strong> <?php echo date('d/m/Y', strtotime($mascota['fecha_registro'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($mascota['notas']) || !empty($mascota['historial_medico']) || !empty($mascota['alergias'])): ?>
                                    <div class="consulta-section">
                                        <div class="consulta-section-title">
                                            <i class='bx bx-clipboard'></i>
                                            Historial Médico y Notas
                                        </div>
                                        <div class="consulta-section-content">
                                            <?php if (!empty($mascota['alergias'])): ?>
                                                <p><strong>Alergias:</strong> <?php echo nl2br(htmlspecialchars($mascota['alergias'])); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['historial_medico'])): ?>
                                                <p><strong>Historial Médico:</strong> <?php echo nl2br(htmlspecialchars($mascota['historial_medico'])); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mascota['notas'])): ?>
                                                <p><strong>Notas Adicionales:</strong> <?php echo nl2br(htmlspecialchars($mascota['notas'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="consulta-section">
                                        <div class="consulta-section-title">
                                            <i class='bx bx-stats'></i>
                                            Estadísticas
                                        </div>
                                        <div class="consulta-section-content">
                                            <div class="mascota-stats" style="grid-template-columns: repeat(2, 1fr); border: none; padding: 0;">
                                                <div class="stat-item">
                                                    <div class="stat-number"><?php echo count($consultas); ?></div>
                                                    <div class="stat-label">Consultas Totales</div>
                                                </div>
                                                <div class="stat-item">
                                                    <div class="stat-number"><?php echo count($vacunas); ?></div>
                                                    <div class="stat-label">Vacunas Aplicadas</div>
                                                </div>
                                                <div class="stat-item">
                                                    <div class="stat-number"><?php echo count($citas); ?></div>
                                                    <div class="stat-label">Citas Programadas</div>
                                                </div>
                                                <div class="stat-item">
                                                    <div class="stat-number">
                                                        <?php 
                                                        $ultima_consulta = !empty($consultas) ? date('d/m/Y', strtotime($consultas[0]['fecha_consulta'])) : 'Nunca';
                                                        echo $ultima_consulta;
                                                        ?>
                                                    </div>
                                                    <div class="stat-label">Última Consulta</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-content">
                    <div class="footer-simple">
                        <span class="footer-text">© 2026 VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></span>
                    </div>
                </div>
            </footer>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Tabs functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all buttons and contents
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked button and corresponding content
                    this.classList.add('active');
                    document.getElementById(`tab-${tabId}`).classList.add('active');
                });
            });
            
            // Print functionality
            const printBtn = document.getElementById('printBtn');
            if (printBtn) {
                printBtn.addEventListener('click', function() {
                    window.print();
                });
            }
            
            // Expand/Collapse consultas
            const expandButtons = document.querySelectorAll('.btn-expand');
            expandButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const consultaId = this.getAttribute('data-consulta');
                    const detalles = document.getElementById(`detalles-${consultaId}`);
                    
                    if (detalles.style.display === 'none') {
                        detalles.style.display = 'block';
                        this.innerHTML = '<i class="bx bx-chevron-up"></i> Ver Menos';
                    } else {
                        detalles.style.display = 'none';
                        this.innerHTML = '<i class="bx bx-chevron-down"></i> Ver Más';
                    }
                });
            });
            
            // Smooth scrolling for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    if (targetId !== '#') {
                        const targetElement = document.querySelector(targetId);
                        if (targetElement) {
                            targetElement.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    }
                });
            });
            
            // Fade in animation for consultas
            const consultaCards = document.querySelectorAll('.consulta-card');
            consultaCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'fadeInUp 0.5s ease forwards';
                card.style.opacity = '0';
            });
            
            // Add animation CSS
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                
                .print-only {
                    display: none;
                }
                
                @media print {
                    .no-print {
                        display: none !important;
                    }
                    
                    .print-only {
                        display: block;
                    }
                    
                    .main-content {
                        margin-left: 0 !important;
                        padding: 0 !important;
                    }
                    
                    .consulta-card {
                        break-inside: avoid;
                        box-shadow: none !important;
                        border: 1px solid #ddd !important;
                    }
                }
            `;
            document.head.appendChild(style);
            
            // Export to PDF functionality (basic)
            const exportBtn = document.getElementById('exportBtn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    window.print();
                    // Aquí puedes implementar la exportación a PDF usando jsPDF o similar
                });
            }
            
            // Search within page
            const searchInput = document.getElementById('searchConsultas');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const consultas = document.querySelectorAll('.consulta-card');
                    
                    consultas.forEach(consulta => {
                        const text = consulta.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            consulta.style.display = 'block';
                            consulta.style.animation = 'fadeInUp 0.3s ease';
                        } else {
                            consulta.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>