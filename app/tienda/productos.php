<?php
// tienda/productos.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once '../config/bcv.php';

// Verificar si el usuario está logueado (opcional)
$usuario_logueado = isset($_SESSION['user_id']);
$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Invitado';

$database = new Database();
$conn = $database->conectar();

// Obtener parámetros de filtro
$categoria_id = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$orden = isset($_GET['orden']) ? $_GET['orden'] : 'nombre_asc';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$pagina=max(1,$pagina);
$por_pagina = 12;

// Construir consulta base
$sql = "SELECT p.*, c.nombre as categoria_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        WHERE p.activo = 1";

$params = [];

if(!empty($_GET['destacados'])) $sql.=' AND p.destacado=1';
if(!empty($_GET['ofertas'])) $sql.=' AND p.en_oferta=1';
// Aplicar filtro por categoría
if ($categoria_id > 0) {
    $sql .= " AND p.categoria_id = ?";
    $params[] = $categoria_id;
}

// Aplicar filtro por búsqueda
if (!empty($busqueda)) {
    $sql .= " AND (p.nombre LIKE ? OR p.descripcion LIKE ? OR p.codigo LIKE ?)";
    $busqueda_param = "%$busqueda%";
    $params[] = $busqueda_param;
    $params[] = $busqueda_param;
    $params[] = $busqueda_param;
}

// Aplicar ordenamiento
switch ($orden) {
    case 'nombre_asc':
        $sql .= " ORDER BY p.nombre ASC";
        break;
    case 'nombre_desc':
        $sql .= " ORDER BY p.nombre DESC";
        break;
    case 'precio_asc':
        $sql .= " ORDER BY p.precio_venta ASC";
        break;
    case 'precio_desc':
        $sql .= " ORDER BY p.precio_venta DESC";
        break;
    case 'nuevos':
        $sql .= " ORDER BY p.fecha_creacion DESC";
        break;
    default:
        $sql .= " ORDER BY p.nombre ASC";
}

// Contar total de productos para paginación
$stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM (".$sql.") AS productos_filtrados");
$stmt_count->execute($params);
$total_productos = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_productos / $por_pagina);

// Aplicar paginación
$offset = ($pagina - 1) * $por_pagina;
$sql .= " LIMIT $por_pagina OFFSET $offset";

