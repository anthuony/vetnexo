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

// Verificar que se haya proporcionado un ID de mascota
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "No se especificó una mascota";
    header('Location: listademascotas.php');
    exit();
}

$mascota_id = intval($_GET['id']);

// Obtener parámetros de filtro
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';
$tipo_registro = isset($_GET['tipo_registro']) ? $_GET['tipo_registro'] : '';

// Incluir configuración de base de datos
include 'config/database.php';

// Inicializar variables
$db = null;
$mascota = null;
$consultas = [];
$vacunas = [];
$peluquerias = [];
$citas_mascota = [];
$anamnesis = [];
$error = null;

try {
    $db = getDBConnection();
    
    // Obtener información del usuario actual para el avatar
    if ($user_id) {
        $stmt = $db->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $usuario_nombre = $user_data['nombre'];
            $usuario_email = $user_data['email'];
            $usuario_rol = $user_data['rol'];
        }
    }
    
    // Obtener información de la mascota
    $mascota_sql = "
        SELECT m.*, 
               e.nombre as especie_nombre,
               c.id as cliente_id,
               c.nombre as cliente_nombre,
               c.telefono as cliente_telefono,
               c.email as cliente_email,
               c.direccion as cliente_direccion
        FROM mascotas m
        LEFT JOIN especies e ON m.especie_id = e.id
        LEFT JOIN clientes c ON m.cliente_id = c.id
        WHERE m.id = ?
    ";
    
    $mascota_stmt = $db->prepare($mascota_sql);
    $mascota_stmt->execute([$mascota_id]);
    $mascota = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mascota) {
        $_SESSION['error'] = "La mascota solicitada no existe";
        header('Location: listademascotas.php');
        exit();
    }
    
    // Obtener consultas veterinarias con filtros
    $consultas_sql = "
        SELECT * FROM consultas 
        WHERE mascota_id = ?
    ";
    $consultas_params = [$mascota_id];

    if ($fecha_desde) {
        $consultas_sql .= " AND fecha_consulta >= ?";
        $consultas_params[] = $fecha_desde;
    }

    if ($fecha_hasta) {
        $consultas_sql .= " AND fecha_consulta <= ?";
        $consultas_params[] = $fecha_hasta;
    }

    $consultas_sql .= " ORDER BY fecha_consulta DESC, created_at DESC";

    $consultas_stmt = $db->prepare($consultas_sql);
    $consultas_stmt->execute($consultas_params);
    $consultas = $consultas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener vacunas con filtros
    $vacunas_sql = "
        SELECT * FROM vacunas 
        WHERE mascota_id = ?
    ";
    $vacunas_params = [$mascota_id];

    if ($fecha_desde) {
        $vacunas_sql .= " AND fecha_aplicacion >= ?";
        $vacunas_params[] = $fecha_desde;
    }

    if ($fecha_hasta) {
        $vacunas_sql .= " AND fecha_aplicacion <= ?";
        $vacunas_params[] = $fecha_hasta;
    }

    $vacunas_sql .= " ORDER BY fecha_aplicacion DESC";

    $vacunas_stmt = $db->prepare($vacunas_sql);
    $vacunas_stmt->execute($vacunas_params);
    $vacunas = $vacunas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener servicios de peluquería con filtros
    $peluquerias_sql = "
        SELECT cp.*, 
               s.nombre as servicio_nombre,
               s.precio as servicio_precio,
               DATE_FORMAT(cp.fecha_corte, '%d/%m/%Y') as fecha_corte_formateada,
               CASE 
                 WHEN cp.tipo_corte = 'corto' THEN 'Corto'
                 WHEN cp.tipo_corte = 'medio' THEN 'Medio'
                 WHEN cp.tipo_corte = 'largo' THEN 'Largo'
                 WHEN cp.tipo_corte = 'baño' THEN 'Baño'
                 WHEN cp.tipo_corte = 'baño_completo' THEN 'Baño Completo'
                 ELSE cp.tipo_corte 
               END as tipo_corte_display
        FROM cortes_peluqueria cp
        LEFT JOIN servicios s ON cp.servicio_id = s.id
        WHERE cp.mascota_id = ?
    ";
    $peluquerias_params = [$mascota_id];

    if ($fecha_desde) {
        $peluquerias_sql .= " AND cp.fecha_corte >= ?";
        $peluquerias_params[] = $fecha_desde;
    }

    if ($fecha_hasta) {
        $peluquerias_sql .= " AND cp.fecha_corte <= ?";
        $peluquerias_params[] = $fecha_hasta;
    }

    $peluquerias_sql .= " ORDER BY cp.fecha_corte DESC";

    $peluquerias_stmt = $db->prepare($peluquerias_sql);
    $peluquerias_stmt->execute($peluquerias_params);
    $peluquerias = $peluquerias_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener citas de la mascota
    $citas_sql = "
        SELECT c.*, s.nombre as servicio_nombre,
               DATE_FORMAT(c.fecha_cita, '%d/%m/%Y') as fecha_formateada,
               TIME_FORMAT(c.hora_cita, '%H:%i') as hora_formateada,
               CASE 
                   WHEN c.estado = 'scheduled' THEN 'Programada'
                   WHEN c.estado = 'completed' THEN 'Completada'
                   WHEN c.estado = 'cancelled' THEN 'Cancelada'
                   ELSE c.estado
               END as estado_display
        FROM citas c
        LEFT JOIN servicios s ON c.servicio_id = s.id
        WHERE c.mascota_id = ?
        ORDER BY c.fecha_cita DESC, c.hora_cita DESC
        LIMIT 20
    ";
    $citas_stmt = $db->prepare($citas_sql);
    $citas_stmt->execute([$mascota_id]);
    $citas_mascota = $citas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener anamnesis (historial clínico completo) con filtros
    if ($tipo_registro) {
        if ($tipo_registro === 'consulta') {
            $anamnesis_sql = "
                SELECT 
                    'consulta' as tipo,
                    fecha_consulta as fecha,
                    motivo,
                    diagnostico,
                    tratamiento,
                    observaciones,
                    veterinario,
                    created_at,
                    id as registro_id
                FROM consultas 
                WHERE mascota_id = ?
                " . ($fecha_desde ? " AND fecha_consulta >= ?" : "") . "
                " . ($fecha_hasta ? " AND fecha_consulta <= ?" : "") . "
                ORDER BY fecha_consulta DESC, created_at DESC
            ";
            
            $anamnesis_params = [$mascota_id];
            if ($fecha_desde) $anamnesis_params[] = $fecha_desde;
            if ($fecha_hasta) $anamnesis_params[] = $fecha_hasta;
            
            $anamnesis_stmt = $db->prepare($anamnesis_sql);
            $anamnesis_stmt->execute($anamnesis_params);
        } elseif ($tipo_registro === 'vacuna') {
            $anamnesis_sql = "
                SELECT 
                    'vacuna' as tipo,
                    fecha_aplicacion as fecha,
                    nombre_vacuna as motivo,
                    '' as diagnostico,
                    '' as tratamiento,
                    observaciones,
                    veterinario,
                    created_at,
                    id as registro_id
                FROM vacunas 
                WHERE mascota_id = ?
                " . ($fecha_desde ? " AND fecha_aplicacion >= ?" : "") . "
                " . ($fecha_hasta ? " AND fecha_aplicacion <= ?" : "") . "
                ORDER BY fecha_aplicacion DESC
            ";
            
            $anamnesis_params = [$mascota_id];
            if ($fecha_desde) $anamnesis_params[] = $fecha_desde;
            if ($fecha_hasta) $anamnesis_params[] = $fecha_hasta;
            
            $anamnesis_stmt = $db->prepare($anamnesis_sql);
            $anamnesis_stmt->execute($anamnesis_params);
        } elseif ($tipo_registro === 'peluqueria') {
            $anamnesis_sql = "
                SELECT 
                    'peluqueria' as tipo,
                    fecha_corte as fecha,
                    s.nombre as motivo,
                    CASE 
                        WHEN cp.tipo_corte = 'corto' THEN 'Corto'
                        WHEN cp.tipo_corte = 'medio' THEN 'Medio'
                        WHEN cp.tipo_corte = 'largo' THEN 'Largo'
                        WHEN cp.tipo_corte = 'baño' THEN 'Baño'
                        WHEN cp.tipo_corte = 'baño_completo' THEN 'Baño Completo'
                        ELSE cp.tipo_corte 
                    END as diagnostico,
                    productos_utilizados as tratamiento,
                    cp.observaciones,
                    '' as veterinario,
                    cp.created_at,
                    cp.id as registro_id
                FROM cortes_peluqueria cp
                LEFT JOIN servicios s ON cp.servicio_id = s.id
                WHERE cp.mascota_id = ?
                " . ($fecha_desde ? " AND cp.fecha_corte >= ?" : "") . "
                " . ($fecha_hasta ? " AND cp.fecha_corte <= ?" : "") . "
                ORDER BY cp.fecha_corte DESC
            ";
            
            $anamnesis_params = [$mascota_id];
            if ($fecha_desde) $anamnesis_params[] = $fecha_desde;
            if ($fecha_hasta) $anamnesis_params[] = $fecha_hasta;
            
            $anamnesis_stmt = $db->prepare($anamnesis_sql);
            $anamnesis_stmt->execute($anamnesis_params);
        }
    } else {
        $anamnesis_sql = "
            SELECT 
                'consulta' as tipo,
                fecha_consulta as fecha,
                motivo,
                diagnostico,
                tratamiento,
                observaciones,
                veterinario,
                created_at,
                id as registro_id
            FROM consultas 
            WHERE mascota_id = ?
            " . ($fecha_desde ? " AND fecha_consulta >= ?" : "") . "
            " . ($fecha_hasta ? " AND fecha_consulta <= ?" : "") . "
            
            UNION ALL
            
            SELECT 
                'vacuna' as tipo,
                fecha_aplicacion as fecha,
                nombre_vacuna as motivo,
                '' as diagnostico,
                '' as tratamiento,
                observaciones,
                veterinario,
                created_at,
                id as registro_id
            FROM vacunas 
            WHERE mascota_id = ?
            " . ($fecha_desde ? " AND fecha_aplicacion >= ?" : "") . "
            " . ($fecha_hasta ? " AND fecha_aplicacion <= ?" : "") . "
            
            UNION ALL
            
            SELECT 
                'peluqueria' as tipo,
                fecha_corte as fecha,
                s.nombre as motivo,
                CASE 
                    WHEN cp.tipo_corte = 'corto' THEN 'Corto'
                    WHEN cp.tipo_corte = 'medio' THEN 'Medio'
                    WHEN cp.tipo_corte = 'largo' THEN 'Largo'
                    WHEN cp.tipo_corte = 'baño' THEN 'Baño'
                    WHEN cp.tipo_corte = 'baño_completo' THEN 'Baño Completo'
                    ELSE cp.tipo_corte 
                END as diagnostico,
                productos_utilizados as tratamiento,
                cp.observaciones,
                '' as veterinario,
                cp.created_at,
                cp.id as registro_id
            FROM cortes_peluqueria cp
            LEFT JOIN servicios s ON cp.servicio_id = s.id
            WHERE cp.mascota_id = ?
            " . ($fecha_desde ? " AND cp.fecha_corte >= ?" : "") . "
            " . ($fecha_hasta ? " AND cp.fecha_corte <= ?" : "") . "
            
            ORDER BY fecha DESC, created_at DESC
        ";
        
        $anamnesis_params = [$mascota_id];
        if ($fecha_desde) $anamnesis_params[] = $fecha_desde;
        if ($fecha_hasta) $anamnesis_params[] = $fecha_hasta;
        $anamnesis_params[] = $mascota_id;
        if ($fecha_desde) $anamnesis_params[] = $fecha_desde;
        if ($fecha_hasta) $anamnesis_params[] = $fecha_hasta;
        $anamnesis_params[] = $mascota_id;
        if ($fecha_desde) $anamnesis_params[] = $fecha_desde;
        if ($fecha_hasta) $anamnesis_params[] = $fecha_hasta;
        
        $anamnesis_stmt = $db->prepare($anamnesis_sql);
        $anamnesis_stmt->execute($anamnesis_params);
    }
    
    $anamnesis = $anamnesis_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
    if (!isset($consultas)) $consultas = [];
    if (!isset($vacunas)) $vacunas = [];
    if (!isset($peluquerias)) $peluquerias = [];
    if (!isset($citas_mascota)) $citas_mascota = [];
    if (!isset($anamnesis)) $anamnesis = [];
}

