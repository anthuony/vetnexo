<?php
// tienda/checkout.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();
require_once '../config/bcv.php';




// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=tienda/checkout.php');
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

// Obtener datos del cliente
$stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Verificar carrito
if (empty($_SESSION['carrito'])) {
    header('Location: carrito.php?error=carrito_vacio');
    exit();
}

// Obtener productos del carrito
$productos_carrito = [];
$subtotal = 0;
$total_items = 0;
$ids = array_keys($_SESSION['carrito']);

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.id IN ($placeholders) AND p.activo = 1
    ");
    $stmt->execute($ids);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($productos as $producto) {
        $producto_id = $producto['id'];
        $cantidad = $_SESSION['carrito'][$producto_id]['cantidad'];
        $precio = $producto['precio_venta'];
        $importe = $precio * $cantidad;
        
        $productos_carrito[] = [
            'id' => $producto_id,
            'codigo' => $producto['codigo'],
            'nombre' => $producto['nombre'],
            'precio' => $precio,
            'cantidad' => $cantidad,
            'importe' => $importe,
            'stock' => $producto['stock_actual'],
            'imagen' => $producto['imagen']
        ];
        
        $subtotal += $importe;
        $total_items += $cantidad;
    }
}

// Calcular totales
require_once __DIR__.'/../config/ventas.php';
    $totales=av_cart_totals($conn);$subtotal=$totales['subtotal'];$iva=$totales['iva'];
$total = $subtotal + $iva;

// Tasa de cambio
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);

// Obtener datos bancarios activos
$stmt_bancos = $conn->prepare("SELECT * FROM datos_bancarios WHERE activo = 1 LIMIT 1");
$stmt_bancos->execute();
$datos_bancarios = $stmt_bancos->fetch(PDO::FETCH_ASSOC);

// Si no hay datos bancarios, usar valores por defecto
if (!$datos_bancarios) {
    $datos_bancarios = [
        'banco' => 'Banco de Your Region',
        'titular' => 'Configure account holder in settings',
        'rif' => '',
        'cuenta_ahorros' => '',
        'cuenta_corriente' => '',
        'telefono_pago_movil' => '',
        'cedula_pago_movil' => '',
        'banco_pago_movil' => '',
        'email' => 'payments@vetnexo.example'
    ];
}

// Obtener métodos de pago configurados
$stmt_metodos = $conn->query("SELECT * FROM metodos_pago_config WHERE activo = 1 ORDER BY orden");
$metodos_pago = $stmt_metodos->fetchAll(PDO::FETCH_ASSOC);

// Si no hay métodos configurados, usar valores por defecto
if (empty($metodos_pago)) {
    $metodos_pago = [
        ['tipo' => 'transferencia', 'nombre' => 'Transferencia Bancaria', 'descripcion' => 'Paga desde cualquier banco'],
        ['tipo' => 'pago_movil', 'nombre' => 'Pago Móvil', 'descripcion' => 'Rápido y seguro desde tu teléfono'],
        ['tipo' => 'efectivo', 'nombre' => 'Efectivo', 'descripcion' => 'Paga al retirar en tienda']
    ];
}

// Recuperar datos guardados del checkout si existen
$checkout_data = $_SESSION['checkout_data'] ?? [];
unset($_SESSION['checkout_data']);

