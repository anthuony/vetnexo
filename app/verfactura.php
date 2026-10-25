<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

include 'config/database.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: facturas.php');
    exit();
}

$factura_id = intval($_GET['id']);

try {
    $db = getDBConnection();
    
    // Obtener información de la factura
    $sql = "SELECT f.*, 
            c.nombre as cliente_nombre,
            c.documento_identidad as cliente_documento,
            c.telefono as cliente_telefono,
            c.direccion as cliente_direccion,
            c.email as cliente_email,
            u.nombre as vendedor_nombre,
            COALESCE(SUM(fd.descuento), 0) as total_descuento
            FROM facturas f 
            LEFT JOIN clientes c ON f.cliente_id = c.id 
            LEFT JOIN usuarios u ON f.usuario_id = u.id 
            LEFT JOIN factura_detalles fd ON f.id = fd.factura_id
            WHERE f.id = ?
            GROUP BY f.id";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$factura_id]);
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$factura) {
        die('<div style="padding: 40px; text-align: center; font-family: Arial;">
                <h2 style="color: #e74c3c;">Factura no encontrada</h2>
                <p>La factura solicitada no existe en el sistema.</p>
                <a href="facturas.php" style="color: #3498db; text-decoration: none;">← Volver al historial</a>
            </div>');
    }
    
    // Obtener detalles de la factura
    $sql_detalles = "SELECT * FROM factura_detalles WHERE factura_id = ?";
    $stmt_detalles = $db->prepare($sql_detalles);
    $stmt_detalles->execute([$factura_id]);
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular totales
    $subtotal = 0;
    $descuento_total = 0;
    foreach ($detalles as $detalle) {
        $item_subtotal = $detalle['cantidad'] * $detalle['precio_unitario'];
        $subtotal += $item_subtotal;
        $descuento_total += $detalle['descuento'] ?? 0;
    }
    
    $subtotal = (float)$factura['subtotal'] + $descuento_total;
    $iva = (float)$factura['impuestos'];
    $total_con_iva = (float)$factura['total'];
    $total_final = (float)$factura['total'];
    
} catch (PDOException $e) {
    error_log("[VetNexo][verfactura] " . $e->getMessage()); die("No se pudo cargar la factura.");
}

