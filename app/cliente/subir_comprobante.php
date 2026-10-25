<?php
// cliente/subir_comprobante.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once __DIR__.'/../config/auth.php'; require_login();

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$conn = $database->conectar();

$cliente_id = $_SESSION['cliente_id'] ?? null;
$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Cliente';

// Si no hay cliente_id en sesión, intentar obtenerlo
if (!$cliente_id) {
    $stmt = $conn->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $cliente_id = $usuario['cliente_id'] ?? null;
    $_SESSION['cliente_id'] = $cliente_id;
}

$pedido_id = $_GET['pedido_id'] ?? 0;

if (!$pedido_id) {
    header('Location: historial_compras.php');
    exit();
}

// Verificar que el pedido pertenezca al cliente
$stmt = $conn->prepare("
    SELECT p.*, pg.id as pago_id, pg.estado as estado_pago,
           pg.comprobante_path, pg.referencia, c.nombre as cliente_nombre
    FROM pedidos p
    LEFT JOIN pagos pg ON p.pago_id = pg.id
    JOIN clientes c ON p.cliente_id = c.id
    WHERE p.id = ? AND p.cliente_id = ?
");
$stmt->execute([$pedido_id, $cliente_id]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    header('Location: historial_compras.php');
    exit();
}

// Verificar que el pedido esté en estado pendiente de pago
if ($pedido['estado'] !== 'pendiente_pago' && $pedido['estado'] !== 'pendiente_verificacion') {
    $_SESSION['error_message'] = "Este pedido no requiere subir comprobante.";
    header("Location: ver_pedido.php?id={$pedido_id}");
    exit();
}

// Verificar que el método de pago sea transferencia o pago móvil
if ($pedido['metodo_pago'] !== 'transferencia' && $pedido['metodo_pago'] !== 'pago_movil') {
    $_SESSION['error_message'] = "Este pedido no requiere comprobante de pago.";
    header("Location: ver_pedido.php?id={$pedido_id}");
    exit();
}

// Si ya hay un pago verificado, no permitir subir otro
if ($pedido['estado_pago'] === 'verificado') {
    $_SESSION['error_message'] = "El pago de este pedido ya fue verificado.";
    header("Location: ver_pedido.php?id={$pedido_id}");
    exit();
}

// Obtener datos bancarios
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

// Tasa de cambio
require_once __DIR__.'/../config/bcv.php';
$tasa_bs = (float)(bcv_get_rate()['rate']??0);

// Procesar formulario
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $metodo_pago = $_POST['metodo_pago'] ?? $pedido['metodo_pago'];
    $referencia = trim($_POST['referencia'] ?? '');
    $banco_origen = trim($_POST['banco_origen'] ?? '');
    $telefono_origen = trim($_POST['telefono_origen'] ?? '');
    $fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d');
    $monto = floatval($_POST['monto'] ?? $pedido['total']);
    $notas = trim($_POST['notas'] ?? '');
    
    // Validaciones
    if (empty($referencia)) {
        $errors[] = "El número de referencia es obligatorio";
    }
    
    if ($metodo_pago === 'transferencia' && empty($banco_origen)) {
        $errors[] = "El banco de origen es obligatorio para transferencias";
    }
    
    if ($metodo_pago === 'pago_movil' && empty($telefono_origen)) {
        $errors[] = "El teléfono de origen es obligatorio para pago móvil";
    }
    
    if (!is_finite($monto) || $monto<=0 || abs($monto-(float)$pedido['total'])>0.005) {
        $errors[] = "El monto debe coincidir con el total del pedido en USD";
    }
    
    if(!av_valid_date($fecha_pago)||$fecha_pago>date('Y-m-d'))$errors[]='Fecha de pago no válida.';
    if(!in_array($metodo_pago,['transferencia','pago_movil'],true))$errors[]='Método no válido.';
    // Procesar comprobante
    $comprobante_path = null;
    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/comprobantes/';
        
        // Crear directorio si no existe
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file = $_FILES['comprobante'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        
        // Validar tipo de archivo por extensión y MIME real
        if (!in_array($extension, $allowed, true)) {
            $errors[] = "Tipo de archivo no permitido. Solo: " . implode(', ', $allowed);
        }

        $allowed_mime = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf']
        ];
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        if ($mime && isset($allowed_mime[$extension]) && !in_array($mime, $allowed_mime[$extension], true)) {
            $errors[] = "El archivo no coincide con el tipo permitido.";
        }
        
        // Validar tamaño (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = "El archivo no debe superar los 5MB";
        }
        
        if (empty($errors)) {
            $filename = 'pago_' . $pedido_id . '_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $extension;
            $target_path = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                @chmod($target_path, 0644);
                $comprobante_path = 'uploads/comprobantes/' . $filename;
            } else {
                $errors[] = "Error al subir el archivo. Intenta de nuevo.";
            }
        }
    } else {
        // Si no hay archivo pero tampoco hay un comprobante previo, es error
        if (empty($pedido['comprobante_path'])) {
            $errors[] = "Debes subir el comprobante de pago";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            $lock=$conn->prepare('SELECT estado,pago_id FROM pedidos WHERE id=? AND cliente_id=? FOR UPDATE');$lock->execute([$pedido_id,$cliente_id]);$current=$lock->fetch();
            if(!$current||!in_array($current['estado'],['pendiente_pago','pendiente_verificacion'],true))throw new RuntimeException('El pedido ya fue procesado.');
            $pedido['pago_id']=$current['pago_id'];
            
            // Si ya existe un pago pendiente, actualizarlo
            if ($pedido['pago_id']) {
                $stmt = $conn->prepare("
                    UPDATE pagos SET
                        metodo_pago = ?,
                        referencia = ?,
                        banco_origen = ?,
                        telefono_origen = ?,
                        fecha_pago = ?,
                        monto = ?,
                        comprobante_path = COALESCE(?, comprobante_path),
                        notas = ?,
                        estado = 'pendiente',
                        fecha_creacion = NOW()
                    WHERE id = ? AND estado != 'verificado'
                ");
                
                $stmt->execute([
                    $metodo_pago, 
                    $referencia, 
                    $banco_origen, 
                    $telefono_origen,
                    $fecha_pago, 
                    $monto, 
                    $comprobante_path, 
                    $notas, 
                    $pedido['pago_id']
                ]);
                
                $pago_id = $pedido['pago_id'];
            } else {
                // Insertar nuevo pago
                $stmt = $conn->prepare("
                    INSERT INTO pagos (
                        pedido_id, cliente_id, metodo_pago, referencia, 
                        banco_origen, telefono_origen, fecha_pago, monto,
                        comprobante_path, notas, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
                ");
                
                $stmt->execute([
                    $pedido_id, 
                    $cliente_id, 
                    $metodo_pago, 
                    $referencia,
                    $banco_origen, 
                    $telefono_origen, 
                    $fecha_pago, 
                    $monto,
                    $comprobante_path, 
                    $notas
                ]);
                
                $pago_id = $conn->lastInsertId();
                
                // Actualizar pedido con el pago_id
                $stmt = $conn->prepare("
                    UPDATE pedidos 
                    SET pago_id = ?, estado = 'pendiente_verificacion' 
                    WHERE id = ?
                ");
                $stmt->execute([$pago_id, $pedido_id]);
            }
            
            // Actualizar estado del pedido a pendiente_verificacion
            $stmt = $conn->prepare("
                UPDATE pedidos 
                SET estado = 'pendiente_verificacion' 
                WHERE id = ? AND estado != 'pendiente_verificacion'
            ");
            $stmt->execute([$pedido_id]);
            
            // Registrar en logs
            $stmt_log = $conn->prepare("
                INSERT INTO logs_verificacion_pagos (pedido_id, usuario_id, accion, observaciones)
                VALUES (?, ?, 'cliente_subio_comprobante', ?)
            ");
            $stmt_log->execute([
                $pedido_id, 
                $usuario_id, 
                "Cliente subió comprobante. Ref: {$referencia}"
            ]);
            
            // Notificar al cliente - CORREGIDO: usando notificaciones_cliente
            $stmt_notif = $conn->prepare("
                INSERT INTO notificaciones_cliente (cliente_id, tipo, titulo, mensaje, fecha_creacion)
                VALUES (?, 'pedido_estado', ?, ?, NOW())
            ");
            
            $titulo = "💰 Comprobante recibido - Pedido #{$pedido['numero_pedido']}";
            $mensaje = "Hemos recibido tu comprobante de pago. Lo verificaremos en máximo 24 horas. Gracias por tu compra.";
            
            $stmt_notif->execute([$cliente_id, $titulo, $mensaje]);
            
            // Notificar a admins - CORREGIDO: usando notificaciones_cliente con un cliente_id especial o crear tabla notificaciones_admin
            // Como no existe tabla notificaciones, crearemos un registro para cada admin
            $stmt_admins = $conn->prepare("
                SELECT id FROM usuarios WHERE rol IN ('admin', 'veterinarian')
            ");
            $stmt_admins->execute();
            $admins = $stmt_admins->fetchAll(PDO::FETCH_ASSOC);
            
            $admin_titulo = "🔔 Nuevo pago pendiente de verificación";
            $admin_mensaje = "El pedido #{$pedido['numero_pedido']} de {$pedido['cliente_nombre']} ha subido un comprobante de pago por $" . number_format($monto, 2);
            
            foreach ($admins as $admin) {
                // Buscar cliente_id asociado al admin (si existe)
                $stmt_admin_cliente = $conn->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
                $stmt_admin_cliente->execute([$admin['id']]);
                $admin_cliente = $stmt_admin_cliente->fetch(PDO::FETCH_ASSOC);
                
                if ($admin_cliente && $admin_cliente['cliente_id']) {
                    // Si el admin tiene un cliente_id asociado, usar notificaciones_cliente
                    $stmt_admin_notif = $conn->prepare("
                        INSERT INTO notificaciones_cliente (cliente_id, tipo, titulo, mensaje, fecha_creacion)
                        VALUES (?, 'pedido_estado', ?, ?, NOW())
                    ");
                    $stmt_admin_notif->execute([$admin_cliente['cliente_id'], $admin_titulo, $admin_mensaje]);
                }
                // Si no, podríamos enviar un email, pero por ahora lo omitimos
            }
            
            $conn->commit();
            
            $success = true;
            $_SESSION['success_message'] = "Comprobante subido exitosamente. Tu pago será verificado en las próximas 24 horas.";
            
            header("Location: ver_pedido.php?id={$pedido_id}");
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Error al procesar el pago: " . $e->getMessage();
            error_log("Error en subir_comprobante: " . $e->getMessage());
        }
    }
}

// Si ya hay un comprobante previo, mostrar información
$tiene_comprobante_previo = !empty($pedido['comprobante_path']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📎 Subir Comprobante - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Mismos estilos que antes */
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

        /* Header Cliente */
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 2px solid var(--primary-light);
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

        /* Main Content */
        .main-content {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            margin-bottom: 2rem;
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
        }

        .breadcrumb li i {
            font-size: 1rem;
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

        .alert-info {
            background: var(--info);
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
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .page-title i {
            color: var(--accent);
        }

        .page-subtitle {
            color: var(--text-light);
            font-size: 1rem;
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

        /* Order Info */
        .order-info {
            background: var(--primary-soft);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            border: 1px solid var(--primary-light);
        }

        .info-item {
            text-align: center;
        }

        .info-label {
            color: var(--text-light);
            font-size: 0.8rem;
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

        /* Bank Info */
        .bank-info {
            background: var(--primary-soft);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 2px solid var(--primary-light);
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

        /* Upload Area */
        .upload-area {
            border: 2px dashed var(--primary);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            background: var(--primary-soft);
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
            position: relative;
        }

        .upload-area:hover {
            background: var(--primary-light);
            color: white;
        }

        .upload-area i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .upload-area p {
            color: var(--text-light);
        }

        .upload-area:hover i,
        .upload-area:hover p {
            color: white;
        }

        .file-info {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 0.5rem;
        }

        .preview-container {
            text-align: center;
            margin-top: 1rem;
            padding: 1rem;
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
        }

        .preview-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .preview-file {
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .preview-file i {
            font-size: 2rem;
            color: var(--danger);
        }

        /* Existing voucher */
        .existing-voucher {
            background: var(--info);
            color: white;
            padding: 1rem;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .existing-voucher a {
            background: white;
            color: var(--info);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s ease;
        }

        .existing-voucher a:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Form */
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

        select.form-control {
            cursor: pointer;
        }

        /* Buttons */
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

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
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

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 2rem 0;
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
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="../index.php" class="logo">
                <img src="../includes/img/logo.svg" alt="VetNexo" class="logo-img">
                <div class="logo-text">
                    <h1>VetNexo</h1>
                    <span>🐾 Mi Portal</span>
                </div>
            </a>

            <div class="nav-menu">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars(explode(' ', $usuario_nombre)[0]); ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <ul>
                <li><a href="dashboard.php">Mi Portal</a></li>
                <li><i class='bx bx-chevron-right'></i></li>
                <li><a href="historial_compras.php">Mis Pedidos</a></li>
                <li><i class='bx bx-chevron-right'></i></li>
                <li><a href="ver_pedido.php?id=<?php echo $pedido_id; ?>">Pedido #<?php echo $pedido['numero_pedido']; ?></a></li>
                <li><i class='bx bx-chevron-right'></i></li>
                <li>Subir Comprobante</li>
            </ul>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <?php echo $_SESSION['success_message'] ?? 'Comprobante subido exitosamente'; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle'></i>
                <ul style="margin-left: 1rem;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle'></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message']) && !$success): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class='bx bx-upload'></i>
                Subir Comprobante de Pago
            </h1>
            <p class="page-subtitle">Pedido #<?php echo $pedido['numero_pedido']; ?></p>
        </div>

        <!-- Order Info -->
        <div class="order-info">
            <div class="info-item">
                <div class="info-label">Total del pedido</div>
                <div class="info-value large">$<?php echo number_format($pedido['total'], 2); ?></div>
                <div class="info-value" style="font-size: 0.9rem;">Bs. <?php echo number_format($pedido['total'] * $tasa_bs, 2); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Fecha</div>
                <div class="info-value"><?php echo date('d/m/Y', strtotime($pedido['fecha_pedido'])); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Método de pago</div>
                <div class="info-value">
                    <?php if ($pedido['metodo_pago'] === 'transferencia'): ?>
                        Transferencia
                    <?php else: ?>
                        Pago Móvil
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Estado</div>
                <div class="info-value" style="color: var(--warning);">
                    <?php if ($pedido['estado_pago'] === 'verificado'): ?>
                        ✓ Pago Verificado
                    <?php elseif ($pedido['estado_pago'] === 'pendiente' && $tiene_comprobante_previo): ?>
                        ⏳ Pago en Verificación
                    <?php else: ?>
                        ⏳ Pendiente de Pago
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Datos Bancarios -->
        <div class="bank-info">
            <div class="bank-info-title">
                <i class='bx bx-bank'></i>
                Datos para realizar el pago
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

        <!-- Comprobante existente -->
        <?php if ($tiene_comprobante_previo): ?>
            <div class="existing-voucher">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class='bx bx-check-circle'></i>
                    <span>Ya has subido un comprobante anteriormente. Puedes reemplazarlo si es necesario.</span>
                </div>
                <a href="../<?php echo $pedido['comprobante_path']; ?>" target="_blank">
                    <i class='bx bx-file'></i>
                    Ver comprobante actual
                </a>
            </div>
        <?php endif; ?>

        <!-- Formulario -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i class='bx bx-receipt'></i>
                    Información del Pago
                </h2>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="formComprobante"><?php echo av_csrf_field(); ?>
                    <!-- Método de pago (solo informativo) -->
                    <div class="form-group">
                        <label class="form-label">Método de pago</label>
                        <input type="text" class="form-control" 
                               value="<?php echo $pedido['metodo_pago'] === 'transferencia' ? 'Transferencia Bancaria' : 'Pago Móvil'; ?>" 
                               readonly>
                        <input type="hidden" name="metodo_pago" value="<?php echo $pedido['metodo_pago']; ?>">
                    </div>

                    <!-- Campos específicos según método -->
                    <?php if ($pedido['metodo_pago'] === 'transferencia'): ?>
                        <div class="form-group">
                            <label class="form-label">Banco de origen *</label>
                            <input type="text" name="banco_origen" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['banco_origen'] ?? $pedido['banco_origen'] ?? ''); ?>" 
                                   placeholder="Ej: Banco Mercantil" required>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label class="form-label">Teléfono desde donde pagaste *</label>
                            <input type="text" name="telefono_origen" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['telefono_origen'] ?? $pedido['telefono_origen'] ?? ''); ?>" 
                                   placeholder="[Clinic phone not configured]" required>
                        </div>
                    <?php endif; ?>

                    <!-- Campos comunes -->
                    <div class="form-group">
                        <label class="form-label">Número de referencia / confirmación *</label>
                        <input type="text" name="referencia" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['referencia'] ?? $pedido['referencia'] ?? ''); ?>" 
                               placeholder="Ej: 1234567890" required>
                        <small style="color: var(--text-lighter);">El número que te dio el banco al realizar el pago</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fecha del pago *</label>
                        <input type="date" name="fecha_pago" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['fecha_pago'] ?? date('Y-m-d')); ?>" 
                               required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Monto pagado</label>
                        <input type="number" name="monto" class="form-control" step="0.01" 
                               value="<?php echo htmlspecialchars($_POST['monto'] ?? $pedido['total']); ?>" 
                               placeholder="0.00">
                        <small style="color: var(--text-lighter);">Si pagaste exactamente el monto del pedido, déjalo así</small>
                    </div>

                    <!-- Comprobante -->
                    <div class="form-group">
                        <label class="form-label">Comprobante de pago *</label>
                        <div class="upload-area" onclick="document.getElementById('comprobante').click()">
                            <i class='bx bx-cloud-upload'></i>
                            <h4>Haz clic para subir tu comprobante</h4>
                            <p>Formatos permitidos: JPG, PNG, PDF, GIF (Máx 5MB)</p>
                            <input type="file" id="comprobante" name="comprobante" 
                                   accept=".jpg,.jpeg,.png,.pdf,.gif" 
                                   style="display: none;" 
                                   onchange="previewFile(this)"
                                   <?php echo $tiene_comprobante_previo ? '' : 'required'; ?>>
                        </div>
                        
                        <div id="preview-container" class="preview-container" style="display: none;">
                            <div id="preview-content"></div>
                            <button type="button" class="btn btn-secondary" style="margin-top: 1rem;" onclick="resetFile()">
                                <i class='bx bx-refresh'></i>
                                Cambiar archivo
                            </button>
                        </div>
                        
                        <div class="file-info" id="file-info"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notas adicionales (opcional)</label>
                        <textarea name="notas" class="form-control" rows="3" 
                                  placeholder="Alguna información adicional sobre tu pago..."><?php echo htmlspecialchars($_POST['notas'] ?? ''); ?></textarea>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary" id="btnSubmit">
                            <i class='bx bx-check-shield'></i>
                            Subir Comprobante
                        </button>
                        <a href="ver_pedido.php?id=<?php echo $pedido_id; ?>" class="btn btn-secondary">
                            <i class='bx bx-arrow-back'></i>
                            Volver al pedido
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Instrucciones -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <i class='bx bx-info-circle'></i>
                    Instrucciones
                </h2>
            </div>
            <div class="card-body">
                <ol style="margin-left: 1.5rem; color: var(--text-light); line-height: 1.8;">
                    <li>Realiza el pago por el monto total de <strong>$<?php echo number_format($pedido['total'], 2); ?></strong> a la cuenta indicada.</li>
                    <li>Guarda el comprobante o número de referencia que te da el banco.</li>
                    <li>Completa todos los campos del formulario con la información de tu pago.</li>
                    <li>Sube una foto o captura del comprobante (formato JPG, PNG, PDF).</li>
                    <li>Haz clic en "Subir Comprobante" para enviar la información.</li>
                    <li>Nuestro equipo verificará el pago en máximo 24 horas.</li>
                    <li>Recibirás una notificación cuando el pago sea verificado.</li>
                    <li>Una vez verificado, puedes retirar tu pedido en tienda.</li>
                </ol>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
        </div>
    </footer>

    <script>
        function copiarTexto(texto) {
            navigator.clipboard.writeText(texto).then(function() {
                mostrarNotificacion('✅ Copiado al portapapeles');
            }, function() {
                mostrarNotificacion('❌ Error al copiar', 'error');
            });
        }

        function previewFile(input) {
            const previewContainer = document.getElementById('preview-container');
            const previewContent = document.getElementById('preview-content');
            const uploadArea = document.querySelector('.upload-area');
            const fileInfo = document.getElementById('file-info');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                
                fileInfo.textContent = `Archivo seleccionado: ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
                
                if (file.type.startsWith('image/')) {
                    reader.onload = function(e) {
                        previewContent.innerHTML = `<img src="${e.target.result}" class="preview-image" alt="Vista previa">`;
                    }
                    reader.readAsDataURL(file);
                } else if (file.type === 'application/pdf') {
                    previewContent.innerHTML = `
                        <div class="preview-file">
                            <i class='bx bxs-file-pdf'></i>
                            <span>${file.name}</span>
                        </div>
                    `;
                } else {
                    previewContent.innerHTML = `
                        <div class="preview-file">
                            <i class='bx bxs-file'></i>
                            <span>${file.name}</span>
                        </div>
                    `;
                }
                
                previewContainer.style.display = 'block';
                uploadArea.style.display = 'none';
            }
        }

        function resetFile() {
            const fileInput = document.getElementById('comprobante');
            const previewContainer = document.getElementById('preview-container');
            const uploadArea = document.querySelector('.upload-area');
            const fileInfo = document.getElementById('file-info');
            
            fileInput.value = '';
            previewContainer.style.display = 'none';
            uploadArea.style.display = 'block';
            fileInfo.textContent = '';
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
                max-width: 400px;
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

        // Validar formulario antes de enviar
        document.getElementById('formComprobante').addEventListener('submit', function(e) {
            const btn = document.getElementById('btnSubmit');
            btn.innerHTML = '<span class="loading"></span> Subiendo...';
            btn.disabled = true;
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
</body>
</html>