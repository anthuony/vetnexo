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

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas');

// Incluir configuración de base de datos
include 'config/database.php';

// Parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$estado = $_GET['estado'] ?? '';

// Parámetros de paginación
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$limite = 20; // Facturas por página
$offset = ($pagina_actual - 1) * $limite;

// Construir consulta con filtros
try {
    $db = getDBConnection();
    
    // Obtener información del usuario actual para el avatar
    if ($user_id) {
        $stmt = $db->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $usuario_nombre = $user_data['nombre'];
            $usuario_email = $user_data['email'];
            $usuario_rol = $user_data['rol'];
        }
    }
    
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(f.numero_factura LIKE ? OR c.nombre LIKE ? OR c.documento_identidad LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($fecha_desde)) {
        $where_conditions[] = "DATE(f.fecha_emision) >= ?";
        $params[] = $fecha_desde;
    }
    
    if (!empty($fecha_hasta)) {
        $where_conditions[] = "DATE(f.fecha_emision) <= ?";
        $params[] = $fecha_hasta;
    }
    
    if (!empty($estado)) {
        $where_conditions[] = "f.estado = ?";
        $params[] = $estado;
    }
    
    $where_sql = '';
    if (!empty($where_conditions)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Consulta para contar total de facturas (para paginación)
    $sql_count = "
        SELECT COUNT(DISTINCT f.id) as total
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        $where_sql
    ";
    
    $stmt_count = $db->prepare($sql_count);
    $stmt_count->execute($params);
    $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = ceil($total_registros / $limite);
    
    // Consulta para obtener facturas con paginación
    $sql = "
        SELECT 
            f.*,
            c.nombre as cliente_nombre,
            c.documento_identidad as cliente_identificacion,
            c.telefono as cliente_telefono,
            c.direccion as cliente_direccion,
            u.nombre as usuario_nombre,
            COUNT(d.id) as total_items,
            COALESCE(SUM(d.cantidad * d.precio_unitario), 0) as subtotal_calculado,
            COALESCE(SUM(d.descuento), 0) as total_descuento_calculado,
            COALESCE(SUM(d.iva_item), 0) as iva_calculado,
            COALESCE(SUM(d.subtotal), 0) as total_calculado,
            CASE 
                WHEN f.estado = 'paid' THEN 'Pagada'
                WHEN f.estado = 'pending' THEN 'Pendiente'
                WHEN f.estado = 'cancelled' THEN 'Cancelada'
                ELSE f.estado
            END as estado_texto
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        LEFT JOIN usuarios u ON f.usuario_id = u.id
        LEFT JOIN factura_detalles d ON f.id = d.factura_id
        $where_sql
        GROUP BY f.id
        ORDER BY f.fecha_emision DESC, f.numero_factura DESC
        LIMIT $limite OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas de facturas (últimos 30 días)
    $stats_sql = "
        SELECT 
            COUNT(*) as total_facturas,
            COALESCE(SUM(total), 0) as ingresos_totales,
            COALESCE(AVG(total), 0) as promedio_factura,
            COUNT(CASE WHEN estado = 'paid' THEN 1 END) as facturas_pagadas,
            COUNT(CASE WHEN estado = 'pending' THEN 1 END) as facturas_pendientes,
            COUNT(CASE WHEN estado = 'cancelled' THEN 1 END) as facturas_anuladas
        FROM facturas
        WHERE fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ";
    
    $stats_stmt = $db->query($stats_sql);
    $estadisticas = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Manejar error
    error_log("Error en historial de facturas: " . $e->getMessage());
    $facturas = [];
    $estadisticas = [
        'total_facturas' => 0,
        'ingresos_totales' => 0,
        'promedio_factura' => 0,
        'facturas_pagadas' => 0,
        'facturas_pendientes' => 0,
        'facturas_anuladas' => 0
    ];
    $total_paginas = 1;
    $total_registros = 0;
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
    <title>Historial de Facturas - VetNexo</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
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
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.5s ease;
            position: relative;
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

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Botón Atrás */
        .back-button {
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

        .back-button:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
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

        .btn-success {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--accent-dark), #d88206);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--amarillo-dark));
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--amarillo-dark), #c4b712);
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

        .btn-icon {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: var(--white);
            border: 1px solid var(--gray);
        }

        .btn-icon-primary {
            color: var(--primary);
        }

        .btn-icon-primary:hover {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .btn-icon-success {
            color: var(--accent);
        }

        .btn-icon-success:hover {
            background: var(--accent);
            color: var(--white);
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .btn-icon-danger {
            color: var(--emergency);
        }

        .btn-icon-danger:hover {
            background: var(--emergency);
            color: var(--white);
            border-color: var(--emergency);
            transform: translateY(-2px);
        }

        /* Secciones de Contenido */
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

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .stat-card.success::before {
            background: linear-gradient(90deg, var(--success), var(--primary));
        }

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--accent), var(--warning));
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--emergency), #c00f02);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.success .stat-icon {
            background: #e8f5e9;
            color: var(--primary);
        }

        .stat-card.warning .stat-icon {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-card.danger .stat-icon {
            background: var(--emergency-soft);
            color: var(--emergency);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.success .stat-number {
            color: var(--primary-dark);
        }

        .stat-card.warning .stat-number {
            color: var(--accent-dark);
        }

        .stat-card.danger .stat-number {
            color: var(--emergency);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
            color: var(--text-lighter);
        }

        /* Filtros Section */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .filters-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .filters-body {
            padding: 1.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-control {
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

        .filter-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: #e8f5e9;
            color: var(--primary);
        }

        .badge-warning {
            background: #fff3e0;
            color: var(--accent);
        }

        .badge-danger {
            background: #ffebee;
            color: var(--emergency);
        }

        .badge-info {
            background: #e3f2fd;
            color: var(--azul);
        }

        /* Table Section */
        .table-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
            background: var(--white);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: var(--primary-soft);
        }

        .data-table tbody tr.fade-in {
            animation: fadeInRow 0.3s ease;
        }

        @keyframes fadeInRow {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .action-buttons {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }

        .action-buttons:hover {
            opacity: 1;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: var(--primary-soft);
            border-top: 1px solid var(--gray);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray);
            background: var(--white);
            color: var(--text-dark);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            min-width: 40px;
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        /* Empty State */
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

        .empty-state h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-dark);
            font-size: 1.2rem;
        }

        .empty-state p {
            margin: 0 0 1.5rem 0;
            font-size: 0.9rem;
        }

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Notification */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .notification {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--white);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 400px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
        }

        .notification.error {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
        }

        .notification.warning {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
        }

        .notification.info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
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

        /* Partículas de fondo */
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
                padding: 1.5rem;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .back-button {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .pagination {
                flex-direction: column;
                text-align: center;
            }
            
            .empty-actions {
                flex-direction: column;
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
    </div>

    <div class="notification-container" id="notificationContainer"></div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-receipt'></i>
                        Historial de Facturas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestión y consulta de todas las facturas del sistema
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="dashboard.php" class="back-button">
                        <i class='bx bx-arrow-back'></i> Volver al Dashboard
                    </a>
                    <a href="nuevafactura.php" class="btn btn-primary">
                        <i class='bx bx-plus'></i> Nueva Factura
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

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-receipt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_facturas'] ?? 0); ?></div>
                        <div class="stat-label">Facturas (30 días)</div>
                        <div class="stat-trend">
                            <i class='bx bx-calendar'></i>
                            Último mes
                        </div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-money'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ingresos Totales</div>
                        <div class="stat-trend">
                            <i class='bx bx-trending-up'></i>
                            <?php echo $estadisticas['facturas_pagadas'] ?? 0; ?> pagadas
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['promedio_factura'] ?? 0, 2); ?></div>
                        <div class="stat-label">Promedio por Factura</div>
                        <div class="stat-trend">
                            <i class='bx bx-calculator'></i>
                            Últimos 30 días
                        </div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['facturas_pendientes'] ?? 0; ?></div>
                        <div class="stat-label">Facturas Pendientes</div>
                        <div class="stat-trend">
                            <i class='bx bx-hourglass'></i>
                            Por cobrar
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros de Búsqueda -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Búsqueda
                    </h2>
                </div>
                <div class="filters-body">
                    <form method="GET" action="facturas.php">
                        <div class="filters-grid">
                            <div class="form-group">
                                <label class="form-label">Buscar</label>
                                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="N° factura, cliente, identificación...">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fecha Desde</label>
                                <input type="date" class="form-control" name="fecha_desde" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fecha Hasta</label>
                                <input type="date" class="form-control" name="fecha_hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Estado</label>
                                <select class="form-control select" name="estado">
                                    <option value="">Todos los estados</option>
                                    <option value="paid" <?php echo $estado === 'paid' ? 'selected' : ''; ?>>Pagada</option>
                                    <option value="pending" <?php echo $estado === 'pending' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="cancelled" <?php echo $estado === 'cancelled' ? 'selected' : ''; ?>>Cancelada</option>
                                </select>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-search'></i>
                                Buscar
                            </button>
                            <a href="facturas.php" class="btn btn-secondary">
                                <i class='bx bx-reset'></i>
                                Limpiar Filtros
                            </a>
                            <a href="exportar_facturas.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                                <i class='bx bx-download'></i>
                                Exportar Excel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabla de Facturas -->
            <div class="table-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-list-ul'></i>
                        Lista de Facturas
                    </h2>
                    <div class="pagination-info">
                        Mostrando <?php echo count($facturas); ?> de <?php echo number_format($total_registros); ?> facturas
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>N° Factura</th>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Identificación</th>
                                <th>Items</th>
                                <th>Subtotal</th>
                                <th>IVA</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($facturas)): ?>
                                <?php foreach ($facturas as $index => $factura): ?>
                                    <tr class="fade-in" style="animation-delay: <?php echo $index * 0.05; ?>s">
                                        <td>
                                            <strong><?php echo htmlspecialchars($factura['numero_factura']); ?></strong>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'N/A'); ?></div>
                                            <small style="color: var(--text-light);"><?php echo htmlspecialchars($factura['cliente_telefono'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($factura['cliente_identificacion'] ?? 'N/A'); ?></td>
                                        <td class="text-center"><?php echo $factura['total_items'] ?? 0; ?></td>
                                        <td>$<?php echo number_format($factura['subtotal_calculado'] ?? 0, 2, ',', '.'); ?></td>
                                        <td>$<?php echo number_format($factura['iva_calculado'] ?? 0, 2, ',', '.'); ?></td>
                                        <td>
                                            <strong>$<?php echo number_format($factura['total_calculado'] ?? 0, 2, ',', '.'); ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = 'badge-info';
                                            $estado_text = $factura['estado_texto'] ?? 'Desconocido';
                                            
                                            if ($factura['estado'] === 'paid') {
                                                $badge_class = 'badge-success';
                                            } elseif ($factura['estado'] === 'pending') {
                                                $badge_class = 'badge-warning';
                                            } elseif ($factura['estado'] === 'cancelled') {
                                                $badge_class = 'badge-danger';
                                            }
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo $estado_text; ?></span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-primary" onclick="viewInvoice(<?php echo $factura['id']; ?>)" title="Ver Factura">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-success" onclick="printInvoice(<?php echo $factura['id']; ?>)" title="Imprimir">
                                                    <i class='bx bx-printer'></i>
                                                </button>
                                                <?php if (($factura['estado'] ?? '') === 'pending'): ?>
                                                    <button class="btn-icon btn-icon-success" onclick="markAsPaid(<?php echo $factura['id']; ?>)" title="Marcar como Pagada">
                                                        <i class='bx bx-check'></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (($factura['estado'] ?? '') !== 'cancelled'): ?>
                                                    <button class="btn-icon btn-icon-danger" onclick="cancelInvoice(<?php echo $factura['id']; ?>)" title="Cancelar Factura">
                                                        <i class='bx bx-x'></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class='bx bx-receipt'></i>
                                            <h3>No se encontraron facturas</h3>
                                            <p>No hay facturas que coincidan con los criterios de búsqueda</p>
                                            <div class="empty-actions">
                                                <a href="facturas.php" class="btn btn-secondary">
                                                    <i class='bx bx-reset'></i>
                                                    Limpiar Filtros
                                                </a>
                                                <a href="nuevafactura.php" class="btn btn-primary">
                                                    <i class='bx bx-plus'></i>
                                                    Crear Factura
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación -->
                <?php if (!empty($facturas) && $total_paginas > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> • <?php echo number_format($total_registros); ?> facturas en total
                    </div>
                    <div class="pagination-controls">
                        <?php if ($pagina_actual > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" class="pagination-btn" title="Primera página">
                                <i class='bx bx-chevrons-left'></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" class="pagination-btn" title="Página anterior">
                                <i class='bx bx-chevron-left'></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn" disabled><i class='bx bx-chevrons-left'></i></span>
                            <span class="pagination-btn" disabled><i class='bx bx-chevron-left'></i></span>
                        <?php endif; ?>
                        
                        <?php
                        // Mostrar páginas cercanas
                        $start_page = max(1, $pagina_actual - 2);
                        $end_page = min($total_paginas, $pagina_actual + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <?php if ($i == $pagina_actual): ?>
                                <span class="pagination-btn active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>" class="pagination-btn"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($pagina_actual < $total_paginas): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" class="pagination-btn" title="Página siguiente">
                                <i class='bx bx-chevron-right'></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>" class="pagination-btn" title="Última página">
                                <i class='bx bx-chevrons-right'></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn" disabled><i class='bx bx-chevron-right'></i></span>
                            <span class="pagination-btn" disabled><i class='bx bx-chevrons-right'></i></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
        // Sistema de notificaciones
        function showNotification(message, type = 'info', duration = 5000) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
                <span>${message}</span>
            `;
            
            container.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 10);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, duration);
        }

        // Ver factura en nueva pestaña
        function viewInvoice(invoiceId) {
            window.open(`verfactura.php?id=${invoiceId}`, '_blank');
        }

        // Imprimir factura
        function printInvoice(invoiceId) {
            const printWindow = window.open(`imprimirfactura.php?id=${invoiceId}`, '_blank');
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            };
        }

        // Marcar como pagada
        function markAsPaid(invoiceId) {
            if (confirm('¿Marcar esta factura como pagada?')) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'marcar_pagada.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showNotification('Factura marcada como pagada', 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                showNotification('Error: ' + (response.message || 'Error desconocido'), 'error');
                            }
                        } catch (e) {
                            showNotification('Error al procesar la respuesta', 'error');
                        }
                    } else {
                        showNotification('Error en la solicitud', 'error');
                    }
                };
                
                xhr.onerror = function() {
                    showNotification('Error de conexión', 'error');
                };
                
                xhr.send(`id=${invoiceId}&action=mark_paid`);
            }
        }

        // Cancelar factura
        function cancelInvoice(invoiceId) {
            if (confirm('¿Cancelar esta factura? Esta acción no se puede deshacer.')) {
                const reason = prompt('Motivo de cancelación:');
                if (reason === null) return;
                
                if (!reason.trim()) {
                    showNotification('Debe ingresar un motivo', 'warning');
                    return;
                }
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'cancelar_factura.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showNotification('Factura cancelada', 'warning');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                showNotification('Error: ' + (response.message || 'Error desconocido'), 'error');
                            }
                        } catch (e) {
                            showNotification('Error al procesar la respuesta', 'error');
                        }
                    } else {
                        showNotification('Error en la solicitud', 'error');
                    }
                };
                
                xhr.onerror = function() {
                    showNotification('Error de conexión', 'error');
                };
                
                xhr.send(`id=${invoiceId}&reason=${encodeURIComponent(reason)}&action=cancel`);
            }
        }

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de contadores
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.]/g, ''));
                
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
                            stat.textContent = '$' + current.toFixed(2);
                        } else {
                            stat.textContent = Math.floor(current);
                        }
                    }, 30);
                }
            });

            // Validación de fechas
            const fechaDesde = document.querySelector('input[name="fecha_desde"]');
            const fechaHasta = document.querySelector('input[name="fecha_hasta"]');
            const today = new Date().toISOString().split('T')[0];
            
            if (fechaDesde) fechaDesde.max = today;
            if (fechaHasta) fechaHasta.max = today;
            
            if (fechaDesde && fechaHasta) {
                fechaDesde.addEventListener('change', function() {
                    if (fechaHasta.value && this.value > fechaHasta.value) {
                        fechaHasta.value = this.value;
                        showNotification('Fecha ajustada', 'info');
                    }
                });
                
                fechaHasta.addEventListener('change', function() {
                    if (fechaDesde.value && this.value < fechaDesde.value) {
                        fechaDesde.value = this.value;
                        showNotification('Fecha ajustada', 'info');
                    }
                });
            }

            // Crear partículas dinámicas
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