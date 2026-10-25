<?php
// admin/verificar_pago.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';

// Verificar si el usuario es admin o veterinarian
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'veterinarian'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$conn = $database->conectar();

$usuario_id = $_SESSION['user_id'];
$pago_id = $_GET['id'] ?? 0;

if (!$pago_id) {
    header('Location: verificar_pagos.php');
    exit();
}

// Obtener datos del pago
$stmt = $conn->prepare("
    SELECT p.*, pe.numero_pedido, pe.total as total_pedido, 
           pe.direccion_envio, pe.telefono_contacto, pe.notas as notas_pedido,
           c.id as cliente_id, c.nombre as cliente_nombre, c.email, c.telefono, c.documento_identidad,
           u.nombre as verificador_nombre
    FROM pagos p
    JOIN pedidos pe ON p.pedido_id = pe.id
    JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN usuarios u ON p.verificado_por = u.id
    WHERE p.id = ?
");
$stmt->execute([$pago_id]);
$pago = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pago) {
    header('Location: verificar_pagos.php');
    exit();
}

// Obtener detalles del pedido
$stmt_detalles = $conn->prepare("
    SELECT pd.*, pr.nombre as producto_nombre, pr.codigo
    FROM pedido_detalles pd
    JOIN productos pr ON pd.producto_id = pr.id
    WHERE pd.pedido_id = ?
");
$stmt_detalles->execute([$pago['pedido_id']]);
$detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

// Obtener historial de logs
$stmt_logs = $conn->prepare("
    SELECT l.*, u.nombre as usuario_nombre
    FROM logs_verificacion_pagos l
    LEFT JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.pedido_id = ?
    ORDER BY l.created_at DESC
");
$stmt_logs->execute([$pago['pedido_id']]);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

// Tasa de cambio
require_once __DIR__.'/config/bcv.php';
$tasa_bs = (float)(bcv_get_rate()['rate']??0);

// Procesar verificación
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 try {require_once __DIR__.'/config/pedidos.php';av_verify_payment($conn,(int)$pago_id,(int)$usuario_id,$_POST['accion']??'',trim($_POST['motivo_rechazo']??''));header('Location: verificar_pago.php?id='.(int)$pago_id);exit;}
 catch(Throwable $e){error_log('[pago] '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo procesar el pago.':$e->getMessage();}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Verificar Pago #<?php echo $pago['numero_pedido']; ?> - Admin</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Mismos estilos que verificar_pagos.php más algunos adicionales */
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
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar (mismo estilo) */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 1rem;
            border: 3px solid white;
        }

        .sidebar-header h3 {
            font-size: 1.2rem;
            margin-bottom: 0.3rem;
        }

        .sidebar-header p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .sidebar-menu {
            padding: 1.5rem 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--accent);
        }

        .menu-item.active {
            background: rgba(255,255,255,0.15);
            border-left-color: var(--accent);
        }

        .menu-item i {
            font-size: 1.3rem;
            width: 24px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .content-header h1 {
            font-size: 2rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .content-header h1 i {
            color: var(--accent);
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 2px solid var(--primary-light);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
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

        .alert-error {
            background: var(--danger);
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

        /* Grid */
        .verificacion-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 968px) {
            .verificacion-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Badges */
        .badge-pendiente {
            background: var(--warning);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-verificado {
            background: var(--success);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-rechazado {
            background: var(--danger);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .info-item {
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray);
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .info-value.large {
            font-size: 1.3rem;
        }

        /* Comprobante */
        .comprobante-container {
            text-align: center;
            margin-bottom: 1rem;
        }

        .comprobante-img {
            max-width: 100%;
            max-height: 400px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .comprobante-img:hover {
            transform: scale(1.02);
        }

        .comprobante-pdf {
            padding: 2rem;
            background: var(--gray-light);
            border-radius: var(--radius);
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .comprobante-pdf i {
            font-size: 4rem;
            color: var(--danger);
        }

        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 2rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
        }

        .btn-download:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Tabla productos */
        .productos-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        .productos-table th {
            text-align: left;
            padding: 0.8rem;
            background: var(--gray-light);
            color: var(--text-dark);
            font-size: 0.9rem;
            border-bottom: 2px solid var(--gray);
        }

        .productos-table td {
            padding: 0.8rem;
            border-bottom: 1px solid var(--gray);
        }

        .productos-table tr:last-child td {
            border-bottom: none;
        }

        .total-row {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 1.1rem;
        }

        /* Actions */
        .action-buttons {
            display: flex;
            gap: 1rem;
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
            flex: 1;
            justify-content: center;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #2E7D32);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #C62828);
            color: white;
            box-shadow: 0 4px 15px rgba(244, 67, 54, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(244, 67, 54, 0.4);
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

        .reject-form {
            background: var(--danger);
            color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-top: 1rem;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .reject-form textarea {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid white;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            margin: 1rem 0;
            background: rgba(255,255,255,0.9);
        }

        .reject-form textarea:focus {
            outline: none;
            border-color: var(--warning);
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

        /* Logs */
        .logs-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .log-item {
            padding: 0.8rem;
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .log-item:last-child {
            border-bottom: none;
        }

        .log-time {
            font-size: 0.8rem;
            color: var(--text-lighter);
            min-width: 80px;
        }

        .log-action {
            background: var(--primary-soft);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .log-user {
            font-weight: 500;
            color: var(--primary);
        }

        .log-desc {
            color: var(--text-light);
            font-size: 0.9rem;
            flex: 1;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 2rem 0 1rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="includes/img/logo.svg" alt="VetNexo">
            <h3><?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Admin'); ?></h3>
            <p><?php echo $_SESSION['rol'] === 'admin' ? 'Administrador' : 'Veterinario'; ?></p>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class='bx bx-home'></i>
                Dashboard
            </a>
            <a href="verificar_pagos.php" class="menu-item active">
                <i class='bx bx-check-shield'></i>
                Verificar Pagos
            </a>
            <a href="tienda/pedidos.php" class="menu-item">
                <i class='bx bx-package'></i>
                Pedidos
            </a>
            <a href="listadeclientes.php" class="menu-item">
                <i class='bx bx-user'></i>
                Clientes
            </a>
            <a href="logout.php" class="menu-item">
                <i class='bx bx-log-out'></i>
                Cerrar Sesión
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-header">
            <h1>
                <i class='bx bx-check-shield'></i>
                Verificar Pago - Pedido #<?php echo $pago['numero_pedido']; ?>
            </h1>
            <div class="user-badge">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['nombre'] ?? 'A', 0, 1)); ?>
                </div>
                <span><?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Admin'); ?></span>
            </div>
        </div>

        <!-- Alertas -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle'></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <!-- Grid Principal -->
        <div class="verificacion-grid">
            <!-- Columna Izquierda - Info del Pago -->
            <div>
                <!-- Estado del Pago -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-info-circle'></i>
                            Estado del Pago
                        </h2>
                        <?php if ($pago['estado'] === 'pendiente'): ?>
                            <span class="badge-pendiente">
                                <i class='bx bx-time'></i>
                                Pendiente de verificación
                            </span>
                        <?php elseif ($pago['estado'] === 'verificado'): ?>
                            <span class="badge-verificado">
                                <i class='bx bx-check'></i>
                                Verificado
                            </span>
                        <?php elseif ($pago['estado'] === 'rechazado'): ?>
                            <span class="badge-rechazado">
                                <i class='bx bx-x'></i>
                                Rechazado
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Método de pago</div>
                                <div class="info-value">
                                    <?php if ($pago['metodo_pago'] === 'transferencia'): ?>
                                        Transferencia Bancaria
                                    <?php else: ?>
                                        Pago Móvil
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Fecha de pago</div>
                                <div class="info-value"><?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Referencia</div>
                                <div class="info-value"><strong><?php echo $pago['referencia']; ?></strong></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Monto pagado</div>
                                <div class="info-value large">$<?php echo number_format($pago['monto'], 2); ?></div>
                                <div class="info-value" style="font-size: 0.9rem;">Bs. <?php echo number_format($pago['monto'] * $tasa_bs, 2); ?></div>
                            </div>
                            
                            <?php if ($pago['metodo_pago'] === 'transferencia' && !empty($pago['banco_origen'])): ?>
                                <div class="info-item">
                                    <div class="info-label">Banco de origen</div>
                                    <div class="info-value"><?php echo $pago['banco_origen']; ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($pago['metodo_pago'] === 'pago_movil' && !empty($pago['telefono_origen'])): ?>
                                <div class="info-item">
                                    <div class="info-label">Teléfono origen</div>
                                    <div class="info-value"><?php echo $pago['telefono_origen']; ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($pago['notas'])): ?>
                            <div style="margin-top: 1rem; padding: 1rem; background: var(--accent-soft); border-radius: var(--radius);">
                                <strong>Notas del cliente:</strong>
                                <p style="margin-top: 0.5rem; color: var(--text-light);"><?php echo nl2br(htmlspecialchars($pago['notas'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Comprobante -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-file'></i>
                            Comprobante de Pago
                        </h2>
                        <?php if (!empty($pago['comprobante_path'])): ?>
                            <a href="../<?php echo $pago['comprobante_path']; ?>" download class="btn-download">
                                <i class='bx bx-download'></i>
                                Descargar
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pago['comprobante_path'])): ?>
                            <?php 
                            $extension = strtolower(pathinfo($pago['comprobante_path'], PATHINFO_EXTENSION));
                            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])): 
                            ?>
                                <div class="comprobante-container">
                                    <img src="../<?php echo $pago['comprobante_path']; ?>" 
                                         alt="Comprobante" 
                                         class="comprobante-img"
                                         onclick="window.open('../<?php echo $pago['comprobante_path']; ?>', '_blank')">
                                </div>
                            <?php elseif ($extension === 'pdf'): ?>
                                <div class="comprobante-pdf">
                                    <i class='bx bxs-file-pdf'></i>
                                    <a href="../<?php echo $pago['comprobante_path']; ?>" target="_blank" class="btn-download">
                                        <i class='bx bx-show'></i>
                                        Ver PDF
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state" style="text-align: center; padding: 2rem;">
                                <i class='bx bx-error-circle' style="font-size: 3rem; color: var(--danger);"></i>
                                <p style="margin-top: 1rem;">No se ha subido ningún comprobante</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Información del Cliente -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-user'></i>
                            Datos del Cliente
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Nombre</div>
                                <div class="info-value"><?php echo htmlspecialchars($pago['cliente_nombre']); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo $pago['email']; ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Teléfono</div>
                                <div class="info-value"><?php echo $pago['telefono']; ?></div>
                            </div>
                            
                            <?php if (!empty($pago['documento_identidad'])): ?>
                                <div class="info-item">
                                    <div class="info-label">Documento</div>
                                    <div class="info-value"><?php echo $pago['documento_identidad']; ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="info-item" style="grid-column: span 2;">
                                <div class="info-label">Dirección de retiro</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($pago['direccion_envio'])); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Teléfono contacto</div>
                                <div class="info-value"><?php echo $pago['telefono_contacto']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha - Acciones y Logs -->
            <div>
                <!-- Acciones -->
                <?php if ($pago['estado'] === 'pendiente'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h2>
                                <i class='bx bx-check-circle'></i>
                                Acciones
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="action-buttons">
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('¿Estás seguro de verificar este pago? Se reducirá el stock automáticamente.')"><?php echo av_csrf_field(); ?>
                                    <input type="hidden" name="accion" value="verificar">
                                    <button type="submit" class="btn btn-success">
                                        <i class='bx bx-check'></i>
                                        Verificar Pago
                                    </button>
                                </form>
                                
                                <button type="button" class="btn btn-danger" onclick="mostrarFormRechazo()">
                                    <i class='bx bx-x'></i>
                                    Rechazar
                                </button>
                            </div>

                            <!-- Formulario de rechazo -->
                            <div id="rejectForm" class="reject-form">
                                <form method="POST"><?php echo av_csrf_field(); ?>
                                    <input type="hidden" name="accion" value="rechazar">
                                    <h3 style="margin-bottom: 1rem;">Motivo del rechazo:</h3>
                                    <textarea name="motivo_rechazo" rows="4" required 
                                              placeholder="Indica el motivo por el cual se rechaza este pago (ej: comprobante ilegible, monto incorrecto, referencia no coincide, etc.)"></textarea>
                                    <div style="display: flex; gap: 1rem;">
                                        <button type="submit" class="btn btn-danger" style="flex: 1;">
                                            <i class='bx bx-x'></i>
                                            Confirmar Rechazo
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="ocultarFormRechazo()">
                                            Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Resumen del Pedido -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-package'></i>
                            Productos del Pedido
                        </h2>
                        <span style="background: var(--primary-soft); padding: 0.3rem 1rem; border-radius: 50px;">
                            Total: $<?php echo number_format($pago['total_pedido'], 2); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <table class="productos-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Cant</th>
                                    <th>Precio</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detalles as $detalle): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($detalle['producto_nombre']); ?></strong>
                                            <br>
                                            <small style="color: var(--text-lighter);">Cód: <?php echo $detalle['codigo']; ?></small>
                                        </td>
                                        <td><?php echo $detalle['cantidad']; ?></td>
                                        <td>$<?php echo number_format($detalle['precio_unitario'], 2); ?></td>
                                        <td>$<?php echo number_format($detalle['subtotal'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Historial de Verificaciones -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class='bx bx-history'></i>
                            Historial
                        </h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($logs)): ?>
                            <div class="empty-state" style="text-align: center; padding: 2rem;">
                                <i class='bx bx-history' style="font-size: 3rem; color: var(--gray);"></i>
                                <p style="margin-top: 1rem;">No hay registros de actividad</p>
                            </div>
                        <?php else: ?>
                            <div class="logs-list">
                                <?php foreach ($logs as $log): ?>
                                    <div class="log-item">
                                        <span class="log-time"><?php echo date('H:i', strtotime($log['created_at'])); ?></span>
                                        <span class="log-action">
                                            <?php if ($log['accion'] === 'pago_verificado'): ?>
                                                <span style="color: var(--success);">✓</span>
                                            <?php elseif ($log['accion'] === 'pago_rechazado'): ?>
                                                <span style="color: var(--danger);">✗</span>
                                            <?php else: ?>
                                                <span style="color: var(--info);">↻</span>
                                            <?php endif; ?>
                                            <?php echo $log['accion']; ?>
                                        </span>
                                        <span class="log-user"><?php echo $log['usuario_nombre'] ?? 'Sistema'; ?></span>
                                        <div class="log-desc"><?php echo $log['observaciones']; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botón Volver -->
        <div style="margin-top: 2rem;">
            <a href="verificar_pagos.php" class="btn btn-secondary">
                <i class='bx bx-arrow-back'></i>
                Volver a la lista de pagos
            </a>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> VetNexo Demo</p>
        </div>
    </div>

    <script>
        function mostrarFormRechazo() {
            document.getElementById('rejectForm').style.display = 'block';
        }

        function ocultarFormRechazo() {
            document.getElementById('rejectForm').style.display = 'none';
        }

        // Confirmación antes de verificar
        document.querySelector('form[onsubmit]')?.addEventListener('submit', function(e) {
            if (!confirm('¿Verificar este pago? Se reducirá el stock automáticamente.')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>