// Ejecutar consulta principal
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener todas las categorías para el filtro
$stmt_categorias = $conn->query("SELECT * FROM categorias_productos WHERE activo = 1 ORDER BY nombre");
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Obtener productos destacados para sidebar
$stmt_destacados = $conn->prepare("
    SELECT p.*, c.nombre as categoria_nombre 
    FROM productos p 
    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
    WHERE p.activo = 1 AND p.destacado = 1 
    ORDER BY p.id DESC 
    LIMIT 3
");
$stmt_destacados->execute();
$productos_destacados = $stmt_destacados->fetchAll(PDO::FETCH_ASSOC);

// Contar items en el carrito
$carrito_count = 0;
if (isset($_SESSION['carrito']) && is_array($_SESSION['carrito'])) {
    $carrito_count = array_sum(array_column($_SESSION['carrito'], 'cantidad'));
}

// Tasa de cambio
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Mismos estilos que en index.php */
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

        /* Header - mismos estilos que index.php */
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

        .product-count {
            background: var(--primary-soft);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            color: var(--primary-dark);
            font-weight: 500;
        }

        /* Filters */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
        }

        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .filter-select, .filter-input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .filter-btn {
            padding: 0.8rem 2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 120px;
            justify-content: center;
        }

        .filter-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .filter-btn.reset {
            background: var(--gray);
            color: var(--text-dark);
        }

        .filter-btn.reset:hover {
            background: var(--text-light);
            color: white;
        }

        /* Products Grid Layout */
        .products-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
        }

        @media (max-width: 968px) {
            .products-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Sidebar */
        .sidebar-filters {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--gray);
            height: fit-content;
            position: sticky;
            top: 100px;
        }

        .sidebar-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .filter-block {
            margin-bottom: 1.5rem;
        }

        .filter-block-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .category-list {
            list-style: none;
        }

        .category-item {
            margin-bottom: 0.5rem;
        }

        .category-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            color: var(--text-light);
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
        }

        .category-link:hover {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .category-link.active {
            background: var(--primary);
            color: white;
        }

        .category-count {
            background: var(--gray-light);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .category-link.active .category-count {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .price-range {
            margin-top: 1rem;
        }

        .price-inputs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .price-input {
            flex: 1;
            padding: 0.5rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
        }

        /* Products Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-5px);
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

        .product-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, var(--gray-light), var(--white));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 3rem;
            border-bottom: 1px solid var(--gray);
            overflow: hidden;
            position: relative;
            cursor: pointer;
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

        .quick-view {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(46, 125, 50, 0.9);
            color: white;
            text-align: center;
            padding: 0.5rem;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
        }

        .product-card:hover .quick-view {
            transform: translateY(0);
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

        .product-title a {
            color: inherit;
            text-decoration: none;
        }

        .product-title a:hover {
            color: var(--primary);
        }

        .product-price {
            margin-bottom: 1rem;
            background: var(--primary-soft);
            padding: 0.8rem;
            border-radius: var(--radius);
            border: 1px solid var(--primary-light);
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

        .price-bs {
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .price-bs strong {
            color: var(--text-dark);
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
            text-decoration: none;
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 3rem;
            flex-wrap: wrap;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--white);
            border: 1px solid var(--gray);
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .page-link:hover {
            background: var(--primary-soft);
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .page-link.next-prev {
            width: auto;
            padding: 0 1rem;
            border-radius: 50px;
        }

        /* Featured Products Sidebar */
        .featured-products {
            margin-top: 2rem;
        }

        .featured-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .featured-item {
            display: flex;
            gap: 1rem;
            padding: 0.8rem;
            border-bottom: 1px solid var(--gray-light);
            transition: background 0.3s ease;
        }

        .featured-item:hover {
            background: var(--primary-soft);
        }

        .featured-image {
            width: 60px;
            height: 60px;
            background: var(--gray-light);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            font-size: 1.5rem;
            overflow: hidden;
        }

        .featured-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .featured-info h4 {
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .featured-info h4 a {
            color: var(--text-dark);
            text-decoration: none;
        }

        .featured-info h4 a:hover {
            color: var(--primary);
        }

        .featured-price {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray);
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 2rem;
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
            
            .filters-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-btn {
                width: 100%;
            }
            
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .page-link.next-prev {
                width: auto;
                padding: 0 1rem;
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
                    <a href="productos.php" class="active"><i class='bx bx-package'></i> Productos</a>
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
            <li>Productos</li>
        </ul>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">
                <i class='bx bx-package'></i>
                Todos los Productos
            </h1>
            <span class="product-count"><?php echo $total_productos; ?> producto(s)</span>
        </div>

        <!-- Filtros rápidos (visible en móvil) -->
        <div class="filters-section">
            <form method="GET" action="productos.php" class="filters-form">
                <div class="filter-group">
                    <label class="filter-label">Buscar</label>
                    <input type="text" name="buscar" class="filter-input" placeholder="Nombre, código..." value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Categoría</label>
                    <select name="categoria" class="filter-select">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoria_id == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Ordenar por</label>
                    <select name="orden" class="filter-select">
                        <option value="nombre_asc" <?php echo $orden == 'nombre_asc' ? 'selected' : ''; ?>>Nombre A-Z</option>
                        <option value="nombre_desc" <?php echo $orden == 'nombre_desc' ? 'selected' : ''; ?>>Nombre Z-A</option>
                        <option value="precio_asc" <?php echo $orden == 'precio_asc' ? 'selected' : ''; ?>>Precio: menor a mayor</option>
                        <option value="precio_desc" <?php echo $orden == 'precio_desc' ? 'selected' : ''; ?>>Precio: mayor a menor</option>
                        <option value="nuevos" <?php echo $orden == 'nuevos' ? 'selected' : ''; ?>>Más nuevos</option>
                    </select>
                </div>
                
                <button type="submit" class="filter-btn">
                    <i class='bx bx-filter-alt'></i>
                    Filtrar
                </button>
                
                <a href="productos.php" class="filter-btn reset">
                    <i class='bx bx-reset'></i>
                    Limpiar
                </a>
            </form>
        </div>

        <!-- Products Layout -->
        <div class="products-layout">
            <!-- Sidebar Filters -->
            <aside class="sidebar-filters">
                <h3 class="sidebar-title">
                    <i class='bx bx-filter-alt'></i>
                    Filtros
                </h3>
                
                <div class="filter-block">
                    <h4 class="filter-block-title">Categorías</h4>
                    <ul class="category-list">
                        <li class="category-item">
                            <a href="productos.php" class="category-link <?php echo $categoria_id == 0 ? 'active' : ''; ?>">
                                <span>Todos los productos</span>
                                <span class="category-count"><?php echo $total_productos; ?></span>
                            </a>
                        </li>
                        <?php foreach ($categorias as $cat): ?>
                            <?php
                            // Contar productos por categoría
                            $stmt_count_cat = $conn->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id = ? AND activo = 1");
                            $stmt_count_cat->execute([$cat['id']]);
                            $count_cat = $stmt_count_cat->fetchColumn();
                            ?>
                            <li class="category-item">
                                <a href="productos.php?categoria=<?php echo $cat['id']; ?>" class="category-link <?php echo $categoria_id == $cat['id'] ? 'active' : ''; ?>">
                                    <span><?php echo htmlspecialchars($cat['nombre']); ?></span>
                                    <span class="category-count"><?php echo $count_cat; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <!-- Productos Destacados en Sidebar -->
                <?php if (!empty($productos_destacados)): ?>
                <div class="featured-products">
                    <h4 class="featured-title">✨ Productos Destacados</h4>
                    <?php foreach ($productos_destacados as $destacado): ?>
                        <div class="featured-item">
                            <div class="featured-image">
                                <?php if (!empty($destacado['imagen'])): ?>
                                    <img src="../uploads/productos/<?php echo $destacado['imagen']; ?>" alt="<?php echo htmlspecialchars($destacado['nombre']); ?>">
                                <?php else: ?>
                                    <i class='bx bx-package'></i>
                                <?php endif; ?>
                            </div>
                            <div class="featured-info">
                                <h4><a href="producto.php?id=<?php echo $destacado['id']; ?>"><?php echo htmlspecialchars($destacado['nombre']); ?></a></h4>
                                <div class="featured-price">$<?php echo number_format($destacado['precio_venta'], 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </aside>

            <!-- Products Grid -->
            <div>
                <?php if (empty($productos)): ?>
                    <div class="empty-state">
                        <i class='bx bx-package'></i>
                        <h3>No se encontraron productos</h3>
                        <p>Intenta con otros filtros o categorías</p>
                        <a href="productos.php" class="btn-shop">Ver todos los productos</a>
                    </div>
                <?php else: ?>
                    <div class="products-grid">
                        <?php foreach ($productos as $producto): ?>
                            <?php
                            $descuento = 0;
                            $precio_original = $producto['precio_venta'];
                            if ($producto['precio_compra'] > 0 && $producto['precio_venta'] < $producto['precio_compra'] * 1.2) {
                                $precio_original = $producto['precio_compra'] * 1.5;
                                $descuento = round((($precio_original - $producto['precio_venta']) / $precio_original) * 100);
                            }
                            ?>
                            <div class="product-card">
                                <?php if ($descuento > 0): ?>
                                    <div class="product-badge oferta">-<?php echo $descuento; ?>%</div>
                                <?php elseif ($producto['destacado']): ?>
                                    <div class="product-badge destacado">Destacado</div>
                                <?php endif; ?>
                                
                                <div class="product-image" onclick="window.location.href='producto.php?id=<?php echo $producto['id']; ?>'">
                                    <?php if (!empty($producto['imagen'])): ?>
                                        <img src="../uploads/productos/<?php echo $producto['imagen']; ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                    <?php else: ?>
                                        <i class='bx bx-package'></i>
                                    <?php endif; ?>
                                    <button class="quick-view" onclick="event.stopPropagation(); getProducto(<?php echo $producto['id']; ?>)">
                                        <i class='bx bx-show'></i> Vista rápida
                                    </button>
                                </div>
                                
                                <div class="product-info">
                                    <div class="product-category"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'General'); ?></div>
                                    <h3 class="product-title">
                                        <a href="producto.php?id=<?php echo $producto['id']; ?>">
                                            <?php echo htmlspecialchars($producto['nombre']); ?>
                                        </a>
                                    </h3>
                                    
                                    <div class="product-price">
                                        <div class="price-usd">
                                            $<?php echo number_format($producto['precio_venta'], 2); ?>
                                            <small>USD</small>
                                            <?php if ($descuento > 0): ?>
                                                <span class="old-price">$<?php echo number_format($precio_original, 2); ?></span>
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
                                            <span class="stock-low"><i class='bx bx-error-circle'></i> ¡Últimas <?php echo $producto['stock_actual']; ?>!</span>
                                        <?php else: ?>
                                            <span><i class='bx bx-check-circle' style="color: var(--success);"></i> En stock</span>
                                        <?php endif; ?>
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
                                        <a href="producto.php?id=<?php echo $producto['id']; ?>" class="btn-view" title="Ver detalles">
                                            <i class='bx bx-show'></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Paginación -->
                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>" class="page-link next-prev">
                                    <i class='bx bx-chevron-left'></i> Anterior
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <?php if ($i == $pagina): ?>
                                    <span class="page-link active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>" class="page-link"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>" class="page-link next-prev">
                                    Siguiente <i class='bx bx-chevron-right'></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal para vista rápida -->
    <div id="modalProducto" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="cerrarModal()">&times;</span>
            <div id="modalContent"></div>
        </div>
    </div>

    <!-- Loader -->
    <div id="loader" class="loader" style="display: none;">
        <div class="spinner"></div>
    </div>

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
        let modalCantidad = 1;
        let productoActual = null;

        function getProducto(productoId) {
            mostrarLoading(true);
            
            fetch('../api/get_producto.php?id=' + productoId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        productoActual = data.producto;
                        mostrarProductoModal(data.producto);
                    } else {
                        mostrarNotificacion('❌ ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    mostrarNotificacion('❌ Error al cargar el producto', 'error');
                })
                .finally(() => {
                    mostrarLoading(false);
                });
        }

        function mostrarProductoModal(producto) {
            const modalContent = document.getElementById('modalContent');
            
            let precioHtml = `
                <div class="precio-actual">$${producto.precio.usd}</div>
                <div class="precio-bs">Bs. ${producto.precio.bs}</div>
            `;
            
            if (producto.precio_original) {
                precioHtml += `<div class="precio-anterior">$${producto.precio_original}</div>`;
            }
            
            let stockHtml = '';
            if (producto.stock.estado === 'agotado') {
                stockHtml = `<span class="stock-agotado"><i class='bx bx-x-circle'></i> ${producto.stock.mensaje}</span>`;
            } else if (producto.stock.estado === 'bajo') {
                stockHtml = `<span class="stock-bajo"><i class='bx bx-error-circle'></i> ${producto.stock.mensaje}</span>`;
            } else {
                stockHtml = `<span class="stock-disponible"><i class='bx bx-check-circle'></i> ${producto.stock.mensaje}</span>`;
            }
            
            let botonHtml = '';
            if (producto.stock.estado !== 'agotado') {
                botonHtml = `
                    <div class="cantidad-selector">
                        <span class="cantidad-label">Cantidad:</span>
                        <div class="cantidad-control">
                            <button type="button" class="cantidad-btn" onclick="decrementarModal()" ${producto.en_carrito >= producto.stock.actual ? 'disabled' : ''}>-</button>
                            <input type="number" id="modalCantidad" class="cantidad-input" value="1" min="1" max="${producto.stock.actual - producto.en_carrito}" readonly>
                            <button type="button" class="cantidad-btn" onclick="incrementarModal(${producto.stock.actual - producto.en_carrito})" ${producto.en_carrito >= producto.stock.actual ? 'disabled' : ''}>+</button>
                        </div>
                    </div>
                    <button class="btn-add-cart" onclick="agregarAlCarritoModal(${producto.id})" id="modalBtnAgregar">
                        <i class='bx bx-cart-add'></i>
                        Agregar al carrito
                    </button>
                `;
                
                if (producto.en_carrito > 0) {
                    botonHtml += `<p class="en-carrito-info"><i class='bx bx-info-circle'></i> Ya tienes ${producto.en_carrito} en tu carrito</p>`;
                }
            } else {
                botonHtml = `<button class="btn-add-cart" disabled><i class='bx bx-x'></i> Producto agotado</button>`;
            }
            
            modalContent.innerHTML = `
                <div class="producto-detalle-modal">
                    <div class="producto-grid-modal">
                        <div class="producto-imagen-modal">
                            <img src="${producto.imagen_principal || '../includes/img/no-image.png'}" alt="${producto.nombre}" class="imagen-principal-modal">
                        </div>
                        
                        <div class="producto-info-modal">
                            <span class="producto-categoria">${producto.categoria.nombre}</span>
                            <h2>${producto.nombre}</h2>
                            <span class="producto-codigo">Código: ${producto.codigo}</span>
                            
                            <div class="producto-precio-modal">
                                ${precioHtml}
                            </div>
                            
                            <div class="producto-stock-modal">
                                ${stockHtml}
                            </div>
                            
                            ${botonHtml}
                        </div>
                    </div>
                    
                    <div class="producto-descripcion-modal">
                        <h3>Descripción</h3>
                        <p>${producto.descripcion || 'Sin descripción disponible'}</p>
                    </div>
                </div>
            `;
            
            document.getElementById('modalProducto').style.display = 'block';
        }

        function incrementarModal(maxStock) {
            if (modalCantidad < maxStock) {
                modalCantidad++;
                document.getElementById('modalCantidad').value = modalCantidad;
            }
        }

        function decrementarModal() {
            if (modalCantidad > 1) {
                modalCantidad--;
                document.getElementById('modalCantidad').value = modalCantidad;
            }
        }

        function agregarAlCarritoModal(productoId) {
            const btn = document.getElementById('modalBtnAgregar');
            btn.innerHTML = '<span class="loading"></span> Agregando...';
            btn.disabled = true;
            
            fetch('../api/agregar_carrito.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'producto_id=' + productoId + '&cantidad=' + modalCantidad
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    mostrarNotificacion('✅ Producto agregado al carrito');
                    cerrarModal();
                    actualizarBadge(data.total_items);
                } else {
                    mostrarNotificacion('❌ ' + data.message, 'error');
                    btn.innerHTML = '<i class="bx bx-cart-add"></i> Agregar al carrito';
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al agregar', 'error');
                btn.innerHTML = '<i class="bx bx-cart-add"></i> Agregar al carrito';
                btn.disabled = false;
            });
        }

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
                    actualizarBadge(data.total_items);
                } else {
                    mostrarNotificacion('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarNotificacion('❌ Error al agregar', 'error');
            });
        }

        function cerrarModal() {
            document.getElementById('modalProducto').style.display = 'none';
            modalCantidad = 1;
        }

        function mostrarLoading(mostrar) {
            document.getElementById('loader').style.display = mostrar ? 'flex' : 'none';
        }

        function actualizarBadge(totalItems) {
            const badge = document.querySelector('.cart-badge');
            if (badge) {
                if (totalItems > 0) {
                    badge.textContent = totalItems;
                } else {
                    badge.remove();
                }
            } else if (totalItems > 0) {
                const cartIcon = document.querySelector('.cart-icon');
                if (cartIcon) {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'cart-badge';
                    newBadge.textContent = totalItems;
                    cartIcon.appendChild(newBadge);
                }
            }
        }

        function mostrarNotificacion(mensaje, tipo = 'success') {
            const notificacion = document.createElement('div');
            notificacion.className = 'alert alert-' + tipo;
            notificacion.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                animation: slideIn 0.3s ease;
                background: ${tipo === 'success' ? '#4CAF50' : '#F44336'};
                color: white;
                padding: 1rem 2rem;
                border-radius: 50px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
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

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modalProducto');
            if (event.target == modal) {
                cerrarModal();
            }
        }
    </script>

    <style>
        /* Estilos para modal y loader */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: var(--white);
            margin: 2rem auto;
            padding: 2rem;
            border-radius: var(--radius-lg);
            max-width: 800px;
            width: 90%;
            position: relative;
            animation: slideDown 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .close {
            position: absolute;
            right: 1.5rem;
            top: 1rem;
            font-size: 2rem;
            font-weight: bold;
            color: var(--text-light);
            cursor: pointer;
            transition: color 0.3s ease;
            z-index: 10;
        }

        .close:hover {
            color: var(--danger);
        }

        .producto-grid-modal {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .producto-imagen-modal {
            width: 100%;
            height: 300px;
            background: var(--gray-light);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .producto-imagen-modal img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .producto-info-modal h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .producto-precio-modal {
            background: var(--primary-soft);
            padding: 1rem;
            border-radius: var(--radius);
            margin: 1rem 0;
        }

        .precio-actual {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .precio-anterior {
            color: var(--text-lighter);
            text-decoration: line-through;
            font-size: 1rem;
        }

        .cantidad-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1rem 0;
        }

        .cantidad-control {
            display: flex;
            align-items: center;
            border: 2px solid var(--primary-light);
            border-radius: 50px;
            overflow: hidden;
        }

        .cantidad-btn {
            width: 36px;
            height: 36px;
            background: var(--white);
            border: none;
            cursor: pointer;
            font-size: 1.2rem;
        }

        .cantidad-btn:hover:not(:disabled) {
            background: var(--primary-soft);
        }

        .cantidad-input {
            width: 50px;
            height: 36px;
            border: none;
            text-align: center;
            font-family: 'Poppins', sans-serif;
        }

        .en-carrito-info {
            color: var(--info);
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid var(--gray);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .producto-grid-modal {
                grid-template-columns: 1fr;
            }
        }
    </style>
<?php echo bcv_badge_html(); ?>
</body>
</html>