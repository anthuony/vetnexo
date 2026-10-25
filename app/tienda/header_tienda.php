<?php
// tienda/header_tienda.php - Encabezado reutilizable de la tienda.
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/database.php';
$total_items_header = 0;
if (!empty($_SESSION['carrito']) && is_array($_SESSION['carrito'])) {
    foreach ($_SESSION['carrito'] as $item) {
        $total_items_header += (int)($item['cantidad'] ?? 0);
    }
}
?>
<header class="tienda-header">
    <a href="index.php" class="logo">VetNexo</a>
    <nav>
        <a href="index.php">Inicio</a>
        <a href="productos.php">Productos</a>
        <a href="ofertas.php">Ofertas</a>
        <a href="carrito.php">Carrito (<?php echo $total_items_header; ?>)</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a href="../cliente/dashboard.php">Mi cuenta</a>
            <a href="../logout.php">Salir</a>
        <?php else: ?>
            <a href="../login.php">Ingresar</a>
        <?php endif; ?>
    </nav>
</header>
