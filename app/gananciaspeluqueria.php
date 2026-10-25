<?php
// gananciaspeluqueria.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';

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

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas'); // UTC-4

// Conexión centralizada para hosting
try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    die('Error de conexión a la base de datos. Revise config/env.php.');
}

// Porcentajes de ganancia
$porcentaje_peluquero = 53;
$porcentaje_clinica = 47;

// Inicializar variables
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$estadisticas = [];
$cortes_detallados = [];

// Procesar registro de hora de salida
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_salida'])) {
    $corte_id = $_POST['corte_id'];
    
    try {
        // Actualizar el campo updated_at en la tabla cortes_peluqueria
        $sql_update = "UPDATE cortes_peluqueria SET updated_at = NOW() WHERE id = ?";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([$corte_id]);
        
        // Mensaje de éxito
        $_SESSION['mensaje_exito'] = "Hora de salida registrada correctamente.";
        
        // Redirigir para evitar reenvío del formulario
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . $_SERVER['QUERY_STRING']);
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['mensaje_error'] = "Error al registrar la hora de salida: " . $e->getMessage();
    }
}

// Obtener estadísticas de ganancias
try {
    $sql = "
        SELECT 
            COUNT(*) as total_cortes,
            SUM(c.costo_total) as ingresos_totales,
            AVG(c.costo_total) as promedio_por_corte,
            MIN(c.costo_total) as corte_minimo,
            MAX(c.costo_total) as corte_maximo,
            COUNT(DISTINCT c.cliente_id) as clientes_unicos,
            COUNT(DISTINCT c.mascota_id) as mascotas_unicas
        FROM citas c
        INNER JOIN servicios s ON c.servicio_id = s.id
        WHERE s.categoria = 'grooming' 
        AND c.estado = 'completed'
        AND DATE(c.fecha_cita) BETWEEN ? AND ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fecha_inicio, $fecha_fin]);
    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular ganancias distribuidas
    if ($estadisticas['ingresos_totales']) {
        $estadisticas['ganancia_peluquero'] = $estadisticas['ingresos_totales'] * ($porcentaje_peluquero / 100);
        $estadisticas['ganancia_clinica'] = $estadisticas['ingresos_totales'] * ($porcentaje_clinica / 100);
    } else {
        $estadisticas['ganancia_peluquero'] = 0;
        $estadisticas['ganancia_clinica'] = 0;
    }
    
    // Obtener cortes detallados usando created_at como hora_entrada y updated_at como hora_salida
    $sql_detalles = "
        SELECT 
            cp.id as corte_id,
            c.id as cita_id,
            c.fecha_cita,
            c.hora_cita,
            c.costo_total,
            m.nombre as mascota_nombre,
            m.raza,
            cl.nombre as cliente_nombre,
            s.nombre as servicio_nombre,
            cp.estado_pelo,
            cp.tipo_corte,
            cp.productos_utilizados,
            cp.costo_adicional,
            cp.created_at as hora_entrada,
            cp.updated_at as hora_salida,
            cp.costo
        FROM citas c
        INNER JOIN mascotas m ON c.mascota_id = m.id
        INNER JOIN clientes cl ON c.cliente_id = cl.id
        INNER JOIN servicios s ON c.servicio_id = s.id
        LEFT JOIN cortes_peluqueria cp ON c.id = cp.cita_id
        WHERE s.categoria = 'grooming' 
        AND c.estado = 'completed'
        AND DATE(c.fecha_cita) BETWEEN ? AND ?
        ORDER BY c.fecha_cita DESC, c.hora_cita DESC
    ";
    
    $stmt_detalles = $pdo->prepare($sql_detalles);
    $stmt_detalles->execute([$fecha_inicio, $fecha_fin]);
    $cortes_detallados = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("[VetNexo][gananciaspeluqueria] " . $e->getMessage()); die("No se pudieron cargar las estadísticas.");
}

// Función helper para obtener color según rol
function getRolColor($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return '#F99B07'; // Naranja Vibrante para Admin
        case 'veterinario':
        case 'veterinarian':
            return '#0F6C33'; // Verde Bosque para Veterinario
        case 'asistente':
            return '#2261BD'; // Azul Real para Asistente
        default:
            return '#5A4B37'; // Marrón Chocolate para otros
    }
}

