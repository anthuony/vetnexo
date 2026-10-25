<?php
// cliente/historial_compras.php
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

// Parámetros de filtrado
$filtro_estado = $_GET['estado'] ?? 'todos';
$filtro_fecha = $_GET['fecha'] ?? '';
$busqueda = $_GET['buscar'] ?? '';

// Construir consulta base
$sql = "SELECT p.*, 
               COUNT(pd.id) as total_items,
               f.id as factura_id,
               f.numero_factura
        FROM pedidos p
        LEFT JOIN pedido_detalles pd ON p.id = pd.pedido_id
        LEFT JOIN facturas f ON p.factura_id = f.id
        WHERE p.cliente_id = ?";

$params = [$cliente_id];

// Aplicar filtros
if ($filtro_estado != 'todos') {
    $sql .= " AND p.estado = ?";
    $params[] = $filtro_estado;
}

if (!empty($filtro_fecha)) {
    $sql .= " AND DATE(p.fecha_pedido) = ?";
    $params[] = $filtro_fecha;
}

if (!empty($busqueda)) {
    $sql .= " AND (p.numero_pedido LIKE ? OR f.numero_factura LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$sql .= " GROUP BY p.id ORDER BY p.fecha_pedido DESC";

// Ejecutar consulta
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas de compras
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_pedidos,
        SUM(CASE WHEN estado = 'completado' THEN total ELSE 0 END) as total_gastado,
        AVG(total) as promedio_compra,
        COUNT(CASE WHEN estado = 'completado' THEN 1 END) as pedidos_completados
    FROM pedidos 
    WHERE cliente_id = ?
");
$stmt->execute([$cliente_id]);
$estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);

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
    <title>Historial de Compras - VetNexo</title>
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1rem;
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
            font-size: 1.5rem;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .stat-info p {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--gray);
            box-shadow: var(--shadow-sm);
        }

        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.6rem 1rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-filter {
            padding: 0.6rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
        }

        .btn-clear {
            padding: 0.6rem 1.5rem;
            background: var(--gray-light);
            color: var(--text-dark);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-clear:hover {
            background: var(--gray);
        }

        /* Tabla de pedidos */
        .pedidos-table-container {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow-x: auto;
            box-shadow: var(--shadow);
        }

        .pedidos-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .pedidos-table th {
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.9rem;
            padding: 1.2rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--primary-light);
        }

        .pedidos-table td {
            padding: 1.2rem 1rem;
            border-bottom: 1px solid var(--gray);
            color: var(--text-dark);
        }

        .pedidos-table tr:last-child td {
            border-bottom: none;
        }

        .pedidos-table tr:hover td {
            background: var(--primary-soft);
        }

        .pedido-numero {
            font-weight: 600;
            color: var(--primary);
        }

        .pedido-fecha {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .pedido-items {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .items-count {
            background: var(--gray-light);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .pedido-total {
            font-weight: 700;
            color: var(--primary-dark);
        }

        .badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-pendiente {
            background: #fff3cd;
            color: #856404;
        }

        .badge-procesando {
            background: #cce5ff;
            color: #004085;
        }

        .badge-completado {
            background: #d4edda;
            color: #155724;
        }

        .badge-cancelado {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            padding: 0.4rem 0.8rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s ease;
        }

        .btn-view {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
        }

        .btn-invoice {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .btn-invoice:hover {
            background: var(--accent);
            color: white;
        }

        /* Estado vacío */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
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

        .empty-state .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 2rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
        }

        .empty-state .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
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
            
            .filters-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .btn-filter,
            .btn-clear {
                width: 100%;
                justify-content: center;
            }
            
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
        <span>Historial de Compras</span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-receipt'></i>
                Historial de Compras
            </h1>
            <a href="../tienda/" class="btn-back">
                <i class='bx bx-cart'></i>
                Seguir comprando
            </a>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-package'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $estadisticas['total_pedidos'] ?? 0; ?></h3>
                    <p>Total de pedidos</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-dollar-circle'></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($estadisticas['total_gastado'] ?? 0, 2); ?></h3>
                    <p>Total gastado</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-check-circle'></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $estadisticas['pedidos_completados'] ?? 0; ?></h3>
                    <p>Pedidos completados</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class='bx bx-line-chart'></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($estadisticas['promedio_compra'] ?? 0, 2); ?></h3>
                    <p>Promedio por compra</p>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label><i class='bx bx-filter-alt'></i> Estado</label>
                    <select name="estado">
                        <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                        <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                        <option value="procesando" <?php echo $filtro_estado == 'procesando' ? 'selected' : ''; ?>>Procesando</option>
                        <option value="completado" <?php echo $filtro_estado == 'completado' ? 'selected' : ''; ?>>Completado</option>
                        <option value="cancelado" <?php echo $filtro_estado == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class='bx bx-calendar'></i> Fecha</label>
                    <input type="date" name="fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                </div>
                <div class="filter-group">
                    <label><i class='bx bx-search'></i> Buscar</label>
                    <input type="text" name="buscar" placeholder="N° pedido o factura" value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class='bx bx-filter'></i>
                        Filtrar
                    </button>
                    <a href="historial_compras.php" class="btn-clear">
                        <i class='bx bx-reset'></i>
                        Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabla de pedidos -->
        <?php if (!empty($pedidos)): ?>
            <div class="pedidos-table-container">
                <table class="pedidos-table">
                    <thead>
                        <tr>
                            <th>N° Pedido</th>
                            <th>Fecha</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Factura</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pedidos as $pedido): ?>
                            <tr>
                                <td>
                                    <span class="pedido-numero">#<?php echo htmlspecialchars($pedido['numero_pedido']); ?></span>
                                </td>
                                <td>
                                    <span class="pedido-fecha">
                                        <i class='bx bx-calendar'></i>
                                        <?php echo date('d/m/Y', strtotime($pedido['fecha_pedido'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="pedido-items">
                                        <i class='bx bx-package'></i>
                                        <span class="items-count"><?php echo $pedido['total_items']; ?> items</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="pedido-total">$<?php echo number_format($pedido['total'], 2); ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $pedido['estado']; ?>">
                                        <?php 
                                        switch($pedido['estado']) {
                                            case 'pendiente': echo '⏳ Pendiente'; break;
                                            case 'procesando': echo '⚙️ Procesando'; break;
                                            case 'completado': echo '✅ Completado'; break;
                                            case 'cancelado': echo '❌ Cancelado'; break;
                                            default: echo ucfirst($pedido['estado']);
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($pedido['factura_id'])): ?>
                                        <span class="badge badge-success">
                                            <i class='bx bx-receipt'></i>
                                            <?php echo $pedido['numero_factura'] ?? 'Facturado'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-pendiente">
                                            <i class='bx bx-time'></i>
                                            Pendiente
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="ver_pedido.php?id=<?php echo $pedido['id']; ?>" class="btn-action btn-view" title="Ver detalles">
                                            <i class='bx bx-show'></i>
                                            Ver
                                        </a>
                                        <?php if (!empty($pedido['factura_id'])): ?>
                                            <a href="ver_factura.php?id=<?php echo $pedido['factura_id']; ?>" class="btn-action btn-invoice" title="Ver factura">
                                                <i class='bx bx-receipt'></i>
                                                Factura
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Estado vacío -->
            <div class="empty-state">
                <i class='bx bx-cart'></i>
                <h3>No hay compras registradas</h3>
                <p>Aún no has realizado ninguna compra. ¡Visita nuestra tienda y encuentra los mejores productos para tu mascota!</p>
                <a href="../tienda/" class="btn">
                    <i class='bx bx-cart'></i>
                    Ir a la tienda
                </a>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>
</body>
</html>