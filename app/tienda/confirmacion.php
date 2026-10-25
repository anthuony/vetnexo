<?php
// tienda/confirmacion.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();
require_once '../config/bcv.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Cliente';

// Obtener parámetros de la URL
$pedido_id = $_GET['pedido_id'] ?? 0;
$numero_pedido = $_GET['numero'] ?? '';

// Si no hay pedido_id, intentar obtener el último pedido de la sesión
if (!$pedido_id && isset($_SESSION['ultimo_pedido']['id'])) {
    $pedido_id = $_SESSION['ultimo_pedido']['id'];
    $numero_pedido = $_SESSION['ultimo_pedido']['numero'];
}

// Verificar que el pedido pertenezca al cliente
$stmt = $conn->prepare("
    SELECT p.*, c.nombre as cliente_nombre, c.email, c.telefono 
    FROM pedidos p
    JOIN clientes c ON p.cliente_id = c.id
    WHERE p.id = ? AND p.cliente_id = ?
");
$stmt->execute([$pedido_id, $cliente_id]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no existe el pedido o no pertenece al cliente, redirigir
if (!$pedido) {
    header('Location: index.php');
    exit();
}

// Obtener detalles del pedido
$stmt_detalles = $conn->prepare("
    SELECT pd.*, p.nombre as producto_nombre, p.imagen, p.codigo
    FROM pedido_detalles pd
    JOIN productos p ON pd.producto_id = p.id
    WHERE pd.pedido_id = ?
");
$stmt_detalles->execute([$pedido_id]);
$detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

// Obtener información del pago si existe
$pago_info = null;
if (!empty($pedido['info_pago'])) {
    $pago_info = json_decode($pedido['info_pago'], true);
}

// Buscar si hay un pago registrado en la tabla pagos
$stmt_pago = $conn->prepare("
    SELECT * FROM pagos WHERE pedido_id = ? ORDER BY fecha_creacion DESC LIMIT 1
");
$stmt_pago->execute([$pedido_id]);
$pago_db = $stmt_pago->fetch(PDO::FETCH_ASSOC);

// Tasa de cambio
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);

// Obtener productos relacionados (recomendados)
$categorias_pedido = [];
foreach ($detalles as $detalle) {
    $stmt_cat = $conn->prepare("
        SELECT categoria_id FROM productos WHERE id = ?
    ");
    $stmt_cat->execute([$detalle['producto_id']]);
    $cat = $stmt_cat->fetch(PDO::FETCH_ASSOC);
    if ($cat && $cat['categoria_id']) {
        $categorias_pedido[] = $cat['categoria_id'];
    }
}

$productos_recomendados = [];
if (!empty($categorias_pedido)) {
    $categorias_unicas = array_unique($categorias_pedido);
    $placeholders = implode(',', array_fill(0, count($categorias_unicas), '?'));
    
    $stmt_recomendados = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.activo = 1 
        AND p.stock_actual > 0 
        AND p.categoria_id IN ($placeholders)
        AND p.id NOT IN (SELECT producto_id FROM pedido_detalles WHERE pedido_id = ?)
        ORDER BY p.id DESC 
        LIMIT 4
    ");
    
    $params = array_merge($categorias_unicas, [$pedido_id]);
    $stmt_recomendados->execute($params);
    $productos_recomendados = $stmt_recomendados->fetchAll(PDO::FETCH_ASSOC);
}

// Si no hay recomendaciones por categoría, mostrar productos aleatorios
if (empty($productos_recomendados)) {
    $stmt_recomendados = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.activo = 1 AND p.stock_actual > 0 
        ORDER BY p.id DESC 
        LIMIT 4
    ");
    $stmt_recomendados->execute();
    $productos_recomendados = $stmt_recomendados->fetchAll(PDO::FETCH_ASSOC);
}

// Determinar mensaje según estado y método de pago
$mensaje_estado = '';
$icono_estado = 'bx-check-circle';
$color_estado = 'var(--success)';

switch ($pedido['estado']) {
    case 'pendiente_pago':
        $mensaje_estado = 'Tu pedido está pendiente de pago.';
        $icono_estado = 'bx-time';
        $color_estado = 'var(--warning)';
        break;
    case 'pendiente_verificacion':
        $mensaje_estado = 'Hemos recibido tu pago y está en proceso de verificación.';
        $icono_estado = 'bx-time';
        $color_estado = 'var(--info)';
        break;
    case 'pagado_verificado':
        $mensaje_estado = '¡Pago verificado! Tu pedido está listo para retirar.';
        $icono_estado = 'bx-check-circle';
        $color_estado = 'var(--success)';
        break;
    case 'preparando':
        $mensaje_estado = 'Estamos preparando tu pedido.';
        $icono_estado = 'bx-package';
        $color_estado = 'var(--info)';
        break;
    case 'listo_retiro':
        $mensaje_estado = '¡Tu pedido está listo para retirar en tienda!';
        $icono_estado = 'bx-store';
        $color_estado = 'var(--success)';
        break;
    case 'completado':
        $mensaje_estado = 'Pedido completado. Gracias por tu compra.';
        $icono_estado = 'bx-check-double';
        $color_estado = 'var(--success)';
        break;
    case 'cancelado':
        $mensaje_estado = 'Este pedido ha sido cancelado.';
        $icono_estado = 'bx-x-circle';
        $color_estado = 'var(--danger)';
        break;
    default:
        $mensaje_estado = 'Gracias por tu compra.';
}

// Instrucciones según método de pago
$instrucciones = '';
if ($pedido['metodo_pago'] === 'efectivo') {
    $instrucciones = 'Acércate a nuestra tienda en VetNexo, Your Clinic Location, con tu número de pedido para pagar en efectivo y retirar tus productos. Tienes 24 horas para retirar.';
} elseif ($pedido['metodo_pago'] === 'transferencia' || $pedido['metodo_pago'] === 'pago_movil') {
    if ($pedido['estado'] === 'pendiente_pago') {
        $instrucciones = 'Realiza el pago por ' . ($pedido['metodo_pago'] === 'transferencia' ? 'transferencia bancaria' : 'pago móvil') . ' y luego sube el comprobante en la sección "Mis Pedidos".';
    } elseif ($pedido['estado'] === 'pendiente_verificacion') {
        $instrucciones = 'Estamos verificando tu pago. Te notificaremos cuando esté confirmado (máximo 24 horas).';
    } else {
        $instrucciones = 'Tu pago ha sido verificado. Puedes retirar tu pedido en tienda.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✅ Pedido Confirmado - VetNexo</title>
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

        .checkout-steps {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .step.completed {
            color: var(--success);
        }

        .step.completed .step-number {
            background: var(--success);
            color: white;
        }

        .step.active {
            color: var(--primary);
            font-weight: 600;
        }

        .step.active .step-number {
            background: var(--primary);
            color: white;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--gray-light);
            color: var(--text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Success Card */
        .success-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            margin-bottom: 2rem;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-header {
            background: linear-gradient(135deg, <?php echo $color_estado; ?>, var(--primary-dark));
            color: white;
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .success-header::before {
            content: '🐾';
            position: absolute;
            right: 2rem;
            bottom: -1rem;
            font-size: 8rem;
            opacity: 0.1;
            transform: rotate(-15deg);
        }

        .success-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
            animation: scaleIn 0.5s ease-out 0.3s both;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        .success-header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
        }

        .success-header p {
            font-size: 1.1rem;
            opacity: 0.95;
            position: relative;
            z-index: 2;
        }

        .success-body {
            padding: 2rem;
        }

        /* Estado del pedido */
        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            background: <?php echo $color_estado; ?>;
            color: white;
            margin-bottom: 1rem;
        }

        /* Order Info */
        .order-info {
            background: var(--primary-soft);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            border: 1px solid var(--primary-light);
        }

        .info-item {
            text-align: center;
        }

        .info-label {
            color: var(--text-light);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .info-value.large {
            font-size: 1.5rem;
        }

        /* Instrucciones */
        .instrucciones-card {
            background: var(--accent-soft);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 2px solid var(--accent-light);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .instrucciones-icon {
            font-size: 3rem;
            color: var(--accent-dark);
        }

        .instrucciones-text {
            flex: 1;
        }

        .instrucciones-text h3 {
            color: var(--accent-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .instrucciones-text p {
            color: var(--text-light);
            line-height: 1.6;
        }

        /* Order Details */
        .section-title {
            font-size: 1.3rem;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--accent);
            border-radius: 2px;
        }

        .order-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            background: var(--white);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .order-table th {
            text-align: left;
            padding: 1rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .order-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray);
        }

        .order-table tbody tr:hover {
            background: var(--gray-light);
        }

        .order-table tfoot td {
            padding: 1rem;
            font-weight: 600;
            border-top: 2px solid var(--primary-light);
        }

        .order-table .text-right {
            text-align: right;
        }

        .order-table .total-row {
            font-size: 1.1rem;
            color: var(--primary-dark);
        }

        .product-image-mini {
            width: 50px;
            height: 50px;
            background: var(--gray-light);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 1.5rem;
            overflow: hidden;
        }

        .product-image-mini img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Customer Info */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .info-card h3 {
            font-size: 1rem;
            color: var(--primary-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .info-card p {
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .info-card i {
            color: var(--primary);
            width: 20px;
            font-size: 1.1rem;
        }

        /* Pago Info */
        .pago-info {
            background: var(--primary-soft);
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
            border: 1px solid var(--primary-light);
        }

        .pago-info p {
            display: flex;
            justify-content: space-between;
            padding: 0.3rem 0;
            border-bottom: 1px dashed var(--primary-light);
        }

        .pago-info p:last-child {
            border-bottom: none;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.4);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-soft);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .btn-accent {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .btn-accent:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.4);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 193, 7, 0.4);
        }

        /* Productos Relacionados */
        .related-products {
            margin-top: 3rem;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .product-card {
            background: var(--white);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .product-image {
            width: 100%;
            height: 150px;
            background: linear-gradient(135deg, var(--gray-light), var(--white));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 2rem;
            border-bottom: 1px solid var(--gray);
            overflow: hidden;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .product-card:hover .product-image img {
            transform: scale(1.1);
        }

        .product-info {
            padding: 1rem;
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-category {
            color: var(--primary);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.3rem;
        }

        .product-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .product-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .price-bs {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .btn-add {
            width: 100%;
            padding: 0.6rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            font-size: 0.9rem;
            margin-top: auto;
        }

        .btn-add:hover {
            background: var(--primary-dark);
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
            
            .checkout-steps {
                width: 100%;
                justify-content: center;
            }
            
            .success-header h1 {
                font-size: 1.8rem;
            }
            
            .order-table {
                font-size: 0.9rem;
            }
            
            .order-table th, .order-table td {
                padding: 0.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .instrucciones-card {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Print styles */
        @media print {
            .header, .footer, .action-buttons, .related-products, .instrucciones-card {
                display: none;
            }
            
            .success-card {
                box-shadow: none;
                border: 2px solid #ccc;
            }
            
            .success-header {
                background: #f0f0f0;
                color: black;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* Animaciones */
        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
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

            <div class="checkout-steps">
                <div class="step completed">
                    <span class="step-number"><i class='bx bx-check'></i></span>
                    <span>Carrito</span>
                </div>
                <i class='bx bx-chevron-right'></i>
                <div class="step completed">
                    <span class="step-number"><i class='bx bx-check'></i></span>
                    <span>Checkout</span>
                </div>
                <i class='bx bx-chevron-right'></i>
                <div class="step active">
                    <span class="step-number">3</span>
                    <span>Confirmación</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="success-card">
            <div class="success-header">
                <div class="success-icon">
                    <i class='bx <?php echo $icono_estado; ?>'></i>
                </div>
                <h1>¡Gracias por tu compra!</h1>
                <p>Tu pedido ha sido registrado exitosamente</p>
                <div class="estado-badge" style="margin-top:1rem;">
                    <i class='bx <?php echo $icono_estado; ?>'></i>
                    <?php echo $mensaje_estado; ?>
                </div>
            </div>
            
            <div class="success-body">
                <!-- Información del pedido -->
                <div class="order-info">
                    <div class="info-item">
                        <div class="info-label">Número de pedido</div>
                        <div class="info-value large"><?php echo htmlspecialchars($pedido['numero_pedido']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Fecha</div>
                        <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($pedido['fecha_pedido'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Método de pago</div>
                        <div class="info-value">
                            <?php 
                                $metodos = [
                                    'transferencia' => 'Transferencia',
                                    'pago_movil' => 'Pago Móvil',
                                    'efectivo' => 'Efectivo'
                                ];
                                echo $metodos[$pedido['metodo_pago']] ?? $pedido['metodo_pago'];
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total</div>
                        <div class="info-value">$<?php echo number_format($pedido['total'], 2); ?></div>
                        <div class="price-bs">Bs. <?php echo number_format($pedido['total'] * $tasa_bs, 2); ?></div>
                    </div>
                </div>

                <!-- Instrucciones según estado -->
                <?php if (!empty($instrucciones)): ?>
                <div class="instrucciones-card">
                    <div class="instrucciones-icon">
                        <i class='bx bx-info-circle'></i>
                    </div>
                    <div class="instrucciones-text">
                        <h3><i class='bx bx-bulb'></i> ¿Qué sigue?</h3>
                        <p><?php echo $instrucciones; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Detalles del pedido -->
                <h2 class="section-title">
                    <i class='bx bx-receipt'></i>
                    Detalles del pedido
                </h2>

                <table class="order-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-right">Precio unit.</th>
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
                                            <small style="color: var(--text-lighter);">Cód: <?php echo $detalle['codigo'] ?? ''; ?></small>
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
                                <strong>$<?php echo number_format($pedido['total'], 2); ?></strong>
                                <br>
                                <small>Bs. <?php echo number_format($pedido['total'] * $tasa_bs, 2); ?></small>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Información del cliente y envío -->
                <h2 class="section-title">
                    <i class='bx bx-user'></i>
                    Información de contacto y envío
                </h2>

                <div class="info-grid">
                    <div class="info-card">
                        <h3><i class='bx bx-user-circle'></i> Cliente</h3>
                        <p><i class='bx bx-user'></i> <?php echo htmlspecialchars($pedido['cliente_nombre']); ?></p>
                        <p><i class='bx bx-envelope'></i> <?php echo htmlspecialchars($pedido['email']); ?></p>
                        <p><i class='bx bx-phone'></i> <?php echo htmlspecialchars($pedido['telefono_contacto'] ?? $pedido['telefono']); ?></p>
                    </div>
                    
                    <div class="info-card">
                        <h3><i class='bx bx-map'></i> Dirección de retiro</h3>
                        <p><i class='bx bx-location-plus'></i> <?php echo nl2br(htmlspecialchars($pedido['direccion_envio'])); ?></p>
                        <p><i class='bx bx-store'></i> Tienda: VetNexo, Your Clinic Location</p>
                    </div>
                    
                    <div class="info-card">
                        <h3><i class='bx bx-credit-card'></i> Método de pago</h3>
                        <p>
                            <i class='bx bx-<?php 
                                echo $pedido['metodo_pago'] === 'transferencia' ? 'transfer' : 
                                    ($pedido['metodo_pago'] === 'pago_movil' ? 'mobile-alt' : 
                                    ($pedido['metodo_pago'] === 'efectivo' ? 'money' : 'credit-card')); 
                            ?>'></i>
                            <?php 
                                $metodos = [
                                    'transferencia' => 'Transferencia Bancaria',
                                    'pago_movil' => 'Pago Móvil',
                                    'efectivo' => 'Pago en Efectivo'
                                ];
                                echo $metodos[$pedido['metodo_pago']] ?? $pedido['metodo_pago'];
                            ?>
                        </p>
                        
                        <?php if ($pago_db): ?>
                            <div class="pago-info">
                                <p>
                                    <span>Estado del pago:</span>
                                    <span>
                                        <?php if ($pago_db['estado'] === 'verificado'): ?>
                                            <span style="color: var(--success);">✓ Verificado</span>
                                        <?php elseif ($pago_db['estado'] === 'pendiente'): ?>
                                            <span style="color: var(--warning);">⏳ Pendiente</span>
                                        <?php elseif ($pago_db['estado'] === 'rechazado'): ?>
                                            <span style="color: var(--danger);">✗ Rechazado</span>
                                        <?php endif; ?>
                                    </span>
                                </p>
                                <?php if (!empty($pago_db['referencia'])): ?>
                                    <p><span>Referencia:</span> <span><?php echo $pago_db['referencia']; ?></span></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($pedido['notas'])): ?>
                    <div class="info-card">
                        <h3><i class='bx bx-note'></i> Notas del pedido</h3>
                        <p><?php echo nl2br(htmlspecialchars($pedido['notas'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Acciones -->
                <div class="action-buttons">
                    <?php if ($pedido['metodo_pago'] !== 'efectivo' && $pedido['estado'] === 'pendiente_pago'): ?>
                        <a href="../cliente/subir_comprobante.php?pedido_id=<?php echo $pedido_id; ?>" class="btn btn-warning pulse">
                            <i class='bx bx-upload'></i>
                            Subir comprobante de pago
                        </a>
                    <?php endif; ?>
                    
                    <a href="../cliente/historial_compras.php" class="btn btn-primary">
                        <i class='bx bx-history'></i>
                        Ver mis pedidos
                    </a>
                    
                    <a href="index.php" class="btn btn-secondary">
                        <i class='bx bx-shopping-bag'></i>
                        Seguir comprando
                    </a>
                    
                    <button onclick="window.print()" class="btn btn-accent">
                        <i class='bx bx-printer'></i>
                        Imprimir comprobante
                    </button>
                </div>
            </div>
        </div>

        <!-- Productos relacionados -->
        <?php if (!empty($productos_recomendados)): ?>
        <div class="related-products">
            <h2 class="section-title">
                <i class='bx bx-purchase-tag'></i>
                Productos que podrían interesarte
            </h2>
            
            <div class="products-grid">
                <?php foreach ($productos_recomendados as $producto): ?>
                    <div class="product-card">
                        <div class="product-image">
                            <?php if (!empty($producto['imagen'])): ?>
                                <img src="../uploads/productos/<?php echo $producto['imagen']; ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>">
                            <?php else: ?>
                                <i class='bx bx-package'></i>
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <div class="product-category"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'General'); ?></div>
                            <h3 class="product-title"><?php echo htmlspecialchars($producto['nombre']); ?></h3>
                            <div class="product-price">
                                $<?php echo number_format($producto['precio_venta'], 2); ?>
                                <div class="price-bs">Bs. <?php echo number_format($producto['precio_venta'] * $tasa_bs, 2); ?></div>
                            </div>
                            <button class="btn-add" onclick="agregarAlCarrito(<?php echo $producto['id']; ?>)">
                                <i class='bx bx-cart-add'></i>
                                Agregar al carrito
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        function agregarAlCarrito(productoId) {
            fetch('../api/agregar_carrito.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'producto_id=' + productoId + '&cantidad=1'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    mostrarNotificacion('✅ Producto agregado al carrito');
                } else {
                    mostrarNotificacion('❌ Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al agregar el producto', 'error');
            });
        }

        function mostrarNotificacion(mensaje, tipo = 'success') {
            const notificacion = document.createElement('div');
            notificacion.className = 'alert alert-' + tipo;
            notificacion.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                background: ${tipo === 'success' ? 'var(--success)' : 'var(--danger)'};
                color: white;
                padding: 1rem 2rem;
                border-radius: 50px;
                box-shadow: var(--shadow-lg);
                display: flex;
                align-items: center;
                gap: 0.5rem;
            `;
            notificacion.innerHTML = `
                <i class='bx ${tipo === 'success' ? 'bx-check-circle' : 'bx-error-circle'}'></i>
                ${mensaje}
            `;
            
            document.body.appendChild(notificacion);
            
            setTimeout(() => {
                notificacion.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(notificacion);
                }, 300);
            }, 3000);
        }

        // Agregar estilos para las animaciones
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
<?php echo bcv_badge_html(); ?>
</body>
</html>