// Función helper para texto del rol
function getRolTexto($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return 'Administrador';
        case 'veterinario':
        case 'veterinarian':
            return 'Veterinario';
        case 'asistente':
            return 'Asistente';
        default:
            return ucfirst($rol);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganancias de Peluquería - VetNexo</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <!-- Incluir las bibliotecas para PDF -->
    <script src="assets/vendor/html2canvas.min.js"></script>
    <script src="assets/vendor/jspdf.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0F6C33;        /* Verde Bosque */
            --primary-dark: #0a4f25;    /* Verde Bosque Oscuro */
            --primary-light: #2e8b57;   /* Verde más claro */
            --primary-soft: #e8f5e9;    /* Verde muy suave */
            
            --accent: #F99B07;           /* Naranja Vibrante */
            --accent-light: #ffb84d;     /* Naranja claro */
            --accent-soft: #fff3e0;      /* Naranja suave */
            --accent-dark: #d88206;       /* Naranja oscuro */
            
            --emergency: #F91302;         /* Rojo Corazón */
            --emergency-dark: #c00f02;    /* Rojo oscuro */
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            
            --blanco: #FFFFFF;             /* Blanco Puro */
            
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --text-lighter: #888888;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --danger: #F91302;
            --warning: #FBEF16;
            --success: #0F6C33;
            --info: #2261BD;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow: 0 5px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
            --radius-sm: 8px;
            --radius: 16px;
            --radius-lg: 24px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--background);
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            overflow-x: hidden;
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

        /* Header Mejorado */
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

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Botón de Atrás */
        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            background: var(--primary-soft);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
        }

        /* Botones */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(15, 108, 51, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
            color: var(--white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--accent-dark), #d88206);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, var(--azul-light), #3a6ab8);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-salida {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.3s ease;
            min-width: 120px;
            justify-content: center;
        }

        .btn-salida:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(249, 155, 7, 0.3);
        }

        .btn-salida:disabled {
            background: var(--text-lighter);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Secciones de Contenido */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .filters-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .filters-body {
            padding: 1.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--white);
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.1);
        }

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .stat-card.peluquero::before {
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
        }

        .stat-card.clinica::before {
            background: linear-gradient(90deg, var(--success), var(--primary));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.peluquero .stat-icon {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-card.clinica .stat-icon {
            background: var(--primary-soft);
            color: var(--success);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.peluquero .stat-number {
            color: var(--accent-dark);
        }

        .stat-card.clinica .stat-number {
            color: var(--primary-dark);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-percentage {
            color: var(--text-lighter);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Distribution Card */
        .distribution-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .distribution-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .distribution-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .distribution-body {
            padding: 1.5rem;
        }

        .distribution-title {
            text-align: center;
            margin-bottom: 2rem;
        }

        .distribution-title h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .distribution-subtitle {
            color: var(--text-light);
            font-size: 1rem;
        }

        .distribution-bars {
            display: flex;
            height: 60px;
            border-radius: 30px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .bar-peluquero {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .bar-clinica {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .distribution-labels {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .distribution-label {
            text-align: center;
            flex: 1;
            padding: 1.5rem;
            background: var(--primary-soft);
            border-radius: var(--radius);
            border: 1px solid var(--primary-light);
        }

        .label-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .label-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .label-peluquero .label-amount {
            color: var(--accent-dark);
        }

        .label-percentage {
            font-size: 0.9rem;
            color: var(--text-light);
            font-weight: 600;
        }

        /* Table Card */
        .table-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .table-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .table-body {
            padding: 1.5rem;
        }

        .table-container {
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
            border-bottom: 2px solid var(--primary-light);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-light);
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .data-table tr:hover {
            background: var(--primary-soft);
        }

        .data-table tfoot td {
            background: var(--primary-soft);
            font-weight: 600;
            border-top: 2px solid var(--primary-light);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-corte {
            background: #f3e5f5;
            color: #7B1FA2;
        }

        .badge-baño {
            background: #e3f2fd;
            color: #1565C0;
        }

        .badge-estilizado {
            background: #fff3e0;
            color: #F99B07;
        }

        /* Hora Estado */
        .hora-estado {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
        }

        .hora-entrada {
            color: var(--primary);
            background-color: var(--primary-soft);
        }

        .hora-salida {
            color: var(--success);
            background-color: rgba(15, 108, 51, 0.1);
        }

        .hora-pendiente {
            color: var(--accent);
            background-color: rgba(249, 155, 7, 0.1);
        }

        /* Export Options */
        .export-options {
            display: flex;
            gap: 1rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: var(--success);
            color: #1e4620;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--emergency);
            color: #601e1e;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-success .alert-icon {
            color: var(--success);
        }

        .alert-error .alert-icon {
            color: var(--emergency);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-dark);
            font-size: 1.2rem;
        }

        .empty-state p {
            margin: 0 0 1.5rem 0;
            font-size: 0.9rem;
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

        /* Loading State */
        .loading {
            position: relative;
            opacity: 0.7;
            pointer-events: none;
        }

        .bx-loader-alt {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Partículas de fondo */
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
            background: rgba(15, 108, 51, 0.05);
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

        /* Estilos para impresión */
        @media print {
            .sidebar, .export-options, .filters-section, .main-footer, .back-btn, .btn-salida, .alert, .particles {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            
            body {
                background: white;
            }
            
            .stat-card, .distribution-card, .table-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
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
            
            .distribution-labels {
                flex-direction: column;
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
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn,
            .header-actions .back-btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .export-options {
                width: 100%;
                justify-content: center;
            }
            
            .export-options .btn-outline {
                flex: 1;
            }
            
            .table-container {
                font-size: 0.85rem;
            }
            
            .btn-salida {
                min-width: auto;
                padding: 0.3rem 0.6rem;
                font-size: 0.75rem;
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
    </div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class="bx bx-scissors"></i>
                        Ganancias de Peluquería
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Distribución de ganancias - VetNexo
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="citaspeluqueria.php" class="btn btn-info">
                        <i class='bx bx-list-ul'></i> Ver Citas
                    </a>
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
                </div>
            </header>

            <!-- Mensajes -->
            <?php if (isset($_SESSION['mensaje_exito'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $_SESSION['mensaje_exito']; unset($_SESSION['mensaje_exito']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['mensaje_error'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter-alt'></i>
                        Seleccionar Período
                    </h2>
                </div>
                <div class="filters-body">
                    <form method="GET" action="" class="filter-form">
                        <div class="form-group">
                            <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" 
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($fecha_inicio); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="fecha_fin" class="form-label">Fecha Fin</label>
                            <input type="date" id="fecha_fin" name="fecha_fin" 
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($fecha_fin); ?>" required>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-filter'></i>
                                Aplicar Filtros
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Estadísticas Principales -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-money'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ingresos Totales</div>
                        <div class="stat-percentage"><?php echo $estadisticas['total_cortes'] ?? 0; ?> cortes realizados</div>
                    </div>
                    
                    <div class="stat-card peluquero">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-user'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ganancia_peluquero'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ganancia del Peluquero</div>
                        <div class="stat-percentage"><?php echo $porcentaje_peluquero; ?>% del total</div>
                    </div>
                    
                    <div class="stat-card clinica">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-building'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['ganancia_clinica'] ?? 0, 2); ?></div>
                        <div class="stat-label">Ganancia de la Clínica</div>
                        <div class="stat-percentage"><?php echo $porcentaje_clinica; ?>% del total</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['promedio_por_corte'] ?? 0, 2); ?></div>
                        <div class="stat-label">Promedio por Corte</div>
                        <div class="stat-percentage"><?php echo $estadisticas['clientes_unicos'] ?? 0; ?> clientes únicos</div>
                    </div>
                </div>
            </div>

            <!-- Distribución de Ganancias -->
            <div class="distribution-card fade-in">
                <div class="distribution-header">
                    <h2>
                        <i class='bx bx-pie-chart-alt'></i>
                        Distribución de Ganancias
                    </h2>
                </div>
                <div class="distribution-body">
                    <div class="distribution-title">
                        <h3>Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></h3>
                    </div>
                    
                    <div class="distribution-bars">
                        <div class="bar-peluquero" style="width: <?php echo $porcentaje_peluquero; ?>%">
                            <?php echo $porcentaje_peluquero; ?>%
                        </div>
                        <div class="bar-clinica" style="width: <?php echo $porcentaje_clinica; ?>%">
                            <?php echo $porcentaje_clinica; ?>%
                        </div>
                    </div>
                    
                    <div class="distribution-labels">
                        <div class="distribution-label label-peluquero">
                            <div class="label-title">Peluquero</div>
                            <div class="label-amount">$<?php echo number_format($estadisticas['ganancia_peluquero'] ?? 0, 2); ?></div>
                            <div class="label-percentage"><?php echo $porcentaje_peluquero; ?>% del total</div>
                        </div>
                        <div class="distribution-label">
                            <div class="label-title">Clínica</div>
                            <div class="label-amount">$<?php echo number_format($estadisticas['ganancia_clinica'] ?? 0, 2); ?></div>
                            <div class="label-percentage"><?php echo $porcentaje_clinica; ?>% del total</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cortes Detallados -->
            <div class="table-card fade-in">
                <div class="table-header">
                    <h2>
                        <i class='bx bx-list-ul'></i>
                        Cortes Realizados
                    </h2>
                    <div class="export-options">
                        <button class="btn-outline btn-sm" onclick="exportToPDF()">
                            <i class='bx bx-download'></i>
                            PDF
                        </button>
                        <button class="btn-outline btn-sm" onclick="window.print()">
                            <i class='bx bx-printer'></i>
                            Imprimir
                        </button>
                    </div>
                </div>
                
                <div class="table-body">
                    <div class="table-container">
                        <?php if (!empty($cortes_detallados)): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Hora Entrada</th>
                                        <th>Hora Salida</th>
                                        <th>Mascota</th>
                                        <th>Cliente</th>
                                        <th>Servicio</th>
                                        <th>Tipo Corte</th>
                                        <th>Monto</th>
                                        <th>Peluquero</th>
                                        <th>Clínica</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cortes_detallados as $corte): 
                                        $ganancia_peluquero_corte = $corte['costo_total'] * ($porcentaje_peluquero / 100);
                                        $ganancia_clinica_corte = $corte['costo_total'] * ($porcentaje_clinica / 100);
                                        
                                        // Determinar si ya tiene hora de salida registrada
                                        $hora_salida_registrada = ($corte['hora_entrada'] !== $corte['hora_salida']);
                                    ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($corte['fecha_cita'])); ?></td>
                                            <td>
                                                <?php if (!empty($corte['hora_entrada'])): ?>
                                                    <span class="hora-estado hora-entrada">
                                                        <i class='bx bx-log-in'></i>
                                                        <?php echo date('H:i', strtotime($corte['hora_entrada'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="hora-estado hora-pendiente">
                                                        <i class='bx bx-time'></i>
                                                        Sin entrada
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($hora_salida_registrada && !empty($corte['hora_salida'])): ?>
                                                    <span class="hora-estado hora-salida">
                                                        <i class='bx bx-log-out'></i>
                                                        <?php echo date('H:i', strtotime($corte['hora_salida'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="hora-estado hora-pendiente">
                                                        <i class='bx bx-time'></i>
                                                        Pendiente
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($corte['mascota_nombre']); ?></strong>
                                                <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($corte['raza']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($corte['cliente_nombre']); ?></td>
                                            <td><?php echo htmlspecialchars($corte['servicio_nombre']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $corte['tipo_corte'] ?? 'corte'; ?>">
                                                    <?php echo $corte['tipo_corte'] ?? 'No especificado'; ?>
                                                </span>
                                            </td>
                                            <td><strong>$<?php echo number_format($corte['costo_total'], 2); ?></strong></td>
                                            <td>$<?php echo number_format($ganancia_peluquero_corte, 2); ?></td>
                                            <td>$<?php echo number_format($ganancia_clinica_corte, 2); ?></td>
                                            <td>
                                                <?php if (!empty($corte['corte_id'])): ?>
                                                    <form method="POST" action="" style="display: inline;"><?php echo av_csrf_field(); ?>
                                                        <input type="hidden" name="corte_id" value="<?php echo $corte['corte_id']; ?>">
                                                        <button type="submit" name="registrar_salida" 
                                                                class="btn-salida btn-sm" 
                                                                <?php echo $hora_salida_registrada ? 'disabled' : ''; ?>>
                                                            <i class='bx bx-log-out'></i>
                                                            <?php echo $hora_salida_registrada ? 'Completado' : 'Registrar Salida'; ?>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="hora-estado hora-pendiente">
                                                        Sin registro
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="7" style="text-align: right; font-weight: 600;">TOTALES</td>
                                        <td><strong>$<?php echo number_format($estadisticas['ingresos_totales'], 2); ?></strong></td>
                                        <td><strong>$<?php echo number_format($estadisticas['ganancia_peluquero'], 2); ?></strong></td>
                                        <td><strong>$<?php echo number_format($estadisticas['ganancia_clinica'], 2); ?></strong></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-info-circle'></i>
                                <h3>No hay cortes registrados</h3>
                                <p>No hay cortes registrados en el período seleccionado.</p>
                                <a href="nuevocorte.php" class="btn btn-primary">
                                    <i class='bx bx-plus'></i>
                                    Registrar Nuevo Corte
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
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
        // Función para exportar a PDF
        function exportToPDF() {
            // Mostrar mensaje de carga
            const loadingMessage = document.createElement('div');
            loadingMessage.innerHTML = '<div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(15,108,51,0.9); color:white; padding:20px; border-radius:10px; z-index:9999;">Generando PDF, por favor espere...</div>';
            document.body.appendChild(loadingMessage);
            
            // Usar html2canvas para capturar el contenido
            html2canvas(document.querySelector('.main-content'), {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#F8FDF8'
            }).then(canvas => {
                // Crear PDF
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = 210;
                const pageHeight = 295;
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;
                
                // Agregar la primera página
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                // Agregar páginas adicionales si es necesario
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                // Guardar el PDF
                const fileName = `Ganancias_Peluqueria_${new Date().toISOString().slice(0,10)}.pdf`;
                pdf.save(fileName);
                
                // Eliminar mensaje de carga
                document.body.removeChild(loadingMessage);
            }).catch(error => {
                console.error('Error al generar PDF:', error);
                alert('Error al generar el PDF. Por favor, intente nuevamente.');
                document.body.removeChild(loadingMessage);
            });
        }

        // Validación de fechas en el filtro
        document.addEventListener('DOMContentLoaded', function() {
            const fechaInicio = document.getElementById('fecha_inicio');
            const fechaFin = document.getElementById('fecha_fin');
            
            fechaInicio.addEventListener('change', function() {
                if (fechaFin.value && this.value > fechaFin.value) {
                    alert('La fecha de inicio no puede ser mayor a la fecha fin');
                    this.value = fechaFin.value;
                }
            });
            
            fechaFin.addEventListener('change', function() {
                if (fechaInicio.value && this.value < fechaInicio.value) {
                    alert('La fecha fin no puede ser menor a la fecha de inicio');
                    this.value = fechaInicio.value;
                }
            });

            // Animación de contadores
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.]/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = text.replace(number, current.toLocaleString('es-VE', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }));
                    }, 30);
                }
            });

            // Efecto de carga para las secciones
            const sections = document.querySelectorAll('.filters-section, .stats-grid, .distribution-card, .table-card');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100 + 200);
            });

            // Confirmación al registrar salida
            const forms = document.querySelectorAll('form[method="POST"]');
            forms.forEach(form => {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.addEventListener('click', function(e) {
                        if (!confirm('¿Está seguro de registrar la hora de salida? Esta acción no se puede deshacer.')) {
                            e.preventDefault();
                        }
                    });
                }
            });

            // Crear partículas dinámicas
            const particlesContainer = document.querySelector('.particles');
            if (particlesContainer) {
                for (let i = 0; i < 15; i++) {
                    const particle = document.createElement('div');
                    particle.className = 'particle';
                    particle.style.cssText = `
                        top: ${Math.random() * 100}%;
                        left: ${Math.random() * 100}%;
                        width: ${Math.random() * 10 + 5}px;
                        height: ${Math.random() * 10 + 5}px;
                        animation-delay: ${Math.random() * 5}s;
                        opacity: ${Math.random() * 0.5 + 0.1};
                        background: ${Math.random() > 0.5 ? 'rgba(15, 108, 51, 0.05)' : 'rgba(249, 155, 7, 0.05)'};
                    `;
                    particlesContainer.appendChild(particle);
                }
            }
        });
    </script>
</body>
</html>