// Función helper para obtener color según rol
function getRolColor($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return '#FF9800';
        case 'veterinario':
            return '#4CAF50';
        case 'asistente':
            return '#2196F3';
        default:
            return '#888888';
    }
}

// Función helper para texto del rol
function getRolTexto($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return 'Administrador';
        case 'veterinario':
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
    <title>Historial Clínico - VetNexo</title>
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
            --peluqueria: #9C27B0;
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
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #0d3c11);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
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
            background: linear-gradient(135deg, var(--success), #2E7D32);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #1976D2, #0d6efd);
            transform: translateY(-2px);
        }

        .btn-peluqueria {
            background: linear-gradient(135deg, var(--peluqueria), #7B1FA2);
            color: var(--white);
        }

        .btn-peluqueria:hover {
            background: linear-gradient(135deg, #7B1FA2, #6A1B9A);
            transform: translateY(-2px);
        }

        .btn-back {
            background: linear-gradient(135deg, var(--text-light), var(--text-lighter));
            color: var(--white);
        }

        .btn-back:hover {
            background: linear-gradient(135deg, var(--text-lighter), #5a6268);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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

        .section-header h2 i {
            color: var(--accent);
        }

        .section-header h3 {
            font-size: 1.1rem;
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

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--danger);
            color: #601e1e;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: var(--success);
            color: #1e4620;
        }

        /* Perfil de Mascota */
        .pet-profile-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            position: relative;
        }

        .pet-profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
        }

        .pet-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 700;
            font-size: 2.5rem;
            flex-shrink: 0;
            border: 4px solid var(--white);
            box-shadow: var(--shadow);
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h2 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 700;
        }

        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .meta-item i {
            color: var(--primary);
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .info-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1.2rem;
            border: 1px solid var(--primary-light);
        }

        .info-card h3 {
            margin: 0 0 1rem 0;
            color: var(--primary-dark);
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(46, 125, 50, 0.1);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .info-value {
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
            text-align: right;
        }

        /* Contadores */
        .stats-counter {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1rem 1.5rem;
        }

        .counter-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--primary-soft);
            border-radius: 25px;
            border: 1px solid var(--primary-light);
        }

        .counter-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .counter-label {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .stat-card {
            padding: 1.2rem;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--primary);
        }

        .stat-card h4 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-dark);
            font-size: 1rem;
        }

        /* Filtros */
        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
            background: var(--primary-soft);
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.3rem;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-control {
            padding: 0.6rem 0.8rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: var(--white);
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.8rem center;
            padding-right: 2.5rem;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding: 1.5rem 1.5rem 1.5rem 2.5rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 1rem;
            top: 1.5rem;
            bottom: 1.5rem;
            width: 3px;
            background: var(--primary-light);
            border-radius: 3px;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding: 1.2rem;
            background: var(--white);
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .timeline-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.2rem;
            top: 1.2rem;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 3px solid var(--white);
            box-shadow: 0 0 0 2px var(--primary-light);
        }

        .timeline-item.consulta::before {
            background: var(--primary);
        }

        .timeline-item.vacuna::before {
            background: var(--info);
        }

        .timeline-item.peluqueria::before {
            background: var(--peluqueria);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .timeline-date {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .timeline-type {
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .timeline-type.consulta {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .timeline-type.vacuna {
            background: #e3f2fd;
            color: var(--info);
        }

        .timeline-type.peluqueria {
            background: #f3e5f5;
            color: var(--peluqueria);
        }

        .timeline-content h4 {
            margin: 0 0 0.5rem 0;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .timeline-content p {
            margin: 0.3rem 0;
            color: var(--text-light);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .timeline-details {
            background: var(--gray-light);
            padding: 0.8rem;
            border-radius: var(--radius-sm);
            margin-top: 0.8rem;
            font-size: 0.8rem;
        }

        .detail-label {
            color: var(--text-light);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-dark);
            margin-left: 0.3rem;
        }

        /* Tabla */
        .table-container {
            overflow-x: auto;
            padding: 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--primary-soft);
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.8rem;
            border-bottom: 1px solid var(--gray-light);
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .data-table tr:hover {
            background: var(--primary-soft);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        .badge-peluqueria {
            background: #f3e5f5;
            color: #7B1FA2;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
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
            font-size: 1.2rem;
            color: var(--text-dark);
        }

        .empty-state p {
            margin: 0 0 1.5rem 0;
            font-size: 0.9rem;
        }

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
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

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .profile-grid {
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
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
                justify-content: center;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
            
            .timeline {
                padding-left: 1.5rem;
            }
            
            .timeline-item::before {
                left: -1.2rem;
            }
            
            .empty-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-clipboard'></i>
                        Historial Clínico
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Historial médico completo de <?php echo htmlspecialchars($mascota['nombre'] ?? 'la mascota'); ?>
                    </p>
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

            <!-- Mensajes de error -->
            <?php if (!empty($error)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <i class='bx bx-error-circle'></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <i class='bx bx-error-circle'></i>
                            <?php echo htmlspecialchars($_SESSION['error']); ?>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i>
                            <?php echo htmlspecialchars($_SESSION['success']); ?>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if ($mascota): ?>
            <!-- Perfil de la Mascota -->
            <div class="content-section pet-profile-card fade-in">
                <div class="profile-header">
                    <div class="pet-avatar-large">
                        <?php echo strtoupper(substr($mascota['nombre'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($mascota['nombre']); ?></h2>
                        <div class="profile-meta">
                            <span class="meta-item">
                                <i class='bx bx-cube'></i>
                                <?php echo htmlspecialchars($mascota['especie_nombre']); ?>
                            </span>
                            <span class="meta-item">
                                <i class='bx bx-user'></i>
                                <?php echo htmlspecialchars($mascota['cliente_nombre']); ?>
                            </span>
                            <span class="meta-item">
                                <i class='bx bx-phone'></i>
                                <?php echo htmlspecialchars($mascota['cliente_telefono']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="profile-grid">
                    <div class="info-card">
                        <h3><i class='bx bx-info-circle'></i> Información Básica</h3>
                        <div class="info-item">
                            <span class="info-label">Nombre:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['nombre']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Especie:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['especie_nombre']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Raza:</span>
                            <span class="info-value"><?php echo !empty($mascota['raza']) ? htmlspecialchars($mascota['raza']) : 'No especificada'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Color:</span>
                            <span class="info-value"><?php echo !empty($mascota['color']) ? htmlspecialchars($mascota['color']) : 'No especificado'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Sexo:</span>
                            <span class="info-value">
                                <?php 
                                if (in_array(strtolower((string)$mascota['sexo']), ['macho','m'], true)) echo 'Macho';
                                elseif (in_array(strtolower((string)$mascota['sexo']), ['hembra','h'], true)) echo 'Hembra';
                                else echo 'No especificado';
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-card">
                        <h3><i class='bx bx-heart'></i> Datos de Salud</h3>
                        <?php if (!empty($mascota['fecha_nacimiento'])): 
                            $fecha_nac = new DateTime($mascota['fecha_nacimiento']);
                            $hoy = new DateTime();
                            $diff = $fecha_nac->diff($hoy);
                            if ($diff->y > 0) {
                                $edad = $diff->y . ' año' . ($diff->y > 1 ? 's' : '');
                            } elseif ($diff->m > 0) {
                                $edad = $diff->m . ' mes' . ($diff->m > 1 ? 'es' : '');
                            } else {
                                $edad = $diff->d . ' día' . ($diff->d > 1 ? 's' : '');
                            }
                        ?>
                        <div class="info-item">
                            <span class="info-label">Edad:</span>
                            <span class="info-value"><?php echo $edad; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($mascota['peso'])): ?>
                        <div class="info-item">
                            <span class="info-label">Peso:</span>
                            <span class="info-value"><?php echo !empty($mascota['peso']) ? htmlspecialchars($mascota['peso']) . ' kg' : 'No especificado'; ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="info-item">
                            <span class="info-label">Alergias:</span>
                            <span class="info-value">
                                <?php 
                                if (!empty($mascota['alergias'])) {
                                    echo htmlspecialchars($mascota['alergias']);
                                } else {
                                    echo 'Sin alergias conocidas';
                                }
                                ?>
                            </span>
                        </div>

                        <?php if (isset($mascota['notas'])): ?>
                        <div class="info-item">
                            <span class="info-label">Notas:</span>
                            <span class="info-value">
                                <?php 
                                if (!empty($mascota['notas'])) {
                                    echo htmlspecialchars($mascota['notas']);
                                } else {
                                    echo 'Sin notas adicionales';
                                }
                                ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="info-card">
                        <h3><i class='bx bx-user-circle'></i> Información del Dueño</h3>
                        <div class="info-item">
                            <span class="info-label">Nombre:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_nombre']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Teléfono:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_telefono']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo !empty($mascota['cliente_email']) ? htmlspecialchars($mascota['cliente_email']) : 'No especificado'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Dirección:</span>
                            <span class="info-value"><?php echo !empty($mascota['cliente_direccion']) ? htmlspecialchars($mascota['cliente_direccion']) : 'No especificada'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Acciones:</span>
                            <span class="info-value">
                                <a href="editar_mascota.php?id=<?php echo $mascota_id; ?>" class="btn btn-primary btn-sm">
                                    <i class='bx bx-edit'></i> Editar
                                </a>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Contadores de Registros -->
                <div class="stats-counter">
                    <div class="counter-item">
                        <span class="counter-number"><?php echo count($consultas); ?></span>
                        <span class="counter-label">Consultas</span>
                    </div>
                    <div class="counter-item">
                        <span class="counter-number"><?php echo count($vacunas); ?></span>
                        <span class="counter-label">Vacunas</span>
                    </div>
                    <div class="counter-item">
                        <span class="counter-number"><?php echo count($peluquerias); ?></span>
                        <span class="counter-label">Peluquerías</span>
                    </div>
                    <div class="counter-item">
                        <span class="counter-number"><?php echo count($citas_mascota); ?></span>
                        <span class="counter-label">Citas</span>
                    </div>
                    <div class="counter-item">
                        <span class="counter-number"><?php echo count($anamnesis); ?></span>
                        <span class="counter-label">Total Registros</span>
                    </div>
                </div>
            </div>

            <!-- Resumen de Historial -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-stats'></i>
                        Resumen de Historial
                    </h2>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card" style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-left-color: var(--primary);">
                        <h4 style="color: var(--primary-dark);">Última Consulta</h4>
                        <?php if (!empty($consultas)): 
                            $ultima_consulta = $consultas[0];
                        ?>
                            <p style="margin: 0; color: var(--text-dark); font-weight: 600;">
                                <?php echo date('d/m/Y', strtotime($ultima_consulta['fecha_consulta'])); ?>
                            </p>
                            <p style="margin: 0.25rem 0 0 0; color: var(--text-light); font-size: 0.9rem;">
                                <?php echo htmlspecialchars(substr($ultima_consulta['motivo'], 0, 50)) . (strlen($ultima_consulta['motivo']) > 50 ? '...' : ''); ?>
                            </p>
                        <?php else: ?>
                            <p style="margin: 0; color: var(--text-light);">No hay consultas registradas</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); border-left-color: var(--info);">
                        <h4 style="color: var(--info);">Última Vacuna</h4>
                        <?php if (!empty($vacunas)): 
                            $ultima_vacuna = $vacunas[0];
                        ?>
                            <p style="margin: 0; color: var(--text-dark); font-weight: 600;">
                                <?php echo date('d/m/Y', strtotime($ultima_vacuna['fecha_aplicacion'])); ?>
                            </p>
                            <p style="margin: 0.25rem 0 0 0; color: var(--text-light); font-size: 0.9rem;">
                                <?php echo htmlspecialchars($ultima_vacuna['nombre_vacuna']); ?>
                            </p>
                        <?php else: ?>
                            <p style="margin: 0; color: var(--text-light);">No hay vacunas registradas</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #f3e5f5, #e1bee7); border-left-color: var(--peluqueria);">
                        <h4 style="color: var(--peluqueria);">Última Peluquería</h4>
                        <?php if (!empty($peluquerias)): 
                            $ultima_peluqueria = $peluquerias[0];
                        ?>
                            <p style="margin: 0; color: var(--text-dark); font-weight: 600;">
                                <?php echo date('d/m/Y', strtotime($ultima_peluqueria['fecha_corte'])); ?>
                            </p>
                            <p style="margin: 0.25rem 0 0 0; color: var(--text-light); font-size: 0.9rem;">
                                <?php echo htmlspecialchars($ultima_peluqueria['servicio_nombre']); ?>
                            </p>
                        <?php else: ?>
                            <p style="margin: 0; color: var(--text-light);">No hay peluquerías registradas</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-left-color: var(--accent);">
                        <h4 style="color: var(--accent-dark);">Próximas Atenciones</h4>
                        <?php 
                        $hoy = date('Y-m-d');
                        $proximas_vacunas = [];
                        foreach ($vacunas as $vacuna) {
                            if (!empty($vacuna['proxima_dosis']) && $vacuna['proxima_dosis'] >= $hoy) {
                                $proximas_vacunas[] = $vacuna;
                            }
                        }
                        ?>
                        <p style="margin: 0; color: var(--text-dark); font-weight: 600;">
                            <?php echo count($proximas_vacunas); ?> próximas vacunas
                        </p>
                        <p style="margin: 0.25rem 0 0 0; color: var(--text-light); font-size: 0.9rem;">
                            Próxima: 
                            <?php if (!empty($proximas_vacunas)): 
                                $proxima = $proximas_vacunas[0];
                            ?>
                                <?php echo date('d/m/Y', strtotime($proxima['proxima_dosis'])); ?>
                            <?php else: ?>
                                No programada
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Filtros de Historial -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-filter'></i>
                        Filtros de Historial
                    </h2>
                </div>
                
                <form method="GET" action="" class="filters-form">
                    <input type="hidden" name="id" value="<?php echo $mascota_id; ?>">
                    
                    <div class="form-group">
                        <label for="fecha_desde" class="form-label">Fecha Desde:</label>
                        <input type="date" id="fecha_desde" name="fecha_desde" class="form-control" 
                               value="<?php echo htmlspecialchars($fecha_desde); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="fecha_hasta" class="form-label">Fecha Hasta:</label>
                        <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control"
                               value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo_registro" class="form-label">Tipo de Registro:</label>
                        <select id="tipo_registro" name="tipo_registro" class="form-control select">
                            <option value="">Todos los registros</option>
                            <option value="consulta" <?php echo ($tipo_registro == 'consulta') ? 'selected' : ''; ?>>Consultas</option>
                            <option value="vacuna" <?php echo ($tipo_registro == 'vacuna') ? 'selected' : ''; ?>>Vacunas</option>
                            <option value="peluqueria" <?php echo ($tipo_registro == 'peluqueria') ? 'selected' : ''; ?>>Peluquerías</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class='bx bx-search'></i> Filtrar
                        </button>
                    </div>
                    
                    <?php if ($fecha_desde || $fecha_hasta || $tipo_registro): ?>
                    <div class="form-group" style="align-self: flex-end;">
                        <a href="historial.php?id=<?php echo $mascota_id; ?>" class="btn btn-secondary" style="width: 100%;">
                            <i class='bx bx-x'></i> Limpiar Filtros
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Anamnesis - Timeline -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <div>
                        <h2>
                            <i class='bx bx-history'></i>
                            Anamnesis - Historial Médico Completo
                        </h2>
                        <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.25rem;">
                            Registro cronológico de consultas, vacunas y servicios de peluquería
                        </p>
                    </div>
                    <div class="header-actions">
                        <a href="nueva_consulta.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-success btn-sm">
                            <i class='bx bx-plus'></i>
                            Consulta
                        </a>
                        <a href="nueva_vacuna.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-info btn-sm">
                            <i class='bx bx-plus'></i>
                            Vacuna
                        </a>
                        <a href="agendarcita.php?mascota_id=<?php echo $mascota_id; ?>&servicio=5" class="btn btn-peluqueria btn-sm">
                            <i class='bx bx-plus'></i>
                            Peluquería
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($anamnesis)): ?>
                    <div class="timeline">
                        <?php foreach ($anamnesis as $registro): 
                            $fecha = !empty($registro['fecha']) ? date('d/m/Y', strtotime($registro['fecha'])) : 'Fecha no especificada';
                            $tipo = $registro['tipo'];
                            $tipo_texto = '';
                            $tipo_icon = '';
                            switch($tipo) {
                                case 'consulta':
                                    $tipo_texto = 'CONSULTA';
                                    $tipo_icon = 'bx-clipboard';
                                    break;
                                case 'vacuna':
                                    $tipo_texto = 'VACUNA';
                                    $tipo_icon = 'bx-syringe';
                                    break;
                                case 'peluqueria':
                                    $tipo_texto = 'PELUQUERÍA';
                                    $tipo_icon = 'bx-cut';
                                    break;
                            }
                        ?>
                            <div class="timeline-item <?php echo $tipo; ?>">
                                <div class="timeline-header">
                                    <span class="timeline-date">
                                        <i class='bx bx-calendar'></i> <?php echo $fecha; ?>
                                    </span>
                                    <span class="timeline-type <?php echo $tipo; ?>">
                                        <i class='bx <?php echo $tipo_icon; ?>'></i>
                                        <?php echo $tipo_texto; ?>
                                    </span>
                                </div>
                                
                                <div class="timeline-content">
                                    <h4><?php echo htmlspecialchars($registro['motivo']); ?></h4>
                                    
                                    <?php if ($tipo === 'consulta'): ?>
                                        <?php if (!empty($registro['diagnostico'])): ?>
                                            <p><strong>Diagnóstico:</strong> <?php echo htmlspecialchars($registro['diagnostico']); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($registro['tratamiento'])): ?>
                                            <p><strong>Tratamiento:</strong> <?php echo htmlspecialchars($registro['tratamiento']); ?></p>
                                        <?php endif; ?>
                                    <?php elseif ($tipo === 'peluqueria'): ?>
                                        <?php if (!empty($registro['diagnostico'])): ?>
                                            <p><strong>Tipo de Corte:</strong> <?php echo htmlspecialchars($registro['diagnostico']); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($registro['tratamiento'])): ?>
                                            <p><strong>Productos Utilizados:</strong> <?php echo htmlspecialchars($registro['tratamiento']); ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($registro['observaciones'])): ?>
                                        <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($registro['observaciones']); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="timeline-details">
                                        <?php if (!empty($registro['veterinario'])): ?>
                                            <div>
                                                <span class="detail-label">Veterinario:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($registro['veterinario']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($tipo === 'peluqueria'): ?>
                                            <div>
                                                <span class="detail-label">Tipo:</span>
                                                <span class="detail-value">Servicio de Peluquería</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-clipboard'></i>
                        <h3>No hay registros médicos</h3>
                        <p>No se han registrado consultas, vacunas ni servicios de peluquería para esta mascota.</p>
                        <div class="empty-actions">
                            <a href="nueva_consulta.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-success btn-sm">
                                <i class='bx bx-plus'></i>
                                Registrar Consulta
                            </a>
                            <a href="nueva_vacuna.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-info btn-sm">
                                <i class='bx bx-plus'></i>
                                Registrar Vacuna
                            </a>
                            <a href="agendarcita.php?mascota_id=<?php echo $mascota_id; ?>&servicio=5" class="btn btn-peluqueria btn-sm">
                                <i class='bx bx-plus'></i>
                                Registrar Peluquería
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Historial de Consultas -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <div>
                        <h2>
                            <i class='bx bx-clipboard'></i>
                            Historial de Consultas
                        </h2>
                        <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.25rem;">
                            Registro detallado de consultas médicas
                        </p>
                    </div>
                    <a href="nueva_consulta.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-success btn-sm">
                        <i class='bx bx-plus'></i>
                        Nueva Consulta
                    </a>
                </div>
                
                <div class="table-container">
                    <?php if (!empty($consultas)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Motivo</th>
                                    <th>Diagnóstico</th>
                                    <th>Tratamiento</th>
                                    <th>Veterinario</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($consultas as $consulta): ?>
                                    <tr>
                                        <td><?php echo !empty($consulta['fecha_consulta']) ? date('d/m/Y', strtotime($consulta['fecha_consulta'])) : 'No especificada'; ?></td>
                                        <td><?php echo !empty($consulta['motivo']) ? htmlspecialchars($consulta['motivo']) : 'Sin motivo'; ?></td>
                                        <td><?php echo !empty($consulta['diagnostico']) ? htmlspecialchars($consulta['diagnostico']) : 'Sin diagnóstico'; ?></td>
                                        <td><?php echo !empty($consulta['tratamiento']) ? htmlspecialchars($consulta['tratamiento']) : 'Sin tratamiento'; ?></td>
                                        <td><?php echo !empty($consulta['veterinario']) ? htmlspecialchars($consulta['veterinario']) : 'No especificado'; ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="editar_consulta.php?id=<?php echo $consulta['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class='bx bx-edit'></i>
                                                </a><?php if(auth_current_role()==='admin'):?><a class="btn btn-danger btn-sm" href="eliminar_registro.php?tipo=consulta&id=<?php echo $consulta['id']; ?>">Eliminar</a><?php endif;?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-clipboard'></i>
                            <h3>No hay consultas registradas</h3>
                            <p>No se han registrado consultas para esta mascota.</p>
                            <a href="nueva_consulta.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-success btn-sm">
                                <i class='bx bx-plus'></i>
                                Registrar Primera Consulta
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Historial de Vacunas -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <div>
                        <h2>
                            <i class='bx bx-syringe'></i>
                            Historial de Vacunas
                        </h2>
                        <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.25rem;">
                            Registro de vacunas aplicadas
                        </p>
                    </div>
                    <a href="nueva_vacuna.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-info btn-sm">
                        <i class='bx bx-plus'></i>
                        Nueva Vacuna
                    </a>
                </div>
                
                <div class="table-container">
                    <?php if (!empty($vacunas)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Vacuna</th>
                                    <th>Fecha Aplicación</th>
                                    <th>Próxima Dosis</th>
                                    <th>Veterinario</th>
                                    <th>Observaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vacunas as $vacuna): ?>
                                    <tr>
                                        <td><?php echo !empty($vacuna['nombre_vacuna']) ? htmlspecialchars($vacuna['nombre_vacuna']) : 'Sin nombre'; ?></td>
                                        <td><?php echo !empty($vacuna['fecha_aplicacion']) ? date('d/m/Y', strtotime($vacuna['fecha_aplicacion'])) : 'No especificada'; ?></td>
                                        <td>
                                            <?php if (!empty($vacuna['proxima_dosis'])): ?>
                                                <?php 
                                                $prox_dosis = date('d/m/Y', strtotime($vacuna['proxima_dosis']));
                                                $hoy = new DateTime();
                                                $prox = new DateTime($vacuna['proxima_dosis']);
                                                if ($prox < $hoy) {
                                                    echo '<span class="badge badge-danger">Vencida: ' . $prox_dosis . '</span>';
                                                } else {
                                                    echo $prox_dosis;
                                                }
                                                ?>
                                            <?php else: ?>
                                                No programada
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($vacuna['veterinario']) ? htmlspecialchars($vacuna['veterinario']) : 'No especificado'; ?></td>
                                        <td><?php echo !empty($vacuna['observaciones']) ? htmlspecialchars($vacuna['observaciones']) : 'Sin observaciones'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-syringe'></i>
                            <h3>No hay vacunas registradas</h3>
                            <p>No se han registrado vacunas para esta mascota.</p>
                            <a href="nueva_vacuna.php?mascota_id=<?php echo $mascota_id; ?>" class="btn btn-info btn-sm">
                                <i class='bx bx-plus'></i>
                                Registrar Primera Vacuna
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Historial de Peluquería -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <div>
                        <h2>
                            <i class='bx bx-cut'></i>
                            Historial de Peluquería
                        </h2>
                        <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.25rem;">
                            Registro de servicios de peluquería realizados
                        </p>
                    </div>
                    <a href="agendarcita.php?mascota_id=<?php echo $mascota_id; ?>&servicio=5" class="btn btn-peluqueria btn-sm">
                        <i class='bx bx-plus'></i>
                        Nueva Peluquería
                    </a>
                </div>
                
                <div class="table-container">
                    <?php if (!empty($peluquerias)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Servicio</th>
                                    <th>Tipo de Corte</th>
                                    <th>Productos Utilizados</th>
                                    <th>Costo</th>
                                    <th>Observaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($peluquerias as $peluqueria): ?>
                                    <tr>
                                        <td><?php echo !empty($peluqueria['fecha_corte']) ? date('d/m/Y', strtotime($peluqueria['fecha_corte'])) : 'No especificada'; ?></td>
                                        <td><?php echo !empty($peluqueria['servicio_nombre']) ? htmlspecialchars($peluqueria['servicio_nombre']) : 'No especificado'; ?></td>
                                        <td>
                                            <span class="badge badge-peluqueria">
                                                <?php echo !empty($peluqueria['tipo_corte_display']) ? htmlspecialchars($peluqueria['tipo_corte_display']) : 'No especificado'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo !empty($peluqueria['productos_utilizados']) ? htmlspecialchars($peluqueria['productos_utilizados']) : 'No especificados'; ?></td>
                                        <td><?php echo !empty($peluqueria['costo']) ? '$' . number_format($peluqueria['costo'], 2) : 'No especificado'; ?></td>
                                        <td><?php echo !empty($peluqueria['observaciones']) ? htmlspecialchars($peluqueria['observaciones']) : 'Sin observaciones'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-cut'></i>
                            <h3>No hay servicios de peluquería registrados</h3>
                            <p>No se han registrado servicios de peluquería para esta mascota.</p>
                            <a href="agendarcita.php?mascota_id=<?php echo $mascota_id; ?>&servicio=5" class="btn btn-peluqueria btn-sm">
                                <i class='bx bx-plus'></i>
                                Registrar Primera Peluquería
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

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
        document.addEventListener('DOMContentLoaded', function() {
            // Confirmación antes de eliminar (si hay botones de eliminar)
            const deleteButtons = document.querySelectorAll('.btn-delete');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('¿Estás seguro de que deseas eliminar este registro? Esta acción no se puede deshacer.')) {
                        e.preventDefault();
                    }
                });
            });

            // Animación para timeline items
            const timelineItems = document.querySelectorAll('.timeline-item');
            timelineItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, index * 100);
            });

            // Establecer fecha máxima para filtros
            const hoy = new Date().toISOString().split('T')[0];
            const fechaHasta = document.getElementById('fecha_hasta');
            const fechaDesde = document.getElementById('fecha_desde');
            
            if (fechaHasta) {
                fechaHasta.max = hoy;
            }
            if (fechaDesde) {
                fechaDesde.max = hoy;
            }

            // Validar que fecha desde no sea mayor que fecha hasta
            if (fechaDesde && fechaHasta) {
                fechaDesde.addEventListener('change', function() {
                    fechaHasta.min = this.value;
                });
                
                fechaHasta.addEventListener('change', function() {
                    fechaDesde.max = this.value;
                });
            }
            
            // Botón para imprimir historial
            const printBtn = document.createElement('button');
            printBtn.innerHTML = '<i class="bx bx-printer"></i> Imprimir Historial';
            printBtn.className = 'btn btn-secondary btn-sm';
            printBtn.style.marginLeft = '1rem';
            printBtn.onclick = function() {
                window.print();
            };
            
            const anamnesisHeader = document.querySelector('.section-header .header-actions');
            if (anamnesisHeader) {
                anamnesisHeader.appendChild(printBtn);
            }
        });
    </script>
</body>
</html>