<?php
// admin/verificar_pagos.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';

// Verificar si el usuario es admin o veterinarian
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'veterinarian'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$conn = $database->conectar();

$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['nombre'] ?? 'Administrador';

// Obtener pagos pendientes
$stmt_pendientes = $conn->prepare("
    SELECT p.*, pe.numero_pedido, pe.total as total_pedido, 
           c.nombre as cliente_nombre, c.email, c.telefono,
           u.nombre as verificador_nombre
    FROM pagos p
    JOIN pedidos pe ON p.pedido_id = pe.id
    JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN usuarios u ON p.verificado_por = u.id
    WHERE p.estado = 'pendiente'
    ORDER BY p.fecha_creacion DESC
");
$stmt_pendientes->execute();
$pagos_pendientes = $stmt_pendientes->fetchAll(PDO::FETCH_ASSOC);

// Obtener pagos verificados hoy
$stmt_hoy = $conn->prepare("
    SELECT p.*, pe.numero_pedido, c.nombre as cliente_nombre,
           u.nombre as verificador_nombre
    FROM pagos p
    JOIN pedidos pe ON p.pedido_id = pe.id
    JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN usuarios u ON p.verificado_por = u.id
    WHERE DATE(p.fecha_verificacion) = CURDATE()
    ORDER BY p.fecha_verificacion DESC
");
$stmt_hoy->execute();
$pagos_hoy = $stmt_hoy->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas
$stmt_stats = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
        COUNT(CASE WHEN estado = 'verificado' AND fecha_verificacion >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as verificados_semana,
        COUNT(CASE WHEN estado = 'rechazado' AND fecha_verificacion >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as rechazados_semana,
        SUM(CASE WHEN estado = 'verificado' AND fecha_verificacion >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN monto ELSE 0 END) as monto_verificado_semana
    FROM pagos
");
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Tasa de cambio
require_once __DIR__.'/config/bcv.php';
$tasa_bs = (float)(bcv_get_rate()['rate']??0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💰 Verificar Pagos - Panel Admin</title>
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    
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
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar (incluido aparte) */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 1rem;
            border: 3px solid white;
        }

        .sidebar-header h3 {
            font-size: 1.2rem;
            margin-bottom: 0.3rem;
        }

        .sidebar-header p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .sidebar-menu {
            padding: 1.5rem 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--accent);
        }

        .menu-item.active {
            background: rgba(255,255,255,0.15);
            border-left-color: var(--accent);
        }

        .menu-item i {
            font-size: 1.3rem;
            width: 24px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }

        /* Header */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .content-header h1 {
            font-size: 2rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .content-header h1 i {
            color: var(--accent);
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 2px solid var(--primary-light);
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }

        .stat-icon.pendientes {
            background: var(--warning);
            color: white;
        }

        .stat-icon.verificados {
            background: var(--success);
            color: white;
        }

        .stat-icon.rechazados {
            background: var(--danger);
            color: white;
        }

        .stat-icon.monto {
            background: var(--info);
            color: white;
        }

        .stat-details h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .stat-details p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 0.8rem 2rem;
            background: var(--white);
            border: 2px solid var(--gray);
            border-radius: 50px;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .tab-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .tab-btn .count {
            background: var(--white);
            color: var(--primary);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .tab-btn.active .count {
            background: white;
            color: var(--primary);
        }

        /* Tables */
        .table-responsive {
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
        }

        .table-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-header .badge {
            background: var(--primary);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
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

        .cliente-info {
            display: flex;
            flex-direction: column;
        }

        .cliente-nombre {
            font-weight: 600;
            color: var(--text-dark);
        }

        .cliente-email {
            font-size: 0.8rem;
            color: var(--text-lighter);
        }

        .badge-pendiente {
            background: var(--warning);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-verificado {
            background: var(--success);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-rechazado {
            background: var(--danger);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
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

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-lighter);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 2rem 0 1rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="includes/img/logo.svg" alt="VetNexo">
            <h3><?php echo htmlspecialchars($usuario_nombre); ?></h3>
            <p><?php echo $_SESSION['rol'] === 'admin' ? 'Administrador' : 'Veterinario'; ?></p>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class='bx bx-home'></i>
                Dashboard
            </a>
            <a href="verificar_pagos.php" class="menu-item active">
                <i class='bx bx-check-shield'></i>
                Verificar Pagos
                <?php if ($stats['pendientes'] > 0): ?>
                    <span style="background: var(--accent); color: white; padding: 0.2rem 0.5rem; border-radius: 20px; font-size: 0.7rem; margin-left: auto;">
                        <?php echo $stats['pendientes']; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="tienda/pedidos.php" class="menu-item">
                <i class='bx bx-package'></i>
                Pedidos
            </a>
            <a href="listadeclientes.php" class="menu-item">
                <i class='bx bx-user'></i>
                Clientes
            </a>
            <a href="productos.php" class="menu-item">
                <i class='bx bx-cube'></i>
                Productos
            </a>
            <a href="logout.php" class="menu-item">
                <i class='bx bx-log-out'></i>
                Cerrar Sesión
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="content-header">
            <h1>
                <i class='bx bx-check-shield'></i>
                Verificar Pagos
            </h1>
            <div class="user-badge">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                </div>
                <span><?php echo htmlspecialchars($usuario_nombre); ?></span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon pendientes">
                    <i class='bx bx-time'></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['pendientes']; ?></h3>
                    <p>Pagos pendientes</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon verificados">
                    <i class='bx bx-check-circle'></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['verificados_semana']; ?></h3>
                    <p>Verificados esta semana</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon rechazados">
                    <i class='bx bx-x-circle'></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['rechazados_semana']; ?></h3>
                    <p>Rechazados esta semana</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon monto">
                    <i class='bx bx-dollar'></i>
                </div>
                <div class="stat-details">
                    <h3>$<?php echo number_format($stats['monto_verificado_semana'], 2); ?></h3>
                    <p>Monto verificado (semana)</p>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="window.location.href='verificar_pagos.php'">
                <i class='bx bx-time'></i>
                Pendientes
                <span class="count"><?php echo $stats['pendientes']; ?></span>
            </button>
            <button class="tab-btn" onclick="window.location.href='verificar_pagos.php?tab=hoy'">
                <i class='bx bx-calendar'></i>
                Verificados Hoy
                <span class="count"><?php echo count($pagos_hoy); ?></span>
            </button>
            <button class="tab-btn" onclick="window.location.href='verificar_pagos.php?tab=historial'">
                <i class='bx bx-history'></i>
                Historial
            </button>
        </div>

        <!-- Pagos Pendientes -->
        <?php if (empty($pagos_pendientes)): ?>
            <div class="table-responsive">
                <div class="table-header">
                    <h2>
                        <i class='bx bx-time'></i>
                        Pagos Pendientes de Verificación
                    </h2>
                </div>
                <div class="empty-state">
                    <i class='bx bx-check-circle'></i>
                    <h3>No hay pagos pendientes</h3>
                    <p>Todos los pagos están al día. ¡Buen trabajo!</p>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <div class="table-header">
                    <h2>
                        <i class='bx bx-time'></i>
                        Pagos Pendientes de Verificación
                    </h2>
                    <span class="badge"><?php echo count($pagos_pendientes); ?> pendiente(s)</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Método</th>
                            <th>Referencia</th>
                            <th>Monto</th>
                            <th>Comprobante</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagos_pendientes as $pago): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_creacion'])); ?></td>
                                <td>
                                    <strong>#<?php echo $pago['numero_pedido']; ?></strong>
                                </td>
                                <td>
                                    <div class="cliente-info">
                                        <span class="cliente-nombre"><?php echo htmlspecialchars($pago['cliente_nombre']); ?></span>
                                        <span class="cliente-email"><?php echo $pago['email']; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($pago['metodo_pago'] === 'transferencia'): ?>
                                        <span class="badge-pendiente" style="background: var(--info);">
                                            <i class='bx bx-transfer'></i>
                                            Transferencia
                                        </span>
                                        <br>
                                        <small><?php echo $pago['banco_origen'] ?? 'N/E'; ?></small>
                                    <?php else: ?>
                                        <span class="badge-pendiente" style="background: var(--success);">
                                            <i class='bx bx-mobile-alt'></i>
                                            Pago Móvil
                                        </span>
                                        <br>
                                        <small><?php echo $pago['telefono_origen'] ?? 'N/E'; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo $pago['referencia']; ?></strong>
                                </td>
                                <td>
                                    <div class="monto-info">
                                        <span class="monto-usd">$<?php echo number_format($pago['monto'], 2); ?></span>
                                        <span class="monto-bs">Bs. <?php echo number_format($pago['monto'] * $tasa_bs, 2); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($pago['comprobante_path'])): ?>
                                        <a href="../<?php echo $pago['comprobante_path']; ?>" target="_blank" class="btn-view" style="background: var(--info);">
                                            <i class='bx bx-file'></i>
                                            Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="badge-pendiente" style="background: var(--danger);">Sin comprobante</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="verificar_pago.php?id=<?php echo $pago['id']; ?>" class="btn-view">
                                        <i class='bx bx-check'></i>
                                        Verificar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Pagos Verificados Hoy -->
        <?php if (!empty($pagos_hoy)): ?>
            <div class="table-responsive">
                <div class="table-header">
                    <h2>
                        <i class='bx bx-calendar-check'></i>
                        Pagos Verificados Hoy
                    </h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Método</th>
                            <th>Monto</th>
                            <th>Verificado por</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagos_hoy as $pago): ?>
                            <tr>
                                <td><?php echo date('H:i', strtotime($pago['fecha_verificacion'])); ?></td>
                                <td><strong>#<?php echo $pago['numero_pedido']; ?></strong></td>
                                <td><?php echo htmlspecialchars($pago['cliente_nombre']); ?></td>
                                <td>
                                    <?php if ($pago['metodo_pago'] === 'transferencia'): ?>
                                        Transferencia
                                    <?php else: ?>
                                        Pago Móvil
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="monto-info">
                                        <span class="monto-usd">$<?php echo number_format($pago['monto'], 2); ?></span>
                                    </div>
                                </td>
                                <td><?php echo $pago['verificador_nombre'] ?? 'Sistema'; ?></td>
                                <td>
                                    <?php if ($pago['estado'] === 'verificado'): ?>
                                        <span class="badge-verificado">
                                            <i class='bx bx-check'></i>
                                            Verificado
                                        </span>
                                    <?php elseif ($pago['estado'] === 'rechazado'): ?>
                                        <span class="badge-rechazado">
                                            <i class='bx bx-x'></i>
                                            Rechazado
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> VetNexo Demo</p>
        </div>
    </div>
</body>
</html>