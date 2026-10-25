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

if (!isset($_GET['id'])) {
    header('Location: facturas.php');
    exit();
}

$factura_id = intval($_GET['id']);

try {
    $db = getDBConnection();
    
    // Obtener información del usuario actual para el avatar (opcional, no necesario en vista de factura)
    
    // Obtener información de la factura con descuento total
    $sql = "SELECT f.*, c.nombre,c.documento_identidad,c.telefono,c.direccion,c.email,u.nombre as vendedor,
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
        die('<div style="text-align: center; padding: 50px; color: var(--emergency);"><h2>Factura no encontrada</h2></div>');
    }
    
    // Obtener detalles de la factura
    $sql_detalles = "SELECT * FROM factura_detalles WHERE factura_id = ?";
    $stmt_detalles = $db->prepare($sql_detalles);
    $stmt_detalles->execute([$factura_id]);
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular subtotal, IVA y total
    $subtotal = 0;
    $descuento_total = 0;
    $base_imponible = 0; // Para productos con IVA
    $base_exenta = 0;    // Para servicios y productos sin IVA
    
    foreach ($detalles as $detalle) {
        $item_subtotal = $detalle['cantidad'] * $detalle['precio_unitario'];
        $subtotal += $item_subtotal;
        $descuento_total += $detalle['descuento'] ?? 0;
        
        // Clasificar según aplique IVA o no
        if (($detalle['aplica_iva'] ?? 0) == 1) {
            $base_imponible += $item_subtotal - ($detalle['descuento'] ?? 0);
        } else {
            $base_exenta += $item_subtotal - ($detalle['descuento'] ?? 0);
        }
    }
    
    // Calcular IVA sobre base imponible (productos con IVA)
    $subtotal = (float)$factura['subtotal'] + $descuento_total;
    $iva = (float)$factura['impuestos'];
    $total = (float)$factura['total'];
    
    // Formas de pago para mostrar
    $formas_pago = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'CONTADO' => 'Contado',
        'DIVISAS' => 'Divisas',
        'PAGO_MOVIL' => 'Pago Móvil',
        'TARJETA_DEBITO' => 'Tarjeta Débito',
        'TARJETA_CREDITO' => 'Tarjeta Crédito',
        'TRANSFERENCIA' => 'Transferencia'
    ];
    
    $metodo_pago_texto = $formas_pago[$factura['metodo_pago']] ?? $factura['metodo_pago'] ?? 'Efectivo';
    
} catch (PDOException $e) {
    error_log("[VetNexo][ver_factura] " . $e->getMessage()); die('<div style="text-align:center;padding:50px"><h2>No se pudo cargar la factura.</h2></div>');
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
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
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
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            background: var(--background);
            padding: 20px;
            line-height: 1.6;
        }
        
        .factura-container {
            max-width: 900px;
            margin: 0 auto;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            border: 1px solid var(--gray);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--primary);
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        
        .empresa-info {
            flex: 1;
        }
        
        .empresa-info h1 {
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .empresa-info p {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .empresa-info i {
            color: var(--primary);
            width: 20px;
            font-size: 1rem;
        }
        
        .factura-info {
            text-align: right;
        }
        
        .factura-info h2 {
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
            font-weight: 700;
        }
        
        .factura-info p {
            margin-bottom: 0.3rem;
            color: var(--text-dark);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        
        .factura-info i {
            color: var(--primary);
            width: 20px;
        }
        
        .estado-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-top: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .estado-paid, .estado-pagada {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }
        
        .estado-pending {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }
        
        .estado-cancelled {
            background: var(--emergency-soft);
            color: var(--emergency-dark);
        }
        
        .cliente-info {
            margin-bottom: 2rem;
            background: var(--primary-soft);
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
        }
        
        .cliente-info h3 {
            color: var(--primary-dark);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .cliente-info h3 i {
            color: var(--primary);
            font-size: 1.2rem;
        }
        
        .cliente-datos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .cliente-dato {
            display: flex;
            flex-direction: column;
        }
        
        .cliente-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
            margin-bottom: 0.2rem;
        }
        
        .cliente-valor {
            color: var(--text-light);
            font-size: 0.95rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            font-size: 0.9rem;
        }
        
        th {
            background: var(--primary);
            color: var(--white);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        th:first-child {
            border-top-left-radius: var(--radius-sm);
        }
        
        th:last-child {
            border-top-right-radius: var(--radius-sm);
        }
        
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-light);
            color: var(--text-dark);
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tbody tr:hover {
            background: var(--primary-soft);
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .totales {
            margin-top: 2rem;
            background: var(--primary-soft);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--primary-light);
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed var(--gray);
        }
        
        .total-row:last-child {
            border-bottom: none;
        }
        
        .total-label {
            font-weight: 500;
            color: var(--text-light);
        }
        
        .total-value {
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .iva-row {
            background: var(--accent-soft);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            margin: 0.5rem 0;
        }
        
        .gran-total {
            font-size: 1.2rem;
            color: var(--primary-dark);
            font-weight: 700;
            border-top: 2px solid var(--primary);
            padding-top: 1rem;
            margin-top: 1rem;
        }
        
        .gran-total .total-label {
            color: var(--primary-dark);
            font-weight: 600;
        }
        
        .gran-total .total-value {
            color: var(--primary-dark);
            font-size: 1.3rem;
        }
        
        .observaciones {
            margin-top: 1.5rem;
            padding: 1rem;
            background: var(--white);
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--azul);
            color: var(--text-light);
            font-style: italic;
            font-size: 0.9rem;
        }
        
        .footer {
            margin-top: 2rem;
            text-align: center;
            color: var(--text-lighter);
            font-size: 0.8rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray);
        }
        
        .action-buttons {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
            color: var(--white);
        }
        
        .btn-info:hover {
            background: linear-gradient(135deg, var(--azul-light), #3a6ab8);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, var(--accent-dark), #d88206);
        }
        
        .iva-badge {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .iva-badge.yes {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }
        
        .iva-badge.no {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .factura-container {
                box-shadow: none;
                padding: 1rem;
            }
            
            .action-buttons {
                display: none;
            }
            
            .btn {
                display: none;
            }
            
            th {
                background: var(--primary) !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .estado-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1.5rem;
            }
            
            .empresa-info h1,
            .factura-info h2 {
                text-align: center;
            }
            
            .factura-info p {
                justify-content: center;
            }
            
            .cliente-datos {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 0.8rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <div class="factura-container">
        <!-- Header con logo de la empresa -->
        <div class="header">
            <div class="empresa-info">
                <h1>
                    <img src="includes/img/logo.svg" alt="VetNexo" style="height: 50px; vertical-align: middle; margin-right: 10px;">
                    VetNexo
                </h1>
                <p><i class='bx bx-id-card'></i> RIF: J-29763608-0</p>
                <p><i class='bx bx-phone'></i> (0424) 960 29 06 - (0426) 462 33 56</p>
                <p><i class='bx bx-envelope'></i> info@vetnexo.example</p>
                <p><i class='bx bx-map'></i> Demo Veterinary Clinic</p>
            </div>
            <div class="factura-info">
                <h2>FACTURA #<?php echo htmlspecialchars($factura['numero_factura']); ?></h2>
                <p><i class='bx bx-calendar'></i> <?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></p>
                <p><i class='bx bx-user'></i> Vendedor: <?php echo htmlspecialchars($factura['vendedor'] ?? 'Sistema'); ?></p>
                <p><i class='bx bx-credit-card'></i> <?php echo htmlspecialchars($metodo_pago_texto); ?></p>
                <span class="estado-badge estado-<?php echo $factura['estado']; ?>">
                    <i class='bx <?php 
                        if ($factura['estado'] === 'paid') echo 'bx-check-circle';
                        elseif ($factura['estado'] === 'pending') echo 'bx-time-five';
                        elseif ($factura['estado'] === 'cancelled') echo 'bx-x-circle';
                        else echo 'bx-info-circle';
                    ?>'></i>
                    <?php 
                    $estado_text = 'Pendiente';
                    if ($factura['estado'] === 'paid') $estado_text = 'Pagada';
                    if ($factura['estado'] === 'pending') $estado_text = 'Pendiente';
                    if ($factura['estado'] === 'cancelled') $estado_text = 'Cancelada';
                    echo $estado_text;
                    ?>
                </span>
            </div>
        </div>
        
        <!-- Datos del Cliente -->
        <div class="cliente-info">
            <h3><i class='bx bx-user-circle'></i> Datos del Cliente</h3>
            <div class="cliente-datos">
                <div class="cliente-dato">
                    <span class="cliente-label">Nombre/Razón Social</span>
                    <span class="cliente-valor"><?php echo htmlspecialchars($factura['nombre'] ?? 'No especificado'); ?></span>
                </div>
                <div class="cliente-dato">
                    <span class="cliente-label">RIF/Cédula</span>
                    <span class="cliente-valor"><?php echo htmlspecialchars($factura['documento_identidad'] ?? 'No especificado'); ?></span>
                </div>
                <div class="cliente-dato">
                    <span class="cliente-label">Teléfono</span>
                    <span class="cliente-valor"><?php echo htmlspecialchars($factura['telefono'] ?? 'No especificado'); ?></span>
                </div>
                <div class="cliente-dato">
                    <span class="cliente-label">Dirección</span>
                    <span class="cliente-valor"><?php echo htmlspecialchars($factura['direccion'] ?? 'No especificada'); ?></span>
                </div>
                <?php if (!empty($factura['email'])): ?>
                <div class="cliente-dato">
                    <span class="cliente-label">Email</span>
                    <span class="cliente-valor"><?php echo htmlspecialchars($factura['email']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tabla de detalles -->
        <table>
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="40%">Descripción</th>
                    <th width="8%">Cant.</th>
                    <th width="12%">P.Unitario</th>
                    <th width="10%">Descuento</th>
                    <th width="15%">IVA</th>
                    <th width="10%">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $contador = 1;
                foreach ($detalles as $detalle): 
                    $item_subtotal = $detalle['cantidad'] * $detalle['precio_unitario'];
                    $item_descuento = $detalle['descuento'] ?? 0;
                    $item_total = $item_subtotal - $item_descuento;
                    $aplica_iva = ($detalle['aplica_iva'] ?? 0) == 1;
                ?>
                <tr>
                    <td class="text-center"><?php echo $contador++; ?></td>
                    <td>
                        <?php echo htmlspecialchars($detalle['descripcion']); ?>
                        <?php if ($detalle['tipo_item'] ?? ''): ?>
                            <br><small style="color: var(--text-lighter);"><?php echo ucfirst($detalle['tipo_item']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?php echo number_format($detalle['cantidad'], 2, ',', '.'); ?></td>
                    <td class="text-right">$<?php echo number_format($detalle['precio_unitario'], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo $item_descuento > 0 ? '$' . number_format($item_descuento, 2, ',', '.') : '-'; ?></td>
                    <td class="text-center">
                        <span class="iva-badge <?php echo $aplica_iva ? 'yes' : 'no'; ?>">
                            <?php echo $aplica_iva ? ($detalle['porcentaje_iva'] ?? '16') . '%' : 'Exento'; ?>
                        </span>
                    </td>
                    <td class="text-right"><strong>$<?php echo number_format($item_total, 2, ',', '.'); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($detalles)): ?>
                <tr>
                    <td colspan="7" class="text-center" style="padding: 2rem; color: var(--text-lighter);">
                        <i class='bx bx-info-circle' style="font-size: 2rem;"></i><br>
                        No hay detalles para esta factura
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Totales y resumen -->
        <div class="totales">
            <div class="total-row">
                <span class="total-label">Subtotal:</span>
                <span class="total-value">$<?php echo number_format($subtotal, 2, ',', '.'); ?></span>
            </div>
            
            <?php if ($descuento_total > 0): ?>
            <div class="total-row">
                <span class="total-label">Descuento Total:</span>
                <span class="total-value" style="color: var(--emergency);">-$<?php echo number_format($descuento_total, 2, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="total-row">
                <span class="total-label">Base Imponible (con IVA):</span>
                <span class="total-value">$<?php echo number_format($base_imponible, 2, ',', '.'); ?></span>
            </div>
            
            <div class="total-row">
                <span class="total-label">Base Exenta (sin IVA):</span>
                <span class="total-value">$<?php echo number_format($base_exenta, 2, ',', '.'); ?></span>
            </div>
            
            <div class="total-row iva-row">
                <span class="total-label">IVA registrado:</span>
                <span class="total-value" style="color: var(--accent-dark);">$<?php echo number_format($iva, 2, ',', '.'); ?></span>
            </div>
            
            <div class="total-row gran-total">
                <span class="total-label">TOTAL A PAGAR:</span>
                <span class="total-value">$<?php echo number_format($total, 2, ',', '.'); ?></span>
            </div>
            
            <?php if (!empty($factura['observaciones']) || !empty($factura['notas'])): 
                $observaciones = $factura['observaciones'] ?? $factura['notas'] ?? '';
            ?>
            <div class="observaciones">
                <i class='bx bx-note' style="color: var(--azul); margin-right: 0.5rem;"></i>
                <strong>Observaciones:</strong><br>
                <?php echo nl2br(htmlspecialchars($observaciones)); ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p><strong>¡Gracias por su compra!</strong></p>
            <p>Este documento es una representación impresa de la factura electrónica</p>
            <p>VetNexo - <?php echo date('Y'); ?></p>
            <p><i class='bx bx-time'></i> Documento generado el: <?php echo date('d/m/Y H:i:s'); ?></p>
            <p style="margin-top: 0.5rem; font-style: italic;">"ESTA FACTURA VA SIN TACHADURA NI ENMENDADURA"</p>
            <p><strong>Nº CONTROL: 00-<?php echo htmlspecialchars($factura['numero_factura']); ?></strong></p>
        </div>
        
        <!-- Botones de acción -->
        <div class="action-buttons">
            <button onclick="window.print()" class="btn btn-primary">
                <i class='bx bx-printer'></i> Imprimir Factura
            </button>
            <button onclick="downloadPDF()" class="btn btn-info">
                <i class='bx bx-download'></i> Descargar PDF
            </button>
            <a href="facturas.php" class="btn btn-secondary">
                <i class='bx bx-arrow-back'></i> Volver a Facturas
            </a>
        </div>
    </div>
    
    <script>
        // Imprimir automáticamente si se solicita
        if (window.location.search.includes('print=1')) {
            setTimeout(() => {
                window.print();
            }, 500);
        }
        
        function downloadPDF() {
            // Abrir una nueva ventana para imprimir y guardar como PDF
            const printWindow = window.open('', '_blank');
            printWindow.document.write(document.documentElement.innerHTML);
            printWindow.document.close();
            printWindow.focus();
            
            setTimeout(() => {
                printWindow.print();
            }, 1000);
        }
        
        // Agregar manejo de impresión mejorado
        window.addEventListener('afterprint', function(event) {
            // No mostrar alerta para no molestar
        });
    </script>
</body>
</html>