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
    
    // Obtener facturas pendientes con información completa
    $facturas_sql = "
        SELECT 
            f.*,
            c.nombre as cliente_nombre,
            c.telefono as cliente_telefono,
            c.email as cliente_email,
            c.id as cliente_id,
            m.nombre as mascota_nombre,
            m.id as mascota_id,
            DATEDIFF(f.fecha_vencimiento, CURDATE()) as dias_vencimiento,
            CASE 
                WHEN f.estado = 'pending' AND f.fecha_vencimiento < CURDATE() THEN 'overdue'
                WHEN f.estado = 'pending' AND f.fecha_vencimiento >= CURDATE() THEN 'pending'
                WHEN f.estado = 'partial' THEN 'partial'
                ELSE f.estado
            END as estado_real,
            CASE 
                WHEN f.estado = 'pending' AND f.fecha_vencimiento < CURDATE() THEN TRUE
                ELSE FALSE
            END as is_overdue
        FROM facturas f
        LEFT JOIN clientes c ON f.cliente_id = c.id
        LEFT JOIN mascotas m ON f.mascota_id = m.id
        WHERE f.estado IN ('pending', 'partial')
        ORDER BY 
            CASE 
                WHEN f.fecha_vencimiento < CURDATE() THEN 0
                ELSE 1
            END,
            f.fecha_vencimiento ASC,
            f.fecha_emision DESC
    ";
    
    $facturas_stmt = $db->query($facturas_sql);
    $facturas = $facturas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas de facturas pendientes
    $stats_sql = "
        SELECT 
            COUNT(*) as total_pendientes,
            SUM(CASE WHEN estado = 'pending' AND fecha_vencimiento >= CURDATE() THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN estado = 'pending' AND fecha_vencimiento < CURDATE() THEN 1 ELSE 0 END) as vencidas,
            SUM(CASE WHEN estado = 'partial' THEN 1 ELSE 0 END) as pago_parcial,
            COALESCE(SUM(CASE WHEN estado IN ('pending', 'partial') THEN total - COALESCE(pagado, 0) ELSE 0 END), 0) as monto_total_pendiente,
            COALESCE(SUM(CASE WHEN estado = 'pending' AND fecha_vencimiento < CURDATE() THEN total - COALESCE(pagado, 0) ELSE 0 END), 0) as monto_vencido,
            MAX(fecha_vencimiento) as fecha_mas_antigua,
            MIN(fecha_vencimiento) as fecha_mas_reciente
        FROM facturas
        WHERE estado IN ('pending', 'partial')
    ";
    
    $stats_stmt = $db->query($stats_sql);
    $estadisticas = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Clientes con deudas
    $clientes_deuda_sql = "
        SELECT 
            c.id,
            c.nombre,
            c.email,
            c.telefono,
            COUNT(f.id) as facturas_pendientes,
            SUM(f.total - COALESCE(f.pagado, 0)) as total_deuda,
            MAX(f.fecha_vencimiento) as ultimo_vencimiento
        FROM clientes c
        JOIN facturas f ON c.id = f.cliente_id
        WHERE f.estado IN ('pending', 'partial')
        GROUP BY c.id, c.nombre, c.email, c.telefono
        ORDER BY total_deuda DESC
        LIMIT 5
    ";
    
    $clientes_stmt = $db->query($clientes_deuda_sql);
    $clientes_deuda = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Métodos de pago para el modal
    $metodos_pago_sql = "SELECT * FROM metodos_pago_config WHERE activo = 1 ORDER BY orden, nombre";
    $metodos_stmt = $db->query($metodos_pago_sql);
    $metodos_pago = $metodos_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $facturas = [];
    $estadisticas = [
        'total_pendientes' => 0,
        'pendientes' => 0,
        'vencidas' => 0,
        'pago_parcial' => 0,
        'monto_total_pendiente' => 0,
        'monto_vencido' => 0
    ];
    $clientes_deuda = [];
    $error_db = "Error al cargar datos: " . $e->getMessage();
}

// Función para obtener color del rol (reutilizada de dashboard.php)
function getRolColor($rol) {
    switch ($rol) {
        case 'admin':
            return '#F44336';
        case 'veterinario':
            return '#2196F3';
        case 'recepcionista':
            return '#FF9800';
        default:
            return '#4CAF50';
    }
}

