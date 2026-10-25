<?php
// cliente/ver_pedido.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Cliente';

// Si no hay cliente_id en sesión, intentar obtenerlo
if (!$cliente_id) {
    $stmt = $conn->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $cliente_id = $usuario['cliente_id'] ?? null;
    $_SESSION['cliente_id'] = $cliente_id;
}

$pedido_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$pedido_id) {
    header('Location: historial_compras.php');
    exit();
}

// Obtener información del pedido
$stmt = $conn->prepare("
    SELECT p.*, c.nombre as cliente_nombre, c.email, c.telefono as cliente_telefono
    FROM pedidos p
    JOIN clientes c ON p.cliente_id = c.id
    WHERE p.id = ? AND p.cliente_id = ?
");
$stmt->execute([$pedido_id, $cliente_id]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    header('Location: historial_compras.php');
    exit();
}

// Obtener detalles del pedido
$stmt_detalles = $conn->prepare("
    SELECT pd.*, pr.nombre as producto_nombre, pr.imagen, pr.codigo
    FROM pedido_detalles pd
    JOIN productos pr ON pd.producto_id = pr.id
    WHERE pd.pedido_id = ?
");
$stmt_detalles->execute([$pedido_id]);
$detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

// Obtener información del pago
$pago = null;
if (!empty($pedido['pago_id'])) {
    $stmt_pago = $conn->prepare("
        SELECT p.*, u.nombre as verificador_nombre
        FROM pagos p
        LEFT JOIN usuarios u ON p.verificado_por = u.id
        WHERE p.id = ?
    ");
    $stmt_pago->execute([$pedido['pago_id']]);
    $pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
} else {
    // Buscar pagos por pedido_id
    $stmt_pago = $conn->prepare("
        SELECT p.*, u.nombre as verificador_nombre
        FROM pagos p
        LEFT JOIN usuarios u ON p.verificado_por = u.id
        WHERE p.pedido_id = ?
        ORDER BY p.fecha_creacion DESC
        LIMIT 1
    ");
    $stmt_pago->execute([$pedido_id]);
    $pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
}

// Obtener historial de logs
$stmt_logs = $conn->prepare("
    SELECT l.*, u.nombre as usuario_nombre
    FROM logs_verificacion_pagos l
    LEFT JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.pedido_id = ?
    ORDER BY l.created_at DESC
");
$stmt_logs->execute([$pedido_id]);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

// Tasa de cambio
require_once __DIR__.'/../config/bcv.php';
$tasa_bs = (float)(bcv_get_rate()['rate']??0);

// Decodificar info_pago si es JSON
$info_pago = [];
if (!empty($pedido['info_pago'])) {
    $info_pago = json_decode($pedido['info_pago'], true);
}

// Determinar clase CSS para el estado
$estado_clase = '';
$estado_icono = '';
$estado_texto = '';

switch ($pedido['estado']) {
    case 'pendiente_pago':
        $estado_clase = 'badge-warning';
        $estado_icono = 'bx-time';
        $estado_texto = 'Pendiente de Pago';
        break;
    case 'pendiente_verificacion':
        $estado_clase = 'badge-info';
        $estado_icono = 'bx-loader-alt';
        $estado_texto = 'Pago en Verificación';
        break;
    case 'pagado_verificado':
        $estado_clase = 'badge-success';
        $estado_icono = 'bx-check-circle';
        $estado_texto = 'Pago Verificado';
        break;
    case 'preparando':
        $estado_clase = 'badge-info';
        $estado_icono = 'bx-package';
        $estado_texto = 'Preparando Pedido';
        break;
    case 'listo_retiro':
        $estado_clase = 'badge-success';
        $estado_icono = 'bx-store';
        $estado_texto = 'Listo para Retirar';
        break;
    case 'completado':
        $estado_clase = 'badge-success';
        $estado_icono = 'bx-check-double';
        $estado_texto = 'Completado';
        break;
    case 'cancelado':
        $estado_clase = 'badge-danger';
        $estado_icono = 'bx-x-circle';
        $estado_texto = 'Cancelado';
        break;
    default:
        $estado_clase = 'badge-secondary';
        $estado_icono = 'bx-question-mark';
        $estado_texto = $pedido['estado'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Pedido #<?php echo $pedido['numero_pedido']; ?> - VetNexo</title>
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

        /* Header Cliente */
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
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a.active {
            color: var(--primary);
            font-weight: 600;
            position: relative;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 2px solid var(--primary-light);
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

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            margin-bottom: 2rem;
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
        }

        .breadcrumb li i {
            font-size: 1rem;
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

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 1rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            background: var(--primary-soft);
            transform: translateX(-5px);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
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

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Order Info */
        .order-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .info-item {
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .info-value.large {
            font-size: 1.3rem;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 1rem;
            background: var(--gray-light);
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--gray);
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray);
        }

        tr:last-child td {
            border-bottom: none;
        }

        .product-image-mini {
            width: 50px;
            height: 50px;
            background: var(--gray-light);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .product-image-mini img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .text-right {
            text-align: right;
        }

        .total-row {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1.1rem;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
            border-left: 2px solid var(--gray);
            padding-left: 1.5rem;
        }

        .timeline-item:last-child {
            border-left-color: transparent;
            padding-bottom: 0;
        }

        .timeline-icon {
            position: absolute;
            left: -0.9rem;
            width: 1.8rem;
            height: 1.8rem;
            border-radius: 50%;
            background: var(--white);
            border: 2px solid var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .timeline-content {
            background: var(--gray-light);
            padding: 1rem;
            border-radius: var(--radius);
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .timeline-time {
            font-size: 0.8rem;
            color: var(--text-lighter);
        }

        /* Payment Info */
        .payment-info {
            background: var(--accent-soft);
            border-radius: var(--radius);
            padding: 1rem;
            border: 1px solid var(--accent-light);
        }

        .payment-detail {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--accent-light);
        }

        .payment-detail:last-child {
            border-bottom: none;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: var(--accent-dark);
            color: white;
            transform: translateY(-2px);
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .alert-info {
            background: var(--info);
            color: white;
        }

        .alert-success {
            background: var(--success);
            color: white;
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

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav-menu {
                width: 100%;
                justify-content: space-between;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            table {
                font-size: 0.9rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
        }
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="../tienda/index.php" class="logo">
                <img src="../includes/img/logo.svg" alt="VetNexo" class="logo-img">
                <div class="logo-text">
                    <h1>VetNexo</h1>
                    <span>🐾 Mi Portal</span>
                </div>
            </a>

            <div class="nav-menu">
                <div class="nav-links">
                    <a href="dashboard.php"><i class='bx bx-home'></i> Inicio</a>
                    <a href="historial_compras.php" class="active"><i class='bx bx-package'></i> Mis Pedidos</a>
                    <a href="mis_mascotas.php"><i class='bx bx-dog'></i> Mis Mascotas</a>
                </div>

                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars(explode(' ', $usuario_nombre)[0]); ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <ul>
                <li><a href="dashboard.php">Inicio</a></li>
                <li><i class='bx bx-chevron-right'></i></li>
                <li><a href="historial_compras.php">Mis Pedidos</a></li>
                <li><i class='bx bx-chevron-right'></i></li>
                <li>Pedido #<?php echo $pedido['numero_pedido']; ?></li>
            </ul>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <a href="historial_compras.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver a mis pedidos
                </a>
                <h1 class="page-title">
                    <i class='bx bx-package'></i>
                    Pedido #<?php echo $pedido['numero_pedido']; ?>
                </h1>
            </div>
            <span class="badge <?php echo $estado_clase; ?>">
                <i class='bx <?php echo $estado_icono; ?>'></i>
                <?php echo $estado_texto; ?>
            </span>
        </div>

        <!-- Alertas de acción -->
        <?php if ($pedido['estado'] === 'pendiente_pago' && ($pedido['metodo_pago'] === 'transferencia' || $pedido['metodo_pago'] === 'pago_movil')): ?>
            <?php if (!$pago || $pago['estado'] === 'rechazado'): ?>
                <div class="alert alert-warning">
                    <i class='bx bx-error-circle'></i>
                    <div>
                        <strong>Pago pendiente:</strong> Realiza el pago y sube el comprobante para continuar.
                        <?php if ($pago && $pago['estado'] === 'rechazado'): ?>
                            <br>
                            <small>Motivo del rechazo: <?php echo htmlspecialchars($pago['motivo_rechazo']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($pago && $pago['estado'] === 'pendiente'): ?>
                <div class="alert alert-info">
                    <i class='bx bx-loader-alt bx-spin'></i>
                    <div>
                        <strong>Pago en verificación:</strong> Hemos recibido tu comprobante. Lo estamos verificando.
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif ($pedido['estado'] === 'pendiente_pago' && $pedido['metodo_pago'] === 'efectivo'): ?>
            <div class="alert alert-info">
                <i class='bx bx-store'></i>
                <div>
                    <strong>Listo para retirar:</strong> Puedes pasar por nuestra tienda a pagar en efectivo y retirar tu pedido.
                </div>
            </div>
        <?php elseif ($pedido['estado'] === 'pagado_verificado' || $pedido['estado'] === 'listo_retiro'): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <div>
                    <strong>¡Pago verificado!</strong> Tu pedido está listo para retirar en tienda.
                </div>
            </div>
        <?php endif; ?>

        <!-- Información del Pedido -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i class='bx bx-info-circle'></i>
                    Detalles del Pedido
                </h2>
                <span>Fecha: <?php echo date('d/m/Y H:i', strtotime($pedido['fecha_pedido'])); ?></span>
            </div>
            <div class="card-body">
                <div class="order-info">
                    <div class="info-item">
                        <div class="info-label">Número de pedido</div>
                        <div class="info-value large">#<?php echo $pedido['numero_pedido']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Método de pago</div>
                        <div class="info-value">
                            <?php 
                            $metodos = [
                                'transferencia' => 'Transferencia Bancaria',
                                'pago_movil' => 'Pago Móvil',
                                'efectivo' => 'Efectivo'
                            ];
                            echo $metodos[$pedido['metodo_pago']] ?? $pedido['metodo_pago'];
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total</div>
                        <div class="info-value large">$<?php echo number_format($pedido['total'], 2); ?></div>
                        <div class="info-value">Bs. <?php echo number_format($pedido['total'] * $tasa_bs, 2); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Teléfono de contacto</div>
                        <div class="info-value"><?php echo htmlspecialchars($pedido['telefono_contacto']); ?></div>
                    </div>
                </div>
                
                <div style="margin-top: 1rem;">
                    <strong>Dirección de retiro:</strong>
                    <p><?php echo nl2br(htmlspecialchars($pedido['direccion_envio'])); ?></p>
                </div>
                
                <?php if (!empty($pedido['notas'])): ?>
                    <div style="margin-top: 1rem;">
                        <strong>Notas del pedido:</strong>
                        <p><?php echo nl2br(htmlspecialchars($pedido['notas'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Productos -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i class='bx bx-package'></i>
                    Productos
                </h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-right">Precio Unit.</th>
                                <th class="text-right">Cantidad</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detalles as $detalle): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div class="product-image-mini">
                                                <?php if (!empty($detalle['imagen'])): ?>
                                                    <img src="../uploads/productos/<?php echo $detalle['imagen']; ?>" alt="<?php echo htmlspecialchars($detalle['producto_nombre']); ?>">
                                                <?php else: ?>
                                                    <i class='bx bx-package'></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($detalle['producto_nombre']); ?></strong>
                                                <br>
                                                <small>Cód: <?php echo htmlspecialchars($detalle['codigo']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        $<?php echo number_format($detalle['precio_unitario'], 2); ?>
                                        <br>
                                        <small>Bs. <?php echo number_format($detalle['precio_unitario'] * $tasa_bs, 2); ?></small>
                                    </td>
                                    <td class="text-right"><?php echo $detalle['cantidad']; ?></td>
                                    <td class="text-right">
                                        <strong>$<?php echo number_format($detalle['subtotal'], 2); ?></strong>
                                        <br>
                                        <small>Bs. <?php echo number_format($detalle['subtotal'] * $tasa_bs, 2); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right">Subtotal:</td>
                                <td class="text-right">
                                    $<?php echo number_format($pedido['subtotal'], 2); ?>
                                    <br>
                                    <small>Bs. <?php echo number_format($pedido['subtotal'] * $tasa_bs, 2); ?></small>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-right">IVA (16%):</td>
                                <td class="text-right">
                                    $<?php echo number_format($pedido['impuestos'], 2); ?>
                                    <br>
                                    <small>Bs. <?php echo number_format($pedido['impuestos'] * $tasa_bs, 2); ?></small>
                                </td>
                            </tr>
                            <tr class="total-row">
                                <td colspan="3" class="text-right">Total:</td>
                                <td class="text-right">
                                    $<?php echo number_format($pedido['total'], 2); ?>
                                    <br>
                                    <small>Bs. <?php echo number_format($pedido['total'] * $tasa_bs, 2); ?></small>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Información de Pago -->
        <?php if ($pago): ?>
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class='bx bx-credit-card'></i>
                        Información del Pago
                    </h2>
                    <?php if ($pago['estado'] === 'verificado'): ?>
                        <span class="badge badge-success">
                            <i class='bx bx-check'></i>
                            Verificado
                        </span>
                    <?php elseif ($pago['estado'] === 'rechazado'): ?>
                        <span class="badge badge-danger">
                            <i class='bx bx-x'></i>
                            Rechazado
                        </span>
                    <?php else: ?>
                        <span class="badge badge-warning">
                            <i class='bx bx-time'></i>
                            Pendiente
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="payment-info">
                        <div class="payment-detail">
                            <span>Método de pago:</span>
                            <strong>
                                <?php 
                                if ($pago['metodo_pago'] === 'transferencia') echo 'Transferencia Bancaria';
                                elseif ($pago['metodo_pago'] === 'pago_movil') echo 'Pago Móvil';
                                else echo ucfirst($pago['metodo_pago']);
                                ?>
                            </strong>
                        </div>
                        <div class="payment-detail">
                            <span>Referencia:</span>
                            <strong><?php echo htmlspecialchars($pago['referencia']); ?></strong>
                        </div>
                        <?php if (!empty($pago['banco_origen'])): ?>
                            <div class="payment-detail">
                                <span>Banco origen:</span>
                                <span><?php echo htmlspecialchars($pago['banco_origen']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($pago['telefono_origen'])): ?>
                            <div class="payment-detail">
                                <span>Teléfono origen:</span>
                                <span><?php echo htmlspecialchars($pago['telefono_origen']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="payment-detail">
                            <span>Fecha pago:</span>
                            <span><?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?></span>
                        </div>
                        <div class="payment-detail">
                            <span>Monto pagado:</span>
                            <strong>$<?php echo number_format($pago['monto'], 2); ?></strong>
                        </div>
                        <?php if ($pago['estado'] === 'verificado' && !empty($pago['fecha_verificacion'])): ?>
                            <div class="payment-detail">
                                <span>Verificado el:</span>
                                <span><?php echo date('d/m/Y H:i', strtotime($pago['fecha_verificacion'])); ?></span>
                            </div>
                            <?php if (!empty($pago['verificador_nombre'])): ?>
                                <div class="payment-detail">
                                    <span>Verificado por:</span>
                                    <span><?php echo htmlspecialchars($pago['verificador_nombre']); ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($pago['estado'] === 'rechazado' && !empty($pago['motivo_rechazo'])): ?>
                            <div class="payment-detail" style="color: var(--danger);">
                                <span>Motivo del rechazo:</span>
                                <strong><?php echo htmlspecialchars($pago['motivo_rechazo']); ?></strong>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pago['comprobante_path'])): ?>
                            <div style="margin-top: 1rem; text-align: center;">
                                <a href="../<?php echo $pago['comprobante_path']; ?>" target="_blank" class="btn btn-secondary">
                                    <i class='bx bx-file'></i>
                                    Ver comprobante
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Historial / Timeline -->
        <?php if (!empty($logs)): ?>
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class='bx bx-history'></i>
                        Historial del Pedido
                    </h2>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <?php foreach ($logs as $log): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <?php if ($log['accion'] === 'pago_verificado'): ?>
                                        <i class='bx bx-check'></i>
                                    <?php elseif ($log['accion'] === 'pago_rechazado'): ?>
                                        <i class='bx bx-x'></i>
                                    <?php else: ?>
                                        <i class='bx bx-time'></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?php 
                                        switch ($log['accion']) {
                                            case 'pago_verificado':
                                                echo 'Pago verificado';
                                                break;
                                            case 'pago_rechazado':
                                                echo 'Pago rechazado';
                                                break;
                                            case 'cliente_subio_comprobante':
                                                echo 'Comprobante subido';
                                                break;
                                            case 'pedido_creado':
                                                echo 'Pedido creado';
                                                break;
                                            default:
                                                echo ucfirst(str_replace('_', ' ', $log['accion']));
                                        }
                                        ?>
                                    </div>
                                    <div class="timeline-time">
                                        <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?>
                                        por <?php echo htmlspecialchars($log['usuario_nombre'] ?? 'Sistema'); ?>
                                    </div>
                                    <?php if (!empty($log['observaciones'])): ?>
                                        <p style="margin-top: 0.5rem; font-size: 0.9rem;"><?php echo htmlspecialchars($log['observaciones']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Evento inicial: pedido creado -->
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class='bx bx-cart'></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">Pedido realizado</div>
                                <div class="timeline-time">
                                    <?php echo date('d/m/Y H:i', strtotime($pedido['fecha_pedido'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Acciones -->
        <div class="action-buttons">
            <?php if ($pedido['estado'] === 'pendiente_pago' && ($pedido['metodo_pago'] === 'transferencia' || $pedido['metodo_pago'] === 'pago_movil')): ?>
                <?php if (!$pago || $pago['estado'] === 'rechazado'): ?>
                    <a href="subir_comprobante.php?pedido_id=<?php echo $pedido_id; ?>" class="btn btn-warning">
                        <i class='bx bx-upload'></i>
                        Subir comprobante de pago
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($pedido['estado'] === 'listo_retiro' || $pedido['estado'] === 'pagado_verificado'): ?>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class='bx bx-printer'></i>
                    Imprimir comprobante
                </button>
            <?php endif; ?>
            
            <a href="historial_compras.php" class="btn btn-secondary">
                <i class='bx bx-arrow-back'></i>
                Volver a mis pedidos
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>
</body>
</html>