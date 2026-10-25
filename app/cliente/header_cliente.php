<?php
// cliente/header_cliente.php - Encabezado reutilizable del panel cliente.
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();
autovet_require_login('../login.php');
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Cliente';
?>
<header class="cliente-header">
    <a href="dashboard.php" class="logo">VetNexo</a>
    <nav>
        <a href="dashboard.php">Inicio</a>
        <a href="mis_mascotas.php">Mis mascotas</a>
        <a href="mis_citas.php">Mis citas</a>
        <a href="historial_compras.php">Pedidos</a>
        <a href="perfil.php"><?php echo e($usuario_nombre); ?></a>
        <a href="../logout.php">Salir</a>
    </nav>
</header>
