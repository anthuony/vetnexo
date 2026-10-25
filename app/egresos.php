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

// Inicializar variables
$db = null;
$error = '';
$success = '';

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
    
    // Procesar registro de egreso
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_egreso'])) {
        try {
            $fecha = $_POST['fecha'];
            $categoria = $_POST['categoria'];
            $proveedor = $_POST['proveedor'];
            $descripcion = $_POST['descripcion'];
            $monto = $_POST['monto'];
            $metodo_pago = $_POST['metodo_pago'];
            $numero_factura = $_POST['numero_factura'] ?? '';
            $estado = $_POST['estado'] ?? 'pagado';
            
            $sql = "INSERT INTO egresos (fecha, categoria, proveedor, descripcion, monto, metodo_pago, numero_factura, estado, usuario_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $db->prepare($sql);
            $stmt->execute([$fecha, $categoria, $proveedor, $descripcion, $monto, $metodo_pago, $numero_factura, $estado, $user_id]);
            
            $_SESSION['success'] = "Egreso registrado exitosamente";
            header('Location: egresos.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al registrar egreso: " . $e->getMessage();
            header('Location: egresos.php');
            exit();
        }
    }
    
    // Procesar pago de factura pendiente
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagar_factura'])) {
        try {
            $egreso_id = $_POST['egreso_id'];
            
            $sql = "UPDATE egresos SET estado = 'pagado', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$egreso_id]);
            
            $_SESSION['success'] = "Factura marcada como pagada exitosamente";
            header('Location: egresos.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al actualizar factura: " . $e->getMessage();
            header('Location: egresos.php');
            exit();
        }
    }
    
    // Procesar marcar todas como pagadas
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_todas_pagadas'])) {
        try {
            $sql = "UPDATE egresos SET estado = 'pagado', updated_at = CURRENT_TIMESTAMP WHERE estado = 'pendiente'";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            
            $_SESSION['success'] = "Todas las facturas pendientes han sido marcadas como pagadas";
            header('Location: egresos.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al actualizar facturas: " . $e->getMessage();
            header('Location: egresos.php');
            exit();
        }
    }
    
    // Obtener estadísticas de egresos
    $fecha_hoy = date('Y-m-d');
    $sql_hoy = "SELECT COALESCE(SUM(monto), 0) as total FROM egresos WHERE DATE(fecha) = ? AND estado = 'pagado'";
    $stmt_hoy = $db->prepare($sql_hoy);
    $stmt_hoy->execute([$fecha_hoy]);
    $egresos_hoy = $stmt_hoy->fetch(PDO::FETCH_ASSOC);
    
    // Obtener egresos de la semana actual
    $semana_actual = date('Y-m-d', strtotime('monday this week'));
    $sql_semana = "SELECT COALESCE(SUM(monto), 0) as total FROM egresos WHERE DATE(fecha) >= ? AND estado = 'pagado'";
    $stmt_semana = $db->prepare($sql_semana);
    $stmt_semana->execute([$semana_actual]);
    $egresos_semana = $stmt_semana->fetch(PDO::FETCH_ASSOC);
    
    // Obtener egresos del mes actual
    $mes_actual = date('Y-m-01');
    $sql_mes = "SELECT COALESCE(SUM(monto), 0) as total FROM egresos WHERE DATE(fecha) >= ? AND estado = 'pagado'";
    $stmt_mes = $db->prepare($sql_mes);
    $stmt_mes->execute([$mes_actual]);
    $egresos_mes = $stmt_mes->fetch(PDO::FETCH_ASSOC);
    
    // Obtener total de egresos pendientes
    $sql_pendientes_total = "SELECT COALESCE(SUM(monto), 0) as total FROM egresos WHERE estado = 'pendiente'";
    $stmt_pendientes_total = $db->query($sql_pendientes_total);
    $total_pendiente = $stmt_pendientes_total->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Obtener últimos egresos registrados
    $sql_ultimos = "SELECT * FROM egresos ORDER BY created_at DESC LIMIT 10";
    $stmt_ultimos = $db->query($sql_ultimos);
    $ultimos_egresos = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener egresos por categoría del mes actual
    $sql_categorias = "
        SELECT 
            categoria,
            SUM(monto) as total,
            COUNT(*) as cantidad,
            ROUND((SUM(monto) / NULLIF((SELECT SUM(monto) FROM egresos WHERE DATE(fecha) >= ? AND estado = 'pagado'), 0)) * 100, 1) as porcentaje
        FROM egresos 
        WHERE DATE(fecha) >= ? AND estado = 'pagado'
        GROUP BY categoria 
        ORDER BY total DESC
    ";
    $stmt_categorias = $db->prepare($sql_categorias);
    $stmt_categorias->execute([$mes_actual, $mes_actual]);
    $egresos_por_categoria = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular total del mes para porcentajes
    $total_mes = $egresos_mes['total'] ?? 0;
    
    // Obtener facturas pendientes
    $sql_pendientes = "
        SELECT * FROM egresos 
        WHERE estado = 'pendiente' 
        ORDER BY fecha ASC
    ";
    $stmt_pendientes = $db->query($sql_pendientes);
    $facturas_pendientes = $stmt_pendientes->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en egresos.php: " . $e->getMessage());
    $error = "Error al cargar datos: " . $e->getMessage();
    // Inicializar variables en caso de error
    $egresos_hoy = ['total' => 0];
    $egresos_semana = ['total' => 0];
    $egresos_mes = ['total' => 0];
    $ultimos_egresos = [];
    $egresos_por_categoria = [];
    $facturas_pendientes = [];
    $total_pendiente = 0;
    $total_mes = 0;
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