// Función para obtener texto del rol (reutilizada de dashboard.php)
function getRolTexto($rol) {
    switch ($rol) {
        case 'admin':
            return 'Administrador';
        case 'veterinario':
            return 'Veterinario';
        case 'recepcionista':
            return 'Recepcionista';
        default:
            return 'Usuario';
    }
}

// Función para formatear fecha
function formatDateToDisplay($date) {
    if (!$date) return 'N/A';
    $dateObj = new DateTime($date);
    return $dateObj->format('d/m/Y');
}

// Función para obtener clase de estado
function getStatusClass($estado) {
    switch ($estado) {
        case 'pending':
            return 'badge-warning';
        case 'overdue':
            return 'badge-danger';
        case 'partial':
            return 'badge-info';
        default:
            return 'badge-secondary';
    }
}

// Función para obtener texto de estado
function getStatusText($estado) {
    switch ($estado) {
        case 'pending':
            return 'Pendiente';
        case 'overdue':
            return 'Vencida';
        case 'partial':
            return 'Pago Parcial';
        default:
            return ucfirst($estado);
    }
}

$page_title = "Facturas Pendientes - VetNexo";
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
        }

        .btn-primary, .btn-secondary {
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
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
            cursor: pointer;
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

        .stat-card.purple::before {
            background: linear-gradient(90deg, var(--purple), #BA68C8);
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

        .stat-card.purple .stat-icon {
            background: #f3e5f5;
            color: var(--purple);
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

        .stat-card.purple .stat-number {
            color: var(--purple);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filters-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-header h3 i {
            color: var(--accent);
        }

        .filters-toggle {
            background: var(--white);
            border: 1px solid var(--primary-light);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .filters-toggle:hover {
            background: var(--primary-soft);
        }

        .filters-content {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .filter-select, .filter-input {
            padding: 0.6rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            background: var(--white);
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Tabla de Facturas */
        .invoices-container {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .invoices-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .invoices-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .invoices-header h3 i {
            color: var(--accent);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .invoices-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .invoices-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 1px solid var(--gray);
            font-size: 0.9rem;
        }

        .invoices-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .invoices-table tr:hover {
            background: var(--gray-light);
        }

        .client-cell {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .client-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        .badge-secondary {
            background: var(--gray);
            color: var(--text-light);
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

        .btn-icon.primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-icon.success {
            background: #d4edda;
            color: var(--success);
        }

        .btn-icon.warning {
            background: var(--accent-soft);
            color: var(--warning);
        }

        .btn-icon.danger {
            background: #ffebee;
            color: var(--danger);
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        /* Paginación */
        .pagination {
            padding: 1.5rem;
            border-top: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray);
            background: var(--white);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .pagination-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .pagination-btn:hover:not(.active) {
            background: var(--primary-soft);
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
            max-width: 500px;
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

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        /* Clientes deuda card */
        .clientes-deuda-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .clientes-deuda-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .clientes-deuda-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cliente-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cliente-item:last-child {
            border-bottom: none;
        }

        .cliente-info h4 {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 0.3rem;
        }

        .cliente-info p {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cliente-deuda {
            text-align: right;
        }

        .cliente-deuda .monto {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--danger);
        }

        .cliente-deuda .facturas {
            font-size: 0.75rem;
            color: var(--text-light);
        }

        /* Grid secundario */
        .secondary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
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
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
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
            
            .filters-content {
                grid-template-columns: 1fr;
            }
            
            .pagination {
                flex-direction: column;
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
                        <i class='bx bx-receipt'></i>
                        Facturas Pendientes
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestión de cobranza
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
            <div class="page-actions" style="justify-content: flex-end;">
                <button class="btn-secondary btn-sm" onclick="exportPendingInvoices()">
                    <i class='bx bx-download'></i>
                    Exportar
                </button>
                <button class="btn-primary btn-sm" onclick="sendReminders()">
                    <i class='bx bx-envelope'></i>
                    Enviar Recordatorios
                </button>
            </div>

            <!-- Stats Cards -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card" onclick="filterByStatus('all')">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-receipt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_pendientes'] ?? 0); ?></div>
                        <div class="stat-label">Total Pendientes</div>
                    </div>

                    <div class="stat-card warning" onclick="filterByStatus('pending')">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['pendientes'] ?? 0); ?></div>
                        <div class="stat-label">Pendientes</div>
                    </div>

                    <div class="stat-card danger" onclick="filterByStatus('overdue')">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['vencidas'] ?? 0); ?></div>
                        <div class="stat-label">Vencidas</div>
                    </div>

                    <div class="stat-card purple" onclick="filterByAmount('high')">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['monto_total_pendiente'] ?? 0, 2); ?></div>
                        <div class="stat-label">Monto Total Pendiente</div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters-section">
                <div class="filters-header">
                    <h3>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Búsqueda
                    </h3>
                    <button class="filters-toggle" onclick="toggleFilters()">
                        <i class='bx bx-filter-alt'></i>
                        Mostrar Filtros
                    </button>
                </div>
                <div class="filters-content" id="filtersContent" style="display: none;">
                    <div class="filter-group">
                        <label class="filter-label">Estado</label>
                        <select class="filter-select" id="statusFilter">
                            <option value="all">Todos los estados</option>
                            <option value="pending">Pendiente</option>
                            <option value="overdue">Vencida</option>
                            <option value="partial">Pago Parcial</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Rango de Fechas</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <input type="date" class="filter-input" id="startDate">
                            <input type="date" class="filter-input" id="endDate">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Cliente</label>
                        <input type="text" class="filter-input" id="clientFilter" placeholder="Buscar cliente...">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Monto Mínimo</label>
                        <input type="number" class="filter-input" id="minAmount" placeholder="Monto mínimo">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Ordenar por</label>
                        <select class="filter-select" id="sortFilter">
                            <option value="date-desc">Fecha (Más reciente)</option>
                            <option value="date-asc">Fecha (Más antigua)</option>
                            <option value="amount-desc">Monto (Mayor a menor)</option>
                            <option value="amount-asc">Monto (Menor a mayor)</option>
                            <option value="client">Cliente A-Z</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Grid Principal y Secundario -->
            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr;">
                <!-- Tabla de Facturas Pendientes -->
                <div class="invoices-container">
                    <div class="invoices-header">
                        <h3>
                            <i class='bx bx-list-ul'></i>
                            Facturas Pendientes de Pago
                        </h3>
                        <div class="invoices-actions">
                            <button class="btn-secondary btn-sm" onclick="printPendingInvoices()">
                                <i class='bx bx-printer'></i>
                                Imprimir
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="invoices-table">
                            <thead>
                                <tr>
                                    <th>N° Factura</th>
                                    <th>Cliente / Mascota</th>
                                    <th>Emisión</th>
                                    <th>Vencimiento</th>
                                    <th>Monto Total</th>
                                    <th>Saldo</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="invoicesTableBody">
                                <?php if (!empty($facturas)): ?>
                                    <?php foreach ($facturas as $factura): 
                                        $estado_real = $factura['estado_real'] ?? $factura['estado'];
                                        $is_overdue = $factura['is_overdue'] ?? false;
                                        $saldo = $factura['total'] - ($factura['pagado'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($factura['numero_factura'] ?? 'FAC-' . str_pad($factura['id'], 6, '0', STR_PAD_LEFT)); ?></strong>
                                        </td>
                                        <td class="client-cell">
                                            <div class="client-avatar">
                                                <?php echo strtoupper(substr($factura['cliente_nombre'] ?? 'C', 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'Cliente'); ?></div>
                                                <div style="font-size: 0.8rem; color: var(--text-lighter);">
                                                    <?php echo htmlspecialchars($factura['mascota_nombre'] ?? 'Sin mascota'); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo formatDateToDisplay($factura['fecha_emision']); ?></td>
                                        <td style="<?php echo $is_overdue ? 'color: var(--danger); font-weight: 600;' : ''; ?>">
                                            <?php echo formatDateToDisplay($factura['fecha_vencimiento']); ?>
                                            <?php if ($is_overdue): ?>
                                                <br><small style="font-size: 0.7rem;">Vencida</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>$<?php echo number_format($factura['total'], 2); ?></td>
                                        <td><strong>$<?php echo number_format($saldo, 2); ?></strong></td>
                                        <td>
                                            <span class="badge <?php echo getStatusClass($estado_real); ?>">
                                                <?php echo getStatusText($estado_real); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon primary" title="Ver factura" onclick="viewInvoice('<?php echo $factura['id']; ?>')">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <button class="btn-icon success" title="Registrar pago" onclick="openPaymentModal('<?php echo $factura['id']; ?>')">
                                                    <i class='bx bx-credit-card'></i>
                                                </button>
                                                <button class="btn-icon warning" title="Enviar recordatorio" onclick="sendReminder('<?php echo $factura['id']; ?>')">
                                                    <i class='bx bx-envelope'></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-light);">
                                            <i class='bx bx-check-circle' style="font-size: 3rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                                            <p>No hay facturas pendientes de pago</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <div class="pagination">
                        <div class="pagination-info">
                            Mostrando <span id="paginationStart">1</span>-<span id="paginationEnd"><?php echo min(10, count($facturas)); ?></span> de <span id="paginationTotal"><?php echo count($facturas); ?></span> facturas pendientes
                        </div>
                        <div class="pagination-controls">
                            <button class="pagination-btn" onclick="changePage('prev')">
                                <i class='bx bx-chevron-left'></i>
                            </button>
                            <button class="pagination-btn active">1</button>
                            <?php if (count($facturas) > 10): ?>
                            <button class="pagination-btn">2</button>
                            <button class="pagination-btn">3</button>
                            <?php endif; ?>
                            <button class="pagination-btn" onclick="changePage('next')">
                                <i class='bx bx-chevron-right'></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Derecho con información adicional -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <!-- Clientes con mayor deuda -->
                    <div class="clientes-deuda-card">
                        <div class="clientes-deuda-header">
                            <h3>
                                <i class='bx bx-trending-up'></i>
                                Clientes con Mayor Deuda
                            </h3>
                        </div>
                        <div>
                            <?php if (!empty($clientes_deuda)): ?>
                                <?php foreach ($clientes_deuda as $cliente): ?>
                                <div class="cliente-item">
                                    <div class="cliente-info">
                                        <h4><?php echo htmlspecialchars($cliente['nombre']); ?></h4>
                                        <p>
                                            <i class='bx bx-phone'></i> <?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?>
                                            • <?php echo $cliente['facturas_pendientes']; ?> factura(s)
                                        </p>
                                    </div>
                                    <div class="cliente-deuda">
                                        <div class="monto">$<?php echo number_format($cliente['total_deuda'], 2); ?></div>
                                        <div class="facturas">Vence: <?php echo formatDateToDisplay($cliente['ultimo_vencimiento']); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                                    <i class='bx bx-smile' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                                    <p>No hay clientes con deudas</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Resumen de cobranza -->
                    <div class="clientes-deuda-card">
                        <div class="clientes-deuda-header">
                            <h3>
                                <i class='bx bx-pie-chart-alt-2'></i>
                                Resumen de Cobranza
                            </h3>
                        </div>
                        <div style="padding: 1.5rem;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                                <span>Monto vencido:</span>
                                <span style="font-weight: 700; color: var(--danger);">$<?php echo number_format($estadisticas['monto_vencido'] ?? 0, 2); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                                <span>Facturas por vencer:</span>
                                <span style="font-weight: 700; color: var(--warning);"><?php echo number_format($estadisticas['pendientes'] ?? 0); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                                <span>Pagos parciales:</span>
                                <span style="font-weight: 700; color: var(--info);"><?php echo number_format($estadisticas['pago_parcial'] ?? 0); ?></span>
                            </div>
                            <div style="background: var(--gray-light); height: 8px; border-radius: 4px; margin: 1rem 0; overflow: hidden;">
                                <?php 
                                $total_facturas = $estadisticas['total_pendientes'] ?? 1;
                                $porcentaje_vencido = ($estadisticas['vencidas'] ?? 0) / $total_facturas * 100;
                                ?>
                                <div style="width: <?php echo $porcentaje_vencido; ?>%; height: 100%; background: var(--danger);"></div>
                            </div>
                            <div style="text-align: center; font-size: 0.85rem; color: var(--text-light);">
                                <?php echo number_format($estadisticas['vencidas'] ?? 0); ?> de <?php echo $total_facturas; ?> facturas vencidas (<?php echo number_format($porcentaje_vencido, 1); ?>%)
                            </div>
                        </div>
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

    <!-- Modal de Pago -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class='bx bx-credit-card'></i>
                    Registrar Pago
                </h3>
                <button class="modal-close" onclick="closePaymentModal()">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Factura</label>
                    <input type="text" class="form-input" id="modalInvoiceNumber" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <input type="text" class="form-input" id="modalClientName" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Saldo Pendiente</label>
                    <input type="text" class="form-input" id="modalPendingAmount" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Monto a Pagar</label>
                    <input type="number" class="form-input" id="paymentAmount" placeholder="Ingrese el monto" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Método de Pago</label>
                    <select class="form-select" id="paymentMethod">
                        <option value="">Seleccionar método</option>
                        <?php if (!empty($metodos_pago)): ?>
                            <?php foreach ($metodos_pago as $metodo): ?>
                                <option value="<?php echo htmlspecialchars($metodo['tipo']); ?>"><?php echo htmlspecialchars($metodo['nombre']); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="cash">Efectivo</option>
                            <option value="card">Tarjeta</option>
                            <option value="transfer">Transferencia</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha de Pago</label>
                    <input type="date" class="form-input" id="paymentDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Notas</label>
                    <textarea class="form-textarea" id="paymentNotes" placeholder="Notas adicionales..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closePaymentModal()">Cancelar</button>
                <button class="btn-primary" onclick="processPayment()">Registrar Pago</button>
            </div>
        </div>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Variables globales
        let currentInvoiceId = null;
        let filtersVisible = false;
        let currentPage = 1;
        const itemsPerPage = 10;

        // Datos de facturas (pasados desde PHP)
        const invoicesData = <?php echo json_encode($facturas); ?>;
        const clientsData = <?php echo json_encode($clientes_deuda); ?>;

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar filtros
            initFilters();
            
            // Establecer fechas por defecto
            setDefaultDates();
            
            // Actualizar paginación
            updatePagination(invoicesData.length);
            
            // Animación de contadores
            animateStats();
            
            console.log('Sistema de facturas pendientes inicializado');
        });

        // Establecer fechas por defecto (último mes)
        function setDefaultDates() {
            const today = new Date();
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, today.getDate());
            
            document.getElementById('startDate').value = formatDate(lastMonth);
            document.getElementById('endDate').value = formatDate(today);
        }

        // Formatear fecha para input
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

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
                            stat.textContent = Math.floor(current).toLocaleString();
                        }
                    }, 30);
                }
            });
        }

        // Toggle filtros
        function toggleFilters() {
            const filtersContent = document.getElementById('filtersContent');
            const filtersToggle = document.querySelector('.filters-toggle');
            
            if (filtersVisible) {
                filtersContent.style.display = 'none';
                filtersToggle.innerHTML = '<i class="bx bx-filter-alt"></i> Mostrar Filtros';
            } else {
                filtersContent.style.display = 'grid';
                filtersToggle.innerHTML = '<i class="bx bx-filter-alt"></i> Ocultar Filtros';
            }
            
            filtersVisible = !filtersVisible;
        }

        // Inicializar filtros
        function initFilters() {
            const filterInputs = document.querySelectorAll('.filter-select, .filter-input');
            filterInputs.forEach(input => {
                input.addEventListener('change', filterInvoices);
            });
            
            document.getElementById('clientFilter').addEventListener('input', filterInvoices);
        }

        // Filtrar facturas
        function filterInvoices() {
            const status = document.getElementById('statusFilter').value;
            const clientFilter = document.getElementById('clientFilter').value.toLowerCase();
            const minAmount = parseFloat(document.getElementById('minAmount').value) || 0;
            
            const filteredInvoices = invoicesData.filter(invoice => {
                // Filtro por estado
                if (status !== 'all') {
                    const estado_real = invoice.estado_real || invoice.estado;
                    if (estado_real !== status) return false;
                }
                
                // Filtro por cliente
                if (clientFilter && !(invoice.cliente_nombre || '').toLowerCase().includes(clientFilter)) {
                    return false;
                }
                
                // Filtro por monto
                const saldo = invoice.total - (invoice.pagado || 0);
                if (minAmount > 0 && saldo < minAmount) return false;
                
                return true;
            });
            
            renderFilteredInvoices(filteredInvoices);
            updatePagination(filteredInvoices.length);
        }

        // Renderizar facturas filtradas
        function renderFilteredInvoices(invoices) {
            const tableBody = document.getElementById('invoicesTableBody');
            
            if (invoices.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-light);">
                            <i class='bx bx-search' style="font-size: 3rem; margin-bottom: 0.5rem;"></i>
                            <p>No se encontraron facturas con los filtros aplicados</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            // Paginación
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const paginatedInvoices = invoices.slice(start, end);
            
            let html = '';
            paginatedInvoices.forEach(invoice => {
                const estado_real = invoice.estado_real || invoice.estado;
                const is_overdue = invoice.is_overdue || false;
                const saldo = invoice.total - (invoice.pagado || 0);
                
                html += `
                    <tr>
                        <td><strong>${invoice.numero_factura || 'FAC-' + String(invoice.id).padStart(6, '0')}</strong></td>
                        <td class="client-cell">
                            <div class="client-avatar">${(invoice.cliente_nombre || 'C').charAt(0).toUpperCase()}</div>
                            <div>
                                <div style="font-weight: 600;">${invoice.cliente_nombre || 'Cliente'}</div>
                                <div style="font-size: 0.8rem; color: var(--text-lighter);">${invoice.mascota_nombre || 'Sin mascota'}</div>
                            </div>
                        </td>
                        <td>${formatDisplayDate(invoice.fecha_emision)}</td>
                        <td style="${is_overdue ? 'color: var(--danger); font-weight: 600;' : ''}">
                            ${formatDisplayDate(invoice.fecha_vencimiento)}
                            ${is_overdue ? '<br><small style="font-size: 0.7rem;">Vencida</small>' : ''}
                        </td>
                        <td>$${invoice.total.toFixed(2)}</td>
                        <td><strong>$${saldo.toFixed(2)}</strong></td>
                        <td>
                            <span class="badge ${getStatusClass(estado_real)}">
                                ${getStatusText(estado_real)}
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon primary" title="Ver factura" onclick="viewInvoice('${invoice.id}')">
                                    <i class='bx bx-show'></i>
                                </button>
                                <button class="btn-icon success" title="Registrar pago" onclick="openPaymentModal('${invoice.id}')">
                                    <i class='bx bx-credit-card'></i>
                                </button>
                                <button class="btn-icon warning" title="Enviar recordatorio" onclick="sendReminder('${invoice.id}')">
                                    <i class='bx bx-envelope'></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tableBody.innerHTML = html;
        }

        // Formatear fecha para mostrar
        function formatDisplayDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('es-ES', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        }

        // Obtener clase de estado
        function getStatusClass(status) {
            const classes = {
                'pending': 'badge-warning',
                'overdue': 'badge-danger',
                'partial': 'badge-info'
            };
            return classes[status] || 'badge-secondary';
        }

        // Obtener texto de estado
        function getStatusText(status) {
            const texts = {
                'pending': 'Pendiente',
                'overdue': 'Vencida',
                'partial': 'Pago Parcial'
            };
            return texts[status] || status;
        }

        // Filtrar por estado
        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            filterInvoices();
        }

        // Filtrar por monto
        function filterByAmount(range) {
            showNotification(`Filtrando por rango de montos: ${range}`, 'info');
        }

        // Actualizar paginación
        function updatePagination(total) {
            const start = (currentPage - 1) * itemsPerPage + 1;
            const end = Math.min(currentPage * itemsPerPage, total);
            
            document.getElementById('paginationStart').textContent = start;
            document.getElementById('paginationEnd').textContent = end;
            document.getElementById('paginationTotal').textContent = total;
        }

        // Cambiar página
        function changePage(direction) {
            const total = parseInt(document.getElementById('paginationTotal').textContent);
            const maxPages = Math.ceil(total / itemsPerPage);
            
            if (direction === 'prev' && currentPage > 1) {
                currentPage--;
            } else if (direction === 'next' && currentPage < maxPages) {
                currentPage++;
            }
            
            filterInvoices();
        }

        // Ver factura
        function viewInvoice(invoiceId) {
            window.location.href = `verfactura.php?id=${invoiceId}`;
        }

        // Abrir modal de pago
        function openPaymentModal(invoiceId) {
            const invoice = invoicesData.find(inv => inv.id == invoiceId);
            if (!invoice) {
                showNotification('Factura no encontrada', 'error');
                return;
            }
            
            currentInvoiceId = invoiceId;
            const saldo = invoice.total - (invoice.pagado || 0);
            
            document.getElementById('modalInvoiceNumber').value = invoice.numero_factura || 'FAC-' + String(invoice.id).padStart(6, '0');
            document.getElementById('modalClientName').value = invoice.cliente_nombre || 'Cliente';
            document.getElementById('modalPendingAmount').value = `$${saldo.toFixed(2)}`;
            document.getElementById('paymentAmount').value = saldo.toFixed(2);
            document.getElementById('paymentAmount').max = saldo;
            
            document.getElementById('paymentModal').style.display = 'flex';
        }

        // Cerrar modal de pago
        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
            currentInvoiceId = null;
        }

        // Procesar pago
        function processPayment() {
            const amount = parseFloat(document.getElementById('paymentAmount').value);
            const method = document.getElementById('paymentMethod').value;
            const date = document.getElementById('paymentDate').value;
            const notes = document.getElementById('paymentNotes').value;
            
            if (!amount || amount <= 0) {
                showNotification('Por favor, ingrese un monto válido', 'error');
                return;
            }
            
            if (!method) {
                showNotification('Por favor, seleccione un método de pago', 'error');
                return;
            }
            
            const invoice = invoicesData.find(inv => inv.id == currentInvoiceId);
            if (!invoice) {
                showNotification('Factura no encontrada', 'error');
                return;
            }
            
            const saldo = invoice.total - (invoice.pagado || 0);
            if (amount > saldo) {
                showNotification('El monto no puede ser mayor al saldo pendiente', 'error');
                return;
            }
            
            if (confirm(`¿Está seguro de que desea registrar el pago de $${amount.toFixed(2)} para la factura ${invoice.numero_factura}?`)) {
                const processBtn = document.querySelector('#paymentModal .btn-primary');
                const originalText = processBtn.innerHTML;
                
                processBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Procesando...';
                processBtn.disabled = true;
                
                const payload = new URLSearchParams({
                    id: String(currentInvoiceId),
                    amount: amount.toFixed(2),
                    method: method,
                    date: date || '',
                    notes: notes || ''
                });

                fetch('marcar_pagada.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: payload.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'No se pudo registrar el pago');
                    showNotification(data.message || 'Pago registrado exitosamente', 'success');
                    closePaymentModal();
                    setTimeout(() => location.reload(), 500);
                })
                .catch(error => {
                    showNotification(error.message || 'Error al registrar el pago', 'error');
                })
                .finally(() => {
                    processBtn.innerHTML = originalText;
                    processBtn.disabled = false;
                });
            }
        }

        // Enviar recordatorio
        function sendReminder(invoiceId) {
            if (confirm('¿Enviar recordatorio de pago para esta factura?')) {
                showNotification('Recordatorio enviado exitosamente', 'success');
            }
        }

        // Enviar recordatorios masivos
        function sendReminders() {
            if (confirm('¿Enviar recordatorios para todas las facturas pendientes?')) {
                showNotification('Recordatorios enviados exitosamente', 'success');
            }
        }

        // Exportar facturas pendientes
        function exportPendingInvoices() {
            showNotification('Exportando lista de facturas pendientes...', 'info');
            // En producción, aquí se generaría un archivo CSV/Excel
        }

        // Imprimir facturas pendientes
        function printPendingInvoices() {
            window.print();
        }

        // Mostrar notificación
        function showNotification(message, type = 'info') {
            // Crear elemento de notificación
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#F44336' : '#2196F3'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1300;
                animation: slideIn 0.3s ease;
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Cerrar modal al hacer clic fuera
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('paymentModal');
            if (e.target === modal) {
                closePaymentModal();
            }
        });

        // Animaciones CSS adicionales
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>