// Generar token CSRF para seguridad
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💰 Finalizar Compra - VetNexo</title>
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
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideDown 0.3s ease;
        }

        .alert-error {
            background: var(--danger);
            color: white;
        }

        .alert-success {
            background: var(--success);
            color: white;
        }

        .alert-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
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
            color: var(--primary-dark);
            transform: translateX(-5px);
        }

        /* Checkout Grid */
        .checkout-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 968px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Formulario */
        .checkout-form {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .form-section {
            padding: 2rem;
            border-bottom: 1px solid var(--gray);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--accent);
            font-size: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
        }

        .form-control[readonly] {
            background: var(--gray-light);
            cursor: not-allowed;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Datos Bancarios */
        .bank-info {
            background: var(--primary-soft);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 2px solid var(--primary-light);
            margin-bottom: 2rem;
        }

        .bank-info-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bank-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .bank-details {
                grid-template-columns: 1fr;
            }
        }

        .bank-item {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--primary-light);
            transition: all 0.3s ease;
        }

        .bank-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--accent);
        }

        .bank-label {
            font-size: 0.75rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.3rem;
        }

        .bank-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
            word-break: break-word;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .copy-btn {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 1rem;
            padding: 0.2rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .copy-btn:hover {
            background: var(--primary-soft);
            color: var(--accent);
            transform: scale(1.1);
        }

        /* Métodos de Pago */
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .payment-method-card {
            background: var(--white);
            border: 2px solid var(--gray);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .payment-method-card:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .payment-method-card.selected {
            border-color: var(--primary);
            background: var(--primary-soft);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .payment-method-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .payment-method-card i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .payment-method-card h4 {
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .payment-method-card p {
            color: var(--text-light);
            font-size: 0.8rem;
        }

        /* Información de Pago */
        .payment-info {
            background: var(--accent-soft);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 2px solid var(--accent-light);
            margin-top: 1.5rem;
        }

        .payment-info h4 {
            color: var(--accent-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .payment-note {
            font-size: 0.9rem;
            color: var(--text-light);
            background: var(--white);
            padding: 1rem;
            border-radius: var(--radius-sm);
            border: 1px dashed var(--accent);
            line-height: 1.6;
        }

        /* Campos dinámicos */
        .dynamic-fields {
            background: var(--gray-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1rem;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .dynamic-fields.show {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Resumen de pedido */
        .order-summary {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            position: sticky;
            top: 100px;
        }

        .summary-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 3px solid var(--primary-light);
        }

        .summary-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-body {
            padding: 1.5rem;
        }

        .summary-items {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 1.5rem;
            padding-right: 0.5rem;
        }

        .summary-items::-webkit-scrollbar {
            width: 6px;
        }

        .summary-items::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 3px;
        }

        .summary-items::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 3px;
        }

        .summary-item {
            display: flex;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .item-image {
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

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
            color: var(--text-dark);
        }

        .item-meta {
            color: var(--text-light);
            font-size: 0.75rem;
            display: flex;
            gap: 1rem;
        }

        .item-price {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            text-align: right;
            min-width: 80px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-dark);
            padding-top: 1rem;
            border-top: 2px solid var(--primary-light);
            margin-top: 0.5rem;
        }

        .summary-label {
            color: var(--text-light);
        }

        .summary-value {
            font-weight: 600;
        }

        .price-bs {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 0.2rem;
            text-align: right;
        }

        .terms-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 1.5rem 0;
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius);
        }

        .terms-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .terms-checkbox label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .terms-checkbox a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .terms-checkbox a:hover {
            text-decoration: underline;
        }

        .btn-place-order {
            width: 100%;
            padding: 1.2rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 5px 20px rgba(255, 152, 0, 0.3);
        }

        .btn-place-order:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.4);
        }

        .btn-place-order:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            background: var(--gray);
        }

        .secure-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .secure-badge i {
            color: var(--success);
            font-size: 1.2rem;
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

        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 140px;
            background-color: var(--text-dark);
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 150%;
            left: 50%;
            margin-left: -70px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
        }

        .tooltip .tooltiptext::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: var(--text-dark) transparent transparent transparent;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        /* Loading */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
                <div class="step active">
                    <span class="step-number">2</span>
                    <span>Checkout</span>
                </div>
                <i class='bx bx-chevron-right'></i>
                <div class="step">
                    <span class="step-number">3</span>
                    <span>Confirmación</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="page-header">
            <a href="carrito.php" class="btn-back">
                <i class='bx bx-arrow-back'></i>
                Volver al carrito
            </a>
            <h1 class="page-title">
                <i class='bx bx-credit-card'></i>
                Finalizar Compra
            </h1>
        </div>

        <?php if (isset($_SESSION['checkout_error'])): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle'></i>
                <?php 
                    echo htmlspecialchars($_SESSION['checkout_error']); 
                    unset($_SESSION['checkout_error']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'carrito_vacio'): ?>
            <div class="alert alert-warning">
                <i class='bx bx-cart'></i>
                Tu carrito está vacío. Agrega productos antes de continuar.
            </div>
        <?php endif; ?>

        <form method="POST" action="procesar_pedido.php" class="checkout-grid" id="checkoutForm"><?php echo av_csrf_field(); ?>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <!-- Formulario de checkout -->
            <div class="checkout-form">
                <!-- Información de contacto -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class='bx bx-user'></i>
                        Información de contacto
                    </h3>
                    
                    <div class="form-group">
                        <label class="form-label">Nombre completo</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($cliente['nombre'] ?? ''); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Correo electrónico</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($cliente['email'] ?? ''); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Teléfono de contacto *</label>
                        <input type="tel" name="telefono_contacto" class="form-control" 
                               value="<?php echo htmlspecialchars($checkout_data['telefono_contacto'] ?? $cliente['telefono'] ?? ''); ?>" 
                               placeholder="[Clinic phone not configured]" required>
                    </div>
                </div>

                <!-- Dirección para retirar -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class='bx bx-map'></i>
                        Dirección para retirar
                    </h3>
                    
                    <div class="form-group">
                        <label class="form-label">Dirección *</label>
                        <textarea name="direccion_envio" class="form-control" required 
                                  placeholder="Indica la dirección donde retirarás el pedido"><?php echo htmlspecialchars($checkout_data['direccion_envio'] ?? $cliente['direccion'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="payment-info">
                        <h4><i class='bx bx-info-circle'></i> Información importante</h4>
                        <div class="payment-note">
                            <p><i class='bx bx-store'></i> <strong>Retiro en tienda:</strong> Presenta tu comprobante de pago en nuestra tienda en VetNexo, Your Clinic Location.</p>
                            <p style="margin-top:0.5rem;"><i class='bx bx-time'></i> <strong>Horario:</strong> Lunes a Viernes 8:00 AM - 5:00 PM | Sábados 8:00 AM - 2:00 PM</p>
                        </div>
                    </div>
                </div>

                <!-- Método de Pago -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class='bx bx-credit-card'></i>
                        Método de Pago
                    </h3>
                    
                    <!-- Datos Bancarios -->
                    <div class="bank-info">
                        <div class="bank-info-title">
                            <i class='bx bx-bank'></i>
                            Datos Bancarios
                        </div>
                        
                        <div class="bank-details">
                            <div class="bank-item">
                                <div class="bank-label">Banco</div>
                                <div class="bank-value">
                                    <?php echo $datos_bancarios['banco']; ?>
                                    <button type="button" class="copy-btn tooltip" onclick="copiarTexto('<?php echo $datos_bancarios['banco']; ?>')">
                                        <i class='bx bx-copy'></i>
                                        <span class="tooltiptext">Copiar</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bank-item">
                                <div class="bank-label">Titular</div>
                                <div class="bank-value">
                                    <?php echo $datos_bancarios['titular']; ?>
                                    <button type="button" class="copy-btn tooltip" onclick="copiarTexto('<?php echo $datos_bancarios['titular']; ?>')">
                                        <i class='bx bx-copy'></i>
                                        <span class="tooltiptext">Copiar</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bank-item">
                                <div class="bank-label">RIF</div>
                                <div class="bank-value">
                                    <?php echo $datos_bancarios['rif']; ?>
                                    <button type="button" class="copy-btn tooltip" onclick="copiarTexto('<?php echo $datos_bancarios['rif']; ?>')">
                                        <i class='bx bx-copy'></i>
                                        <span class="tooltiptext">Copiar</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bank-item">
                                <div class="bank-label">Cuenta Ahorros</div>
                                <div class="bank-value">
                                    <?php echo $datos_bancarios['cuenta_ahorros']; ?>
                                    <button type="button" class="copy-btn tooltip" onclick="copiarTexto('<?php echo $datos_bancarios['cuenta_ahorros']; ?>')">
                                        <i class='bx bx-copy'></i>
                                        <span class="tooltiptext">Copiar</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bank-item">
                                <div class="bank-label">Pago Móvil</div>
                                <div class="bank-value">
                                    <?php echo $datos_bancarios['telefono_pago_movil']; ?> (<?php echo $datos_bancarios['cedula_pago_movil']; ?>)
                                    <button type="button" class="copy-btn tooltip" onclick="copiarTexto('<?php echo $datos_bancarios['telefono_pago_movil']; ?>')">
                                        <i class='bx bx-copy'></i>
                                        <span class="tooltiptext">Copiar</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selección de método de pago -->
                    <div class="payment-methods">
                        <?php foreach ($metodos_pago as $metodo): ?>
                            <label class="payment-method-card <?php echo ($checkout_data['metodo_pago'] ?? '') === $metodo['tipo'] ? 'selected' : ''; ?>">
                                <input type="radio" name="metodo_pago" value="<?php echo $metodo['tipo']; ?>" 
                                       <?php echo ($checkout_data['metodo_pago'] ?? '') === $metodo['tipo'] ? 'checked' : ''; ?> 
                                       onchange="cambiarMetodoPago(this.value)" required>
                                <i class='bx bx-<?php 
                                    echo $metodo['tipo'] === 'transferencia' ? 'transfer' : 
                                        ($metodo['tipo'] === 'pago_movil' ? 'mobile-alt' : 
                                        ($metodo['tipo'] === 'efectivo' ? 'money' : 'credit-card')); 
                                ?>'></i>
                                <h4><?php echo $metodo['nombre']; ?></h4>
                                <p><?php echo $metodo['descripcion'] ?? ''; ?></p>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Campos dinámicos según método de pago -->
                    <div id="camposTransferencia" class="dynamic-fields <?php echo ($checkout_data['metodo_pago'] ?? '') === 'transferencia' ? 'show' : ''; ?>">
                        <div class="form-group">
                            <label class="form-label">Banco de origen</label>
                            <input type="text" name="banco_origen" class="form-control" 
                                   value="<?php echo htmlspecialchars($checkout_data['banco_origen'] ?? ''); ?>" 
                                   placeholder="Ej: Banco Mercantil">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Número de referencia *</label>
                            <input type="text" name="referencia_pago" class="form-control" 
                                   value="<?php echo htmlspecialchars($checkout_data['referencia_pago'] ?? ''); ?>" 
                                   placeholder="Ej: 1234567890">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Fecha de la transferencia</label>
                            <input type="date" name="fecha_transferencia" class="form-control" 
                                   value="<?php echo htmlspecialchars($checkout_data['fecha_transferencia'] ?? date('Y-m-d')); ?>">
                        </div>
                    </div>
                    
                    <div id="camposPagoMovil" class="dynamic-fields <?php echo ($checkout_data['metodo_pago'] ?? '') === 'pago_movil' ? 'show' : ''; ?>">
                        <div class="form-group">
                            <label class="form-label">Teléfono desde donde pagaste</label>
                            <input type="text" name="telefono_pago" class="form-control" 
                                   value="<?php echo htmlspecialchars($checkout_data['telefono_pago'] ?? ''); ?>" 
                                   placeholder="[Clinic phone not configured]">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Banco desde donde pagaste</label>
                            <input type="text" name="banco_pago" class="form-control" 
                                   value="<?php echo htmlspecialchars($checkout_data['banco_pago'] ?? ''); ?>" 
                                   placeholder="Ej: Banesco">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Número de confirmación *</label>
                            <input type="text" name="referencia_pago_movil" class="form-control" 
                                   value="<?php echo htmlspecialchars($checkout_data['referencia_pago_movil'] ?? ''); ?>" 
                                   placeholder="Ej: 1234567890">
                        </div>
                    </div>
                    
                    <div id="infoEfectivo" class="payment-info" style="<?php echo ($checkout_data['metodo_pago'] ?? '') === 'efectivo' ? 'display:block;' : 'display:none;'; ?>">
                        <h4><i class='bx bx-money'></i> Pago en Efectivo</h4>
                        <p>Pagas en efectivo al momento de retirar tus productos en tienda.</p>
                        <p><strong>Importante:</strong> Los productos se reservarán por 24 horas. Si no retiras en ese tiempo, se liberará el stock.</p>
                    </div>
                    
                    <div class="form-group" style="margin-top:1rem;">
                        <label class="form-label">Notas adicionales (opcional)</label>
                        <textarea name="notas" class="form-control" 
                                  placeholder="Alguna información adicional sobre tu pedido..."><?php echo htmlspecialchars($checkout_data['notas'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Resumen del pedido -->
            <div class="order-summary">
                <div class="summary-header">
                    <h2>
                        <i class='bx bx-receipt'></i>
                        Resumen del pedido
                    </h2>
                </div>
                
                <div class="summary-body">
                    <div class="summary-items">
                        <?php foreach ($productos_carrito as $item): ?>
                            <div class="summary-item">
                                <div class="item-image">
                                    <?php if (!empty($item['imagen'])): ?>
                                        <img src="../uploads/productos/<?php echo $item['imagen']; ?>" alt="<?php echo htmlspecialchars($item['nombre']); ?>">
                                    <?php else: ?>
                                        <i class='bx bx-package'></i>
                                    <?php endif; ?>
                                </div>
                                <div class="item-info">
                                    <div class="item-name"><?php echo htmlspecialchars($item['nombre']); ?></div>
                                    <div class="item-meta">
                                        <span>Cant: <?php echo $item['cantidad']; ?></span>
                                        <span>Stock: <?php echo $item['stock']; ?></span>
                                    </div>
                                </div>
                                <div class="item-price">
                                    <div>$<?php echo number_format($item['importe'], 2); ?></div>
                                    <div class="price-bs">Bs. <?php echo number_format($item['importe'] * $tasa_bs, 2); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="summary-row">
                        <span class="summary-label">Subtotal</span>
                        <div class="summary-value">
                            <div>$<?php echo number_format($subtotal, 2); ?></div>
                            <div class="price-bs">Bs. <?php echo number_format($subtotal * $tasa_bs, 2); ?></div>
                        </div>
                    </div>
                    
                    <div class="summary-row">
                        <span class="summary-label">IVA</span>
                        <div class="summary-value">
                            <div>$<?php echo number_format($iva, 2); ?></div>
                            <div class="price-bs">Bs. <?php echo number_format($iva * $tasa_bs, 2); ?></div>
                        </div>
                    </div>
                    
                    <div class="summary-row total">
                        <span class="summary-label">Total a pagar</span>
                        <div class="summary-value">
                            <div style="font-size:1.3rem;">$<?php echo number_format($total, 2); ?></div>
                            <div class="price-bs" style="font-size:1rem;">Bs. <?php echo number_format($total * $tasa_bs, 2); ?></div>
                        </div>
                    </div>

                    <div class="terms-checkbox">
                        <input type="checkbox" name="acepto_terminos" id="acepto_terminos" required 
                               <?php echo isset($checkout_data['acepto_terminos']) ? 'checked' : ''; ?>>
                        <label for="acepto_terminos">
                            Acepto los <a href="terminos.php" target="_blank">términos y condiciones</a> y la política de privacidad
                        </label>
                    </div>

                    <button type="submit" class="btn-place-order" id="btnPlaceOrder">
                        <i class='bx bx-check-shield'></i>
                        Confirmar pedido
                    </button>

                    <div class="secure-badge">
                        <i class='bx bx-lock'></i>
                        <span>Tus datos están seguros y encriptados</span>
                    </div>

                    <p style="text-align: center; margin-top: 1rem; font-size: 0.8rem; color: var(--text-light);">
                        <i class='bx bx-time'></i>
                        Después de confirmar, podrás subir tu comprobante de pago
                    </p>
                </div>
            </div>
        </form>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        // Función para copiar texto al portapapeles
        function copiarTexto(texto) {
            navigator.clipboard.writeText(texto).then(function() {
                mostrarNotificacion('✅ Copiado al portapapeles');
            }, function() {
                mostrarNotificacion('❌ Error al copiar', 'error');
            });
        }

        // Cambiar método de pago
        function cambiarMetodoPago(metodo) {
            // Ocultar todos los campos dinámicos
            document.getElementById('camposTransferencia').classList.remove('show');
            document.getElementById('camposPagoMovil').classList.remove('show');
            document.getElementById('infoEfectivo').style.display = 'none';
            
            // Quitar selected de todas las tarjetas
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Marcar la tarjeta seleccionada
            event.target.closest('.payment-method-card').classList.add('selected');
            
            // Mostrar campos según método
            if (metodo === 'transferencia') {
                document.getElementById('camposTransferencia').classList.add('show');
            } else if (metodo === 'pago_movil') {
                document.getElementById('camposPagoMovil').classList.add('show');
            } else if (metodo === 'efectivo') {
                document.getElementById('infoEfectivo').style.display = 'block';
            }
        }

        // Validar formulario antes de enviar
        document.getElementById('checkoutForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('btnPlaceOrder');
            const metodoPago = document.querySelector('input[name="metodo_pago"]:checked');
            
            if (!metodoPago) {
                e.preventDefault();
                mostrarNotificacion('❌ Selecciona un método de pago', 'error');
                return;
            }
            
            // Validar campos según método
            const metodo = metodoPago.value;
            
            if (metodo === 'transferencia') {
                const referencia = document.querySelector('input[name="referencia_pago"]').value;
                if (!referencia) {
                    e.preventDefault();
                    mostrarNotificacion('❌ Ingresa el número de referencia', 'error');
                    return;
                }
            } else if (metodo === 'pago_movil') {
                const referencia = document.querySelector('input[name="referencia_pago_movil"]').value;
                if (!referencia) {
                    e.preventDefault();
                    mostrarNotificacion('❌ Ingresa el número de confirmación', 'error');
                    return;
                }
            }
            
            // Deshabilitar botón y mostrar loading
            btn.innerHTML = '<span class="loading"></span> Procesando...';
            btn.disabled = true;
        });

        // Mostrar notificaciones
        function mostrarNotificacion(mensaje, tipo = 'success') {
            const notificacion = document.createElement('div');
            notificacion.className = 'alert alert-' + tipo;
            notificacion.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                animation: slideIn 0.3s ease;
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

        // Inicializar método de pago si hay uno seleccionado
        document.addEventListener('DOMContentLoaded', function() {
            const metodoSeleccionado = document.querySelector('input[name="metodo_pago"]:checked');
            if (metodoSeleccionado) {
                cambiarMetodoPago(metodoSeleccionado.value);
            }
        });

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