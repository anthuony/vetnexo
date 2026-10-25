<?php
// tienda/index.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once '../config/bcv.php';

// Verificar si el usuario está logueado (opcional para la tienda)
$usuario_logueado = isset($_SESSION['user_id']);
$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Invitado';

$database = new Database();
$conn = $database->conectar();

// Obtener categorías de productos
$stmt_categorias = $conn->query("SELECT * FROM categorias_productos WHERE activo = 1 ORDER BY nombre");
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Obtener productos destacados
$stmt_destacados = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 AND p.destacado = 1 
    ORDER BY p.nombre 
    LIMIT 8
");
$stmt_destacados->execute();
$productos_destacados = $stmt_destacados->fetchAll(PDO::FETCH_ASSOC);

// Si no hay destacados, mostrar productos aleatorios con stock
if (empty($productos_destacados)) {
    $stmt_destacados = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.activo = 1 AND p.stock_actual > 0
        ORDER BY p.id DESC 
        LIMIT 8
    ");
    $stmt_destacados->execute();
    $productos_destacados = $stmt_destacados->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener productos nuevos (últimos 30 días)
$stmt_nuevos = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 AND p.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY p.fecha_creacion DESC 
    LIMIT 4
");
$stmt_nuevos->execute();
$productos_nuevos = $stmt_nuevos->fetchAll(PDO::FETCH_ASSOC);

// Obtener productos en oferta (con precio_compra < precio_venta * 0.7)
$stmt_ofertas = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre,
           ROUND(((p.precio_venta - p.precio_compra) / p.precio_venta * 100), 0) as descuento
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 AND p.stock_actual > 0 
          AND p.precio_compra > 0 
          AND p.precio_venta > p.precio_compra * 1.3
    ORDER BY descuento DESC 
    LIMIT 4
");
$stmt_ofertas->execute();
$productos_ofertas = $stmt_ofertas->fetchAll(PDO::FETCH_ASSOC);

// Obtener productos más vendidos (simulado con productos aleatorios)
$stmt_mas_vendidos = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 AND p.stock_actual > 0
    ORDER BY p.id DESC 
    LIMIT 4
");
$stmt_mas_vendidos->execute();
$productos_mas_vendidos = $stmt_mas_vendidos->fetchAll(PDO::FETCH_ASSOC);

// Obtener datos bancarios para mostrarlos
$stmt_bancos = $conn->prepare("SELECT * FROM datos_bancarios WHERE activo = 1 LIMIT 1");
$stmt_bancos->execute();
$datos_bancarios = $stmt_bancos->fetch(PDO::FETCH_ASSOC);

// Contar items en el carrito
$carrito_count = 0;
if (isset($_SESSION['carrito']) && is_array($_SESSION['carrito'])) {
    $carrito_count = array_sum(array_column($_SESSION['carrito'], 'cantidad'));
}

// Tasa de cambio simulada (en un sistema real, esto vendría de una tabla de configuración)
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🐾 Tienda VetNexo - Todo para tu mascota</title>
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

        /* Hero Banner */
        .hero-banner {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-banner:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none" opacity="0.1"><path d="M0,50 Q25,40 50,50 T100,50 L100,100 L0,100 Z" fill="white"/></svg>');
            background-size: cover;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .hero-content h1 {
            font-size: 2.8rem;
            margin-bottom: 1rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .hero-content p {
            font-size: 1.2rem;
            opacity: 0.95;
            max-width: 600px;
            margin: 0 auto;
        }

        .hero-paw {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        /* Search Bar */
        .search-section {
            max-width: 1400px;
            margin: -2rem auto 3rem;
            padding: 0 2rem;
            position: relative;
            z-index: 10;
        }

        .search-container {
            background: var(--white);
            border-radius: 60px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            padding: 0.5rem;
            display: flex;
            align-items: center;
            border: 3px solid var(--primary-light);
        }

        .search-input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: none;
            background: transparent;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
        }

        .search-input:focus {
            outline: none;
        }

        .search-category {
            padding: 0.5rem 1rem;
            border-left: 2px solid var(--gray);
            border-right: 2px solid var(--gray);
        }

        .search-category select {
            border: none;
            background: transparent;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            color: var(--text-dark);
            cursor: pointer;
            padding: 0.3rem;
        }

        .search-category select:focus {
            outline: none;
        }

        .search-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 1rem 2.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .search-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.4);
        }

        /* Categories Section */
        .categories-section {
            max-width: 1400px;
            margin: 4rem auto;
            padding: 0 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-dark);
            position: relative;
            padding-bottom: 0.5rem;
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

        .section-title i {
            color: var(--accent);
            margin-right: 0.5rem;
        }

        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            background: var(--primary-soft);
        }

        .view-all:hover {
            background: var(--primary);
            color: white;
            transform: translateX(5px);
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .category-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2.5rem 1.5rem;
            text-align: center;
            text-decoration: none;
            color: var(--text-dark);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .category-card:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        .category-card:hover:before {
            transform: translateX(0);
        }

        .category-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: var(--shadow-lg);
        }

        .category-icon {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }

        .category-card:hover .category-icon {
            transform: scale(1.1);
            color: var(--accent);
        }

        .category-card h3 {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .category-card p {
            color: var(--text-light);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        /* Products Grid */
        .products-section {
            max-width: 1400px;
            margin: 4rem auto;
            padding: 0 2rem;
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
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .product-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: var(--accent);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 2;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .product-badge.destacado {
            background: var(--primary);
        }

        .product-badge.oferta {
            background: var(--danger);
        }

        .product-badge.nuevo {
            background: var(--info);
        }

        .product-image {
            width: 100%;
            height: 220px;
            background: linear-gradient(135deg, var(--gray-light), var(--white));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 4rem;
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

        .product-image:after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, transparent 70%, rgba(0,0,0,0.1));
            pointer-events: none;
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
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            line-height: 1.4;
        }

        .product-desc {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            line-height: 1.6;
            flex: 1;
        }

        .product-price {
            margin-bottom: 1rem;
            background: var(--primary-soft);
            padding: 0.8rem;
            border-radius: var(--radius);
            border: 1px solid var(--primary-light);
        }

        .price-bs {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 0.2rem;
        }

        .price-bs strong {
            color: var(--text-dark);
            font-size: 1rem;
        }

        .price-usd {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .price-usd small {
            font-size: 0.8rem;
            font-weight: normal;
            color: var(--text-light);
        }

        .old-price {
            color: var(--text-lighter);
            text-decoration: line-through;
            font-size: 0.9rem;
            margin-left: 0.5rem;
        }

        .product-stock {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 1rem;
            padding: 0.3rem 0;
        }

        .stock-low {
            color: var(--danger);
            font-weight: 500;
        }

        .product-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
        }

        .btn-add-cart {
            flex: 1;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-add-cart:hover:not(:disabled) {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-add-cart:disabled {
            background: var(--gray);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .btn-view {
            width: 45px;
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid var(--primary-light);
            border-radius: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.2rem;
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Payment Methods Banner */
        .payment-banner {
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            padding: 3rem 2rem;
            margin: 3rem 0;
            border-top: 3px solid var(--primary-light);
            border-bottom: 3px solid var(--primary-light);
        }

        .payment-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
        }

        .payment-info h2 {
            font-size: 2rem;
            color: var(--primary-dark);
            margin-bottom: 1rem;
        }

        .payment-info h2 i {
            color: var(--accent);
        }

        .payment-info p {
            color: var(--text-light);
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }

        .bank-details {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--primary-light);
        }

        .bank-item {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px dashed var(--gray);
        }

        .bank-item:last-child {
            border-bottom: none;
        }

        .bank-label {
            font-weight: 600;
            color: var(--text-dark);
        }

        .bank-value {
            color: var(--primary);
            font-weight: 500;
        }

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .method-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            text-align: center;
            border: 2px solid var(--primary-light);
            transition: all 0.3s ease;
        }

        .method-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: var(--shadow);
        }

        .method-card i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .method-card h4 {
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .method-card p {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Promo Banner */
        .promo-banner {
            max-width: 1400px;
            margin: 3rem auto;
            padding: 0 2rem;
        }

        .promo-content {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border-radius: var(--radius-lg);
            padding: 3rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .promo-content:before {
            content: '🐾';
            position: absolute;
            right: 2rem;
            bottom: -1rem;
            font-size: 8rem;
            opacity: 0.1;
            transform: rotate(-15deg);
        }

        .promo-text h2 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .promo-text p {
            opacity: 0.95;
            font-size: 1.2rem;
        }

        .promo-btn {
            background: white;
            color: var(--accent-dark);
            padding: 1.2rem 2.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            z-index: 2;
        }

        .promo-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
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
            transition: opacity 0.3s ease;
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
        @media (max-width: 968px) {
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
            
            .search-container {
                flex-direction: column;
                border-radius: var(--radius);
                padding: 1rem;
            }
            
            .search-category {
                border: none;
                width: 100%;
                padding: 0.5rem 0;
            }
            
            .search-category select {
                width: 100%;
            }
            
            .search-btn {
                width: 100%;
                justify-content: center;
            }
            
            .promo-content {
                flex-direction: column;
                text-align: center;
                gap: 2rem;
            }
            
            .payment-content {
                grid-template-columns: 1fr;
            }
            
            .hero-content h1 {
                font-size: 2rem;
            }
        }

        @media (max-width: 768px) {
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .payment-methods {
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
            <a href="index.php" class="logo">
                <img src="../includes/img/logo.svg" alt="VetNexo" class="logo-img">
                <div class="logo-text">
                    <h1>VetNexo</h1>
                    <span>🐾 VetNexo - Your Clinic Location</span>
                </div>
            </a>

            <div class="nav-menu">
                <div class="nav-links">
                    <a href="index.php" class="active"><i class='bx bx-home'></i> Inicio</a>
                    <a href="productos.php"><i class='bx bx-package'></i> Productos</a>
                    <a href="ofertas.php"><i class='bx bx-purchase-tag'></i> Ofertas</a>
                    <a href="contacto.php"><i class='bx bx-envelope'></i> Contacto</a>
                </div>

                <div class="user-actions">
                    <a href="carrito.php" class="cart-icon">
                        <i class='bx bx-cart'></i>
                        <?php if ($carrito_count > 0): ?>
                            <span class="cart-badge"><?php echo $carrito_count; ?></span>
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

    <!-- Hero Banner -->
    <div class="hero-banner">
        <div class="hero-content">
            <div class="hero-paw">🐾</div>
            <h1>¡Bienvenido a VetNexo!</h1>
            <p>Tu tienda de confianza en VetNexo, Your Clinic Location. Encuentra todo para el cuidado y bienestar de tus mascotas.</p>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="search-section">
        <form action="buscar.php" method="GET" class="search-container">
            <input type="text" name="q" class="search-input" placeholder="¿Qué estás buscando? (alimentos, medicinas, accesorios...)" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
            <div class="search-category">
                <select name="categoria">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo (isset($_GET['categoria']) && $_GET['categoria'] == $cat['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="search-btn">
                <i class='bx bx-search'></i>
                Buscar
            </button>
        </form>
    </div>

    <!-- Categorías -->
    <div class="categories-section">
        <div class="section-header">
            <h2 class="section-title"><i class='bx bx-category'></i> Categorías</h2>
            <a href="categorias.php" class="view-all">
                Ver todas <i class='bx bx-chevron-right'></i>
            </a>
        </div>
        <div class="categories-grid">
            <?php foreach ($categorias as $categoria): ?>
                <a href="categoria.php?id=<?php echo $categoria['id']; ?>" class="category-card">
                    <div class="category-icon">
                        <?php
                        $iconos = [
                            'Medicamentos' => 'bx-capsule',
                            'Vacunas' => 'bx-syringe',
                            'Insumos Médicos' => 'bx-first-aid',
                            'Alimentos' => 'bx-bowl-hot',
                            'ACCESORIOS' => 'bx-dog'
                        ];
                        $icono = $iconos[$categoria['nombre']] ?? 'bx-category';
                        ?>
                        <i class='bx <?php echo $icono; ?>'></i>
                    </div>
                    <h3><?php echo htmlspecialchars($categoria['nombre']); ?></h3>
                    <p><?php echo htmlspecialchars($categoria['descripcion'] ?? ''); ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Productos Destacados -->
    <?php if (!empty($productos_destacados)): ?>
    <div class="products-section">
        <div class="section-header">
            <h2 class="section-title"><i class='bx bx-star'></i> Productos Destacados</h2>
            <a href="destacados.php" class="view-all">
                Ver todos <i class='bx bx-chevron-right'></i>
            </a>
        </div>
        <div class="products-grid">
            <?php foreach ($productos_destacados as $producto): ?>
                <div class="product-card">
                    <div class="product-badge destacado">✨ Destacado</div>
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
                        <p class="product-desc"><?php echo htmlspecialchars(substr($producto['descripcion'] ?? '', 0, 80)) . '...'; ?></p>
                        
                        <div class="product-price">
                            <div class="price-usd">
                                $<?php echo number_format($producto['precio_venta'], 2); ?>
                                <small>USD</small>
                                <?php if ($producto['precio_compra'] > 0 && $producto['precio_venta'] > $producto['precio_compra'] * 1.2): ?>
                                    <span class="old-price">$<?php echo number_format($producto['precio_compra'] * 1.5, 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="price-bs">
                                <strong>Bs. <?php echo number_format($producto['precio_venta'] * $tasa_bs, 2); ?></strong>
                            </div>
                        </div>
                        
                        <div class="product-stock">
                            <?php if ($producto['stock_actual'] <= 0): ?>
                                <span class="stock-low"><i class='bx bx-x-circle'></i> Sin stock</span>
                            <?php elseif ($producto['stock_actual'] <= $producto['stock_minimo']): ?>
                                <span class="stock-low"><i class='bx bx-error-circle'></i> ¡Últimas <?php echo $producto['stock_actual']; ?> unidades!</span>
                            <?php else: ?>
                                <span><i class='bx bx-check-circle' style="color: var(--success);"></i> En stock (<?php echo $producto['stock_actual']; ?>)</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-actions">
                            <?php if ($producto['stock_actual'] > 0): ?>
                                <button class="btn-add-cart" onclick="agregarAlCarrito(<?php echo $producto['id']; ?>)">
                                    <i class='bx bx-cart-add'></i>
                                    Agregar
                                </button>
                            <?php else: ?>
                                <button class="btn-add-cart" style="background: var(--gray);" disabled>
                                    <i class='bx bx-x'></i>
                                    Sin stock
                                </button>
                            <?php endif; ?>
                            <a href="producto.php?id=<?php echo $producto['id']; ?>" class="btn-view" title="Ver detalles">
                                <i class='bx bx-show'></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Productos en Oferta -->
    <?php if (!empty($productos_ofertas)): ?>
    <div class="products-section">
        <div class="section-header">
            <h2 class="section-title"><i class='bx bx-purchase-tag'></i> ¡Ofertas Especiales!</h2>
            <a href="ofertas.php" class="view-all">
                Ver todas <i class='bx bx-chevron-right'></i>
            </a>
        </div>
        <div class="products-grid">
            <?php foreach ($productos_ofertas as $producto): ?>
                <div class="product-card">
                    <div class="product-badge oferta">🔥 -<?php echo $producto['descuento']; ?>%</div>
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
                        <p class="product-desc"><?php echo htmlspecialchars(substr($producto['descripcion'] ?? '', 0, 80)) . '...'; ?></p>
                        
                        <div class="product-price">
                            <div class="price-usd">
                                $<?php echo number_format($producto['precio_venta'], 2); ?>
                                <small>USD</small>
                                <?php if ($producto['precio_compra'] > 0): ?>
                                    <span class="old-price">$<?php echo number_format($producto['precio_compra'] * 1.5, 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="price-bs">
                                <strong>Bs. <?php echo number_format($producto['precio_venta'] * $tasa_bs, 2); ?></strong>
                            </div>
                        </div>
                        
                        <div class="product-actions">
                            <?php if ($producto['stock_actual'] > 0): ?>
                                <button class="btn-add-cart" onclick="agregarAlCarrito(<?php echo $producto['id']; ?>)">
                                    <i class='bx bx-cart-add'></i>
                                    Agregar
                                </button>
                            <?php else: ?>
                                <button class="btn-add-cart" disabled>
                                    <i class='bx bx-x'></i>
                                    Sin stock
                                </button>
                            <?php endif; ?>
                            <a href="producto.php?id=<?php echo $producto['id']; ?>" class="btn-view">
                                <i class='bx bx-show'></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Productos Nuevos -->
    <?php if (!empty($productos_nuevos)): ?>
    <div class="products-section">
        <div class="section-header">
            <h2 class="section-title"><i class='bx bx-time'></i> Productos Nuevos</h2>
            <a href="nuevos.php" class="view-all">
                Ver todos <i class='bx bx-chevron-right'></i>
            </a>
        </div>
        <div class="products-grid">
            <?php foreach ($productos_nuevos as $producto): ?>
                <div class="product-card">
                    <div class="product-badge nuevo">🆕 Nuevo</div>
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
                        <p class="product-desc"><?php echo htmlspecialchars(substr($producto['descripcion'] ?? '', 0, 80)) . '...'; ?></p>
                        
                        <div class="product-price">
                            <div class="price-usd">
                                $<?php echo number_format($producto['precio_venta'], 2); ?>
                                <small>USD</small>
                            </div>
                            <div class="price-bs">
                                <strong>Bs. <?php echo number_format($producto['precio_venta'] * $tasa_bs, 2); ?></strong>
                            </div>
                        </div>
                        
                        <div class="product-actions">
                            <button class="btn-add-cart" onclick="agregarAlCarrito(<?php echo $producto['id']; ?>)">
                                <i class='bx bx-cart-add'></i>
                                Agregar
                            </button>
                            <a href="producto.php?id=<?php echo $producto['id']; ?>" class="btn-view">
                                <i class='bx bx-show'></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Banner de Métodos de Pago -->
    <?php if ($datos_bancarios): ?>
    <div class="payment-banner">
        <div class="payment-content">
            <div class="payment-info">
                <h2><i class='bx bx-credit-card'></i> Métodos de Pago</h2>
                <p>Realiza tus pagos de forma segura a nuestras cuentas bancarias</p>
                
                <div class="bank-details">
                    <div class="bank-item">
                        <span class="bank-label">Banco:</span>
                        <span class="bank-value"><?php echo $datos_bancarios['banco']; ?></span>
                    </div>
                    <div class="bank-item">
                        <span class="bank-label">Titular:</span>
                        <span class="bank-value"><?php echo $datos_bancarios['titular']; ?></span>
                    </div>
                    <div class="bank-item">
                        <span class="bank-label">RIF:</span>
                        <span class="bank-value"><?php echo $datos_bancarios['rif']; ?></span>
                    </div>
                    <div class="bank-item">
                        <span class="bank-label">Cuenta Ahorros:</span>
                        <span class="bank-value"><?php echo $datos_bancarios['cuenta_ahorros']; ?></span>
                    </div>
                    <div class="bank-item">
                        <span class="bank-label">Pago Móvil:</span>
                        <span class="bank-value"><?php echo $datos_bancarios['telefono_pago_movil']; ?> (<?php echo $datos_bancarios['cedula_pago_movil']; ?>)</span>
                    </div>
                </div>
            </div>
            
            <div class="payment-methods">
                <div class="method-card">
                    <i class='bx bx-transfer'></i>
                    <h4>Transferencia</h4>
                    <p>Paga desde cualquier banco</p>
                </div>
                <div class="method-card">
                    <i class='bx bx-mobile-alt'></i>
                    <h4>Pago Móvil</h4>
                    <p>Rápido y seguro</p>
                </div>
                <div class="method-card">
                    <i class='bx bx-money'></i>
                    <h4>Efectivo</h4>
                    <p>Paga al retirar</p>
                </div>
                <div class="method-card">
                    <i class='bx bx-credit-card'></i>
                    <h4>Punto de Venta</h4>
                    <p>Débito/Crédito en tienda</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Promo Banner -->
    <div class="promo-banner">
        <div class="promo-content">
            <div class="promo-text">
                <h2>🛍️ ¡Compra desde casa!</h2>
                <p>Haz tu pedido online y retira en nuestra tienda en VetNexo</p>
            </div>
            <a href="productos.php" class="promo-btn">
                Ver productos <i class='bx bx-right-arrow-alt'></i>
            </a>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-section">
                <h3>🐾 VetNexo</h3>
                <p>Tu veterinaria de confianza en VetNexo, Your Clinic Location. Comprometidos con la salud y bienestar de tus mascotas desde 2020.</p>
                <div class="social-links">
                    <a href="#" target="_blank"><i class='bx bxl-facebook-circle'></i></a>
                    <a href="#" target="_blank"><i class='bx bxl-instagram-alt'></i></a>
                    <a href="#" target="_blank"><i class='bx bxl-whatsapp'></i></a>
                    <a href="#" target="_blank"><i class='bx bxl-tiktok'></i></a>
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
                    <li><a href="preguntas.php"><i class='bx bx-chevron-right'></i> Preguntas frecuentes</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Contacto</h3>
                <ul class="footer-links">
                    <li><i class='bx bxl-whatsapp'></i> +58 424-9602906</li>
                    <li><i class='bx bx-envelope'></i> hello@vetnexo.example</li>
                    <li><i class='bx bx-map'></i> VetNexo, Your Clinic Location - Your Region</li>
                    <li><i class='bx bx-time'></i> Lun-Vie: 8am-5pm | Sáb: 8am-2pm</li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
            <p style="margin-top: 0.5rem; font-size: 0.8rem;">Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
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
                    // Actualizar badge del carrito
                    const badge = document.querySelector('.cart-badge');
                    if (badge) {
                        badge.textContent = data.total_items;
                    } else {
                        const cartIcon = document.querySelector('.cart-icon');
                        if (cartIcon) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'cart-badge';
                            newBadge.textContent = data.total_items;
                            cartIcon.appendChild(newBadge);
                        }
                    }
                    
                    // Mostrar notificación flotante
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
            // Crear elemento de notificación
            const notificacion = document.createElement('div');
            notificacion.className = 'notificacion-flotante';
            notificacion.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${tipo === 'success' ? 'var(--success)' : 'var(--danger)'};
                color: white;
                padding: 1rem 2rem;
                border-radius: 50px;
                box-shadow: var(--shadow-lg);
                z-index: 9999;
                animation: slideIn 0.3s ease;
                font-weight: 500;
            `;
            notificacion.textContent = mensaje;
            
            document.body.appendChild(notificacion);
            
            // Eliminar después de 3 segundos
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