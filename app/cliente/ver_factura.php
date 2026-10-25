<?php
// cliente/ver_factura.php
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
$factura_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($factura_id <= 0) {
    header("Location: historial_compras.php");
    exit();
}

// Obtener datos de la factura verificando que pertenezca al cliente
$stmt = $conn->prepare("
    SELECT f.*, 
           c.nombre as cliente_nombre,
           c.documento_identidad as cliente_documento,
           c.direccion as cliente_direccion,
           c.telefono as cliente_telefono,
           c.email as cliente_email,
           u.nombre as usuario_nombre
    FROM facturas f
    JOIN clientes c ON f.cliente_id = c.id
    LEFT JOIN usuarios u ON f.usuario_id = u.id
    WHERE f.id = ? AND f.cliente_id = ?
");
$stmt->execute([$factura_id, $cliente_id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) {
    header("Location: historial_compras.php");
    exit();
}

// Obtener detalles de la factura
$stmt = $conn->prepare("
    SELECT fd.*,
           CASE 
               WHEN fd.tipo_item = 'servicio' THEN s.nombre
               WHEN fd.tipo_item = 'producto' THEN p.nombre
           END as item_nombre,
           CASE 
               WHEN fd.tipo_item = 'servicio' THEN s.descripcion
               WHEN fd.tipo_item = 'producto' THEN p.descripcion
           END as item_descripcion
    FROM factura_detalles fd
    LEFT JOIN servicios s ON fd.servicio_id = s.id AND fd.tipo_item = 'servicio'
    LEFT JOIN productos p ON fd.producto_id = p.id AND fd.tipo_item = 'producto'
    WHERE fd.factura_id = ?
");
$stmt->execute([$factura_id]);
$detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener pedido asociado si existe
$stmt = $conn->prepare("
    SELECT * FROM pedidos 
    WHERE factura_id = ? AND cliente_id = ?
");
$stmt->execute([$factura_id, $cliente_id]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

// Contar notificaciones no leídas
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notificaciones_cliente 
    WHERE cliente_id = ? AND leida = 0
");
$stmt->execute([$cliente_id]);
$notificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
$notificaciones_count = $notificaciones['total'] ?? 0;

// Función para obtener clase de estado
function getEstadoFacturaClass($estado) {
    switch($estado) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'warning';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Función para texto del estado
function getEstadoFacturaTexto($estado) {
    switch($estado) {
        case 'paid':
            return 'Pagada';
        case 'pending':
            return 'Pendiente';
        case 'cancelled':
            return 'Anulada';
        default:
            return ucfirst($estado);
    }
}

// Función para método de pago
function getMetodoPagoTexto($metodo) {
    switch($metodo) {
        case 'cash':
            return 'Efectivo';
        case 'card':
            return 'Tarjeta';
        case 'transfer':
            return 'Transferencia';
        default:
            return ucfirst($metodo);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura <?php echo $factura['numero_factura']; ?> - VetNexo</title>
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
            --secondary: #9E9E9E;
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
            max-width: 1000px;
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

        .btn-print {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-print:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Invoice Card */
        .invoice-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .invoice-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .invoice-title h2 {
            font-size: 1.5rem;
            margin-bottom: 0.3rem;
        }

        .invoice-title p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .invoice-status {
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .invoice-body {
            padding: 2rem;
        }

        /* Company and Client Info */
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 2px dashed var(--gray);
        }

        .info-box {
            background: var(--gray-light);
            padding: 1.5rem;
            border-radius: var(--radius);
        }

        .info-box h3 {
            font-size: 1rem;
            color: var(--primary-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box h3 i {
            color: var(--accent);
        }

        .info-row {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .info-label {
            width: 100px;
            color: var(--text-light);
        }

        .info-value {
            flex: 1;
            color: var(--text-dark);
            font-weight: 500;
        }

        /* Invoice Details */
        .invoice-details {
            margin-bottom: 2rem;
        }

        .details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .details-header h3 {
            font-size: 1.1rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th {
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--primary-light);
        }

        table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray);
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        .item-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .item-desc {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .text-right {
            text-align: right;
        }

        /* Summary */
        .summary-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .summary-box {
            width: 300px;
            background: var(--gray-light);
            padding: 1.5rem;
            border-radius: var(--radius);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            font-size: 0.95rem;
        }

        .summary-row.total {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--gray);
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-dark);
        }

        /* Footer */
        .invoice-footer {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray);
            text-align: center;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .invoice-footer p {
            margin-bottom: 0.3rem;
        }

        /* Notes */
        .notes-section {
            background: var(--accent-soft);
            border-left: 4px solid var(--accent);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-top: 1rem;
        }

        .notes-section h4 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: var(--accent-dark);
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--gray-light);
            border-color: var(--primary);
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
            
            .info-section {
                grid-template-columns: 1fr;
            }
            
            .summary-section {
                justify-content: stretch;
            }
            
            .summary-box {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Print styles */
        @media print {
            .header, .breadcrumb, .btn-back, .btn-print, .action-buttons, .footer {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .invoice-container {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .invoice-header {
                background: #f0f0f0;
                color: black;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
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
                        <?php echo strtoupper(substr($factura['cliente_nombre'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars(explode(' ', $factura['cliente_nombre'])[0]); ?></h4>
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
        <a href="historial_compras.php">Mis Compras</a>
        <i class='bx bx-chevron-right'></i>
        <span>Factura <?php echo $factura['numero_factura']; ?></span>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class='bx bx-receipt'></i>
                Factura <?php echo $factura['numero_factura']; ?>
            </h1>
            <div style="display: flex; gap: 1rem;">
                <button onclick="window.print()" class="btn-print">
                    <i class='bx bx-printer'></i>
                    Imprimir
                </button>
                <a href="historial_compras.php" class="btn-back">
                    <i class='bx bx-arrow-back'></i>
                    Volver
                </a>
            </div>
        </div>

        <!-- Invoice Container -->
        <div class="invoice-container">
            <!-- Header -->
            <div class="invoice-header">
                <div class="invoice-title">
                    <h2>VetNexo</h2>
                    <p>RIF: [Tax ID not configured]</p>
                </div>
                <div class="invoice-status">
                    <i class='bx bx-<?php echo $factura['estado'] == 'paid' ? 'check-circle' : ($factura['estado'] == 'pending' ? 'time' : 'x-circle'); ?>'></i>
                    <?php echo getEstadoFacturaTexto($factura['estado']); ?>
                </div>
            </div>

            <!-- Body -->
            <div class="invoice-body">
                <!-- Información de la factura -->
                <div class="info-section">
                    <div class="info-box">
                        <h3>
                            <i class='bx bx-building'></i>
                            Empresa
                        </h3>
                        <div class="info-row">
                            <span class="info-label">Nombre:</span>
                            <span class="info-value">VetNexo</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">RIF:</span>
                            <span class="info-value">[Tax ID not configured]</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Teléfono:</span>
                            <span class="info-value">[Clinic phone not configured]</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span class="info-value">billing@vetnexo.example</span>
                        </div>
                    </div>

                    <div class="info-box">
                        <h3>
                            <i class='bx bx-user'></i>
                            Cliente
                        </h3>
                        <div class="info-row">
                            <span class="info-label">Nombre:</span>
                            <span class="info-value"><?php echo htmlspecialchars($factura['cliente_nombre']); ?></span>
                        </div>
                        <?php if ($factura['cliente_documento']): ?>
                        <div class="info-row">
                            <span class="info-label">Documento:</span>
                            <span class="info-value"><?php echo htmlspecialchars($factura['cliente_documento']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="info-label">Teléfono:</span>
                            <span class="info-value"><?php echo htmlspecialchars($factura['cliente_telefono'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($factura['cliente_email']); ?></span>
                        </div>
                        <?php if ($factura['cliente_direccion']): ?>
                        <div class="info-row">
                            <span class="info-label">Dirección:</span>
                            <span class="info-value"><?php echo htmlspecialchars($factura['cliente_direccion']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Detalles de la factura -->
                <div class="invoice-details">
                    <div class="details-header">
                        <h3>
                            <i class='bx bx-detail'></i>
                            Detalles de la factura
                        </h3>
                        <div style="display: flex; gap: 2rem;">
                            <div>
                                <span style="color: var(--text-light);">N° Factura:</span>
                                <strong style="margin-left: 0.5rem;"><?php echo $factura['numero_factura']; ?></strong>
                            </div>
                            <div>
                                <span style="color: var(--text-light);">Fecha:</span>
                                <strong style="margin-left: 0.5rem;"><?php echo date('d/m/Y H:i', strtotime($factura['fecha_emision'])); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla de items -->
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Descripción</th>
                                    <th class="text-right">Cant.</th>
                                    <th class="text-right">P.Unitario</th>
                                    <th class="text-right">Descuento</th>
                                    <th class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detalles as $detalle): ?>
                                <tr>
                                    <td>
                                        <div class="item-name"><?php echo htmlspecialchars($detalle['item_nombre']); ?></div>
                                        <?php if (!empty($detalle['item_descripcion'])): ?>
                                            <div class="item-desc"><?php echo htmlspecialchars(substr($detalle['item_descripcion'], 0, 50)) . (strlen($detalle['item_descripcion']) > 50 ? '...' : ''); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right"><?php echo $detalle['cantidad']; ?></td>
                                    <td class="text-right">$<?php echo number_format($detalle['precio_unitario'], 2); ?></td>
                                    <td class="text-right"><?php echo $detalle['descuento'] > 0 ? '$' . number_format($detalle['descuento'], 2) : '-'; ?></td>
                                    <td class="text-right">$<?php echo number_format($detalle['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Resumen -->
                    <div class="summary-section">
                        <div class="summary-box">
                            <div class="summary-row">
                                <span>Subtotal:</span>
                                <span>$<?php echo number_format($factura['subtotal'], 2); ?></span>
                            </div>
                            <?php if ($factura['impuestos'] > 0): ?>
                            <div class="summary-row">
                                <span>IVA (16%):</span>
                                <span>$<?php echo number_format($factura['impuestos'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="summary-row total">
                                <span>Total:</span>
                                <span>$<?php echo number_format($factura['total'], 2); ?></span>
                            </div>
                            <div class="summary-row" style="margin-top: 1rem;">
                                <span>Método de pago:</span>
                                <span><?php echo getMetodoPagoTexto($factura['metodo_pago']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Notas -->
                    <?php if (!empty($factura['notas'])): ?>
                    <div class="notes-section">
                        <h4><i class='bx bx-note'></i> Notas:</h4>
                        <p><?php echo nl2br(htmlspecialchars($factura['notas'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Información de usuario que generó -->
                    <?php if ($factura['usuario_nombre']): ?>
                    <div style="margin-top: 1rem; font-size: 0.8rem; color: var(--text-light); text-align: right;">
                        Generado por: <?php echo htmlspecialchars($factura['usuario_nombre']); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="invoice-footer">
                    <p>¡Gracias por confiar en VetNexo!</p>
                    <p>Esta factura es un documento oficial. Cualquier duda o consulta, contáctanos.</p>
                </div>
            </div>
        </div>

        <!-- Botones de acción adicionales -->
        <div class="action-buttons">
            <?php if ($pedido): ?>
                <a href="ver_pedido.php?id=<?php echo $pedido['id']; ?>" class="btn btn-outline">
                    <i class='bx bx-package'></i>
                    Ver Pedido Asociado
                </a>
            <?php endif; ?>
            
            <a href="historial_compras.php" class="btn btn-outline">
                <i class='bx bx-history'></i>
                Volver al Historial
            </a>
            
            <?php if ($factura['estado'] == 'pending' || $factura['estado'] == 'partial'): ?>
                <span class="btn btn-primary" style="cursor: default; opacity: .9;">
                    <i class='bx bx-info-circle'></i>
                    Pago pendiente de registrar por el personal
                </span>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        // Función para imprimir
        window.onload = function() {
            // Si viene con parámetro print, imprimir automáticamente
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('print') === '1') {
                window.print();
            }
        };
    </script>
</body>
</html>