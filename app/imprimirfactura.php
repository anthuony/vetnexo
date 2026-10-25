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
                <button onclick="window.close()" style="padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">Cerrar</button>
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
    error_log("[VetNexo][imprimirfactura] " . $e->getMessage()); die("No se pudo cargar la factura.");
}

// Forzar impresión automática
$auto_print = isset($_GET['auto']) ? $_GET['auto'] : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imprimir Factura #<?php echo $factura['numero_factura']; ?> - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    
    <style>
        /* Variables de diseño iguales a verfactura.php */
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
            --gradient-primary: linear-gradient(135deg, #2E7D32 0%, #4CAF50 100%);
            --gradient-accent: linear-gradient(135deg, #FF9800 0%, #FFB74D 100%);
        }

        /* Reset y base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            background: white;
            line-height: 1.5;
            padding: 20px;
        }

        /* Contenedor principal - Tamaño A4 */
        .invoice-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: var(--white);
            position: relative;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        /* Estilos para pantalla */
        @media screen {
            body {
                background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e9 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
            }
            
            .invoice-container {
                border-radius: 24px;
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
        }

        /* Estilos para impresión */
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .invoice-container {
                width: 100%;
                height: auto;
                min-height: 100%;
                box-shadow: none;
                border-radius: 0;
                margin: 0;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            /* Asegurar que no se corten elementos */
            .page-break {
                page-break-before: always;
            }
            
            .avoid-break {
                page-break-inside: avoid;
            }
        }

        /* Header con gradiente */
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
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.1;
        }

        .clinic-info {
            position: relative;
            z-index: 1;
        }

        .clinic-name {
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .clinic-name i {
            font-size: 32px;
            color: var(--accent-light);
        }

        .clinic-tagline {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 300;
            margin-bottom: 15px;
            font-style: italic;
        }

        .clinic-details {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            margin-top: 15px;
            font-size: 12px;
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
            padding: 25px 40px;
            background: var(--gray-light);
            border-bottom: 1px solid var(--border);
        }

        .invoice-number-box {
            text-align: center;
            background: var(--white);
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            min-width: 200px;
        }

        .invoice-label {
            color: var(--text-light);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .invoice-number {
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .invoice-date {
            color: var(--text-dark);
            font-weight: 500;
            font-size: 14px;
        }

        .invoice-status {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 11px;
            margin-top: 10px;
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

        /* Datos del cliente y vendedor */
        .client-section {
            padding: 30px 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            border-bottom: 2px solid var(--border);
        }

        .section-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 16px;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
        }

        .section-title i {
            font-size: 18px;
        }

        .client-info-card, .seller-info-card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
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
            font-size: 12px;
            font-weight: 500;
        }

        .info-value {
            color: var(--text-dark);
            font-weight: 500;
            font-size: 14px;
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
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .products-table thead {
            background: var(--gradient-primary);
            color: white;
        }

        .products-table th {
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
        }

        .products-table tbody tr {
            background: var(--white);
            transition: all 0.3s ease;
        }

        .products-table tbody tr:nth-child(even) {
            background: var(--gray-light);
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
            font-size: 12px;
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
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
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
            font-size: 14px;
        }

        .total-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
        }

        .discount-row .total-value {
            color: var(--danger);
        }

        .subtotal-row {
            font-size: 15px;
        }

        .iva-row .total-label::after {
            content: "";
            color: var(--text-light);
            font-size: 12px;
        }

        .grand-total {
            font-size: 18px;
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
            border-radius: 8px;
            border-left: 4px solid var(--accent);
            font-style: italic;
            color: var(--text-light);
            font-size: 14px;
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
            font-size: 16px;
            margin-bottom: 15px;
            color: var(--accent-light);
        }

        .footer-text {
            font-size: 13px;
            opacity: 0.8;
            margin-bottom: 8px;
        }

        .thank-you {
            font-size: 16px;
            font-weight: 600;
            margin-top: 15px;
            color: var(--accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Espacio para firmas */
        .signatures-section {
            padding: 30px 40px;
            border-top: 1px dashed var(--border);
            margin-top: 20px;
        }

        .signatures-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 20px;
        }

        .signature-box {
            text-align: center;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
        }

        .signature-line {
            height: 1px;
            background: var(--text-dark);
            margin: 40px 0 10px;
        }

        .signature-label {
            color: var(--text-light);
            font-size: 13px;
            margin-top: 10px;
        }

        /* Botones de control (solo pantalla) */
        .print-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 180px;
        }

        .print-controls::before {
            content: '🎯 Controles';
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 10px;
            display: block;
            text-align: center;
            font-size: 14px;
        }

        .action-btn {
            padding: 14px 20px;
            border: none;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-print {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-print:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.4);
        }

        .btn-close {
            background: var(--text-light);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .btn-close:hover {
            background: var(--text-dark);
            transform: translateY(-3px);
        }

        /* Decoración de huellas */
        .paw-print {
            position: absolute;
            opacity: 0.03;
            font-size: 24px;
            z-index: 0;
        }

        /* Responsive para pantalla */
        @media screen and (max-width: 900px) {
            .invoice-container {
                width: 100%;
                max-width: 800px;
                min-height: auto;
                margin: 20px;
            }
            
            .client-section {
                grid-template-columns: 1fr;
            }
            
            .invoice-info-section {
                flex-direction: column;
                gap: 20px;
                align-items: center;
            }
            
            .print-controls {
                position: static;
                margin: 20px auto;
                max-width: 300px;
            }
        }

        @media screen and (max-width: 600px) {
            .invoice-header,
            .invoice-info-section,
            .client-section,
            .products-section,
            .totals-section,
            .notes-section,
            .invoice-footer {
                padding: 20px;
            }
            
            .clinic-name {
                font-size: 22px;
            }
            
            .products-table {
                font-size: 12px;
            }
            
            .products-table th,
            .products-table td {
                padding: 12px 8px;
            }
        }

        /* Optimización para impresión a color */
        @media print and (color) {
            .invoice-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .products-table thead {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .invoice-status {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body onload="<?php echo $auto_print == '1' ? 'window.print();' : ''; ?>">
    <!-- Controles de impresión (solo en pantalla) -->
    <div class="print-controls no-print">
        <button onclick="window.print()" class="action-btn btn-print">
            <i class='bx bx-printer'></i> Imprimir
        </button>
        <button onclick="window.close()" class="action-btn btn-close">
            <i class='bx bx-x'></i> Cerrar
        </button>
        <div style="text-align: center; margin-top: 10px; font-size: 11px; color: var(--text-light);">
            🐾 Tamaño A4 optimizado
        </div>
    </div>

    <!-- Decoración con huellas -->
    <div class="paw-print" style="top: 15%; left: 8%;">🐾</div>
    <div class="paw-print" style="top: 25%; right: 10%;">🐾</div>
    <div class="paw-print" style="bottom: 20%; left: 12%;">🐾</div>
    <div class="paw-print" style="bottom: 30%; right: 8%;">🐾</div>

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
                    <div class="info-item">
                        <span class="info-label">Sucursal</span>
                        <span class="info-value">Sede Principal - Caracas</span>
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
                        <span class="info-label">Sistema</span>
                        <span class="info-value">VetMJ VetNexo</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Documento</span>
                        <span class="info-value">Factura Original</span>
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
                    <tr class="avoid-break">
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
                            <i class='bx bx-package' style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                            No hay productos o servicios registrados
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

        <!-- Espacio para firmas -->
        <div class="signatures-section">
            <h3 class="section-title" style="border-bottom: none; text-align: center;">
                <i class='bx bx-edit-alt'></i>
                Firmas Autorizadas
            </h3>
            <div class="signatures-grid">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del Cliente</div>
                    <div style="font-size: 11px; color: var(--text-light); margin-top: 5px;">
                        Nombre: <?php echo htmlspecialchars($factura['cliente_nombre']); ?>
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma Autorizada</div>
                    <div style="font-size: 11px; color: var(--text-light); margin-top: 5px;">
                        VetNexo
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="invoice-footer">
            <div class="footer-content">
                <h4 class="footer-title">VetNexo</h4>
                <p class="footer-text">Especialistas en salud animal y medicina veterinaria</p>
                <p class="footer-text">Horario: Lunes a Viernes 8:00 AM - 6:00 PM | Sábados 8:00 AM - 1:00 PM</p>
                <p class="footer-text">www.vetnexo.example | Emergencias: ((305) 555-0100</p>
                
                <div class="thank-you">
                    <i class='bx bx-heart'></i>
                    ¡Gracias por confiar en nosotros!
                    <i class='bx bx-heart'></i>
                </div>
                
                <p class="footer-text" style="margin-top: 15px; font-size: 11px; opacity: 0.7;">
                    Documento generado el <?php echo date('d/m/Y H:i:s'); ?> | 
                    Esta factura es un comprobante legal válido | 
                    Conserve este documento para cualquier reclamo
                </p>
            </div>
        </div>
    </div>

    <script>
        // Impresión automática si está configurado
        <?php if ($auto_print == '1'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Pequeño delay para que cargue todo
            setTimeout(function() {
                window.print();
            }, 800);
        });
        <?php endif; ?>

        // Mejorar experiencia de impresión
        window.addEventListener('beforeprint', function() {
            console.log('🎯 Preparando para imprimir...');
            // Agregar clase para ocultar controles
            document.querySelectorAll('.no-print').forEach(function(el) {
                el.style.display = 'none';
            });
        });

        window.addEventListener('afterprint', function() {
            console.log('✅ Impresión completada');
            // Mostrar controles nuevamente
            document.querySelectorAll('.no-print').forEach(function(el) {
                el.style.display = 'block';
            });
            
            // Opcional: preguntar si cerrar
            setTimeout(function() {
                if (confirm('¿Desea cerrar esta ventana de impresión?')) {
                    window.close();
                }
            }, 500);
        });

        // Atajo de teclado para imprimir
        document.addEventListener('keydown', function(e) {
            // Ctrl+P o Cmd+P
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Escape para cerrar
            if (e.key === 'Escape') {
                if (confirm('¿Cerrar la ventana de impresión?')) {
                    window.close();
                }
            }
        });

        // Botón personalizado de imprimir
        function customPrint() {
            // Agregar efectos visuales
            const printBtn = document.querySelector('.btn-print');
            const originalText = printBtn.innerHTML;
            
            printBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Preparando...';
            printBtn.disabled = true;
            
            setTimeout(function() {
                window.print();
                
                // Restaurar botón
                setTimeout(function() {
                    printBtn.innerHTML = originalText;
                    printBtn.disabled = false;
                }, 1000);
            }, 500);
        }
    </script>
</body>
</html>