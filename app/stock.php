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
    
    // Obtener productos con stock crítico (agotados o por debajo del mínimo)
    $sql_stock_critico = "
        SELECT 
            p.*,
            c.nombre as categoria_nombre,
            pr.nombre as proveedor_nombre,
            CASE 
                WHEN p.stock_actual = 0 THEN 'agotado'
                WHEN p.stock_actual <= p.stock_minimo THEN 'critico'
                WHEN p.stock_actual <= (p.stock_minimo * 1.5) THEN 'bajo'
                ELSE 'normal'
            END as nivel_alerta,
            ROUND((p.stock_actual / NULLIF(p.stock_minimo, 0)) * 100, 2) as porcentaje_stock,
            CASE 
                WHEN p.categoria_id = 1 THEN 'medicamento'
                ELSE 'producto'
            END as tipo
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
        WHERE p.activo = 1 
        AND (p.stock_actual = 0 OR p.stock_actual <= p.stock_minimo)
        ORDER BY 
            CASE 
                WHEN p.stock_actual = 0 THEN 1
                WHEN p.stock_actual <= p.stock_minimo THEN 2
                ELSE 3
            END,
            p.stock_actual ASC
    ";
    $stmt_stock_critico = $db->query($sql_stock_critico);
    $stock_critico = $stmt_stock_critico->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas generales
    $sql_total_critico = "
        SELECT 
            COUNT(*) as total_critico,
            SUM(CASE WHEN stock_actual = 0 THEN 1 ELSE 0 END) as total_agotados,
            SUM(CASE WHEN stock_actual > 0 AND stock_actual <= stock_minimo THEN 1 ELSE 0 END) as total_bajo_stock,
            COALESCE(SUM(CASE WHEN stock_actual = 0 OR stock_actual <= stock_minimo THEN stock_actual * precio_compra ELSE 0 END), 0) as valor_riesgo,
            COUNT(CASE WHEN categoria_id = 1 THEN 1 END) as medicamentos_criticos,
            COUNT(CASE WHEN categoria_id != 1 THEN 1 END) as productos_criticos
        FROM productos
        WHERE activo = 1 
        AND (stock_actual = 0 OR stock_actual <= stock_minimo)
    ";
    $stmt_total_critico = $db->query($sql_total_critico);
    $estadisticas = $stmt_total_critico->fetch(PDO::FETCH_ASSOC);
    
    // Si no hay resultados, inicializar valores
    if (!$estadisticas) {
        $estadisticas = [
            'total_critico' => 0,
            'total_agotados' => 0,
            'total_bajo_stock' => 0,
            'valor_riesgo' => 0,
            'medicamentos_criticos' => 0,
            'productos_criticos' => 0
        ];
    }
    
} catch (PDOException $e) {
    error_log("Error en stock.php: " . $e->getMessage());
    $stock_critico = [];
    $estadisticas = [
        'total_critico' => 0,
        'total_agotados' => 0,
        'total_bajo_stock' => 0,
        'valor_riesgo' => 0,
        'medicamentos_criticos' => 0,
        'productos_criticos' => 0
    ];
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

// Función para obtener clase de alerta
function getAlertaClass($nivel) {
    switch ($nivel) {
        case 'agotado':
            return 'danger';
        case 'critico':
            return 'critical';
        case 'bajo':
            return 'warning';
        default:
            return 'normal';
    }
}

// Función para obtener texto de alerta
function getAlertaText($nivel) {
    switch ($nivel) {
        case 'agotado':
            return 'AGOTADO';
        case 'critico':
            return 'CRÍTICO';
        case 'bajo':
            return 'BAJO';
        default:
            return 'NORMAL';
    }
}

$page_title = "Alertas de Stock - VetNexo";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
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
            --orange: #FF5722;
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

        .btn-primary, .btn-secondary, .btn-warning {
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

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--accent-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.4);
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

        .alert-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
            border: 1px solid var(--accent-light);
        }

        .alert-danger {
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

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
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

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--warning), var(--accent));
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--danger), #ff6b6b);
        }

        .stat-card.critical::before {
            background: linear-gradient(90deg, var(--orange), #ff8a65);
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
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.warning .stat-icon {
            background: var(--accent-soft);
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #ffebee;
            color: var(--danger);
        }

        .stat-card.critical .stat-icon {
            background: #fff3e0;
            color: var(--orange);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.warning .stat-number {
            color: var(--warning);
        }

        .stat-card.danger .stat-number {
            color: var(--danger);
        }

        .stat-card.critical .stat-number {
            color: var(--orange);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Actions Bar */
        .actions-bar {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 1.2rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: var(--shadow);
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-lighter);
        }

        .search-box input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.5rem;
            border: 1px solid var(--gray);
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .actions-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 1.2rem 1.5rem;
            box-shadow: var(--shadow);
        }

        .filters-header {
            margin-bottom: 1rem;
        }

        .filters-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-btn {
            padding: 0.6rem 1.2rem;
            border: 1px solid var(--gray);
            background: var(--white);
            border-radius: 50px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-dark);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .filter-btn i {
            font-size: 1rem;
        }

        .filter-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .filter-btn:hover:not(.active) {
            background: var(--primary-soft);
        }

        /* Tabla de Stock Crítico */
        .table-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
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
        }

        .badge-count {
            background: var(--white);
            color: var(--primary-dark);
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .data-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 1px solid var(--gray);
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tr:hover td {
            background: var(--gray-light);
        }

        .data-table tr.agotado-row {
            border-left: 4px solid var(--danger);
        }

        .data-table tr.critico-row {
            border-left: 4px solid var(--orange);
        }

        .data-table tr.bajo-row {
            border-left: 4px solid var(--warning);
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-medicamento {
            background: #cce5ff;
            color: #004085;
        }

        .badge-producto {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .badge-categoria {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-agotado {
            background: #ffebee;
            color: var(--danger);
        }

        .badge-critico {
            background: #fff3e0;
            color: var(--orange);
        }

        .badge-bajo {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        /* Progress Bar */
        .stock-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .stock-numbers {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--gray-light);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-agotado {
            background: linear-gradient(90deg, var(--danger), #ff6b6b);
        }

        .progress-critico {
            background: linear-gradient(90deg, var(--orange), #ff8a65);
        }

        .progress-bajo {
            background: linear-gradient(90deg, var(--warning), var(--accent));
        }

        .progress-normal {
            background: linear-gradient(90deg, var(--success), #66bb6a);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: transparent;
        }

        .btn-icon-primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-icon-primary:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-warning {
            background: var(--accent-soft);
            color: var(--warning);
        }

        .btn-icon-warning:hover {
            background: var(--warning);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--success);
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .actions-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .filters-grid {
                flex-direction: column;
            }
            
            .filter-btn {
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .empty-actions {
                flex-direction: column;
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
                        <i class='bx bx-error-circle'></i>
                        Alertas de Stock
                    </h1>
                    <p>
                        <i class='bx bx-package'></i>
                        Monitoreo de productos con stock crítico
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

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card critical">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-alt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_critico']); ?></div>
                        <div class="stat-label">Total en Alerta</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-x-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_agotados']); ?></div>
                        <div class="stat-label">Productos Agotados</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-trending-down'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_bajo_stock']); ?></div>
                        <div class="stat-label">Stock por Debajo del Mínimo</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['valor_riesgo'], 2); ?></div>
                        <div class="stat-label">Valor en Riesgo</div>
                    </div>
                </div>
            </div>

            <!-- Barra de Acciones -->
            <div class="actions-bar fade-in">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" id="searchInput" placeholder="Buscar productos en alerta...">
                </div>
                <div class="actions-buttons">
                    <button class="btn-secondary btn-sm" onclick="window.location.href='productos.php'">
                        <i class='bx bx-package'></i>
                        Ver Productos
                    </button>
                    <button class="btn-secondary btn-sm" onclick="window.location.href='medicamentos.php'">
                        <i class='bx bx-plus-medical'></i>
                        Ver Medicamentos
                    </button>
                    <button class="btn-primary btn-sm" onclick="window.print()">
                        <i class='bx bx-printer'></i>
                        Imprimir Reporte
                    </button>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h3>
                        <i class='bx bx-filter-alt'></i>
                        Filtrar por
                    </h3>
                </div>
                <div class="filters-grid">
                    <button class="filter-btn active" data-filter="all">
                        <i class='bx bx-list-ul'></i>
                        Todos (<?php echo count($stock_critico); ?>)
                    </button>
                    <button class="filter-btn" data-filter="agotado">
                        <i class='bx bx-x-circle'></i>
                        Agotados (<?php echo $estadisticas['total_agotados']; ?>)
                    </button>
                    <button class="filter-btn" data-filter="critico">
                        <i class='bx bx-error-alt'></i>
                        Críticos (<?php echo $estadisticas['total_bajo_stock']; ?>)
                    </button>
                    <button class="filter-btn" data-filter="medicamento">
                        <i class='bx bx-plus-medical'></i>
                        Medicamentos (<?php echo $estadisticas['medicamentos_criticos']; ?>)
                    </button>
                    <button class="filter-btn" data-filter="producto">
                        <i class='bx bx-package'></i>
                        Productos (<?php echo $estadisticas['productos_criticos']; ?>)
                    </button>
                </div>
            </div>

            <!-- Tabla de Stock Crítico -->
            <div class="table-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-error-circle' style="color: var(--orange);"></i>
                        Productos con Stock Crítico
                    </h3>
                    <span class="badge-count">
                        <?php echo count($stock_critico); ?> productos en alerta
                    </span>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="stockTable">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Stock Actual</th>
                                <th>Nivel de Alerta</th>
                                <th>Proveedor</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($stock_critico)): ?>
                                <?php foreach ($stock_critico as $producto): ?>
                                    <tr class="product-row <?php echo $producto['nivel_alerta']; ?>-row" 
                                        data-tipo="<?php echo $producto['tipo']; ?>"
                                        data-alerta="<?php echo $producto['nivel_alerta']; ?>">
                                        <td>
                                            <span class="badge badge-<?php echo $producto['tipo']; ?>">
                                                <?php echo $producto['tipo'] == 'medicamento' ? 'Medicamento' : 'Producto'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($producto['codigo']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                            <?php if (!empty($producto['descripcion'])): ?>
                                                <small style="color: var(--text-light);">
                                                    <?php echo htmlspecialchars(substr($producto['descripcion'], 0, 50)) . (strlen($producto['descripcion']) > 50 ? '...' : ''); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-categoria">
                                                <?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="stock-info">
                                                <div style="font-weight: 600; font-size: 1.1rem; text-align: center;">
                                                    <?php echo $producto['stock_actual']; ?>
                                                </div>
                                                <div class="stock-numbers">
                                                    <span>Actual</span>
                                                    <span>Mín: <?php echo $producto['stock_minimo']; ?></span>
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill progress-<?php echo $producto['nivel_alerta']; ?>" 
                                                         style="width: <?php echo min($producto['porcentaje_stock'] ?? 0, 100); ?>%">
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $producto['nivel_alerta']; ?>">
                                                <?php echo getAlertaText($producto['nivel_alerta']); ?>
                                            </span>
                                            <br>
                                            <small><?php echo number_format($producto['porcentaje_stock'] ?? 0, 1); ?>% del mínimo</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($producto['proveedor_nombre'] ?? 'No asignado'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($producto['tipo'] == 'medicamento'): ?>
                                                    <button class="btn-icon btn-icon-primary" onclick="window.location.href='editar_medicamento.php?id=<?php echo $producto['id']; ?>'" title="Editar Medicamento">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-icon btn-icon-primary" onclick="window.location.href='editar_producto.php?id=<?php echo $producto['id']; ?>'" title="Editar Producto">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn-icon btn-icon-warning" onclick="ajustarStock(<?php echo $producto['id']; ?>, '<?php echo htmlspecialchars($producto['nombre']); ?>', <?php echo $producto['stock_actual']; ?>)" title="Ajustar Stock">
                                                    <i class='bx bx-transfer-alt'></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class='bx bx-check-circle'></i>
                                            <h3>¡Excelente!</h3>
                                            <p>No hay productos con stock crítico en este momento.</p>
                                            <p style="color: var(--primary); font-weight: 600;">El inventario está bien gestionado.</p>
                                            <div class="empty-actions">
                                                <button class="btn-secondary btn-sm" onclick="window.location.href='productos.php'">
                                                    <i class='bx bx-package'></i> Gestionar Productos
                                                </button>
                                                <button class="btn-primary btn-sm" onclick="window.location.href='medicamentos.php'">
                                                    <i class='bx bx-plus-medical'></i> Gestionar Medicamentos
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recomendaciones -->
            <?php if (!empty($stock_critico)): ?>
            <div class="alert alert-warning fade-in">
                <i class='bx bx-info-circle' style="font-size: 1.5rem;"></i>
                <div>
                    <strong>Recomendaciones para gestión de stock:</strong>
                    <ul style="margin: 0.5rem 0 0 1rem; line-height: 1.6;">
                        <li><span class="badge badge-agotado">AGOTADO</span> - Reposición inmediata necesaria</li>
                        <li><span class="badge badge-critico">CRÍTICO</span> - Reabastecimiento urgente</li>
                        <li><span class="badge badge-bajo">BAJO</span> - Planificar próxima compra</li>
                        <li>Priorice medicamentos sobre productos generales</li>
                        <li>Contacte proveedores con productos críticos</li>
                    </ul>
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

    <script src="js/sidebar.js"></script>
    <script>
        // Variables globales
        let currentFilter = 'all';

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de estadísticas
            animateStats();
            
            // Configurar búsqueda en tiempo real
            setupSearch();
            
            // Configurar filtros
            setupFilters();
            
            // Crear partículas de fondo
            createParticles();
        });

        // Animar estadísticas
        function animateStats() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.-]+/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        if (text.includes('$')) {
                            stat.textContent = '$' + current.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        } else {
                            stat.textContent = Math.floor(current).toLocaleString('es-ES');
                        }
                    }, 30);
                }
            });
        }

        // Configurar búsqueda en tiempo real
        function setupSearch() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.product-row');
                    
                    rows.forEach(row => {
                        if (currentFilter === 'all' || row.dataset.tipo === currentFilter || row.dataset.alerta === currentFilter) {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(filter) ? '' : 'none';
                        }
                    });
                    
                    updateVisibleCount();
                });
            }
        }

        // Configurar filtros
        function setupFilters() {
            const filterBtns = document.querySelectorAll('.filter-btn');
            const rows = document.querySelectorAll('.product-row');
            
            filterBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // Remover clase active de todos los botones
                    filterBtns.forEach(b => b.classList.remove('active'));
                    // Agregar clase active al botón clickeado
                    this.classList.add('active');
                    
                    currentFilter = this.dataset.filter;
                    const searchValue = document.getElementById('searchInput').value.toLowerCase();
                    
                    rows.forEach(row => {
                        const tipo = row.dataset.tipo;
                        const alerta = row.dataset.alerta;
                        
                        let showByFilter = false;
                        
                        if (currentFilter === 'all') {
                            showByFilter = true;
                        } else if (currentFilter === 'medicamento' || currentFilter === 'producto') {
                            showByFilter = tipo === currentFilter;
                        } else {
                            showByFilter = alerta === currentFilter;
                        }
                        
                        if (showByFilter) {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchValue) ? '' : 'none';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    updateVisibleCount();
                });
            });
        }

        // Actualizar contador de productos visibles
        function updateVisibleCount() {
            const visibleRows = document.querySelectorAll('.product-row[style=""]').length;
            const totalRows = document.querySelectorAll('.product-row').length;
            const countBadge = document.querySelector('.badge-count');
            if (countBadge) {
                countBadge.textContent = `${visibleRows} de ${totalRows} productos en alerta`;
            }
        }

        // Función para ajustar stock
        function ajustarStock(productoId, productoNombre, stockActual) {
            // Determinar el tipo de producto por la fila actual
            const row = event.target.closest('tr');
            const tipo = row.querySelector('.badge-medicamento') ? 'medicamento' : 'producto';
            
            if (confirm(`¿Desea ajustar el stock de "${productoNombre}"?`)) {
                if (tipo === 'medicamento') {
                    window.location.href = `medicamentos.php?ajustar=${productoId}`;
                } else {
                    window.location.href = `productos.php?ajustar=${productoId}`;
                }
            }
        }

        // Crear partículas de fondo
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.className = 'particles';
            particlesContainer.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                pointer-events: none;
                z-index: -1;
            `;
            
            for (let i = 0; i < 15; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    position: absolute;
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    background: rgba(46, 125, 50, 0.05);
                    border-radius: 50%;
                    animation: float ${Math.random() * 6 + 4}s ease-in-out infinite;
                    animation-delay: ${Math.random() * 5}s;
                `;
                particlesContainer.appendChild(particle);
            }
            
            document.body.appendChild(particlesContainer);
        }

        // Cerrar modales con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style*="display: flex"]');
                modals.forEach(modal => {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
            }
        });

        // Estilo adicional para animación de partículas
        const style = document.createElement('style');
        style.textContent = `
            @keyframes float {
                0%, 100% {
                    transform: translateY(0px) rotate(0deg);
                }
                50% {
                    transform: translateY(-10px) rotate(180deg);
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>