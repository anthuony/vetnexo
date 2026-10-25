<?php
// tienda/carrito.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once '../config/bcv.php';

// Verificar si el usuario está logueado (opcional para el carrito)
$usuario_logueado = isset($_SESSION['user_id']);
$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Invitado';

$database = new Database();
$conn = $database->conectar();

// Inicializar carrito si no existe
if (!isset($_SESSION['carrito']) || !is_array($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

// Procesar acciones del carrito (fallback para cuando JavaScript está deshabilitado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_sincrono'])) {
    $accion = $_POST['accion'] ?? '';
    $producto_id = $_POST['producto_id'] ?? 0;
    $cantidad = $_POST['cantidad'] ?? 1;

    switch ($accion) {
        case 'actualizar':
            if (isset($_SESSION['carrito'][$producto_id])) {
                $_SESSION['carrito'][$producto_id]['cantidad'] = max(1, min(99, (int)$cantidad));
                $_SESSION['carrito_mensaje'] = 'Cantidad actualizada';
            }
            break;
            
        case 'eliminar':
            if (isset($_SESSION['carrito'][$producto_id])) {
                unset($_SESSION['carrito'][$producto_id]);
                $_SESSION['carrito_mensaje'] = 'Producto eliminado del carrito';
            }
            break;
            
        case 'vaciar':
            $_SESSION['carrito'] = [];
            $_SESSION['carrito_mensaje'] = 'Carrito vaciado';
            break;
    }
    
    header('Location: carrito.php');
    exit();
}

// Obtener detalles de los productos en el carrito
$productos_carrito = [];
$subtotal = 0;
$total_items = 0;

if (!empty($_SESSION['carrito'])) {
    $ids = array_keys($_SESSION['carrito']);
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
            'descripcion' => $producto['descripcion'] ?? '',
            'precio' => $precio,
            'cantidad' => $cantidad,
            'importe' => $importe,
            'stock' => $producto['stock_actual'],
            'imagen' => $producto['imagen'],
            'categoria' => $producto['categoria_nombre'] ?? 'General',
            'aplica_iva' => $producto['aplica_iva'],
            'porcentaje_iva' => $producto['porcentaje_iva'] ?? 16
        ];
        
        $subtotal += $importe;
        $total_items += $cantidad;
    }
}

// Calcular impuestos y total
require_once __DIR__.'/../config/ventas.php';
    $totales=av_cart_totals($conn);$subtotal=$totales['subtotal'];$iva=$totales['iva'];
$total = $subtotal + $iva;

// Tasa de cambio (ejemplo)
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);

