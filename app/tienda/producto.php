<?php
// tienda/producto.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once '../config/bcv.php';

// Verificar si el usuario está logueado (opcional)
$usuario_logueado = isset($_SESSION['user_id']);
$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Invitado';

$database = new Database();
$conn = $database->conectar();

// Obtener ID del producto
$producto_id = $_GET['id'] ?? 0;

if (!$producto_id) {
    header('Location: index.php');
    exit();
}

// Obtener información del producto
$stmt = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre, c.id as categoria_id,
           pr.nombre as proveedor_nombre
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
    WHERE p.id = ? AND p.activo = 1
");
$stmt->execute([$producto_id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    header('Location: index.php?error=producto_no_encontrado');
    exit();
}

// Obtener productos relacionados (misma categoría)
$stmt_relacionados = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 
    AND p.categoria_id = ? 
    AND p.id != ? 
    AND p.stock_actual > 0
    ORDER BY p.id DESC 
    LIMIT 4
");
$stmt_relacionados->execute([$producto['categoria_id'], $producto_id]);
$productos_relacionados = $stmt_relacionados->fetchAll(PDO::FETCH_ASSOC);

// Si no hay suficientes relacionados, completar con productos aleatorios
if (count($productos_relacionados) < 4) {
    $restantes = 4 - count($productos_relacionados);
    $ids_relacionados = array_column($productos_relacionados, 'id');
    $ids_relacionados[] = $producto_id;
    $placeholders = implode(',', array_fill(0, count($ids_relacionados), '?'));
    
    $stmt_aleatorios = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.activo = 1 
        AND p.stock_actual > 0 
        AND p.id NOT IN ($placeholders)
        ORDER BY p.id DESC 
        LIMIT $restantes
    ");
    $stmt_aleatorios->execute($ids_relacionados);
    $productos_aleatorios = $stmt_aleatorios->fetchAll(PDO::FETCH_ASSOC);
    
    $productos_relacionados = array_merge($productos_relacionados, $productos_aleatorios);
}

// Obtener comentarios/valoraciones (si existiera tabla de reseñas)
$reseñas = [];
if (isset($producto['permitir_reseñas']) && $producto['permitir_reseñas']) {
    // Aquí iría la consulta de reseñas si existiera la tabla
}

// Contar items en el carrito
$carrito_count = 0;
if (isset($_SESSION['carrito']) && is_array($_SESSION['carrito'])) {
    $carrito_count = array_sum(array_column($_SESSION['carrito'], 'cantidad'));
}

// Verificar si el producto ya está en el carrito
$en_carrito = isset($_SESSION['carrito'][$producto_id]) ? $_SESSION['carrito'][$producto_id]['cantidad'] : 0;

// Tasa de cambio
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);

// Calcular descuento si aplica
$descuento_porcentaje = 0;
$precio_original = $producto['precio_venta'];
$precio_con_descuento = $producto['precio_venta'];

