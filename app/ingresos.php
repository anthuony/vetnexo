<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado (versión compatible con ambos formatos)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Definir variables de sesión de forma consistente
$user_id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? 'usuario';
$usuario_email = $_SESSION['email'] ?? $_SESSION['usuario_email'] ?? '';

// Incluir configuración de base de datos
include 'config/database.php';

// Inicializar variables
$db = null;
$error = '';
$success = '';

try {
    $db = getDBConnection();
    
    // Obtener información del usuario actual para el avatar
    $stmt = $db->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $usuario_nombre = $user_data['nombre'];
        $usuario_email = $user_data['email'];
        $usuario_rol = $user_data['rol'];
    }
    
    // Procesar registro manual de ingreso (para ingresos no facturados)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_ingreso_manual'])) {
        try {
            $fecha = $_POST['fecha'];
            $tipo_ingreso = $_POST['tipo_ingreso'];
            $descripcion = $_POST['descripcion'];
            $monto = $_POST['monto'];
            $categoria = $_POST['categoria'];
            
            $sql = "INSERT INTO ingresos_diarios (fecha, tipo_ingreso, descripcion, monto, categoria, usuario_id) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$fecha, $tipo_ingreso, $descripcion, $monto, $categoria, $user_id]);
            
            $_SESSION['success'] = "Ingreso manual registrado exitosamente";
            header('Location: ingresos.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al registrar ingreso: " . $e->getMessage();
            header('Location: ingresos.php');
            exit();
        }
    }
    
    // Obtener parámetros de fecha (últimos 30 días por defecto)
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
    
    // Validar fechas
    if ($fecha_inicio > $fecha_fin) {
        $fecha_inicio = $fecha_fin;
    }
    
    // 1. OBTENER INGRESOS MANUALES
    $sql_manuales = "SELECT descripcion, monto, categoria, fecha, 'Manual' as origen, 'manual' as tipo_origen 
                     FROM ingresos_diarios 
                     WHERE fecha BETWEEN ? AND ? 
                     ORDER BY fecha DESC";
    $stmt_manuales = $db->prepare($sql_manuales);
    $stmt_manuales->execute([$fecha_inicio, $fecha_fin]);
    $ingresos_manuales = $stmt_manuales->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. OBTENER INGRESOS DE FACTURAS (SERVICIOS)
    $sql_facturas_servicios = "
        SELECT 
            fd.descripcion,
            fd.subtotal as monto,
            CASE 
                WHEN s.categoria = 'grooming' THEN 'peluqueria'
                WHEN s.categoria = 'consultation' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'vaccination' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'surgery' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'emergency' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'laboratory' THEN 'laboratorio'
                WHEN s.categoria = 'other' THEN 'servicios_veterinarios'
                ELSE 'servicios_veterinarios'
            END as categoria,
            DATE(f.fecha_emision) as fecha,
            CONCAT('Factura ', f.numero_factura) as origen,
            'factura' as tipo_origen
        FROM factura_detalles fd
        LEFT JOIN servicios s ON fd.servicio_id = s.id
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) BETWEEN ? AND ?
        AND f.estado IN ('paid')
        AND fd.servicio_id IS NOT NULL
    ";
    
    // 3. OBTENER INGRESOS DE FACTURAS (PRODUCTOS)
    $sql_facturas_productos = "
        SELECT 
            fd.descripcion,
            fd.subtotal as monto,
            'tienda' as categoria,
            DATE(f.fecha_emision) as fecha,
            CONCAT('Factura ', f.numero_factura) as origen,
            'factura' as tipo_origen
        FROM factura_detalles fd
        LEFT JOIN productos p ON fd.producto_id = p.id
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) BETWEEN ? AND ?
        AND f.estado IN ('paid')
        AND fd.producto_id IS NOT NULL
    ";
    
    $stmt_facturas_servicios = $db->prepare($sql_facturas_servicios);
    $stmt_facturas_servicios->execute([$fecha_inicio, $fecha_fin]);
    $ingresos_facturas_servicios = $stmt_facturas_servicios->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_facturas_productos = $db->prepare($sql_facturas_productos);
    $stmt_facturas_productos->execute([$fecha_inicio, $fecha_fin]);
    $ingresos_facturas_productos = $stmt_facturas_productos->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. OBTENER INGRESOS DE CITAS COMPLETADAS
    $sql_citas_completadas = "
        SELECT 
            CONCAT('Cita #', c.id, ' - ', s.nombre) as descripcion,
            COALESCE(c.costo_total, s.precio) as monto,
            CASE 
                WHEN s.categoria = 'grooming' THEN 'peluqueria'
                WHEN s.categoria = 'consultation' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'vaccination' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'surgery' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'emergency' THEN 'servicios_veterinarios'
                WHEN s.categoria = 'laboratory' THEN 'laboratorio'
                WHEN s.categoria = 'other' THEN 'servicios_veterinarios'
                ELSE 'servicios_veterinarios'
            END as categoria,
            DATE(c.fecha_cita) as fecha,
            CONCAT('Cita ', c.id) as origen,
            'cita' as tipo_origen
        FROM citas c
        LEFT JOIN servicios s ON c.servicio_id = s.id
        WHERE DATE(c.fecha_cita) BETWEEN ? AND ?
        AND c.estado = 'completed'
        AND c.id NOT IN (
            SELECT DISTINCT fd.servicio_id 
            FROM factura_detalles fd 
            WHERE fd.servicio_id IS NOT NULL
        )
    ";
    
    $stmt_citas = $db->prepare($sql_citas_completadas);
    $stmt_citas->execute([$fecha_inicio, $fecha_fin]);
    $ingresos_citas = [];
    
    // Combinar todos los ingresos del período
    $ingresos_totales = array_merge(
        $ingresos_manuales, 
        $ingresos_facturas_servicios, 
        $ingresos_facturas_productos,
        $ingresos_citas
    );
    
    // Calcular totales del período por categoría
    $totales_periodo = [
        'peluqueria' => 0,
        'servicios_veterinarios' => 0,
        'tienda' => 0,
        'laboratorio' => 0,
        'ecografia' => 0,
        'fumigacion' => 0,
        'total' => 0
    ];
    
    foreach ($ingresos_totales as $ingreso) {
        if (isset($ingreso['categoria']) && isset($totales_periodo[$ingreso['categoria']])) {
            $totales_periodo[$ingreso['categoria']] += floatval($ingreso['monto']);
        }
        $totales_periodo['total'] += floatval($ingreso['monto']);
    }
    
    // Calcular ingresos del día actual
    $fecha_hoy = date('Y-m-d');
    
    // Ingresos de hoy desde facturas
    $sql_hoy_facturas = "
        SELECT SUM(fd.subtotal) as total
        FROM factura_detalles fd
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) = ? 
        AND f.estado IN ('paid')
    ";
    $stmt_hoy_f = $db->prepare($sql_hoy_facturas);
    $stmt_hoy_f->execute([$fecha_hoy]);
    $ingresos_hoy_facturas = $stmt_hoy_f->fetch(PDO::FETCH_ASSOC);
    
    // Ingresos de hoy desde citas
    $sql_hoy_citas = "
        SELECT SUM(COALESCE(c.costo_total, s.precio)) as total
        FROM citas c
        LEFT JOIN servicios s ON c.servicio_id = s.id
        WHERE DATE(c.fecha_cita) = ? 
        AND c.estado = 'completed'
    ";
    $stmt_hoy_c = $db->prepare($sql_hoy_citas);
    $stmt_hoy_c->execute([$fecha_hoy]);
    $ingresos_hoy_citas = $stmt_hoy_c->fetch(PDO::FETCH_ASSOC);
    
    // Ingresos de hoy manuales
    $sql_hoy_manual = "
        SELECT SUM(monto) as total
        FROM ingresos_diarios 
        WHERE fecha = ?
    ";
    $stmt_hoy_m = $db->prepare($sql_hoy_manual);
    $stmt_hoy_m->execute([$fecha_hoy]);
    $ingresos_hoy_manual = $stmt_hoy_m->fetch(PDO::FETCH_ASSOC);
    
    $ingresos_hoy = floatval($ingresos_hoy_facturas['total'] ?? 0) + 
                   0 + 
                   floatval($ingresos_hoy_manual['total'] ?? 0);
    
    // Ingresos de la semana actual
    $semana_actual = date('Y-m-d', strtotime('monday this week'));
    
    $sql_semana_facturas = "
        SELECT SUM(fd.subtotal) as total
        FROM factura_detalles fd
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) >= ? 
        AND f.estado IN ('paid')
    ";
    $stmt_semana_f = $db->prepare($sql_semana_facturas);
    $stmt_semana_f->execute([$semana_actual]);
    $ingresos_semana_facturas = $stmt_semana_f->fetch(PDO::FETCH_ASSOC);
    
    $sql_semana_citas = "
        SELECT SUM(COALESCE(c.costo_total, s.precio)) as total
        FROM citas c
        LEFT JOIN servicios s ON c.servicio_id = s.id
        WHERE DATE(c.fecha_cita) >= ? 
        AND c.estado = 'completed'
    ";
    $stmt_semana_c = $db->prepare($sql_semana_citas);
    $stmt_semana_c->execute([$semana_actual]);
    $ingresos_semana_citas = $stmt_semana_c->fetch(PDO::FETCH_ASSOC);
    
    $sql_semana_manual = "
        SELECT SUM(monto) as total
        FROM ingresos_diarios 
        WHERE fecha >= ?
    ";
    $stmt_semana_m = $db->prepare($sql_semana_manual);
    $stmt_semana_m->execute([$semana_actual]);
    $ingresos_semana_manual = $stmt_semana_m->fetch(PDO::FETCH_ASSOC);
    
    $ingresos_semana_total = floatval($ingresos_semana_facturas['total'] ?? 0) + 
                            0 + 
                            floatval($ingresos_semana_manual['total'] ?? 0);
    
    // Ingresos del mes actual
    $mes_actual = date('Y-m-01');
    
    $sql_mes_facturas = "
        SELECT SUM(fd.subtotal) as total
        FROM factura_detalles fd
        LEFT JOIN facturas f ON fd.factura_id = f.id
        WHERE DATE(f.fecha_emision) >= ? 
        AND f.estado IN ('paid')
    ";
    $stmt_mes_f = $db->prepare($sql_mes_facturas);
    $stmt_mes_f->execute([$mes_actual]);
    $ingresos_mes_facturas = $stmt_mes_f->fetch(PDO::FETCH_ASSOC);
    
    $sql_mes_citas = "
        SELECT SUM(COALESCE(c.costo_total, s.precio)) as total
        FROM citas c
        LEFT JOIN servicios s ON c.servicio_id = s.id
        WHERE DATE(c.fecha_cita) >= ? 
        AND c.estado = 'completed'
    ";
    $stmt_mes_c = $db->prepare($sql_mes_citas);
    $stmt_mes_c->execute([$mes_actual]);
    $ingresos_mes_citas = $stmt_mes_c->fetch(PDO::FETCH_ASSOC);
    
    $sql_mes_manual = "
        SELECT SUM(monto) as total
        FROM ingresos_diarios 
        WHERE fecha >= ?
    ";
    $stmt_mes_m = $db->prepare($sql_mes_manual);
    $stmt_mes_m->execute([$mes_actual]);
    $ingresos_mes_manual = $stmt_mes_m->fetch(PDO::FETCH_ASSOC);
    
    $ingresos_mes_total = floatval($ingresos_mes_facturas['total'] ?? 0) + 
                         0 + 
                         floatval($ingresos_mes_manual['total'] ?? 0);
    
    // Últimos ingresos registrados
    $sql_ultimos = "
        (SELECT 
            'factura' as tipo,
            f.fecha_emision as fecha,
            CONCAT('Factura #', f.numero_factura) as descripcion,
            f.total as monto,
            f.estado,
            f.fecha_emision as created_at
        FROM facturas f
        WHERE f.estado IN ('paid')
        ORDER BY f.fecha_emision DESC
        LIMIT 10)
        
        UNION ALL
        
        (SELECT 
            'manual' as tipo,
            fecha,
            descripcion,
            monto,
            'confirmed' as estado,
            created_at
        FROM ingresos_diarios 
        ORDER BY created_at DESC
        LIMIT 5)
        
        UNION ALL
        
        (SELECT 
            'cita' as tipo,
            fecha_cita as fecha,
            CONCAT('Cita #', id, ' - Servicio') as descripcion,
            COALESCE(costo_total, 0) as monto,
            estado,
            fecha_creacion as created_at
        FROM citas
        WHERE estado = 'completed'
        ORDER BY fecha_creacion DESC
        LIMIT 5)
        
        ORDER BY created_at DESC
        LIMIT 15
    ";
    $stmt_ultimos = $db->query($sql_ultimos);
    $ultimos_ingresos = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);
    
    // Resumen general
    $sql_resumen = "
        SELECT 
            (SELECT COUNT(DISTINCT f.id) 
             FROM facturas f 
             WHERE DATE(f.fecha_emision) BETWEEN ? AND ? 
             AND f.estado IN ('paid')) as total_facturas,
            
            (SELECT SUM(f.total) 
             FROM facturas f 
             WHERE DATE(f.fecha_emision) BETWEEN ? AND ? 
             AND f.estado IN ('paid')) as total_ingresos_facturas,
            
            (SELECT COUNT(DISTINCT f.cliente_id) 
             FROM facturas f 
             WHERE DATE(f.fecha_emision) BETWEEN ? AND ? 
             AND f.estado IN ('paid')) as clientes_unicos_facturas,
            
            (SELECT SUM(monto) 
             FROM ingresos_diarios 
             WHERE fecha BETWEEN ? AND ?) as total_ingresos_manuales,
            
            (SELECT COUNT(DISTINCT c.id) 
             FROM citas c 
             WHERE DATE(c.fecha_cita) BETWEEN ? AND ? 
             AND c.estado = 'completed') as total_citas_completadas
    ";
    $stmt_resumen = $db->prepare($sql_resumen);
    $stmt_resumen->execute([
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin
    ]);
    $resumen_general = $stmt_resumen->fetch(PDO::FETCH_ASSOC);
    
    $total_facturas = $resumen_general['total_facturas'] ?? 0;
    $total_ingresos = floatval($resumen_general['total_ingresos_facturas'] ?? 0) + 
                     floatval($resumen_general['total_ingresos_manuales'] ?? 0);
    $clientes_unicos = $resumen_general['clientes_unicos_facturas'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Error en ingresos.php: " . $e->getMessage());
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Función para obtener color del rol
function getRolColor($rol) {
    switch ($rol) {
        case 'admin':
            return '#F44336';
        case 'veterinarian':
            return '#2196F3';
        case 'reception':
            return '#FF9800';
        case 'assistant':
            return '#9C27B0';
        default:
            return '#4CAF50';
    }
}

// Función para obtener texto del rol
function getRolTexto($rol) {
    switch ($rol) {
        case 'admin':
            return 'Administrador';
        case 'veterinarian':
            return 'Veterinario';
        case 'reception':
            return 'Recepcionista';
        case 'assistant':
            return 'Asistente';
        default:
            return 'Usuario';
    }
}

// Función para obtener nombre de categoría
function getCategoriaNombre($categoria) {
    $categorias = [
        'peluqueria' => 'Peluquería',
        'servicios_veterinarios' => 'Servicios Veterinarios',
        'tienda' => 'Tienda',
        'laboratorio' => 'Laboratorio',
        'ecografia' => 'Ecografía',
        'fumigacion' => 'Fumigación'
    ];
    return $categorias[$categoria] ?? $categoria;
}

// Función para obtener badge según tipo de origen
function getTipoOrigenBadge($tipo) {
    switch ($tipo) {
        case 'factura':
            return 'badge-success';
        case 'manual':
            return 'badge-warning';
        case 'cita':
            return 'badge-info';
        default:
            return 'badge-secondary';
    }
}

// Función para obtener texto de tipo origen
function getTipoOrigenTexto($tipo) {
    switch ($tipo) {
        case 'factura':
            return 'Factura';
        case 'manual':
            return 'Manual';
        case 'cita':
            return 'Cita';
        default:
            return ucfirst($tipo);
    }
}

$page_title = "Contabilización de Ingresos - VetNexo";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
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
            --purple: #9C27B0;
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

        /* Layout Principal */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        /* Header */
        .dashboard-header {
            background: var(--white);
            padding: 1.5rem 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-section h1 {
            font-size: 1.8rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .welcome-section h1 i {
            color: var(--accent);
        }

        .welcome-section p {
            color: var(--text-light);
            font-size: 1rem;
        }

        .welcome-section .period-badge {
            display: inline-block;
            margin-top: 0.5rem;
            padding: 0.3rem 1rem;
            background: var(--primary-soft);
            color: var(--primary-dark);
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-soft);
            border-radius: 50px;
            border: 1px solid var(--primary-light);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.3rem;
            box-shadow: var(--shadow);
        }

        .user-info h3 {
            font-size: 1rem;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
        }

        .user-info span {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            background: var(--white);
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
        }

        /* Page Actions */
        .page-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            justify-content: flex-end;
        }

        .btn-primary, .btn-secondary, .btn-success, .btn-info {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #2E7D32);
            color: var(--white);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(33, 150, 243, 0.4);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid var(--primary-light);
        }

        .alert-error {
            background: #ffebee;
            color: var(--danger);
            border: 1px solid #ffcdd2;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .filters-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-content {
            padding: 1.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .filter-input {
            padding: 0.6rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            background: var(--white);
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        .quick-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .quick-filter-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray);
            background: var(--white);
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .quick-filter-btn:hover {
            background: var(--primary-soft);
            border-color: var(--primary);
        }

        .quick-filter-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .stat-subtext {
            font-size: 0.8rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.5rem;
        }

        /* Secciones */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .section-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Tablas */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 1px solid var(--gray);
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tr:hover td {
            background: var(--gray-light);
        }

        .data-table tfoot td {
            background: var(--primary-soft);
            font-weight: 600;
            border-top: 2px solid var(--primary-light);
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        .badge-secondary {
            background: var(--gray);
            color: var(--text-light);
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        /* Formulario */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-control {
            padding: 0.8rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Footer */
        .main-footer {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 1.5rem 0;
            margin-top: 2rem;
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .footer-content {
            text-align: center;
        }

        .footer-content p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Partículas */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .particle {
            position: absolute;
            background: rgba(46, 125, 50, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px) rotate(0deg);
            }
            50% {
                transform: translateY(-10px) rotate(180deg);
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .dashboard-header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
            }
            
            .page-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .quick-filters {
                justify-content: center;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Partículas de fondo -->
    <div class="particles">
        <div class="particle" style="top: 20%; left: 10%; width: 8px; height: 8px; animation-delay: 0s;"></div>
        <div class="particle" style="top: 60%; left: 80%; width: 12px; height: 12px; animation-delay: 1s;"></div>
        <div class="particle" style="top: 40%; left: 90%; width: 6px; height: 6px; animation-delay: 2s;"></div>
        <div class="particle" style="top: 80%; left: 20%; width: 10px; height: 10px; animation-delay: 3s;"></div>
        <div class="particle" style="top: 30%; left: 70%; width: 8px; height: 8px; animation-delay: 4s;"></div>
    </div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-dollar-circle'></i>
                        Contabilización de Ingresos
                    </h1>
                    <p>
                        <i class='bx bx-line-chart'></i>
                        Gestión de ingresos de facturas pagadas y registros manuales
                    </p>
                    <div class="period-badge">
                        <i class='bx bx-calendar'></i>
                        Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                    </div>
                </div>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <h3><?php echo htmlspecialchars($usuario_nombre); ?></h3>
                        <span>
                            <i class='bx bx-badge-check' style="color: <?php echo getRolColor($usuario_rol); ?>"></i>
                            <?php echo getRolTexto($usuario_rol); ?>
                        </span>
                    </div>
                </div>
            </header>

            <!-- Page Actions -->
            <div class="page-actions fade-in">
                <button class="btn-info btn-sm" onclick="generarReporte()">
                    <i class='bx bx-file'></i>
                    Reporte Detallado
                </button>
                <button class="btn-secondary btn-sm" onclick="exportarDatos()">
                    <i class='bx bx-download'></i>
                    Exportar Excel
                </button>
                <button class="btn-primary btn-sm" onclick="window.print()">
                    <i class='bx bx-printer'></i>
                    Imprimir
                </button>
            </div>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h3>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Período
                    </h3>
                </div>
                <div class="filters-content">
                    <form method="GET" action="" class="filter-form">
                        <div class="filter-group">
                            <label class="filter-label">Fecha Inicio</label>
                            <input type="date" class="filter-input" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Fecha Fin</label>
                            <input type="date" class="filter-input" name="fecha_fin" value="<?php echo $fecha_fin; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn-primary btn-sm">
                                <i class='bx bx-filter'></i>
                                Filtrar
                            </button>
                            <a href="ingresos.php" class="btn-secondary btn-sm">
                                <i class='bx bx-reset'></i>
                                Resetear
                            </a>
                        </div>
                    </form>
                    
                    <div class="quick-filters">
                        <button class="quick-filter-btn" onclick="setPeriod('today')">
                            <i class='bx bx-calendar-check'></i> Hoy
                        </button>
                        <button class="quick-filter-btn" onclick="setPeriod('week')">
                            <i class='bx bx-calendar-week'></i> Esta Semana
                        </button>
                        <button class="quick-filter-btn" onclick="setPeriod('month')">
                            <i class='bx bx-calendar'></i> Este Mes
                        </button>
                        <button class="quick-filter-btn" onclick="setPeriod('last30')">
                            <i class='bx bx-calendar'></i> Últimos 30 días
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mensajes de sesión -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success fade-in">
                    <i class='bx bx-check-circle'></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error fade-in">
                    <i class='bx bx-error-circle'></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Estadísticas Rápidas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar-check'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($ingresos_hoy, 2); ?></div>
                        <div class="stat-label">Ingresos Hoy</div>
                        <div class="stat-subtext">
                            <i class='bx bx-calendar'></i> <?php echo date('d/m/Y'); ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar-week'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($ingresos_semana_total, 2); ?></div>
                        <div class="stat-label">Esta Semana</div>
                        <div class="stat-subtext">
                            <i class='bx bx-calendar'></i> Semana actual
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($ingresos_mes_total, 2); ?></div>
                        <div class="stat-label">Este Mes</div>
                        <div class="stat-subtext">
                            <i class='bx bx-calendar'></i> <?php echo date('F Y'); ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($totales_periodo['total'], 2); ?></div>
                        <div class="stat-label">Total Período</div>
                        <div class="stat-subtext">
                            <i class='bx bx-receipt'></i> <?php echo count($ingresos_totales); ?> registros
                        </div>
                    </div>
                </div>
            </div>

            <!-- Distribución por Categoría -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-pie-chart-alt'></i>
                        Distribución por Categoría
                    </h3>
                </div>
                <div class="section-body">
                    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                        <?php foreach ($totales_periodo as $key => $value): ?>
                            <?php if ($key !== 'total'): ?>
                                <div class="stat-card" style="padding: 1rem;">
                                    <div class="stat-header">
                                        <div class="stat-icon" style="width: 40px; height: 40px; font-size: 1.2rem;">
                                            <?php
                                            $iconos = [
                                                'peluqueria' => 'bx-cut',
                                                'servicios_veterinarios' => 'bx-plus-medical',
                                                'tienda' => 'bx-store',
                                                'laboratorio' => 'bx-test-tube',
                                                'ecografia' => 'bx-line-chart',
                                                'fumigacion' => 'bx-spray-can'
                                            ];
                                            echo "<i class='bx {$iconos[$key]}'></i>";
                                            ?>
                                        </div>
                                        <span class="badge badge-primary" style="font-size: 0.7rem;">
                                            <?php echo $totales_periodo['total'] > 0 ? number_format(($value / $totales_periodo['total']) * 100, 1) : '0'; ?>%
                                        </span>
                                    </div>
                                    <div class="stat-number" style="font-size: 1.3rem;">$<?php echo number_format($value, 2); ?></div>
                                    <div class="stat-label" style="font-size: 0.8rem;"><?php echo getCategoriaNombre($key); ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Resumen General -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-stats'></i>
                        Resumen General del Período
                    </h3>
                </div>
                <div class="section-body">
                    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                        <div class="stat-card" style="padding: 1rem;">
                            <div class="stat-number" style="font-size: 1.8rem;"><?php echo $total_facturas; ?></div>
                            <div class="stat-label">Facturas Pagadas</div>
                        </div>
                        
                        <div class="stat-card" style="padding: 1rem;">
                            <div class="stat-number" style="font-size: 1.8rem;">$<?php echo number_format($total_ingresos, 2); ?></div>
                            <div class="stat-label">Ingresos Totales</div>
                        </div>
                        
                        <div class="stat-card" style="padding: 1rem;">
                            <div class="stat-number" style="font-size: 1.8rem;"><?php echo $clientes_unicos; ?></div>
                            <div class="stat-label">Clientes Únicos</div>
                        </div>
                        
                        <div class="stat-card" style="padding: 1rem;">
                            <div class="stat-number" style="font-size: 1.8rem;">
                                $<?php echo $total_facturas > 0 ? number_format($total_ingresos / $total_facturas, 2) : '0.00'; ?>
                            </div>
                            <div class="stat-label">Ticket Promedio</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formulario para Ingresos Manuales -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-plus-circle'></i>
                        Registrar Ingreso Manual
                    </h3>
                </div>
                <div class="section-body">
                    <form method="POST" action=""><?php echo av_csrf_field(); ?>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Fecha</label>
                                <input type="date" class="form-control" name="fecha" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Tipo de Ingreso</label>
                                <select class="form-control" name="tipo_ingreso" required>
                                    <option value="peluqueria">Peluquería</option>
                                    <option value="consulta">Consulta</option>
                                    <option value="vacunacion">Vacunación</option>
                                    <option value="producto">Producto</option>
                                    <option value="servicio">Servicio</option>
                                    <option value="otros">Otros</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Categoría</label>
                                <select class="form-control" name="categoria" required>
                                    <option value="peluqueria">Peluquería</option>
                                    <option value="servicios_veterinarios">Servicios Veterinarios</option>
                                    <option value="tienda">Tienda</option>
                                    <option value="laboratorio">Laboratorio</option>
                                    <option value="ecografia">Ecografía</option>
                                    <option value="fumigacion">Fumigación</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Monto ($)</label>
                                <input type="number" class="form-control" name="monto" step="0.01" min="0" required>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Descripción</label>
                                <input type="text" class="form-control" name="descripcion" placeholder="Ej: Corte de pelo - Mascota X" required>
                            </div>
                        </div>
                        
                        <button type="submit" name="registrar_ingreso_manual" class="btn-success btn-sm" style="margin-top: 1rem;">
                            <i class='bx bx-save'></i>
                            Registrar Ingreso Manual
                        </button>
                    </form>
                </div>
            </div>

            <!-- Ingresos del Período -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-check'></i>
                        Ingresos del Período (<?php echo count($ingresos_totales); ?> registros)
                    </h3>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn-secondary btn-sm" onclick="location.reload()">
                            <i class='bx bx-refresh'></i>
                            Actualizar
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($ingresos_totales)): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Origen</th>
                                    <th>Categoría</th>
                                    <th>Descripción</th>
                                    <th>Monto</th>
                                    <th>Tipo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                usort($ingresos_totales, function($a, $b) {
                                    return strtotime($b['fecha']) - strtotime($a['fecha']);
                                });
                                
                                foreach ($ingresos_totales as $ingreso): 
                                ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($ingreso['fecha'])); ?></td>
                                        <td>
                                            <span class="badge badge-success">
                                                <?php echo htmlspecialchars($ingreso['origen']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo getCategoriaNombre($ingreso['categoria'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($ingreso['descripcion']); ?></td>
                                        <td><strong>$<?php echo number_format($ingreso['monto'], 2); ?></strong></td>
                                        <td>
                                            <span class="badge <?php echo getTipoOrigenBadge($ingreso['tipo_origen'] ?? ''); ?>">
                                                <?php echo getTipoOrigenTexto($ingreso['tipo_origen'] ?? ''); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" style="text-align: right; font-weight: bold;">Total del Período:</td>
                                    <td colspan="2" style="font-weight: bold; font-size: 1.1rem;">$<?php echo number_format($totales_periodo['total'], 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-time'></i>
                        <h3>No hay ingresos en este período</h3>
                        <p>Los ingresos aparecerán automáticamente cuando se paguen facturas, registres ingresos manuales.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Últimos Ingresos -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-history'></i>
                        Últimos Ingresos Registrados
                    </h3>
                    <a href="facturas.php?estado=paid" class="view-all-btn">
                        Ver facturas pagadas <i class='bx bx-chevron-right'></i>
                    </a>
                </div>
                
                <?php if (!empty($ultimos_ingresos)): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Descripción</th>
                                    <th>Monto</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimos_ingresos as $ingreso): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($ingreso['created_at'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo $ingreso['tipo'] === 'factura' ? 'badge-success' : ($ingreso['tipo'] === 'manual' ? 'badge-warning' : 'badge-info'); ?>">
                                                <?php 
                                                if ($ingreso['tipo'] === 'factura') echo 'Factura';
                                                elseif ($ingreso['tipo'] === 'manual') echo 'Manual';
                                                elseif ($ingreso['tipo'] === 'cita') echo 'Cita';
                                                else echo $ingreso['tipo'];
                                                ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($ingreso['descripcion']); ?></td>
                                        <td><strong>$<?php echo number_format($ingreso['monto'], 2); ?></strong></td>
                                        <td>
                                            <span class="badge badge-success">
                                                <?php 
                                                if ($ingreso['estado'] === 'paid') echo 'Pagado';
                                                elseif ($ingreso['estado'] === 'completed') echo 'Completado';
                                                else echo $ingreso['estado'];
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-info-circle'></i>
                        <h3>No hay ingresos recientes</h3>
                        <p>Los ingresos se actualizan automáticamente cuando las facturas son pagadas.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-content">
                    <p>© <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
                </div>
            </footer>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Configuración de períodos
        function setPeriod(period) {
            const today = new Date();
            let fechaInicio, fechaFin;
            
            switch(period) {
                case 'today':
                    fechaInicio = today.toISOString().split('T')[0];
                    fechaFin = fechaInicio;
                    break;
                case 'week':
                    const firstDay = new Date(today.setDate(today.getDate() - today.getDay() + 1));
                    const lastDay = new Date(today.setDate(today.getDate() - today.getDay() + 7));
                    fechaInicio = firstDay.toISOString().split('T')[0];
                    fechaFin = lastDay.toISOString().split('T')[0];
                    break;
                case 'month':
                    fechaInicio = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    fechaFin = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
                case 'last30':
                    fechaInicio = new Date(today.setDate(today.getDate() - 30)).toISOString().split('T')[0];
                    fechaFin = new Date().toISOString().split('T')[0];
                    break;
            }
            
            window.location.href = `ingresos.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}`;
        }

        // Funciones de exportación
        function exportarDatos() {
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]').value;
            const fechaFin = document.querySelector('input[name="fecha_fin"]').value;
            window.location.href = `exportar_ingresos.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}`;
        }

        function generarReporte() {
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]').value;
            const fechaFin = document.querySelector('input[name="fecha_fin"]').value;
            window.open(`reporte_ingresos.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}`, '_blank');
        }

        // Validación de fechas
        document.addEventListener('DOMContentLoaded', function() {
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
            const fechaFin = document.querySelector('input[name="fecha_fin"]');
            
            if (fechaInicio && fechaFin) {
                fechaInicio.addEventListener('change', function() {
                    if (fechaFin.value && this.value > fechaFin.value) {
                        fechaFin.value = this.value;
                    }
                });
                
                fechaFin.addEventListener('change', function() {
                    if (fechaInicio.value && this.value < fechaInicio.value) {
                        alert('La fecha fin no puede ser anterior a la fecha inicio');
                        this.value = fechaInicio.value;
                    }
                });
            }

            // Animación de contadores
            animateStats();
            
            // Crear partículas
            createParticles();
        });

        function animateStats() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.-]+/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        if (text.includes('$')) {
                            stat.textContent = '$' + current.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        } else {
                            stat.textContent = Math.floor(current).toLocaleString('es-ES');
                        }
                    }, 30);
                }
            });
        }

        function createParticles() {
            const particlesContainer = document.querySelector('.particles');
            for (let i = 0; i < 10; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    animation-delay: ${Math.random() * 5}s;
                    opacity: ${Math.random() * 0.5 + 0.1};
                    background: ${Math.random() > 0.5 ? 'rgba(46, 125, 50, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        }
    </script>
</body>
</html>