$page_title = "Factura #" . $factura['numero_factura'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    
    <style>
        :root {
            --primary: #2E7D32;
            --primary-light: #4CAF50;
            --primary-dark: #1B5E20;
            --accent: #FF9800;
            --accent-light: #FFB74D;
            --secondary: #2196F3;
            --success: #4CAF50;
            --warning: #FF9800;
            --danger: #F44336;
            --text-dark: #2C3E50;
            --text-light: #7F8C8D;
            --text-lighter: #95A5A6;
            --white: #FFFFFF;
            --gray-light: #F8F9FA;
            --gray: #ECF0F1;
            --border: #E0E0E0;
            --shadow-sm: 0 2px 12px rgba(0, 0, 0, 0.08);
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 8px 40px rgba(0, 0, 0, 0.15);
            --radius-sm: 10px;
            --radius: 16px;
            --radius-lg: 24px;
            --gradient-primary: linear-gradient(135deg, #2E7D32 0%, #4CAF50 100%);
            --gradient-accent: linear-gradient(135deg, #FF9800 0%, #FFB74D 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e9 100%);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            line-height: 1.6;
        }

        .invoice-container {
            width: 100%;
            max-width: 900px;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: relative;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Header con patrón veterinario */
        .invoice-header {
            background: var(--gradient-primary);
            color: white;
            padding: 30px 40px;
            position: relative;
            overflow: hidden;
        }

        .invoice-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.1;
        }

        .clinic-info {
            position: relative;
            z-index: 1;
        }

        .clinic-name {
            font-family: 'Montserrat', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .clinic-name i {
            font-size: 2.5rem;
            color: var(--accent-light);
        }

        .clinic-tagline {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 300;
            margin-bottom: 10px;
        }

        .clinic-details {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 15px;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .clinic-detail {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Información de la factura */
        .invoice-info-section {
            display: flex;
            justify-content: space-between;
            padding: 30px 40px;
            background: var(--gray-light);
            border-bottom: 1px solid var(--border);
        }

        .invoice-number-box {
            text-align: center;
            background: var(--white);
            padding: 20px 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            min-width: 200px;
        }

        .invoice-label {
            color: var(--text-light);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .invoice-number {
            font-family: 'Montserrat', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .invoice-date {
            color: var(--text-dark);
            font-weight: 500;
        }

        .invoice-status {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            margin-top: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid {
            background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
            color: #22543d;
        }

        .status-pending {
            background: linear-gradient(135deg, #fefcbf, #faf089);
            color: #744210;
        }

        .status-cancelled {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            color: #742a2a;
        }

        /* Datos del cliente */
        .client-section {
            padding: 30px 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            border-bottom: 2px solid var(--border);
        }

        .section-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.1rem;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
        }

        .section-title i {
            font-size: 1.3rem;
        }

        .client-info-card, .seller-info-card {
            background: var(--gray-light);
            padding: 25px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            transition: transform 0.3s ease;
        }

        .client-info-card:hover, .seller-info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }

        .info-grid {
            display: grid;
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            color: var(--text-light);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .info-value {
            color: var(--text-dark);
            font-weight: 500;
            font-size: 1rem;
        }

        /* Tabla de productos */
        .products-section {
            padding: 30px 40px;
        }

        .products-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 20px 0;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .products-table thead {
            background: var(--gradient-primary);
            color: white;
        }

        .products-table th {
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
        }

        .products-table th:first-child {
            border-top-left-radius: var(--radius);
        }

        .products-table th:last-child {
            border-top-right-radius: var(--radius);
        }

        .products-table tbody tr {
            background: var(--white);
            transition: all 0.3s ease;
        }

        .products-table tbody tr:nth-child(even) {
            background: var(--gray-light);
        }

        .products-table tbody tr:hover {
            background: #f0f7f0;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(46, 125, 50, 0.1);
        }

        .products-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .products-table tbody tr:last-child td {
            border-bottom: none;
        }

        .product-name {
            font-weight: 500;
            color: var(--text-dark);
        }

        .product-description {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 3px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Totales */
        .totals-section {
            padding: 30px 40px;
            background: var(--gray-light);
            border-top: 2px solid var(--border);
        }

        .totals-container {
            max-width: 400px;
            margin-left: auto;
            background: var(--white);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .total-row:last-child {
            border-bottom: none;
        }

        .total-label {
            color: var(--text-dark);
            font-weight: 500;
        }

        .total-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .discount-row .total-value {
            color: var(--danger);
        }

        .subtotal-row {
            font-size: 1.1rem;
        }

        .iva-row .total-label::after {
            content: "";
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .grand-total {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary);
            margin-top: 10px;
            padding-top: 15px;
            border-top: 2px solid var(--primary-light);
        }

        /* Observaciones */
        .notes-section {
            padding: 25px 40px;
            background: linear-gradient(135deg, #FFF9E6 0%, #FFF3E0 100%);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .notes-content {
            padding: 15px;
            background: white;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--accent);
            font-style: italic;
            color: var(--text-light);
        }

        /* Footer */
        .invoice-footer {
            padding: 30px 40px;
            background: var(--text-dark);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .invoice-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03' fill-rule='evenodd'%3E%3Ccircle cx='3' cy='3' r='3'/%3E%3Ccircle cx='13' cy='13' r='3'/%3E%3C/g%3E%3C/svg%3E");
        }

        .footer-content {
            position: relative;
            z-index: 1;
        }

        .footer-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: var(--accent-light);
        }

        .footer-text {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 8px;
        }

        .thank-you {
            font-size: 1.2rem;
            font-weight: 600;
            margin-top: 15px;
            color: var(--accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Botones de acción */
        .action-buttons {
            padding: 25px 40px;
            background: var(--white);
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            border-top: 1px solid var(--border);
        }

        .action-btn {
            padding: 14px 28px;
            border: none;
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: var(--shadow-sm);
        }

        .btn-print {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-print:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.3);
        }

        .btn-download {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-download:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-3px);
        }

        .btn-close {
            background: var(--text-light);
            color: white;
        }

        .btn-close:hover {
            background: var(--text-dark);
            transform: translateY(-3px);
        }

        /* Decoración de animales */
        .animal-decoration {
            position: absolute;
            opacity: 0.1;
            z-index: 0;
        }

        .paw-print {
            position: absolute;
            font-size: 2rem;
            opacity: 0.05;
        }

        .paw-print:nth-child(1) { top: 10%; left: 5%; }
        .paw-print:nth-child(2) { top: 20%; right: 8%; }
        .paw-print:nth-child(3) { bottom: 15%; left: 10%; }
        .paw-print:nth-child(4) { bottom: 25%; right: 5%; }

        /* Responsive */
        @media (max-width: 768px) {
            .invoice-container {
                border-radius: var(--radius);
            }

            .invoice-header,
            .invoice-info-section,
            .client-section,
            .products-section,
            .totals-section,
            .notes-section,
            .invoice-footer,
            .action-buttons {
                padding: 20px;
            }

            .clinic-name {
                font-size: 1.8rem;
            }

            .client-section {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .invoice-info-section {
                flex-direction: column;
                gap: 20px;
                align-items: center;
            }

            .products-table {
                font-size: 0.9rem;
            }

            .products-table th,
            .products-table td {
                padding: 12px 10px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .products-table {
                display: block;
                overflow-x: auto;
            }

            .clinic-details {
                flex-direction: column;
                gap: 10px;
            }

            .invoice-number-box {
                min-width: auto;
                width: 100%;
            }
        }

        /* Estilos para impresión */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .invoice-container {
                box-shadow: none;
                max-width: 100%;
            }

            .action-buttons,
            .btn-close,
            .btn-download {
                display: none;
            }

            .btn-print {
                display: none !important;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Decoración con huellas -->
    <div class="paw-print">🐾</div>
    <div class="paw-print">🐾</div>
    <div class="paw-print">🐾</div>
    <div class="paw-print">🐾</div>

    <div class="invoice-container">
        <!-- Header de la factura -->
        <div class="invoice-header">
            <div class="clinic-info">
                <h1 class="clinic-name">
                    <i class='bx bx-plus-medical'></i>
                    VetNexo
                </h1>
                <p class="clinic-tagline">Cuidando la salud de tus mejores amigos desde 2010</p>
                
                <div class="clinic-details">
                    <div class="clinic-detail">
                        <i class='bx bx-map'></i>
                        <span>Av. Principal, Caracas, Your Region</span>
                    </div>
                    <div class="clinic-detail">
                        <i class='bx bx-phone'></i>
                        <span>(0412) 123-4567</span>
                    </div>
                    <div class="clinic-detail">
                        <i class='bx bx-envelope'></i>
                        <span>info@vetnexo.example</span>
                    </div>
                    <div class="clinic-detail">
                        <i class='bx bx-id-card'></i>
                        <span>RIF: [Tax ID not configured]</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Información de la factura -->
        <div class="invoice-info-section">
            <div class="invoice-number-box">
                <div class="invoice-label">Factura Número</div>
                <div class="invoice-number">#<?php echo htmlspecialchars($factura['numero_factura']); ?></div>
                <div class="invoice-date">
                    <?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?>
                </div>
                <span class="invoice-status status-<?php echo $factura['estado']; ?>">
                    <?php 
                    $estado_text = 'Pendiente';
                    if ($factura['estado'] === 'pagada') $estado_text = 'Pagada';
                    if ($factura['estado'] === 'cancelled') $estado_text = 'Cancelada';
                    echo $estado_text;
                    ?>
                </span>
            </div>
            
            <div style="max-width: 300px;">
                <h3 class="section-title">
                    <i class='bx bx-receipt'></i>
                    Detalles de Facturación
                </h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Forma de Pago</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['forma_pago'] ?? 'Efectivo'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Válido hasta</span>
                        <span class="info-value">
                            <?php 
                            $valido_hasta = date('d/m/Y', strtotime($factura['fecha_emision'] . ' + 30 days'));
                            echo $valido_hasta;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Datos del cliente y vendedor -->
        <div class="client-section">
            <div class="client-info-card">
                <h3 class="section-title">
                    <i class='bx bx-user'></i>
                    Datos del Cliente
                </h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Nombre / Razón Social</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['cliente_nombre']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Documento de Identidad</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['cliente_documento']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Teléfono</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['cliente_telefono']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Dirección</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['cliente_direccion']); ?></span>
                    </div>
                    <?php if (!empty($factura['cliente_email'])): ?>
                    <div class="info-item">
                        <span class="info-label">Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['cliente_email']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="seller-info-card">
                <h3 class="section-title">
                    <i class='bx bx-user-check'></i>
                    Información del Vendedor
                </h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Atendido por</span>
                        <span class="info-value"><?php echo htmlspecialchars($factura['vendedor_nombre'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fecha de Emisión</span>
                        <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($factura['fecha_emision'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Sucursal</span>
                        <span class="info-value">Sede Principal - Caracas</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Tipo de Factura</span>
                        <span class="info-value">Factura de Venta</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de productos/servicios -->
        <div class="products-section">
            <h3 class="section-title">
                <i class='bx bx-cart'></i>
                Productos y Servicios
            </h3>
            
            <table class="products-table">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="45%">Descripción</th>
                        <th width="10%" class="text-center">Cantidad</th>
                        <th width="15%" class="text-right">Precio Unitario</th>
                        <th width="15%" class="text-right">Descuento</th>
                        <th width="15%" class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contador = 1;
                    foreach ($detalles as $detalle): 
                        $item_subtotal = $detalle['cantidad'] * $detalle['precio_unitario'];
                        $item_descuento = $detalle['descuento'] ?? 0;
                        $item_total = $item_subtotal - $item_descuento;
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $contador++; ?></td>
                        <td>
                            <div class="product-name"><?php echo htmlspecialchars($detalle['descripcion']); ?></div>
                            <?php if (!empty($detalle['observaciones'])): ?>
                            <div class="product-description"><?php echo htmlspecialchars($detalle['observaciones']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo number_format($detalle['cantidad'], 2); ?></td>
                        <td class="text-right">$<?php echo number_format($detalle['precio_unitario'], 2, ',', '.'); ?></td>
                        <td class="text-right">
                            <?php if ($item_descuento > 0): ?>
                            <span style="color: var(--danger);">-$<?php echo number_format($item_descuento, 2, ',', '.'); ?></span>
                            <?php else: ?>
                            <span style="color: var(--text-lighter);">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">$<?php echo number_format($item_total, 2, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($detalles)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 30px; color: var(--text-light);">
                            <i class='bx bx-package' style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                            No hay productos o servicios registrados en esta factura
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Sección de observaciones -->
        <?php if (!empty($factura['observaciones'])): ?>
        <div class="notes-section">
            <h3 class="section-title">
                <i class='bx bx-note'></i>
                Observaciones
            </h3>
            <div class="notes-content">
                <?php echo nl2br(htmlspecialchars($factura['observaciones'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Totales -->
        <div class="totals-section">
            <div class="totals-container">
                <div class="total-row subtotal-row">
                    <span class="total-label">Subtotal</span>
                    <span class="total-value">$<?php echo number_format($subtotal, 2, ',', '.'); ?></span>
                </div>
                
                <?php if ($descuento_total > 0): ?>
                <div class="total-row discount-row">
                    <span class="total-label">Descuento Total</span>
                    <span class="total-value">-$<?php echo number_format($descuento_total, 2, ',', '.'); ?></span>
                </div>
                
                <div class="total-row">
                    <span class="total-label">Subtotal con Descuento</span>
                    <span class="total-value">$<?php echo number_format($subtotal - $descuento_total, 2, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="total-row iva-row">
                    <span class="total-label">IVA</span>
                    <span class="total-value">$<?php echo number_format($iva, 2, ',', '.'); ?></span>
                </div>
                
                <div class="total-row grand-total">
                    <span class="total-label">TOTAL A PAGAR</span>
                    <span class="total-value">$<?php echo number_format($total_final, 2, ',', '.'); ?></span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="invoice-footer">
            <div class="footer-content">
                <h4 class="footer-title">VetNexo</h4>
                <p class="footer-text">Especialistas en salud animal y medicina veterinaria</p>
                <p class="footer-text">Horario de atención: Lunes a Viernes 8:00 AM - 6:00 PM | Sábados 8:00 AM - 1:00 PM</p>
                <p class="footer-text">www.vetnexo.example | Emergencias: ((305) 555-0100</p>
                
                <div class="thank-you">
                    <i class='bx bx-heart'></i>
                    ¡Gracias por confiar en nosotros!
                    <i class='bx bx-heart'></i>
                </div>
                
                <p class="footer-text" style="margin-top: 15px; font-size: 0.8rem;">
                    Documento generado el <?php echo date('d/m/Y H:i:s'); ?> | 
                    Esta factura es un comprobante legal
                </p>
            </div>
        </div>

        <!-- Botones de acción -->
        <div class="action-buttons">
            <button onclick="window.print()" class="action-btn btn-print">
                <i class='bx bx-printer'></i> Imprimir Factura
            </button>
            <button onclick="downloadPDF()" class="action-btn btn-download">
                <i class='bx bx-download'></i> Descargar PDF
            </button>
            <button onclick="window.close()" class="action-btn btn-close">
                <i class='bx bx-x'></i> Cerrar Ventana
            </button>
        </div>
    </div>

    <script>
        // Imprimir automáticamente si se solicita
        if (window.location.search.includes('print=1')) {
            setTimeout(() => {
                window.print();
            }, 1000);
        }

        function downloadPDF() {
            // El navegador permite guardar la factura directamente como PDF desde el diálogo de impresión.
            window.print();
        }

        // Agregar animación al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.products-table tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateX(0)';
                }, index * 100);
            });
        });

        // Manejar impresión
        window.addEventListener('beforeprint', function(event) {
            document.querySelector('.action-buttons').style.display = 'none';
        });

        window.addEventListener('afterprint', function(event) {
            document.querySelector('.action-buttons').style.display = 'flex';
            alert('Impresión completada. La factura se ha generado correctamente.');
        });
    </script>
</body>
</html>