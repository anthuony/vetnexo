<?php
// tienda/pedidos.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();
require_once '../config/bcv.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=tienda/pedidos.php');
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Cliente';

// Si no hay cliente_id en sesión, intentar obtenerlo
if (!$cliente_id) {
    $stmt = $conn->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $cliente_id = $usuario['cliente_id'] ?? null;
    $_SESSION['cliente_id'] = $cliente_id;
}

// Si no hay cliente_id, redirigir a completar perfil
if (!$cliente_id) {
    header('Location: ../cliente/perfil.php?error=completar_datos');
    exit();
}

// Obtener filtros
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$pagina=max(1,$pagina);
$por_pagina = 10;

// Calcular offset para paginación
$offset = ($pagina - 1) * $por_pagina;

// Construir consulta base
$sql = "SELECT p.*, 
               pg.estado as pago_estado, pg.referencia as pago_referencia,
               pg.monto as pago_monto, pg.fecha_verificacion as pago_fecha_verificacion
        FROM pedidos p
        LEFT JOIN pagos pg ON p.pago_id = pg.id
        WHERE p.cliente_id = ?";

$params = [$cliente_id];

// Aplicar filtro por estado
if (!empty($estado)) {
    $sql .= " AND p.estado = ?";
    $params[] = $estado;
}

// Aplicar filtro por búsqueda (número de pedido)
if (!empty($busqueda)) {
    $sql .= " AND p.numero_pedido LIKE ?";
    $params[] = "%$busqueda%";
}

// Contar total de pedidos para paginación
$sql_count = "SELECT COUNT(*) AS total FROM (".$sql.") AS pedidos_filtrados";
$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute($params);
$total_pedidos = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_pedidos / $por_pagina);

// APLICAR PAGINACIÓN - SIN PLACEHOLDERS PARA LIMIT Y OFFSET
// Los valores se concatenan directamente a la consulta (son enteros seguros)
$sql .= " ORDER BY p.fecha_pedido DESC LIMIT " . (int)$por_pagina . " OFFSET " . (int)$offset;

// Ejecutar consulta con SOLO los parámetros de filtro
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas de pedidos del cliente
$stmt_stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN estado = 'pendiente_pago' THEN 1 ELSE 0 END) as pendientes_pago,
        SUM(CASE WHEN estado = 'pendiente_verificacion' THEN 1 ELSE 0 END) as en_verificacion,
        SUM(CASE WHEN estado IN ('pagado_verificado', 'preparando', 'listo_retiro') THEN 1 ELSE 0 END) as activos,
        SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados,
        SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as cancelados,
        SUM(total) as total_gastado
    FROM pedidos
    WHERE cliente_id = ?