// Función para obtener nombre de categoría
function getCategoriaNombre($categoria) {
    $categorias = [
        'peluqueria' => 'Peluquería Canina',
        'servicios_veterinarios' => 'Servicios Veterinarios',
        'encargada' => 'Encargada',
        'leonel' => 'Leonel',
        'logistica' => 'Logística',
        'insumos' => 'Insumos',
        'alquileres' => 'Alquileres',
        'facturas_tienda' => 'Facturas Tienda',
        'eco' => 'Eco',
        'vehiculo' => 'Vehículo',
        'laboratorio' => 'Laboratorio',
        'hogar' => 'Hogar',
        'fondo_ahorro' => 'Fondo de Ahorro',
        'fumigacion' => 'Fumigación'
    ];
    return $categorias[$categoria] ?? $categoria;
}

$page_title = "Contabilización de Egresos - VetNexo";
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

        .btn-primary, .btn-secondary, .btn-success {
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #2E7D32);
            color: var(--white);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
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

        .stat-icon.warning {
            color: var(--warning);
            background: var(--accent-soft);
        }

        .stat-icon.danger {
            color: var(--danger);
            background: #ffebee;
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

        /* Tabs */
        .tabs-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .tabs-header {
            display: flex;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            overflow-x: auto;
        }

        .tab-btn {
            padding: 1rem 2rem;
            background: none;
            border: none;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }

        .tab-btn i {
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }

        .tab-btn:hover {
            color: var(--primary);
            background: rgba(46, 125, 50, 0.05);
        }

        .tab-btn.active {
            color: var(--primary-dark);
            background: var(--white);
            box-shadow: inset 0 -3px 0 var(--primary);
        }

        .tab-content {
            display: none;
            padding: 1.5rem;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Formulario */
        .form-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .form-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .form-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
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

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Tablas */
        .table-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .table-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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

        .data-table tfoot td {
            background: var(--primary-soft);
            font-weight: 600;
            border-top: 2px solid var(--primary-light);
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--gray-light);
            border-radius: 3px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: 3px;
            transition: width 0.3s ease;
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
            
            .page-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs-header {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                flex: 1;
                text-align: center;
                padding: 0.8rem;
            }
            
            .tab-btn i {
                margin-right: 0;
                display: block;
                margin-bottom: 0.3rem;
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
                        <i class='bx bx-credit-card'></i>
                        Contabilización de Egresos
                    </h1>
                    <p>
                        <i class='bx bx-line-chart-down'></i>
                        Control de gastos y facturas pendientes
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
                <button class="btn-secondary btn-sm" onclick="exportarDatos()">
                    <i class='bx bx-download'></i>
                    Exportar Datos
                </button>
                <a href="dashboard.php" class="btn-primary btn-sm">
                    <i class='bx bx-arrow-back'></i>
                    Volver al Dashboard
                </a>
            </div>

            <!-- Mensajes de sesión -->
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

            <!-- Estadísticas Principales -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar-check'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($egresos_hoy['total'], 2); ?></div>
                        <div class="stat-label">Egresos Hoy</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class='bx bx-calendar-week'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($egresos_semana['total'], 2); ?></div>
                        <div class="stat-label">Esta Semana</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($egresos_mes['total'], 2); ?></div>
                        <div class="stat-label">Este Mes</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon danger">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($total_pendiente, 2); ?></div>
                        <div class="stat-label">Total Pendiente</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs-section fade-in">
                <div class="tabs-header">
                    <button class="tab-btn active" onclick="cambiarTab('registro')">
                        <i class='bx bx-plus-circle'></i> Registrar Egreso
                    </button>
                    <button class="tab-btn" onclick="cambiarTab('pendientes')">
                        <i class='bx bx-time'></i> Facturas Pendientes
                        <?php if (count($facturas_pendientes) > 0): ?>
                            <span class="badge badge-danger" style="margin-left: 0.5rem;"><?php echo count($facturas_pendientes); ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="tab-btn" onclick="cambiarTab('analisis')">
                        <i class='bx bx-pie-chart-alt'></i> Análisis por Categoría
                    </button>
                    <button class="tab-btn" onclick="cambiarTab('historial')">
                        <i class='bx bx-history'></i> Historial de Egresos
                    </button>
                </div>

                <!-- Tab: Registrar Egreso -->
                <div id="tab-registro" class="tab-content active">
                    <div class="form-section">
                        <div class="form-header">
                            <h3>
                                <i class='bx bx-plus-circle'></i>
                                Registrar Nuevo Egreso
                            </h3>
                        </div>
                        <div class="form-body">
                            <form method="POST" action=""><?php echo av_csrf_field(); ?>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Fecha</label>
                                        <input type="date" class="form-control" name="fecha" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Categoría</label>
                                        <select class="form-control" name="categoria" required>
                                            <option value="peluqueria">Peluquería Canina</option>
                                            <option value="servicios_veterinarios">Servicios Veterinarios</option>
                                            <option value="encargada">Encargada</option>
                                            <option value="leonel">Leonel</option>
                                            <option value="logistica">Logística</option>
                                            <option value="insumos">Insumos</option>
                                            <option value="alquileres">Alquileres</option>
                                            <option value="facturas_tienda">Facturas Tienda</option>
                                            <option value="eco">Eco</option>
                                            <option value="vehiculo">Vehículo</option>
                                            <option value="laboratorio">Laboratorio</option>
                                            <option value="hogar">Hogar</option>
                                            <option value="fondo_ahorro">Fondo de Ahorro</option>
                                            <option value="fumigacion">Fumigación</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Proveedor/Beneficiario</label>
                                        <input type="text" class="form-control" name="proveedor" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Monto ($)</label>
                                        <input type="number" class="form-control" name="monto" step="0.01" min="0" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Método de Pago</label>
                                        <select class="form-control" name="metodo_pago" required>
                                            <option value="efectivo">Efectivo</option>
                                            <option value="transferencia">Transferencia</option>
                                            <option value="tarjeta">Tarjeta</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">N° Factura/Comprobante</label>
                                        <input type="text" class="form-control" name="numero_factura">
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Descripción</label>
                                        <textarea class="form-control" name="descripcion" rows="3" required></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Estado</label>
                                        <select class="form-control" name="estado">
                                            <option value="pagado">Pagado</option>
                                            <option value="pendiente">Pendiente</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <button type="submit" name="registrar_egreso" class="btn-primary btn-sm" style="margin-top: 1rem;">
                                    <i class='bx bx-save'></i>
                                    Registrar Egreso
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Tab: Facturas Pendientes -->
                <div id="tab-pendientes" class="tab-content">
                    <div class="table-section">
                        <div class="table-header">
                            <h3>
                                <i class='bx bx-time'></i>
                                Facturas Pendientes de Pago
                            </h3>
                            <?php if (!empty($facturas_pendientes)): ?>
                                <div style="display: flex; gap: 1rem; align-items: center;">
                                    <span class="badge badge-danger">Total Pendiente: $<?php echo number_format($total_pendiente, 2); ?></span>
                                    <form method="POST" action="" style="display: inline;"><?php echo av_csrf_field(); ?>
                                        <input type="hidden" name="marcar_todas_pagadas" value="1">
                                        <button type="submit" class="btn-primary btn-sm" onclick="return confirm('¿Está seguro de que desea marcar todas las facturas como pagadas?')">
                                            <i class='bx bx-check-double'></i>
                                            Marcar Todas Pagadas
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($facturas_pendientes)): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Vencimiento</th>
                                            <th>Proveedor</th>
                                            <th>Categoría</th>
                                            <th>Concepto</th>
                                            <th>Monto</th>
                                            <th>Días Venc.</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($facturas_pendientes as $factura): 
                                            $dias_vencimiento = floor((time() - strtotime($factura['fecha'])) / (60 * 60 * 24));
                                        ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($factura['fecha'])); ?></td>
                                                <td><?php echo htmlspecialchars($factura['proveedor']); ?></td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo getCategoriaNombre($factura['categoria']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($factura['descripcion']); ?></td>
                                                <td><strong>$<?php echo number_format($factura['monto'], 2); ?></strong></td>
                                                <td>
                                                    <?php if ($dias_vencimiento > 0): ?>
                                                        <span class="badge badge-danger"><?php echo $dias_vencimiento; ?> días</span>
                                                    <?php elseif ($dias_vencimiento == 0): ?>
                                                        <span class="badge badge-warning">Hoy</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-success"><?php echo abs($dias_vencimiento); ?> días</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="POST" action="" style="display: inline;"><?php echo av_csrf_field(); ?>
                                                        <input type="hidden" name="pagar_factura" value="1">
                                                        <input type="hidden" name="egreso_id" value="<?php echo $factura['id']; ?>">
                                                        <button type="submit" class="btn-primary btn-sm" onclick="return confirm('¿Marcar esta factura como pagada?')">
                                                            <i class='bx bx-check'></i> Pagar
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="4" style="text-align: right; font-weight: bold;">Total Pendiente:</td>
                                            <td colspan="3" style="font-weight: bold; font-size: 1.1rem;">$<?php echo number_format($total_pendiente, 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-check-circle'></i>
                                <h3>No hay facturas pendientes</h3>
                                <p>Todas las facturas están al día.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Análisis por Categoría -->
                <div id="tab-analisis" class="tab-content">
                    <div class="table-section">
                        <div class="table-header">
                            <h3>
                                <i class='bx bx-pie-chart-alt'></i>
                                Distribución de Egresos por Categoría (Este Mes)
                            </h3>
                        </div>
                        
                        <?php if (!empty($egresos_por_categoria) && $total_mes > 0): ?>
                            <div class="stats-grid" style="padding: 1.5rem;">
                                <?php foreach ($egresos_por_categoria as $categoria): ?>
                                    <div class="stat-card" style="padding: 1rem;">
                                        <div class="stat-header">
                                            <div class="stat-icon" style="width: 40px; height: 40px; font-size: 1.2rem;">
                                                <i class='bx bx-category'></i>
                                            </div>
                                            <span class="badge badge-primary"><?php echo $categoria['cantidad']; ?> registros</span>
                                        </div>
                                        <div class="stat-number" style="font-size: 1.3rem;">$<?php echo number_format($categoria['total'], 2); ?></div>
                                        <div class="stat-label" style="font-size: 0.8rem;"><?php echo getCategoriaNombre($categoria['categoria']); ?></div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $categoria['porcentaje']; ?>%"></div>
                                        </div>
                                        <div style="font-size: 0.8rem; color: var(--text-light); text-align: center; margin-top: 0.5rem;">
                                            <?php echo $categoria['porcentaje']; ?>% del total
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-pie-chart'></i>
                                <h3>No hay datos para mostrar</h3>
                                <p>Los análisis por categoría aparecerán cuando registres egresos este mes.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Historial de Egresos -->
                <div id="tab-historial" class="tab-content">
                    <div class="table-section">
                        <div class="table-header">
                            <h3>
                                <i class='bx bx-history'></i>
                                Últimos Egresos Registrados
                            </h3>
                        </div>
                        
                        <?php if (!empty($ultimos_egresos)): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Categoría</th>
                                            <th>Proveedor</th>
                                            <th>Descripción</th>
                                            <th>Monto</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ultimos_egresos as $egreso): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($egreso['fecha'])); ?></td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo getCategoriaNombre($egreso['categoria']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($egreso['proveedor']); ?></td>
                                                <td><?php echo htmlspecialchars($egreso['descripcion']); ?></td>
                                                <td><strong>$<?php echo number_format($egreso['monto'], 2); ?></strong></td>
                                                <td>
                                                    <span class="badge <?php echo $egreso['estado'] == 'pagado' ? 'badge-success' : 'badge-warning'; ?>">
                                                        <?php echo $egreso['estado'] == 'pagado' ? 'Pagado' : 'Pendiente'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-info-circle'></i>
                                <h3>No hay egresos registrados</h3>
                                <p>Los egresos aparecerán aquí una vez que los registres.</p>
                            </div>
                        <?php endif; ?>
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

    <script src="js/sidebar.js"></script>
    <script>
        // Función para cambiar entre pestañas
        function cambiarTab(tabId) {
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Desactivar todas las pestañas
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Activar la pestaña seleccionada
            document.getElementById('tab-' + tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Función para exportar datos
        function exportarDatos() {
            // Crear un enlace para descargar
            const fecha = new Date().toISOString().split('T')[0];
            window.location.href = 'exportar_egresos.php';
        }

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de estadísticas
            animateStats();
            
            // Crear partículas
            createParticles();
        });

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
    </script>
</body>
</html>