// Productos relacionados (recomendados basados en el carrito)
$productos_recomendados = [];
if (!empty($productos_carrito)) {
    // Obtener categorías de los productos en el carrito
    $categorias_carrito = array_unique(array_column($productos_carrito, 'categoria'));
    $categorias_placeholders = implode(',', array_fill(0, count($categorias_carrito), '?'));
    
    $stmt_recomendados = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.activo = 1 
        AND p.stock_actual > 0 
        AND c.nombre IN ($categorias_placeholders)
        AND p.id NOT IN ($placeholders)
        ORDER BY p.id DESC 
        LIMIT 4
    ");
    
    $params = array_merge($categorias_carrito, $ids);
    $stmt_recomendados->execute($params);
    $productos_recomendados = $stmt_recomendados->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Si el carrito está vacío, mostrar productos aleatorios
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

// Obtener mensaje del carrito
$carrito_mensaje = $_SESSION['carrito_mensaje'] ?? '';
unset($_SESSION['carrito_mensaje']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🛒 Carrito de Compras - VetNexo</title>
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

        .alert-success {
            background: var(--success);
            color: white;
        }

        .alert-info {
            background: var(--info);
            color: white;
        }

        .alert-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .alert-error {
            background: var(--danger);
            color: white;
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

        .vaciar-carrito {
            color: var(--danger);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--danger);
            background: none;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .vaciar-carrito:hover {
            background: var(--danger);
            color: white;
        }

        /* Cart Grid */
        .cart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 968px) {
            .cart-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cart Items */
        .cart-items {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .cart-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 3px solid var(--primary-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .item-count {
            background: var(--primary);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .cart-item {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray);
            transition: background 0.3s ease;
            position: relative;
        }

        .cart-item:hover {
            background: var(--primary-soft);
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .item-image {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--gray-light), var(--white));
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 2.5rem;
            margin-right: 1.5rem;
            border: 1px solid var(--gray);
            overflow: hidden;
            position: relative;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .cart-item:hover .item-image img {
            transform: scale(1.1);
        }

        .item-image:after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, transparent 70%, rgba(0,0,0,0.1));
            pointer-events: none;
        }

        .item-details {
            flex: 1;
        }

        .item-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: var(--text-dark);
        }

        .item-category {
            color: var(--primary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.3rem;
            font-weight: 600;
        }

        .item-code {
            color: var(--text-lighter);
            font-size: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .item-price {
            display: flex;
            align-items: center;
            gap: 2rem;
            background: var(--primary-soft);
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            max-width: 300px;
        }

        .price-unit {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .price-unit strong {
            color: var(--text-dark);
            font-size: 1rem;
        }

        .price-total {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1.2rem;
        }

        .item-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-left: 1.5rem;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            border: 2px solid var(--primary-light);
            border-radius: 50px;
            overflow: hidden;
            background: var(--white);
        }

        .quantity-btn {
            width: 36px;
            height: 36px;
            background: var(--white);
            border: none;
            color: var(--text-dark);
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .quantity-btn:hover:not(:disabled) {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .quantity-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .quantity-input {
            width: 50px;
            height: 36px;
            border: none;
            border-left: 1px solid var(--gray);
            border-right: 1px solid var(--gray);
            text-align: center;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .quantity-input:focus {
            outline: none;
        }

        .btn-remove {
            width: 36px;
            height: 36px;
            background: none;
            border: 1px solid var(--danger);
            color: var(--danger);
            font-size: 1.2rem;
            cursor: pointer;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .btn-remove:hover {
            background: var(--danger);
            color: white;
            transform: scale(1.1);
        }

        .stock-warning {
            position: absolute;
            bottom: 0.5rem;
            right: 1rem;
            font-size: 0.75rem;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }

        /* Cart Summary */
        .cart-summary {
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

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            padding-top: 1rem;
            border-top: 2px solid var(--primary-light);
        }

        .summary-label {
            color: var(--text-light);
        }

        .summary-value {
            font-weight: 600;
        }

        .price-bs {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-top: 0.2rem;
        }

        .price-bs strong {
            color: var(--text-dark);
        }

        .coupon-section {
            margin: 1.5rem 0;
            display: flex;
            gap: 0.5rem;
        }

        .coupon-input {
            flex: 1;
            padding: 0.8rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .coupon-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .coupon-btn {
            padding: 0.8rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .coupon-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-checkout {
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
            margin-top: 1rem;
            text-decoration: none;
            box-shadow: 0 5px 20px rgba(255, 152, 0, 0.3);
        }

        .btn-checkout:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.4);
        }

        .btn-checkout:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            background: var(--gray);
        }

        .btn-continue {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
        }

        .btn-continue:hover {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        /* Empty Cart */
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
        }

        .empty-cart i {
            font-size: 6rem;
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        .empty-cart h2 {
            font-size: 2rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-cart p {
            color: var(--text-light);
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }

        .btn-shop {
            display: inline-block;
            padding: 1.2rem 2.5rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 1.1rem;
        }

        .btn-shop:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        /* Productos Relacionados */
        .related-products {
            margin-top: 4rem;
        }

        .section-title {
            font-size: 1.8rem;
            color: var(--text-dark);
            margin-bottom: 2rem;
            position: relative;
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 80px;
            height: 4px;
            background: var(--accent);
            border-radius: 2px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2rem;
        }

        .product-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .product-image {
            width: 100%;
            height: 180px;
            background: linear-gradient(135deg, var(--gray-light), var(--white));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 3rem;
            border-bottom: 1px solid var(--gray);
            overflow: hidden;
            position: relative;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-image img {
            transform: scale(1.1);
        }

        .product-info {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-category {
            color: var(--primary);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
            font-weight: 600;
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
            margin-bottom: 1rem;
        }

        .price-bs-small {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .btn-add {
            width: 100%;
            padding: 0.8rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 500;
            margin-top: auto;
        }

        .btn-add:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 4rem 0 2rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
        }

        .footer-section h3 {
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .footer-section h3:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--accent);
        }

        .footer-section p {
            opacity: 0.9;
            line-height: 1.8;
            font-size: 0.95rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.8rem;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            opacity: 0.9;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .footer-links a:hover {
            opacity: 1;
            transform: translateX(5px);
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .social-links a {
            color: white;
            font-size: 1.8rem;
            opacity: 0.9;
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            opacity: 1;
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            margin-top: 3rem;
            border-top: 1px solid rgba(255,255,255,0.2);
            opacity: 0.8;
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
            
            .cart-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .item-image {
                margin-right: 0;
                align-self: center;
            }
            
            .item-actions {
                margin-left: 0;
                width: 100%;
                justify-content: flex-end;
            }
            
            .item-price {
                max-width: 100%;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Animaciones */
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
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
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
                    <a href="contacto.php"><i class='bx bx-envelope'></i> Contacto</a>
                </div>

                <div class="user-actions">
                    <a href="carrito.php" class="cart-icon">
                        <i class='bx bx-cart'></i>
                        <?php if ($total_items > 0): ?>
                            <span class="cart-badge"><?php echo $total_items; ?></span>
                        <?php endif; ?>
                    </a>

                    <?php if ($usuario_logueado): ?>
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
                    <?php else: ?>
                    <div class="user-info" onclick="window.location.href='../login.php'">
                        <div class="user-avatar">
                            <i class='bx bx-user'></i>
                        </div>
                        <span class="user-name">Iniciar Sesión</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <ul>
            <li><a href="index.php">Inicio</a></li>
            <li><i class='bx bx-chevron-right'></i></li>
            <li>Carrito de Compras</li>
        </ul>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <?php if ($carrito_mensaje): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <?php echo htmlspecialchars($carrito_mensaje); ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">
                <i class='bx bx-cart'></i>
                Carrito de Compras
            </h1>
            <?php if (!empty($productos_carrito)): ?>
                <form method="POST" onsubmit="return confirm('¿Estás seguro de vaciar el carrito?')"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="accion_sincrono" value="1">
                    <input type="hidden" name="accion" value="vaciar">
                    <button type="submit" class="vaciar-carrito">
                        <i class='bx bx-trash'></i>
                        Vaciar carrito
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($productos_carrito)): ?>
            <!-- Carrito Vacío -->
            <div class="empty-cart">
                <i class='bx bx-cart'></i>
                <h2>Tu carrito está vacío</h2>
                <p>Parece que aún no has agregado productos a tu carrito</p>
                <a href="index.php" class="btn-shop">
                    <i class='bx bx-shopping-bag'></i>
                    Ir a la tienda
                </a>
            </div>
        <?php else: ?>
            <!-- Cart Grid -->
            <div class="cart-grid">
                <!-- Cart Items -->
                <div class="cart-items">
                    <div class="cart-header">
                        <h2>
                            <i class='bx bx-package'></i>
                            Productos en tu carrito
                        </h2>
                        <span class="item-count"><?php echo $total_items; ?> producto(s)</span>
                    </div>

                    <?php foreach ($productos_carrito as $item): ?>
                        <div class="cart-item" id="item-<?php echo $item['id']; ?>">
                            <div class="item-image">
                                <?php if (!empty($item['imagen'])): ?>
                                    <img src="../uploads/productos/<?php echo $item['imagen']; ?>" alt="<?php echo htmlspecialchars($item['nombre']); ?>">
                                <?php else: ?>
                                    <i class='bx bx-package'></i>
                                <?php endif; ?>
                            </div>
                            <div class="item-details">
                                <div class="item-category"><?php echo htmlspecialchars($item['categoria']); ?></div>
                                <h3 class="item-title"><?php echo htmlspecialchars($item['nombre']); ?></h3>
                                <div class="item-code">Código: <?php echo htmlspecialchars($item['codigo']); ?></div>
                                <div class="item-price">
                                    <div class="price-unit">
                                        <div>Precio unit:</div>
                                        <strong>$<?php echo number_format($item['precio'], 2); ?></strong>
                                        <div class="price-bs" style="font-size:0.8rem;">
                                            Bs. <?php echo number_format($item['precio'] * $tasa_bs, 2); ?>
                                        </div>
                                    </div>
                                    <div class="price-total">
                                        <div>Total:</div>
                                        <strong id="subtotal-<?php echo $item['id']; ?>">$<?php echo number_format($item['importe'], 2); ?></strong>
                                        <div class="price-bs" style="font-size:0.8rem;">
                                            Bs. <?php echo number_format($item['importe'] * $tasa_bs, 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="item-actions">
                                <div class="quantity-control">
                                    <button type="button" class="quantity-btn" onclick="decrementar(<?php echo $item['id']; ?>, <?php echo $item['stock']; ?>)" <?php echo $item['cantidad'] <= 1 ? 'disabled' : ''; ?>>-</button>
                                    <input type="number" id="cantidad-<?php echo $item['id']; ?>" class="quantity-input" value="<?php echo $item['cantidad']; ?>" min="1" max="<?php echo min(99, $item['stock']); ?>" readonly>
                                    <button type="button" class="quantity-btn" onclick="incrementar(<?php echo $item['id']; ?>, <?php echo $item['stock']; ?>)" <?php echo $item['cantidad'] >= min(99, $item['stock']) ? 'disabled' : ''; ?>>+</button>
                                </div>
                                <button type="button" class="btn-remove" onclick="eliminarProducto(<?php echo $item['id']; ?>)" title="Eliminar producto">
                                    <i class='bx bx-trash'></i>
                                </button>
                            </div>
                            <?php if ($item['stock'] <= 5): ?>
                                <div class="stock-warning">
                                    <i class='bx bx-error-circle'></i>
                                    ¡Últimas <?php echo $item['stock']; ?> unidades!
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Cart Summary -->
                <div class="cart-summary">
                    <div class="summary-header">
                        <h2>
                            <i class='bx bx-receipt'></i>
                            Resumen de compra
                        </h2>
                    </div>
                    <div class="summary-body">
                        <div class="summary-row">
                            <span class="summary-label">Subtotal</span>
                            <div class="summary-value">
                                <div id="subtotal">$<?php echo number_format($subtotal, 2); ?></div>
                                <div class="price-bs">Bs. <?php echo number_format($subtotal * $tasa_bs, 2); ?></div>
                            </div>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">IVA</span>
                            <div class="summary-value">
                                <div id="iva">$<?php echo number_format($iva, 2); ?></div>
                                <div class="price-bs">Bs. <?php echo number_format($iva * $tasa_bs, 2); ?></div>
                            </div>
                        </div>
                        <div class="summary-row total">
                            <span class="summary-label">Total</span>
                            <div class="summary-value">
                                <div id="total" style="font-size:1.3rem;">$<?php echo number_format($total, 2); ?></div>
                                <div class="price-bs" style="font-size:1rem;">Bs. <?php echo number_format($total * $tasa_bs, 2); ?></div>
                            </div>
                        </div>

                        <!-- Botón de checkout -->
                        <?php if ($usuario_logueado): ?>
                            <a href="checkout.php" class="btn-checkout">
                                <i class='bx bx-credit-card'></i>
                                Proceder al pago
                            </a>
                            <p style="text-align: center; margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-light);">
                                <i class='bx bx-info-circle'></i>
                                Puedes pagar por transferencia, pago móvil o efectivo
                            </p>
                        <?php else: ?>
                            <a href="../login.php?redirect=tienda/checkout.php" class="btn-checkout">
                                <i class='bx bx-log-in'></i>
                                Inicia sesión para continuar
                            </a>
                            <p style="text-align: center; margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-light);">
                                ¿No tienes cuenta? <a href="../registro_cliente.php" style="color: var(--primary); font-weight: 600;">Regístrate aquí</a>
                            </p>
                        <?php endif; ?>

                        <a href="index.php" class="btn-continue">
                            <i class='bx bx-arrow-back'></i>
                            Seguir comprando
                        </a>
                    </div>
                </div>
            </div>

            <!-- Productos Relacionados -->
            <?php if (!empty($productos_recomendados)): ?>
            <div class="related-products">
                <h2 class="section-title">
                    <i class='bx bx-purchase-tag'></i>
                    Productos que podrían interesarte
                </h2>
                <div class="products-grid">
                    <?php foreach ($productos_recomendados as $producto): ?>
                        <div class="product-card">
                            <a href="producto.php?id=<?php echo $producto['id']; ?>" class="product-image">
                                <?php if (!empty($producto['imagen'])): ?>
                                    <img src="../uploads/productos/<?php echo $producto['imagen']; ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                <?php else: ?>
                                    <i class='bx bx-package'></i>
                                <?php endif; ?>
                            </a>
                            <div class="product-info">
                                <div class="product-category"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'General'); ?></div>
                                <h3 class="product-title"><?php echo htmlspecialchars($producto['nombre']); ?></h3>
                                <div class="product-price">
                                    $<?php echo number_format($producto['precio_venta'], 2); ?>
                                    <div class="price-bs-small">Bs. <?php echo number_format($producto['precio_venta'] * $tasa_bs, 2); ?></div>
                                </div>
                                <button class="btn-add" onclick="agregarAlCarritoSimple(<?php echo $producto['id']; ?>)">
                                    <i class='bx bx-cart-add'></i>
                                    Agregar al carrito
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-section">
                <h3>🐾 VetNexo</h3>
                <p>Tu veterinaria de confianza en VetNexo, Your Clinic Location. Comprometidos con la salud y bienestar de tus mascotas.</p>
                <div class="social-links">
                    <a href="#" target="_blank"><i class='bx bxl-facebook-circle'></i></a>
                    <a href="#" target="_blank"><i class='bx bxl-instagram-alt'></i></a>
                    <a href="#" target="_blank"><i class='bx bxl-whatsapp'></i></a>
                </div>
            </div>
            
            <div class="footer-section">
                <h3>Enlaces Rápidos</h3>
                <ul class="footer-links">
                    <li><a href="index.php"><i class='bx bx-chevron-right'></i> Inicio</a></li>
                    <li><a href="productos.php"><i class='bx bx-chevron-right'></i> Productos</a></li>
                    <li><a href="ofertas.php"><i class='bx bx-chevron-right'></i> Ofertas</a></li>
                    <li><a href="contacto.php"><i class='bx bx-chevron-right'></i> Contacto</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Atención al Cliente</h3>
                <ul class="footer-links">
                    <li><a href="../cliente/dashboard.php"><i class='bx bx-chevron-right'></i> Mi cuenta</a></li>
                    <li><a href="pedidos.php"><i class='bx bx-chevron-right'></i> Mis pedidos</a></li>
                    <li><a href="carrito.php"><i class='bx bx-chevron-right'></i> Carrito de compras</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Contacto</h3>
                <ul class="footer-links">
                    <li><i class='bx bxl-whatsapp'></i> +58 424-9602906</li>
                    <li><i class='bx bx-envelope'></i> hello@vetnexo.example</li>
                    <li><i class='bx bx-map'></i> VetNexo, Your Clinic Location</li>
                    <li><i class='bx bx-time'></i> Lun-Vie: 8am-5pm | Sáb: 8am-2pm</li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        // Función para actualizar cantidad vía AJAX
        function actualizarCantidad(productoId, nuevaCantidad, accion = 'actualizar') {
            fetch('../api/actualizar_carrito.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'producto_id=' + productoId + '&cantidad=' + nuevaCantidad + '&accion=' + accion
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Actualizar badge del carrito
                    const badge = document.querySelector('.cart-badge');
                    if (badge) {
                        badge.textContent = data.total_items;
                    } else if (data.total_items > 0) {
                        const cartIcon = document.querySelector('.cart-icon');
                        if (cartIcon) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'cart-badge';
                            newBadge.textContent = data.total_items;
                            cartIcon.appendChild(newBadge);
                        }
                    }
                    
                    // Actualizar subtotal del item
                    const itemSubtotal = document.getElementById(`subtotal-${productoId}`);
                    if (itemSubtotal && data.item_subtotal !== undefined) {
                        itemSubtotal.textContent = '$' + data.item_subtotal;
                        
                        // Actualizar también en Bs.
                        const priceBs = itemSubtotal.parentElement.querySelector('.price-bs');
                        if (priceBs) {
                            const tasaBs = <?php echo $tasa_bs; ?>;
                            priceBs.textContent = 'Bs. ' + (data.item_subtotal * tasaBs).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        }
                    }
                    
                    // Actualizar totales
                    document.getElementById('subtotal').innerHTML = '$' + data.subtotal + 
                        '<div class="price-bs">Bs. ' + (data.subtotal * <?php echo $tasa_bs; ?>).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</div>';
                    
                    document.getElementById('iva').innerHTML = '$' + data.iva + 
                        '<div class="price-bs">Bs. ' + (data.iva * <?php echo $tasa_bs; ?>).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</div>';
                    
                    document.getElementById('total').innerHTML = '$' + data.total + 
                        '<div class="price-bs">Bs. ' + (data.total * <?php echo $tasa_bs; ?>).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</div>';
                    
                    // Actualizar contador de items
                    const itemCount = document.querySelector('.item-count');
                    if (itemCount) {
                        itemCount.textContent = data.total_items + ' producto(s)';
                    }
                    
                    // Si cantidad es 0, eliminar la fila del producto
                    if (data.item_cantidad === 0) {
                        const itemRow = document.getElementById(`item-${productoId}`);
                        if (itemRow) {
                            itemRow.remove();
                        }
                        
                        // Si no quedan items, recargar para mostrar carrito vacío
                        if (data.total_items === 0) {
                            location.reload();
                        }
                    }
                    
                    mostrarNotificacion('✅ ' + data.message);
                } else {
                    mostrarNotificacion('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al actualizar el carrito', 'error');
            });
        }

        // Función para incrementar cantidad
        function incrementar(productoId, maxStock) {
            const input = document.getElementById(`cantidad-${productoId}`);
            let valor = parseInt(input.value) + 1;
            if (valor <= maxStock) {
                input.value = valor;
                actualizarCantidad(productoId, valor, 'incrementar');
                
                // Habilitar/deshabilitar botones
                const btnDecrement = input.parentElement.querySelector('button:first-child');
                const btnIncrement = input.parentElement.querySelector('button:last-child');
                
                btnDecrement.disabled = false;
                if (valor >= maxStock) {
                    btnIncrement.disabled = true;
                }
            }
        }

        // Función para decrementar cantidad
        function decrementar(productoId, maxStock) {
            const input = document.getElementById(`cantidad-${productoId}`);
            let valor = parseInt(input.value) - 1;
            
            if (valor >= 1) {
                input.value = valor;
                actualizarCantidad(productoId, valor, 'decrementar');
                
                // Habilitar/deshabilitar botones
                const btnIncrement = input.parentElement.querySelector('button:last-child');
                btnIncrement.disabled = false;
                
                if (valor <= 1) {
                    const btnDecrement = input.parentElement.querySelector('button:first-child');
                    btnDecrement.disabled = true;
                }
            } else if (valor === 0) {
                // Preguntar antes de eliminar
                if (confirm('¿Eliminar producto del carrito?')) {
                    actualizarCantidad(productoId, 0, 'eliminar');
                }
            }
        }

        // Función para eliminar producto directamente
        function eliminarProducto(productoId) {
            if (confirm('¿Eliminar producto del carrito?')) {
                actualizarCantidad(productoId, 0, 'eliminar');
            }
        }

        // Función para agregar producto simple (desde relacionados)
        function agregarAlCarritoSimple(productoId) {
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
                    // Recargar para actualizar badge
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    mostrarNotificacion('❌ Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al agregar el producto', 'error');
            });
        }

        // Función para mostrar notificaciones
        function mostrarNotificacion(mensaje, tipo = 'success') {
            const notificacion = document.createElement('div');
            notificacion.className = 'alert alert-' + tipo;
            notificacion.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                max-width: 400px;
            `;
            notificacion.innerHTML = `
                <i class='bx ${tipo === 'success' ? 'bx-check-circle' : tipo === 'info' ? 'bx-info-circle' : 'bx-error-circle'}'></i>
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

        // Confirmar vaciar carrito
        document.querySelector('form[onsubmit]')?.addEventListener('submit', function(e) {
            if (!confirm('¿Estás seguro de vaciar el carrito?')) {
                e.preventDefault();
            }
        });
    </script>
<?php echo bcv_badge_html(); ?>
</body>
</html>