if ($producto['precio_compra'] > 0 && $producto['precio_venta'] < $producto['precio_compra'] * 1.2) {
    $precio_original = $producto['precio_compra'] * 1.5;
    $descuento_porcentaje = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($producto['nombre']); ?> - VetNexo</title>
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

        /* Producto Grid */
        .producto-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            margin-bottom: 3rem;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
        }

        @media (max-width: 968px) {
            .producto-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        /* Galería de imágenes */
        .producto-gallery {
            position: relative;
        }

        .main-image {
            width: 100%;
            height: 400px;
            background: linear-gradient(135deg, var(--gray-light), var(--white));
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 6rem;
            border: 1px solid var(--gray);
            overflow: hidden;
            position: relative;
            cursor: zoom-in;
            transition: all 0.3s ease;
        }

        .main-image:hover {
            box-shadow: var(--shadow);
        }

        .main-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: transform 0.5s ease;
        }

        .main-image:hover img {
            transform: scale(1.1);
        }

        .product-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: var(--accent);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            z-index: 2;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
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

        .thumbnail-list {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .thumbnail {
            width: 80px;
            height: 80px;
            background: var(--gray-light);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 2rem;
            border: 2px solid var(--gray);
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .thumbnail:hover,
        .thumbnail.active {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Información del producto */
        .producto-info {
            display: flex;
            flex-direction: column;
        }

        .producto-categoria {
            color: var(--primary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .producto-titulo {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }

        .producto-codigo {
            color: var(--text-lighter);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .producto-rating {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .stars {
            display: flex;
            gap: 0.2rem;
            color: var(--warning);
            font-size: 1.2rem;
        }

        .rating-count {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .producto-precio {
            background: var(--primary-soft);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            border: 2px solid var(--primary-light);
        }

        .precio-container {
            display: flex;
            align-items: baseline;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .precio-actual {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .precio-actual small {
            font-size: 1rem;
            font-weight: normal;
            color: var(--text-light);
        }

        .precio-anterior {
            color: var(--text-lighter);
            text-decoration: line-through;
            font-size: 1.3rem;
        }

        .descuento-badge {
            background: var(--danger);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .precio-bs {
            font-size: 1.2rem;
            color: var(--text-light);
            margin-top: 0.5rem;
        }

        .precio-bs strong {
            color: var(--text-dark);
            font-size: 1.4rem;
        }

        .producto-stock {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            padding: 0.8rem;
            background: var(--gray-light);
            border-radius: var(--radius);
        }

        .stock-disponible {
            color: var(--success);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .stock-bajo {
            color: var(--danger);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .stock-agotado {
            color: var(--danger);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .cantidad-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .cantidad-label {
            font-weight: 500;
            color: var(--text-dark);
            min-width: 80px;
        }

        .cantidad-control {
            display: flex;
            align-items: center;
            border: 2px solid var(--primary-light);
            border-radius: 50px;
            overflow: hidden;
            background: var(--white);
        }

        .cantidad-btn {
            width: 40px;
            height: 40px;
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

        .cantidad-btn:hover:not(:disabled) {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .cantidad-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .cantidad-input {
            width: 60px;
            height: 40px;
            border: none;
            border-left: 1px solid var(--gray);
            border-right: 1px solid var(--gray);
            text-align: center;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            font-weight: 500;
        }

        .cantidad-input:focus {
            outline: none;
        }

        .stock-disponible-text {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .producto-acciones {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .btn-add-cart {
            flex: 2;
            min-width: 200px;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
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
            box-shadow: 0 5px 20px rgba(46, 125, 50, 0.3);
        }

        .btn-add-cart:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.4);
        }

        .btn-add-cart:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: var(--gray);
        }

        .btn-wishlist {
            flex: 1;
            min-width: 50px;
            padding: 1rem;
            background: var(--white);
            color: var(--danger);
            border: 2px solid var(--danger);
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-wishlist:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-3px);
        }

        .producto-meta {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            color: var(--text-light);
        }

        .meta-item i {
            color: var(--primary);
            width: 24px;
            font-size: 1.2rem;
        }

        .meta-item strong {
            color: var(--text-dark);
            margin-right: 0.3rem;
        }

        /* Tabs */
        .producto-tabs {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
            overflow: hidden;
            margin-bottom: 3rem;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid var(--gray);
            background: var(--gray-light);
        }

        .tab-btn {
            padding: 1rem 2rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
            background: var(--white);
        }

        .tab-btn.active:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--primary);
        }

        .tab-content {
            padding: 2rem;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .descripcion-content {
            line-height: 1.8;
            color: var(--text-light);
        }

        .descripcion-content h3 {
            color: var(--text-dark);
            margin: 1.5rem 0 1rem;
        }

        .descripcion-content ul,
        .descripcion-content ol {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }

        .descripcion-content li {
            margin-bottom: 0.3rem;
        }

        /* Especificaciones */
        .especificaciones-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .especificaciones-grid {
                grid-template-columns: 1fr;
            }
        }

        .especificacion-item {
            display: flex;
            justify-content: space-between;
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray);
        }

        .espec-label {
            font-weight: 600;
            color: var(--text-dark);
        }

        .espec-value {
            color: var(--primary);
        }

        /* Reseñas */
        .reseñas-stats {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--gray-light);
            border-radius: var(--radius);
            align-items: center;
            flex-wrap: wrap;
        }

        .rating-average {
            text-align: center;
        }

        .average-number {
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
        }

        .average-stars {
            color: var(--warning);
            font-size: 1.2rem;
            margin: 0.5rem 0;
        }

        .total-reviews {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .rating-bars {
            flex: 1;
            min-width: 250px;
        }

        .rating-bar-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .rating-label {
            min-width: 60px;
            color: var(--text-light);
        }

        .bar-container {
            flex: 1;
            height: 8px;
            background: var(--gray);
            border-radius: 4px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            background: var(--warning);
            border-radius: 4px;
        }

        .rating-count-small {
            min-width: 40px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Productos relacionados */
        .related-products {
            margin-top: 3rem;
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

        .price-bs {
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
            
            .producto-titulo {
                font-size: 1.8rem;
            }
            
            .precio-actual {
                font-size: 2rem;
            }
            
            .producto-acciones {
                flex-direction: column;
            }
            
            .btn-add-cart,
            .btn-wishlist {
                width: 100%;
            }
            
            .tabs-header {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                flex: 1;
                padding: 1rem;
                text-align: center;
            }
            
            .reseñas-stats {
                flex-direction: column;
                text-align: center;
            }
            
            .products-grid {
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
                    <a href="index.php"><i class='bx bx-home'></i> Inicio</a>
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

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <ul>
            <li><a href="index.php">Inicio</a></li>
            <li><i class='bx bx-chevron-right'></i></li>
            <li><a href="categoria.php?id=<?php echo $producto['categoria_id']; ?>"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Productos'); ?></a></li>
            <li><i class='bx bx-chevron-right'></i></li>
            <li><?php echo htmlspecialchars($producto['nombre']); ?></li>
        </ul>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Producto Grid -->
        <div class="producto-grid">
            <!-- Galería de imágenes -->
            <div class="producto-gallery">
                <div class="main-image" id="mainImage">
                    <?php if (!empty($producto['imagen'])): ?>
                        <img src="../uploads/productos/<?php echo $producto['imagen']; ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>" id="mainImg">
                    <?php else: ?>
                        <i class='bx bx-package'></i>
                    <?php endif; ?>
                    
                    <?php if ($descuento_porcentaje > 0): ?>
                        <div class="product-badge oferta">-<?php echo $descuento_porcentaje; ?>%</div>
                    <?php elseif ($producto['destacado']): ?>
                        <div class="product-badge destacado">✨ Destacado</div>
                    <?php endif; ?>
                </div>
                
                <?php
                // Aquí podrías obtener más imágenes del producto si tuvieras una tabla de imágenes
                // Por ahora, solo mostramos la imagen principal
                ?>
            </div>

            <!-- Información del producto -->
            <div class="producto-info">
                <div class="producto-categoria"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'General'); ?></div>
                <h1 class="producto-titulo"><?php echo htmlspecialchars($producto['nombre']); ?></h1>
                <div class="producto-codigo">
                    <i class='bx bx-barcode'></i>
                    Código: <?php echo htmlspecialchars($producto['codigo']); ?>
                </div>

                <!-- Precio -->
                <div class="producto-precio">
                    <div class="precio-container">
                        <?php if ($descuento_porcentaje > 0): ?>
                            <span class="precio-anterior">$<?php echo number_format($precio_original, 2); ?></span>
                            <span class="precio-actual">$<?php echo number_format($producto['precio_venta'], 2); ?> <small>USD</small></span>
                            <span class="descuento-badge">-<?php echo $descuento_porcentaje; ?>%</span>
                        <?php else: ?>
                            <span class="precio-actual">$<?php echo number_format($producto['precio_venta'], 2); ?> <small>USD</small></span>
                        <?php endif; ?>
                    </div>
                    <div class="precio-bs">
                        <strong>Bs. <?php echo number_format($producto['precio_venta'] * $tasa_bs, 2); ?></strong>
                    </div>
                </div>

                <!-- Stock -->
                <div class="producto-stock">
                    <?php if ($producto['stock_actual'] <= 0): ?>
                        <span class="stock-agotado">
                            <i class='bx bx-x-circle'></i>
                            Producto agotado
                        </span>
                    <?php elseif ($producto['stock_actual'] <= $producto['stock_minimo']): ?>
                        <span class="stock-bajo">
                            <i class='bx bx-error-circle'></i>
                            ¡Últimas <?php echo $producto['stock_actual']; ?> unidades!
                        </span>
                    <?php else: ?>
                        <span class="stock-disponible">
                            <i class='bx bx-check-circle'></i>
                            En stock (<?php echo $producto['stock_actual']; ?> disponibles)
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($producto['stock_actual'] > 0): ?>
                    <!-- Selector de cantidad -->
                    <div class="cantidad-selector">
                        <span class="cantidad-label">Cantidad:</span>
                        <div class="cantidad-control">
                            <button type="button" class="cantidad-btn" onclick="decrementarCantidad()" <?php echo $en_carrito >= $producto['stock_actual'] ? 'disabled' : ''; ?>>-</button>
                            <input type="number" id="cantidad" class="cantidad-input" value="1" min="1" max="<?php echo $producto['stock_actual']; ?>" readonly>
                            <button type="button" class="cantidad-btn" onclick="incrementarCantidad()" <?php echo $en_carrito >= $producto['stock_actual'] ? 'disabled' : ''; ?>>+</button>
                        </div>
                        <span class="stock-disponible-text"><?php echo $producto['stock_actual']; ?> disponibles</span>
                    </div>

                    <!-- Botones de acción -->
                    <div class="producto-acciones">
                        <button class="btn-add-cart" onclick="agregarAlCarrito()" id="btnAgregar">
                            <i class='bx bx-cart-add'></i>
                            Agregar al carrito
                        </button>
                    </div>
                <?php else: ?>
                    <div class="producto-acciones">
                        <button class="btn-add-cart" disabled>
                            <i class='bx bx-x'></i>
                            Producto agotado
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Meta información -->
                <div class="producto-meta">
                    <div class="meta-item">
                        <i class='bx bx-package'></i>
                        <span><strong>Marca:</strong> <?php echo htmlspecialchars($producto['fabricante'] ?? 'VetNexo'); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class='bx bx-store'></i>
                        <span><strong>Vendido por:</strong> VetNexo - Tienda oficial</span>
                    </div>
                    <div class="meta-item">
                        <i class='bx bx-truck'></i>
                        <span><strong>Retiro en tienda:</strong> VetNexo, Your Clinic Location</span>
                    </div>
                    <div class="meta-item">
                        <i class='bx bx-credit-card'></i>
                        <span><strong>Pagos:</strong> Transferencia, Pago Móvil, Efectivo</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs de información -->
        <div class="producto-tabs">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="openTab(event, 'descripcion')">Descripción</button>
                <button class="tab-btn" onclick="openTab(event, 'especificaciones')">Especificaciones</button>
            </div>

            <div class="tab-content">
                <!-- Descripción -->
                <div id="descripcion" class="tab-pane active">
                    <div class="descripcion-content">
                        <?php if (!empty($producto['descripcion'])): ?>
                            <?php echo nl2br(htmlspecialchars($producto['descripcion'])); ?>
                        <?php else: ?>
                            <p>No hay descripción disponible para este producto.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Especificaciones -->
                <div id="especificaciones" class="tab-pane">
                    <div class="especificaciones-grid">
                        <div class="especificacion-item">
                            <span class="espec-label">Código</span>
                            <span class="espec-value"><?php echo htmlspecialchars($producto['codigo']); ?></span>
                        </div>
                        <div class="especificacion-item">
                            <span class="espec-label">Categoría</span>
                            <span class="espec-value"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'General'); ?></span>
                        </div>
                        <?php if (!empty($producto['principio_activo'])): ?>
                        <div class="especificacion-item">
                            <span class="espec-label">Principio activo</span>
                            <span class="espec-value"><?php echo htmlspecialchars($producto['principio_activo']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($producto['fabricante'])): ?>
                        <div class="especificacion-item">
                            <span class="espec-label">Fabricante</span>
                            <span class="espec-value"><?php echo htmlspecialchars($producto['fabricante']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($producto['proveedor_nombre'])): ?>
                        <div class="especificacion-item">
                            <span class="espec-label">Proveedor</span>
                            <span class="espec-value"><?php echo htmlspecialchars($producto['proveedor_nombre']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="especificacion-item">
                            <span class="espec-label">Stock actual</span>
                            <span class="espec-value"><?php echo $producto['stock_actual']; ?> unidades</span>
                        </div>
                        <div class="especificacion-item">
                            <span class="espec-label">Stock mínimo</span>
                            <span class="espec-value"><?php echo $producto['stock_minimo']; ?> unidades</span>
                        </div>
                        <div class="especificacion-item">
                            <span class="espec-label">IVA</span>
                            <span class="espec-value"><?php echo $producto['aplica_iva'] ? $producto['porcentaje_iva'] . '%' : 'No aplica'; ?></span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Productos relacionados -->
        <?php if (!empty($productos_relacionados)): ?>
        <div class="related-products">
            <h2 class="section-title">
                <i class='bx bx-purchase-tag'></i>
                Productos relacionados
            </h2>
            <div class="products-grid">
                <?php foreach ($productos_relacionados as $producto_rel): ?>
                    <div class="product-card">
                        <a href="producto.php?id=<?php echo $producto_rel['id']; ?>" class="product-image">
                            <?php if (!empty($producto_rel['imagen'])): ?>
                                <img src="../uploads/productos/<?php echo $producto_rel['imagen']; ?>" alt="<?php echo htmlspecialchars($producto_rel['nombre']); ?>">
                            <?php else: ?>
                                <i class='bx bx-package'></i>
                            <?php endif; ?>
                        </a>
                        <div class="product-info">
                            <div class="product-category"><?php echo htmlspecialchars($producto_rel['categoria_nombre'] ?? 'General'); ?></div>
                            <h3 class="product-title"><?php echo htmlspecialchars($producto_rel['nombre']); ?></h3>
                            <div class="product-price">
                                $<?php echo number_format($producto_rel['precio_venta'], 2); ?>
                                <div class="price-bs">Bs. <?php echo number_format($producto_rel['precio_venta'] * $tasa_bs, 2); ?></div>
                            </div>
                            <button class="btn-add" onclick="agregarAlCarritoSimple(<?php echo $producto_rel['id']; ?>)">
                                <i class='bx bx-cart-add'></i>
                                Agregar
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
        let cantidadActual = 1;
        const stockMaximo = <?php echo $producto['stock_actual']; ?>;
        const productoId = <?php echo $producto['id']; ?>;
        const enCarrito = <?php echo $en_carrito; ?>;

        function incrementarCantidad() {
            if (cantidadActual < stockMaximo - enCarrito) {
                cantidadActual++;
                document.getElementById('cantidad').value = cantidadActual;
            }
        }

        function decrementarCantidad() {
            if (cantidadActual > 1) {
                cantidadActual--;
                document.getElementById('cantidad').value = cantidadActual;
            }
        }

        function agregarAlCarrito() {
            const cantidad = cantidadActual;
            const btn = document.getElementById('btnAgregar');
            
            btn.innerHTML = '<span class="loading"></span> Agregando...';
            btn.disabled = true;

            fetch('../api/agregar_carrito.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'producto_id=' + productoId + '&cantidad=' + cantidad
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
                    
                    mostrarNotificacion('✅ Producto agregado al carrito');
                    
                    // Actualizar botón
                    btn.innerHTML = '<i class="bx bx-check"></i> Agregado';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="bx bx-cart-add"></i> Agregar al carrito';
                        btn.disabled = false;
                    }, 2000);
                } else {
                    mostrarNotificacion('❌ Error: ' + data.message, 'error');
                    btn.innerHTML = '<i class="bx bx-cart-add"></i> Agregar al carrito';
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al agregar el producto', 'error');
                btn.innerHTML = '<i class="bx bx-cart-add"></i> Agregar al carrito';
                btn.disabled = false;
            });
        }

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
                    location.reload();
                } else {
                    mostrarNotificacion('❌ Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al agregar el producto', 'error');
            });
        }

        function openTab(event, tabName) {
            // Ocultar todos los tabs
            const tabPanes = document.getElementsByClassName('tab-pane');
            for (let i = 0; i < tabPanes.length; i++) {
                tabPanes[i].classList.remove('active');
            }
            
            // Desactivar todos los botones
            const tabBtns = document.getElementsByClassName('tab-btn');
            for (let i = 0; i < tabBtns.length; i++) {
                tabBtns[i].classList.remove('active');
            }
            
            // Mostrar el tab seleccionado
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
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

        // Cambiar imagen principal al hacer clic en thumbnail
        function cambiarImagen(src) {
            document.getElementById('mainImg').src = src;
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
        `;
        document.head.appendChild(style);
    </script>
<?php echo bcv_badge_html(); ?>
</body>
</html>