");
$stmt_stats->execute([$cliente_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Asegurar que los valores sean números (evitar NULL)
$stats['total'] = $stats['total'] ?? 0;
$stats['pendientes_pago'] = $stats['pendientes_pago'] ?? 0;
$stats['en_verificacion'] = $stats['en_verificacion'] ?? 0;
$stats['activos'] = $stats['activos'] ?? 0;
$stats['completados'] = $stats['completados'] ?? 0;
$stats['cancelados'] = $stats['cancelados'] ?? 0;
$stats['total_gastado'] = $stats['total_gastado'] ?? 0;

// Tasa de cambio
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);

// Contar items en el carrito
$carrito_count = 0;
if (isset($_SESSION['carrito']) && is_array($_SESSION['carrito'])) {
    $carrito_count = array_sum(array_column($_SESSION['carrito'], 'cantidad'));
}

// Función para obtener clase CSS según estado
function getEstadoClase($estado) {
    switch ($estado) {
        case 'pendiente_pago':
            return 'warning';
        case 'pendiente_verificacion':
            return 'info';
        case 'pagado_verificado':
            return 'success';
        case 'preparando':
            return 'info';
        case 'listo_retiro':
            return 'success';
        case 'completado':
            return 'success';
        case 'cancelado':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Función para obtener texto de estado
function getEstadoTexto($estado) {
    switch ($estado) {
        case 'pendiente_pago':
            return 'Pendiente de Pago';
        case 'pendiente_verificacion':
            return 'En Verificación';
        case 'pagado_verificado':
            return 'Pago Verificado';
        case 'preparando':
            return 'Preparando';
        case 'listo_retiro':
            return 'Listo para Retirar';
        case 'completado':
            return 'Completado';
        case 'cancelado':
            return 'Cancelado';
        default:
            return $estado;
    }
}

// Función para obtener icono de estado
function getEstadoIcono($estado) {
    switch ($estado) {
        case 'pendiente_pago':
            return 'bx-time';
        case 'pendiente_verificacion':
            return 'bx-loader-alt';
        case 'pagado_verificado':
            return 'bx-check-circle';
        case 'preparando':
            return 'bx-package';
        case 'listo_retiro':
            return 'bx-store';
        case 'completado':
            return 'bx-check-double';
        case 'cancelado':
            return 'bx-x-circle';
        default:
            return 'bx-question-mark';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Mis Pedidos - VetNexo</title>
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
            border-bottom: 3px solid var(--primary-light);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.8rem 2rem;
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
            height: 50px;
            width: auto;
            border-radius: 10px;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
        }

        .logo-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            line-height: 1.2;
        }

        .logo-text span {
            font-size: 0.8rem;
            color: var(--accent-dark);
            font-weight: 500;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
        }

        .nav-links a {
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.95rem;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a.active {
            color: var(--primary);
            font-weight: 600;
            position: relative;
        }

        .nav-links a.active:after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--accent);
            border-radius: 3px;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .cart-icon {
            position: relative;
            color: var(--text-dark);
            font-size: 1.5rem;
            transition: color 0.3s ease;
        }

        .cart-icon:hover {
            color: var(--primary);
            transform: scale(1.1);
        }

        .cart-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--accent);
            color: white;
            font-size: 0.7rem;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 2px solid var(--primary-light);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-info:hover {
            background: var(--primary-light);
            color: white;
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Dropdown menu */
        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: var(--white);
            min-width: 200px;
            box-shadow: var(--shadow-lg);
            border-radius: var(--radius-sm);
            z-index: 1;
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .dropdown-content a {
            color: var(--text-dark);
            padding: 12px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.9rem;
            transition: background 0.3s ease;
            border-bottom: 1px solid var(--gray-light);
        }

        .dropdown-content a:last-child {
            border-bottom: none;
        }

        .dropdown-content a i {
            color: var(--primary);
            font-size: 1.1rem;
            width: 20px;
        }

        .dropdown-content a:hover {
            background-color: var(--primary-soft);
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        /* Breadcrumb */
        .breadcrumb {
            max-width: 1400px;
            margin: 2rem auto 0;
            padding: 0 2rem;
        }

        .breadcrumb ul {
            list-style: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .breadcrumb li {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .breadcrumb li a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb li a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        .breadcrumb li i {
            font-size: 1rem;
        }

        /* Main Content */
        .main-content {
            max-width: 1400px;
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

        .page-title {
            font-size: 2rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--accent);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .stat-info h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .stat-info p {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Filters */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
        }

        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .filter-select, .filter-input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .filter-btn {
            padding: 0.8rem 2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 120px;
            justify-content: center;
        }

        .filter-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .filter-btn.reset {
            background: var(--gray);
            color: var(--text-dark);
        }

        .filter-btn.reset:hover {
            background: var(--text-light);
            color: white;
        }

        /* Orders Table */
        .orders-table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .table-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .table-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 1rem 1.5rem;
            background: var(--gray-light);
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--gray);
        }

        td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray);
            color: var(--text-light);
        }

        tr:hover td {
            background: var(--primary-soft);
        }

        tr:last-child td {
            border-bottom: none;
        }

        .order-number {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .badge-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .badge-info {
            background: var(--info);
            color: white;
        }

        .badge-success {
            background: var(--success);
            color: white;
        }

        .badge-danger {
            background: var(--danger);
            color: white;
        }

        .badge-secondary {
            background: var(--gray);
            color: var(--text-dark);
        }

        .btn-view {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-view:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-view i {
            font-size: 1rem;
        }

        .monto-info {
            display: flex;
            flex-direction: column;
        }

        .monto-usd {
            font-weight: 600;
            color: var(--text-dark);
        }

        .monto-bs {
            font-size: 0.8rem;
            color: var(--text-lighter);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--white);
            border: 1px solid var(--gray);
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .page-link:hover {
            background: var(--primary-soft);
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .page-link.next-prev {
            width: auto;
            padding: 0 1rem;
            border-radius: 50px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 2rem;
        }

        .btn-shop {
            display: inline-block;
            padding: 1rem 2rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-shop:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 3rem 0 1.5rem;
            margin-top: 4rem;
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
            
            .nav-menu {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .nav-links {
                order: 2;
                width: 100%;
                justify-content: center;
            }
            
            .filters-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-btn {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            table {
                font-size: 0.9rem;
            }
            
            th, td {
                padding: 0.8rem;
            }
            
            .btn-view {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="index.php" class="logo">
                <img src="../includes/img/logo.svg" alt="VetNexo" class="logo-img">
                <div class="logo-text">
                    <h1>VetNexo</h1>
                    <span>🐾 VetNexo - Your Clinic Location</span>
                </div>
            </a>

            <div class="nav-menu">
                <div class="nav-links">
                    <a href="index.php"><i class='bx bx-home'></i> Inicio</a>
                    <a href="productos.php"><i class='bx bx-package'></i> Productos</a>
                    <a href="ofertas.php"><i class='bx bx-purchase-tag'></i> Ofertas</a>
                    <a href="pedidos.php" class="active"><i class='bx bx-history'></i> Mis Pedidos</a>
                </div>

                <div class="user-actions">
                    <a href="carrito.php" class="cart-icon">
                        <i class='bx bx-cart'></i>
                        <?php if ($carrito_count > 0): ?>
                            <span class="cart-badge"><?php echo $carrito_count; ?></span>
                        <?php endif; ?>
                    </a>

                    <div class="dropdown">
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                            </div>
                            <span class="user-name"><?php echo htmlspecialchars(explode(' ', $usuario_nombre)[0]); ?></span>
                            <i class='bx bx-chevron-down'></i>
                        </div>
                        <div class="dropdown-content">
                            <a href="../cliente/dashboard.php"><i class='bx bx-user'></i> Mi Portal</a>
                            <a href="pedidos.php"><i class='bx bx-package'></i> Mis Pedidos</a>
                            <a href="../cliente/perfil.php"><i class='bx bx-cog'></i> Configuración</a>
                            <a href="../logout.php"><i class='bx bx-log-out'></i> Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <ul>
            <li><a href="index.php">Inicio</a></li>
            <li><i class='bx bx-chevron-right'></i></li>
            <li>Mis Pedidos</li>
        </ul>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">
                <i class='bx bx-history'></i>
                Mis Pedidos
            </h1>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-package'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total de pedidos</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-time'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pendientes_pago']; ?></h3>
                    <p>Pendientes de pago</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-loader-alt'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['en_verificacion']; ?></h3>
                    <p>En verificación</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-dollar'></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($stats['total_gastado'], 2); ?></h3>
                    <p>Total gastado</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" action="pedidos.php" class="filters-form">
                <div class="filter-group">
                    <label class="filter-label">Buscar pedido</label>
                    <input type="text" name="busqueda" class="filter-input" placeholder="Número de pedido..." value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Estado</label>
                    <select name="estado" class="filter-select">
                        <option value="">Todos los estados</option>
                        <option value="pendiente_pago" <?php echo $estado == 'pendiente_pago' ? 'selected' : ''; ?>>Pendiente de Pago</option>
                        <option value="pendiente_verificacion" <?php echo $estado == 'pendiente_verificacion' ? 'selected' : ''; ?>>En Verificación</option>
                        <option value="pagado_verificado" <?php echo $estado == 'pagado_verificado' ? 'selected' : ''; ?>>Pago Verificado</option>
                        <option value="preparando" <?php echo $estado == 'preparando' ? 'selected' : ''; ?>>Preparando</option>
                        <option value="listo_retiro" <?php echo $estado == 'listo_retiro' ? 'selected' : ''; ?>>Listo para Retirar</option>
                        <option value="completado" <?php echo $estado == 'completado' ? 'selected' : ''; ?>>Completado</option>
                        <option value="cancelado" <?php echo $estado == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                
                <button type="submit" class="filter-btn">
                    <i class='bx bx-filter-alt'></i>
                    Filtrar
                </button>
                
                <a href="pedidos.php" class="filter-btn reset">
                    <i class='bx bx-reset'></i>
                    Limpiar
                </a>
            </form>
        </div>

        <!-- Orders Table -->
        <div class="orders-table-container">
            <div class="table-header">
                <h2>
                    <i class='bx bx-list-ul'></i>
                    Listado de Pedidos
                </h2>
                <span><?php echo $total_pedidos; ?> pedido(s) encontrado(s)</span>
            </div>

            <?php if (empty($pedidos)): ?>
                <div class="empty-state">
                    <i class='bx bx-package'></i>
                    <h3>No hay pedidos</h3>
                    <p>Aún no has realizado ninguna compra</p>
                    <a href="index.php" class="btn-shop">
                        <i class='bx bx-shopping-bag'></i>
                        Ir a la tienda
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                                <th>Método de Pago</th>
                                <th>Total</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidos as $pedido): ?>
                                <tr>
                                    <td>
                                        <span class="order-number">#<?php echo $pedido['numero_pedido']; ?></span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($pedido['fecha_pedido'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo getEstadoClase($pedido['estado']); ?>">
                                            <i class='bx <?php echo getEstadoIcono($pedido['estado']); ?>'></i>
                                            <?php echo getEstadoTexto($pedido['estado']); ?>
                                        </span>
                                        <?php if ($pedido['pago_estado'] && $pedido['pago_estado'] !== 'pendiente'): ?>
                                            <br>
                                            <small>Pago: <?php echo $pedido['pago_estado']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        switch ($pedido['metodo_pago']) {
                                            case 'transferencia':
                                                echo 'Transferencia';
                                                break;
                                            case 'pago_movil':
                                                echo 'Pago Móvil';
                                                break;
                                            case 'efectivo':
                                                echo 'Efectivo';
                                                break;
                                            default:
                                                echo $pedido['metodo_pago'];
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="monto-info">
                                            <span class="monto-usd">$<?php echo number_format($pedido['total'], 2); ?></span>
                                            <span class="monto-bs">Bs. <?php echo number_format($pedido['total'] * $tasa_bs, 2); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="../cliente/ver_pedido.php?id=<?php echo $pedido['id']; ?>" class="btn-view">
                                            <i class='bx bx-show'></i>
                                            Ver detalles
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_paginas > 1): ?>
                    <div style="padding: 1.5rem;">
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>" class="page-link next-prev">
                                    <i class='bx bx-chevron-left'></i> Anterior
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <?php if ($i == $pagina): ?>
                                    <span class="page-link active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>" class="page-link"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>" class="page-link next-prev">
                                    Siguiente <i class='bx bx-chevron-right'></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>
<?php echo bcv_badge_html(); ?>
</body>
</html>