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
    
    // Procesar operaciones POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // Eliminar proveedor (desactivar)
        if (isset($_POST['eliminar_proveedor'])) {
            $proveedor_id = $_POST['proveedor_id'];
            
            try {
        $db->prepare('UPDATE proveedores SET activo=0 WHERE id=?')->execute([$_POST['proveedor_id']]);
        $_SESSION['success']='Registro desactivado correctamente.';
    } catch (PDOException $e) {
                $_SESSION['error'] = "Error al eliminar el proveedor: " . $e->getMessage();
            }
            
            header('Location: proveedores.php');
            exit();
        }
        
        // Nuevo proveedor
        if (isset($_POST['nuevo_proveedor'])) {
            $nombre = $_POST['nombre'];
            $contacto = $_POST['contacto'] ?? '';
            $telefono = $_POST['telefono'] ?? '';
            $email = $_POST['email'] ?? '';
            $direccion = $_POST['direccion'] ?? '';
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            try {
                $sql_nuevo = "INSERT INTO proveedores (
                    nombre, contacto, telefono, email, direccion, activo
                ) VALUES (?, ?, ?, ?, ?, ?)";
                
                $stmt_nuevo = $db->prepare($sql_nuevo);
                $stmt_nuevo->execute([
                    $nombre, $contacto, $telefono, $email, $direccion, $activo
                ]);
                
                $_SESSION['success'] = "Proveedor creado correctamente.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error al crear el proveedor: " . $e->getMessage();
            }
            
            header('Location: proveedores.php');
            exit();
        }
    }
    
    // Obtener lista de proveedores con estadísticas
    $sql_proveedores = "
        SELECT 
            p.*,
            COUNT(prod.id) as total_productos,
            SUM(CASE WHEN prod.stock_actual <= prod.stock_minimo AND prod.stock_actual > 0 THEN 1 ELSE 0 END) as productos_bajo_stock,
            SUM(CASE WHEN prod.stock_actual = 0 THEN 1 ELSE 0 END) as productos_agotados,
            COALESCE(SUM(prod.stock_actual * prod.precio_compra), 0) as valor_inventario,
            COALESCE(SUM(prod.stock_actual), 0) as total_unidades
        FROM proveedores p
        LEFT JOIN productos prod ON p.id = prod.proveedor_id AND prod.activo = 1
        GROUP BY p.id
        ORDER BY p.activo DESC, total_productos DESC, p.nombre
    ";
    $stmt_proveedores = $db->query($sql_proveedores);
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas generales
    $sql_stats = "
        SELECT 
            COUNT(*) as total_proveedores,
            SUM(activo) as proveedores_activos,
            COUNT(*) - SUM(activo) as proveedores_inactivos,
            (SELECT COUNT(*) FROM productos WHERE proveedor_id IS NOT NULL AND activo = 1) as productos_con_proveedor,
            (SELECT COUNT(DISTINCT proveedor_id) FROM productos WHERE activo = 1 AND proveedor_id IS NOT NULL) as proveedores_con_productos
        FROM proveedores
    ";
    $stmt_stats = $db->query($sql_stats);
    $estadisticas = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en proveedores.php: " . $e->getMessage());
    $proveedores = [];
    $estadisticas = [
        'total_proveedores' => 0,
        'proveedores_activos' => 0,
        'proveedores_inactivos' => 0,
        'productos_con_proveedor' => 0,
        'proveedores_con_productos' => 0
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

$page_title = "Gestión de Proveedores - VetNexo";
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

        .btn-primary, .btn-secondary, .btn-info {
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

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(33, 150, 243, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #D32F2F);
            color: var(--white);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 67, 54, 0.4);
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

        /* Tabla de Proveedores */
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

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-inactive {
            background: var(--gray);
            color: var(--text-light);
        }

        .badge-stock {
            background: var(--accent-soft);
            color: var(--accent-dark);
            font-size: 0.7rem;
            margin-right: 0.3rem;
        }

        .badge-agotado {
            background: #ffebee;
            color: var(--danger);
            font-size: 0.7rem;
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

        .btn-icon-info {
            background: #cce5ff;
            color: var(--info);
        }

        .btn-icon-info:hover {
            background: var(--info);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .btn-icon-danger:hover {
            background: var(--danger);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Product Stats */
        .product-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.3rem;
        }

        .stat-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            background: var(--gray-light);
            color: var(--text-light);
        }

        .stat-tag i {
            font-size: 0.8rem;
        }

        .stat-tag.warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .stat-tag.danger {
            background: #ffebee;
            color: var(--danger);
        }

        /* Contact Info */
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .contact-item i {
            font-size: 1rem;
            color: var(--primary);
        }

        .contact-item a {
            color: var(--text-light);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .contact-item a:hover {
            color: var(--primary);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1200;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--gray);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            position: sticky;
            bottom: 0;
            background: var(--white);
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

        .form-input, .form-textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
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
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
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
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .contact-info {
                align-items: flex-start;
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
                        <i class='bx bx-building-house'></i>
                        Gestión de Proveedores
                    </h1>
                    <p>
                        <i class='bx bx-truck'></i>
                        Administración de proveedores y análisis de suministros
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

            <!-- Alertas de Operaciones -->
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

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-buildings'></i>
                            </div>
                            <div class="trend-indicator">
                                <i class='bx bx-trending-up'></i>
                                <?php echo $estadisticas['proveedores_activos']; ?> activos
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_proveedores']); ?></div>
                        <div class="stat-label">Total Proveedores</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['proveedores_activos']); ?></div>
                        <div class="stat-label">Proveedores Activos</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-package'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['productos_con_proveedor']); ?></div>
                        <div class="stat-label">Productos con Proveedor</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-group'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['proveedores_con_productos']); ?></div>
                        <div class="stat-label">Proveedores con Stock</div>
                    </div>
                </div>
            </div>

            <!-- Barra de Acciones -->
            <div class="actions-bar fade-in">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" id="searchInput" placeholder="Buscar proveedores...">
                </div>
                <div class="actions-buttons">
                    <button class="btn-info btn-sm" onclick="window.location.href='stock.php'">
                        <i class='bx bx-error-circle'></i>
                        Alertas de Stock
                    </button>
                    <button class="btn-primary btn-sm" onclick="openModal('modalNuevoProveedor')">
                        <i class='bx bx-plus'></i>
                        Nuevo Proveedor
                    </button>
                </div>
            </div>

            <!-- Tabla de Proveedores -->
            <div class="table-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-ul'></i>
                        Lista de Proveedores
                    </h3>
                    <span class="badge-count">
                        <?php echo count($proveedores); ?> proveedores
                    </span>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="proveedoresTable">
                        <thead>
                            <tr>
                                <th>Proveedor</th>
                                <th>Contacto</th>
                                <th>Productos</th>
                                <th>Valor Inventario</th>
                                <th>Estado Stock</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($proveedores)): ?>
                                <?php foreach ($proveedores as $proveedor): ?>
                                    <tr class="fade-in">
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($proveedor['nombre']); ?></div>
                                            <?php if (!empty($proveedor['direccion'])): ?>
                                                <small style="color: var(--text-light);">
                                                    <i class='bx bx-map'></i> <?php echo htmlspecialchars($proveedor['direccion']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="contact-info">
                                                <?php if (!empty($proveedor['contacto'])): ?>
                                                    <div class="contact-item">
                                                        <i class='bx bx-user'></i>
                                                        <span><?php echo htmlspecialchars($proveedor['contacto']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($proveedor['telefono'])): ?>
                                                    <div class="contact-item">
                                                        <i class='bx bx-phone'></i>
                                                        <a href="tel:<?php echo htmlspecialchars($proveedor['telefono']); ?>">
                                                            <?php echo htmlspecialchars($proveedor['telefono']); ?>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($proveedor['email'])): ?>
                                                    <div class="contact-item">
                                                        <i class='bx bx-envelope'></i>
                                                        <a href="mailto:<?php echo htmlspecialchars($proveedor['email']); ?>">
                                                            <?php echo htmlspecialchars($proveedor['email']); ?>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (empty($proveedor['contacto']) && empty($proveedor['telefono']) && empty($proveedor['email'])): ?>
                                                    <span style="color: var(--text-light);">Sin información de contacto</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="font-weight: 600; font-size: 1.1rem;">
                                                <?php echo $proveedor['total_productos']; ?>
                                            </div>
                                            <small>productos</small>
                                        </td>
                                        <td>
                                            <div style="text-align: center;">
                                                <strong style="color: var(--success);">
                                                    $<?php echo number_format($proveedor['valor_inventario'], 2); ?>
                                                </strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="product-stats">
                                                <?php if ($proveedor['productos_agotados'] > 0): ?>
                                                    <span class="stat-tag danger">
                                                        <i class='bx bx-x-circle'></i>
                                                        <?php echo $proveedor['productos_agotados']; ?> agotados
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($proveedor['productos_bajo_stock'] > 0): ?>
                                                    <span class="stat-tag warning">
                                                        <i class='bx bx-error-alt'></i>
                                                        <?php echo $proveedor['productos_bajo_stock']; ?> bajos
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($proveedor['productos_agotados'] == 0 && $proveedor['productos_bajo_stock'] == 0 && $proveedor['total_productos'] > 0): ?>
                                                    <span class="stat-tag">
                                                        <i class='bx bx-check-circle' style="color: var(--success);"></i>
                                                        Stock normal
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($proveedor['total_productos'] == 0): ?>
                                                    <span class="stat-tag">
                                                        Sin productos
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $proveedor['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo $proveedor['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-primary" onclick="editarProveedor(<?php echo $proveedor['id']; ?>)" title="Editar Proveedor">
                                                    <i class='bx bx-edit'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-info" onclick="verProductos(<?php echo $proveedor['id']; ?>, '<?php echo htmlspecialchars($proveedor['nombre']); ?>')" title="Ver Productos">
                                                    <i class='bx bx-package'></i>
                                                </button>
                                                <?php if ($proveedor['activo']): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de desactivar este proveedor?');"><?php echo av_csrf_field(); ?>
                                                        <input type="hidden" name="proveedor_id" value="<?php echo $proveedor['id']; ?>">
                                                        <button type="submit" name="eliminar_proveedor" class="btn-icon btn-icon-danger" title="Desactivar Proveedor">
                                                            <i class='bx bx-trash'></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class='bx bx-building-house'></i>
                                            <h3>No hay proveedores registrados</h3>
                                            <p>Comienza agregando un nuevo proveedor al sistema.</p>
                                            <button class="btn-primary btn-sm" onclick="openModal('modalNuevoProveedor')" style="display: inline-flex;">
                                                <i class='bx bx-plus'></i>
                                                Agregar Proveedor
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

    <!-- Modal Nuevo Proveedor -->
    <div class="modal" id="modalNuevoProveedor">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class='bx bx-plus-circle'></i>
                    Nuevo Proveedor
                </h3>
                <button class="modal-close" onclick="closeModal('modalNuevoProveedor')">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <form method="POST" id="formNuevoProveedor"><?php echo av_csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Nombre del Proveedor *</label>
                        <input type="text" class="form-input" name="nombre" required 
                               placeholder="Ej: VetSupply S.A.">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Persona de Contacto</label>
                            <input type="text" class="form-input" name="contacto" 
                                   placeholder="Nombre del contacto">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="tel" class="form-input" name="telefono" 
                                   placeholder="+34 900 123 456">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-input" name="email" 
                               placeholder="contacto@proveedor.com">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Dirección</label>
                        <textarea class="form-textarea" name="direccion" 
                                  placeholder="Dirección completa del proveedor..."></textarea>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="activo" name="activo" value="1" checked>
                        <label for="activo">Proveedor activo</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary btn-sm" onclick="closeModal('modalNuevoProveedor')">Cancelar</button>
                    <button type="submit" name="nuevo_proveedor" class="btn-primary btn-sm">Crear Proveedor</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Variables globales
        let currentProveedorId = null;

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de estadísticas
            animateStats();
            
            // Configurar búsqueda en tiempo real
            setupSearch();
            
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
                    const rows = document.querySelectorAll('#proveedoresTable tbody tr');
                    let visibleCount = 0;
                    
                    rows.forEach(row => {
                        if (!row.querySelector('.empty-state')) {
                            const text = row.textContent.toLowerCase();
                            const isVisible = text.includes(filter);
                            row.style.display = isVisible ? '' : 'none';
                            if (isVisible) visibleCount++;
                        }
                    });
                    
                    // Actualizar contador
                    const counter = document.querySelector('.badge-count');
                    if (counter) {
                        counter.textContent = visibleCount + ' proveedores';
                    }
                });
            }
        }

        // Funciones para modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Enfocar el primer campo
            setTimeout(() => {
                const firstInput = document.querySelector(`#${modalId} input:not([type="checkbox"])`);
                if (firstInput) firstInput.focus();
            }, 100);
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Limpiar formulario
            if (modalId === 'modalNuevoProveedor') {
                document.getElementById('formNuevoProveedor').reset();
                document.getElementById('activo').checked = true;
            }
        }

        // Función para editar proveedor
        function editarProveedor(id) {
            window.location.href = 'editar_proveedor.php?id=' + id;
        }

        // Función para ver productos del proveedor
        function verProductos(proveedorId, proveedorNombre) {
            window.location.href = 'productos.php?proveedor=' + proveedorId;
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

        // Cerrar modales al hacer clic fuera
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target == modal) {
                    closeModal(modal.id);
                }
            }
        }

        // Cerrar modal con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style*="display: flex"]');
                modals.forEach(modal => {
                    closeModal(modal.id);
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