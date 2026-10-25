<?php
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

// Inicializar variables
$citas = [];
$calendario_mensual = [];
$calendario_semanal = [];
$calendario_diario = [];
$citas_pendientes_lista = [];
$todas_las_citas = [];  // Inicializado como array vacío
$estadisticas = [
    'total_citas' => 0,
    'citas_hoy' => 0,
    'citas_pendientes' => 0
];
$error = '';
$mes_actual = isset($_GET['mes']) ? $_GET['mes'] : date('Y-m');
$vista_actual = isset($_GET['vista']) ? $_GET['vista'] : 'mensual';
$fecha_seleccionada = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

try {
    // Crear conexión directamente a la base de datos
    $pdo = getDBConnection();
    
    // Obtener información del usuario actual para el avatar
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $usuario_nombre = $user_data['nombre'];
            $usuario_email = $user_data['email'];
            $usuario_rol = $user_data['rol'];
        }
    }
    
    // Obtener el primer y último día del mes actual para la vista mensual
    $primer_dia_mes = date('Y-m-01', strtotime($mes_actual));
    $ultimo_dia_mes = date('Y-m-t', strtotime($mes_actual));
    
    // CONSULTA PRINCIPAL: Obtener todas las citas EXCEPTO las canceladas
    $sql = "SELECT c.*, 
                   m.nombre as mascota_nombre, 
                   m.raza as mascota_raza,
                   cl.nombre as cliente_nombre, 
                   cl.telefono as cliente_telefono, 
                   cl.email as cliente_email,
                   s.nombre as servicio_nombre, 
                   s.categoria as tipo_servicio,
                   s.precio as servicio_precio
            FROM citas c 
            LEFT JOIN mascotas m ON c.mascota_id = m.id 
            LEFT JOIN clientes cl ON c.cliente_id = cl.id 
            LEFT JOIN servicios s ON c.servicio_id = s.id 
            WHERE c.estado != 'cancelled'
            ORDER BY c.fecha_cita, c.hora_cita";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $todas_las_citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CONSULTA PARA CITAS PENDIENTES (scheduled y confirmed)
    $sql_pendientes = "SELECT c.*, 
                              m.nombre as mascota_nombre, 
                              m.raza as mascota_raza,
                              cl.nombre as cliente_nombre, 
                              cl.telefono as cliente_telefono, 
                              cl.email as cliente_email,
                              s.nombre as servicio_nombre, 
                              s.categoria as tipo_servicio,
                              s.precio as servicio_precio
                       FROM citas c 
                       LEFT JOIN mascotas m ON c.mascota_id = m.id 
                       LEFT JOIN clientes cl ON c.cliente_id = cl.id 
                       LEFT JOIN servicios s ON c.servicio_id = s.id 
                       WHERE c.estado IN ('scheduled', 'confirmed')
                       ORDER BY c.fecha_cita, c.hora_cita";
    
    $stmt_pendientes = $pdo->prepare($sql_pendientes);
    $stmt_pendientes->execute();
    $citas_pendientes_lista = $stmt_pendientes->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtrar citas para el mes actual (para la vista mensual)
    $citas_mes_actual = array_filter($todas_las_citas, function($cita) use ($primer_dia_mes, $ultimo_dia_mes) {
        $fecha_cita = date('Y-m-d', strtotime($cita['fecha_cita']));
        return $fecha_cita >= $primer_dia_mes && $fecha_cita <= $ultimo_dia_mes;
    });
    
    // Calcular estadísticas
    $estadisticas['total_citas'] = count($todas_las_citas);
    
    // Citas para hoy
    $hoy = date('Y-m-d');
    $citas_hoy = array_filter($todas_las_citas, function($cita) use ($hoy) {
        $fecha_cita = date('Y-m-d', strtotime($cita['fecha_cita']));
        return $fecha_cita == $hoy;
    });
    $estadisticas['citas_hoy'] = count($citas_hoy);
    
    // Citas pendientes
    $estadisticas['citas_pendientes'] = count($citas_pendientes_lista);
    
    // Generar calendarios según la vista
    if ($vista_actual == 'mensual') {
        $calendario_mensual = generarCalendarioMensual($mes_actual, $citas_mes_actual);
    } elseif ($vista_actual == 'semanal') {
        $calendario_semanal = generarCalendarioSemanal($fecha_seleccionada, $todas_las_citas);
    } elseif ($vista_actual == 'diaria') {
        $calendario_diario = generarCalendarioDiario($fecha_seleccionada, $todas_las_citas);
    }
    
} catch (PDOException $e) {
    $error = "Error al conectar con la base de datos: " . $e->getMessage();
}

// Función para generar el calendario mensual
function generarCalendarioMensual($mes, $citas) {
    $primer_dia = date('N', strtotime($mes . '-01')); // Día de la semana (1=Lunes, 7=Domingo)
    $dias_en_mes = date('t', strtotime($mes . '-01'));
    
    $calendario = [];
    $semana_actual = [];
    
    // Agregar días vacíos al inicio si es necesario
    for ($i = 1; $i < $primer_dia; $i++) {
        $semana_actual[] = ['dia' => null, 'fecha' => null, 'citas' => []];
    }
    
    // Agregar los días del mes
    for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
        $fecha_actual = $mes . '-' . sprintf('%02d', $dia);
        $citas_dia = array_filter($citas, function($cita) use ($fecha_actual) {
            $fecha_cita = date('Y-m-d', strtotime($cita['fecha_cita']));
            return $fecha_cita == $fecha_actual;
        });
        
        $semana_actual[] = [
            'dia' => $dia,
            'fecha' => $fecha_actual,
            'citas' => array_values($citas_dia)
        ];
        
        // Si completamos una semana (7 días), empezar nueva semana
        if (count($semana_actual) == 7) {
            $calendario[] = $semana_actual;
            $semana_actual = [];
        }
    }
    
    // Completar la última semana con días vacíos si es necesario
    while (count($semana_actual) < 7 && count($semana_actual) > 0) {
        $semana_actual[] = ['dia' => null, 'fecha' => null, 'citas' => []];
    }
    
    if (!empty($semana_actual)) {
        $calendario[] = $semana_actual;
    }
    
    return $calendario;
}

// Función para generar el calendario semanal
function generarCalendarioSemanal($fecha, $citas) {
    $fecha_inicio = date('Y-m-d', strtotime('monday this week', strtotime($fecha)));
    $fecha_fin = date('Y-m-d', strtotime('sunday this week', strtotime($fecha)));
    
    $semana = [];
    
    for ($i = 0; $i < 7; $i++) {
        $fecha_actual = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $i . ' days'));
        $citas_dia = array_filter($citas, function($cita) use ($fecha_actual) {
            $fecha_cita = date('Y-m-d', strtotime($cita['fecha_cita']));
            return $fecha_cita == $fecha_actual;
        });
        
        $semana[] = [
            'dia' => date('d', strtotime($fecha_actual)),
            'fecha' => $fecha_actual,
            'nombre_dia' => obtenerNombreDia(date('N', strtotime($fecha_actual))),
            'citas' => array_values($citas_dia)
        ];
    }
    
    return $semana;
}

// Función para generar el calendario diario
function generarCalendarioDiario($fecha, $citas) {
    $citas_dia = array_filter($citas, function($cita) use ($fecha) {
        $fecha_cita = date('Y-m-d', strtotime($cita['fecha_cita']));
        return $fecha_cita == $fecha;
    });
    
    // Ordenar citas por hora
    usort($citas_dia, function($a, $b) {
        return strtotime($a['hora_cita']) - strtotime($b['hora_cita']);
    });
    
    return [
        'fecha' => $fecha,
        'nombre_dia' => obtenerNombreDia(date('N', strtotime($fecha))),
        'citas' => array_values($citas_dia)
    ];
}

// Función auxiliar para obtener nombre del día
function obtenerNombreDia($numero_dia) {
    $dias = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo'
    ];
    
    return $dias[$numero_dia] ?? 'Día';
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
    <title>Calendario de Citas - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
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
            background: linear-gradient(135deg, var(--warning), var(--amarillo-dark));
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--amarillo-dark), #c4b712);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, var(--emergency-dark), #a50c01);
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

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            cursor: pointer;
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

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--accent), var(--warning));
        }

        .stat-card.success::before {
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

        .stat-card.warning .stat-icon {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-card.success .stat-icon {
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

        .stat-card.warning .stat-number {
            color: var(--accent-dark);
        }

        .stat-card.success .stat-number {
            color: var(--primary-dark);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
            color: var(--text-lighter);
        }

        /* Calendar Controls */
        .calendar-controls {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .controls-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .controls-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .controls-body {
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .view-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-view {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray);
            background: var(--white);
            color: var(--text-dark);
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-view.active {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
        }

        .btn-view:hover:not(.active) {
            border-color: var(--primary);
            color: var(--primary);
        }

        .date-navigation {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .current-date {
            font-weight: 600;
            color: var(--primary-dark);
            min-width: 220px;
            text-align: center;
            font-size: 1rem;
            padding: 0.5rem 1rem;
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
        }

        /* Calendar Views */
        .calendar-view {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .view-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .view-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .view-body {
            padding: 1.5rem;
        }

        /* Monthly Calendar */
        .month-calendar {
            width: 100%;
        }

        .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            border: 1px solid var(--primary-light);
        }

        .calendar-header-day {
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
            font-size: 0.9rem;
        }

        .calendar-week {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            overflow: hidden;
            margin-bottom: 2px;
        }

        .calendar-day {
            background: var(--white);
            min-height: 120px;
            padding: 0.75rem;
            border: 1px solid var(--primary-soft);
            transition: all 0.3s ease;
            position: relative;
        }

        .calendar-day:hover {
            background: var(--primary-soft);
            transform: scale(1.02);
            z-index: 1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .calendar-day.empty {
            background: var(--gray-light);
            color: var(--text-light);
        }

        .calendar-day.today {
            background: linear-gradient(135deg, var(--primary-soft), #e8f5e9);
            border-color: var(--primary);
            border-width: 2px;
        }

        .calendar-day.has-events {
            background: linear-gradient(135deg, var(--accent-soft), var(--amarillo-light, #fff3e0));
        }

        .day-number {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .calendar-day.today .day-number {
            color: var(--primary-dark);
            font-weight: 700;
        }

        .day-events {
            max-height: 80px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .day-events::-webkit-scrollbar {
            width: 4px;
        }

        .day-events::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 2px;
        }

        .day-events::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 2px;
        }

        /* Event Items con los colores de la marca */
        .event-item {
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            margin-bottom: 0.3rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 3px solid;
            color: white;
        }

        .event-item:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        /* Emergencia - Rojo Corazón #F91302 */
        .event-item.emergency {
            background: linear-gradient(135deg, #F91302, #d61002);
            border-left-color: #a50c01;
        }

        /* Vacunación - Verde Bosque #0F6C33 */
        .event-item.vaccination {
            background: linear-gradient(135deg, #0F6C33, #0a4f25);
            border-left-color: #063619;
        }

        /* Cirugía - Naranja Vibrante #F99B07 */
        .event-item.surgery {
            background: linear-gradient(135deg, #F99B07, #d88206);
            border-left-color: #b36a05;
        }

        /* Consulta General - Azul Real #2261BD */
        .event-item.consultation {
            background: linear-gradient(135deg, #2261BD, #1a4a91);
            border-left-color: #12356b;
        }

        /* Grooming / Peluquería - Marrón Chocolate #5A4B37 */
        .event-item.grooming {
            background: linear-gradient(135deg, #5A4B37, #423627);
            border-left-color: #2e251b;
        }

        /* Cita de rutina / Otros - Amarillo Intenso #FBEF16 (con texto oscuro para legibilidad) */
        .event-item.other {
            background: linear-gradient(135deg, #FBEF16, #e6d814);
            border-left-color: #c4b712;
            color: #2E2E2E; /* Texto oscuro para contraste */
        }

        /* Laboratorio / Análisis - Combinación de Azul y Verde */
        .event-item.laboratory {
            background: linear-gradient(135deg, #2261BD, #0F6C33);
            border-left-color: #0a4f25;
        }

        .event-time {
            font-weight: 600;
            font-size: 0.7rem;
            opacity: 0.9;
        }

        .event-pet {
            font-size: 0.7rem;
            opacity: 0.8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Weekly Calendar */
        .week-calendar {
            width: 100%;
        }

        .week-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            border: 1px solid var(--primary-light);
        }

        .week-day-header {
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
        }

        .week-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .week-day {
            background: var(--white);
            min-height: 150px;
            padding: 0.75rem;
            border: 1px solid var(--primary-soft);
            transition: all 0.3s ease;
            position: relative;
        }

        .week-day.today {
            background: linear-gradient(135deg, var(--primary-soft), #e8f5e9);
            border-color: var(--primary);
            border-width: 2px;
        }

        .week-day.selected {
            background: linear-gradient(135deg, var(--accent-soft), var(--amarillo-light, #fff3e0));
            border-color: var(--accent);
        }

        .week-day-number {
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .week-day-name {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
        }

        .week-events {
            max-height: 110px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .week-events::-webkit-scrollbar {
            width: 4px;
        }

        /* Daily Calendar */
        .day-calendar {
            width: 100%;
        }

        .day-header {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            border: 1px solid var(--primary-light);
            padding: 1.5rem;
            text-align: center;
        }

        .day-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
        }

        .day-subtitle {
            color: var(--text-light);
            margin: 0.5rem 0 0 0;
        }

        .day-events-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .day-event-item {
            background: var(--white);
            border-radius: var(--radius-sm);
            padding: 1.2rem;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .day-event-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .day-event-item.emergency {
            border-left-color: #a50c01;
        }

        .day-event-item.vaccination {
            border-left-color: #063619;
        }

        .day-event-item.surgery {
            border-left-color: #b36a05;
        }

        .day-event-item.consultation {
            border-left-color: #12356b;
        }

        .day-event-item.grooming {
            border-left-color: #2e251b;
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.8rem;
        }

        .event-time-large {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .event-service {
            background: var(--primary);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .event-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .event-detail-group {
            margin-bottom: 0.5rem;
        }

        .event-detail-label {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.2rem;
        }

        .event-detail-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        /* Vista de Citas Pendientes */
        .pending-appointments-view {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .pending-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pending-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .pending-body {
            padding: 1.5rem;
        }

        .appointments-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .appointment-card {
            background: var(--white);
            border-radius: var(--radius-sm);
            padding: 1.2rem;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .appointment-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .appointment-card.emergency {
            border-left-color: #a50c01;
        }

        .appointment-card.vaccination {
            border-left-color: #063619;
        }

        .appointment-card.surgery {
            border-left-color: #b36a05;
        }

        .appointment-card.consultation {
            border-left-color: #12356b;
        }

        .appointment-card.grooming {
            border-left-color: #2e251b;
        }

        .appointment-info {
            flex: 1;
            min-width: 250px;
        }

        .appointment-time {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .appointment-date {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
        }

        .appointment-service {
            background: var(--primary);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: inline-block;
        }

        .appointment-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem;
            margin-top: 0.5rem;
        }

        .appointment-detail-group {
            margin-bottom: 0.3rem;
        }

        .appointment-detail-label {
            font-size: 0.75rem;
            color: var(--text-light);
            margin-bottom: 0.2rem;
        }

        .appointment-detail-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .appointment-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Modal de detalles de cita */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--primary);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .modal-detail-group {
            margin-bottom: 1rem;
        }

        .modal-detail-label {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 0.3rem;
        }

        .modal-detail-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-success {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .badge-danger {
            background: #ffebee;
            color: var(--emergency);
        }

        .badge-info {
            background: #e3f2fd;
            color: var(--azul);
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

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            border-color: var(--azul);
            color: #0c5460;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--emergency);
            color: #601e1e;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-info .alert-icon {
            color: var(--azul);
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .controls-body {
                flex-direction: column;
                align-items: stretch;
            }
            
            .view-controls {
                justify-content: center;
            }
            
            .date-navigation {
                justify-content: center;
            }
            
            .current-date {
                min-width: auto;
                width: 100%;
            }
            
            .calendar-day {
                min-height: 80px;
                font-size: 0.8rem;
                padding: 0.5rem;
            }
            
            .week-day {
                min-height: 100px;
            }
            
            .appointment-card {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .appointment-actions {
                margin-top: 0.5rem;
                width: 100%;
            }
            
            .appointment-actions .btn {
                flex: 1;
            }
            
            .event-details {
                grid-template-columns: 1fr;
            }
            
            .appointment-details {
                grid-template-columns: 1fr;
            }
            
            .modal-details {
                grid-template-columns: 1fr;
            }
            
            .empty-actions {
                flex-direction: column;
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
            <!-- Header con perfil de usuario y botón de atrás -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-calendar'></i>
                        Calendario de Citas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestiona y visualiza todas las citas programadas
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="agendarcita.php" class="btn btn-primary">
                        <i class='bx bx-plus'></i> Nueva Cita
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

            <!-- Mensajes de error o información -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-info fade-in">
                    <div class="alert-icon">
                        <i class='bx bx-info-circle'></i>
                    </div>
                    <div class="alert-content">
                        <strong>Información:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card" onclick="cambiarVista('mensual')">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['total_citas'] ?? 0; ?></div>
                        <div class="stat-label">Total de Citas</div>
                        <div class="stat-trend">
                            <i class='bx bx-calendar-check'></i>
                            En el sistema
                        </div>
                    </div>
                    
                    <div class="stat-card warning" onclick="cambiarVista('diaria')">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar-exclamation'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['citas_hoy'] ?? 0; ?></div>
                        <div class="stat-label">Citas para Hoy</div>
                        <div class="stat-trend">
                            <i class='bx bx-time'></i>
                            Requieren atención
                        </div>
                    </div>
                    
                    <div class="stat-card success" onclick="mostrarCitasPendientes()">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['citas_pendientes'] ?? 0; ?></div>
                        <div class="stat-label">Citas Pendientes</div>
                        <div class="stat-trend">
                            <i class='bx bx-hourglass'></i>
                            Por confirmar/realizar
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controles del Calendario -->
            <div class="calendar-controls fade-in">
                <div class="controls-header">
                    <h2>
                        <i class='bx bx-filter'></i>
                        Controles de Visualización
                    </h2>
                </div>
                <div class="controls-body">
                    <div class="view-controls">
                        <button class="btn-view <?php echo $vista_actual == 'mensual' ? 'active' : ''; ?>" onclick="cambiarVista('mensual')">
                            <i class='bx bx-calendar'></i> Mensual
                        </button>
                        <button class="btn-view <?php echo $vista_actual == 'semanal' ? 'active' : ''; ?>" onclick="cambiarVista('semanal')">
                            <i class='bx bx-calendar-week'></i> Semanal
                        </button>
                        <button class="btn-view <?php echo $vista_actual == 'diaria' ? 'active' : ''; ?>" onclick="cambiarVista('diaria')">
                            <i class='bx bx-calendar-event'></i> Diaria
                        </button>
                        <button class="btn-view <?php echo $vista_actual == 'pendientes' ? 'active' : ''; ?>" onclick="mostrarCitasPendientes()">
                            <i class='bx bx-list-check'></i> Pendientes
                        </button>
                    </div>

                    <div class="date-navigation">
                        <button class="btn btn-secondary btn-sm" onclick="navegarFecha('anterior')">
                            <i class='bx bx-chevron-left'></i>
                        </button>
                        
                        <div class="current-date">
                            <?php 
                            $meses = [
                                'January' => 'Enero', 'February' => 'Febrero', 'March' => 'Marzo',
                                'April' => 'Abril', 'May' => 'Mayo', 'June' => 'Junio',
                                'July' => 'Julio', 'August' => 'Agosto', 'September' => 'Septiembre',
                                'October' => 'Octubre', 'November' => 'Noviembre', 'December' => 'Diciembre'
                            ];
                            
                            if ($vista_actual == 'mensual') {
                                $mes_ingles = date('F', strtotime($mes_actual . '-01'));
                                $mes_espanol = $meses[$mes_ingles] ?? $mes_ingles;
                                $anio = date('Y', strtotime($mes_actual . '-01'));
                                echo $mes_espanol . ' ' . $anio;
                            } elseif ($vista_actual == 'semanal') {
                                $inicio_semana = date('d/m', strtotime('monday this week', strtotime($fecha_seleccionada)));
                                $fin_semana = date('d/m', strtotime('sunday this week', strtotime($fecha_seleccionada)));
                                echo "Semana del $inicio_semana al $fin_semana";
                            } elseif ($vista_actual == 'pendientes') {
                                echo "Citas Pendientes";
                            } else {
                                $dia = date('d', strtotime($fecha_seleccionada));
                                $mes_ingles = date('F', strtotime($fecha_seleccionada));
                                $mes_espanol = $meses[$mes_ingles] ?? $mes_ingles;
                                $anio = date('Y', strtotime($fecha_seleccionada));
                                $nombre_dia = obtenerNombreDia(date('N', strtotime($fecha_seleccionada)));
                                echo "$nombre_dia, $dia de $mes_espanol de $anio";
                            }
                            ?>
                        </div>
                        
                        <button class="btn btn-secondary btn-sm" onclick="navegarFecha('siguiente')">
                            <i class='bx bx-chevron-right'></i>
                        </button>
                        
                        <button class="btn btn-secondary btn-sm" onclick="irHoy()">
                            <i class='bx bx-current-location'></i> Hoy
                        </button>
                    </div>
                </div>
            </div>

            <!-- Vista del Calendario o Citas Pendientes -->
            <?php if ($vista_actual == 'pendientes'): ?>
                <!-- Vista de Citas Pendientes -->
                <div class="pending-appointments-view fade-in">
                    <div class="pending-header">
                        <h2>
                            <i class='bx bx-list-check'></i>
                            Citas Pendientes (<?php echo count($citas_pendientes_lista); ?>)
                        </h2>
                        <span class="badge badge-warning">Requieren atención</span>
                    </div>
                    
                    <div class="pending-body">
                        <?php if (!empty($citas_pendientes_lista)): ?>
                            <div class="appointments-list">
                                <?php foreach ($citas_pendientes_lista as $cita): ?>
                                    <?php 
                                    $clase_servicio = $cita['tipo_servicio'] ?? 'consultation';
                                    if (empty($clase_servicio)) {
                                        $clase_servicio = 'consultation';
                                    }
                                    ?>
                                    <div class="appointment-card <?php echo $clase_servicio; ?>" 
                                         onclick="mostrarDetallesCita(<?php echo htmlspecialchars(json_encode($cita), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <div class="appointment-info">
                                            <div class="appointment-time">
                                                <?php echo date('H:i', strtotime($cita['hora_cita'])); ?> - 
                                                <?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Cita'); ?>
                                            </div>
                                            <div class="appointment-date">
                                                <?php 
                                                $fecha = new DateTime($cita['fecha_cita']);
                                                echo $fecha->format('d/m/Y');
                                                ?>
                                            </div>
                                            <span class="appointment-service <?php echo $clase_servicio; ?>">
                                                <?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Cita'); ?>
                                            </span>
                                            
                                            <div class="appointment-details">
                                                <div>
                                                    <div class="appointment-detail-group">
                                                        <div class="appointment-detail-label">Mascota</div>
                                                        <div class="appointment-detail-value"><?php echo htmlspecialchars($cita['mascota_nombre'] ?? 'Sin mascota'); ?></div>
                                                    </div>
                                                    <div class="appointment-detail-group">
                                                        <div class="appointment-detail-label">Raza</div>
                                                        <div class="appointment-detail-value">
                                                            <?php echo htmlspecialchars($cita['mascota_raza'] ?? 'No especificada'); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="appointment-detail-group">
                                                        <div class="appointment-detail-label">Cliente</div>
                                                        <div class="appointment-detail-value"><?php echo htmlspecialchars($cita['cliente_nombre'] ?? 'Sin cliente'); ?></div>
                                                    </div>
                                                    <div class="appointment-detail-group">
                                                        <div class="appointment-detail-label">Contacto</div>
                                                        <div class="appointment-detail-value">
                                                            <?php echo htmlspecialchars($cita['cliente_telefono'] ?? 'Sin teléfono'); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="appointment-actions">
                                            <button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); editarCita(<?php echo $cita['id']; ?>)">
                                                <i class='bx bx-edit'></i> Editar
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-check-circle'></i>
                                <h3>No hay citas pendientes</h3>
                                <p>Todas las citas están completadas o no hay citas programadas.</p>
                                <div class="empty-actions">
                                    <a href="agendarcita.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i>
                                        Programar Nueva Cita
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            
            <?php else: ?>
                <!-- Vista del Calendario (Mensual, Semanal, Diaria) -->
                <div class="calendar-view fade-in">
                    <div class="view-header">
                        <h2>
                            <?php if ($vista_actual == 'mensual'): ?>
                                <i class='bx bx-calendar'></i> Vista Mensual
                            <?php elseif ($vista_actual == 'semanal'): ?>
                                <i class='bx bx-calendar-week'></i> Vista Semanal
                            <?php elseif ($vista_actual == 'diaria'): ?>
                                <i class='bx bx-calendar-event'></i> Vista Diaria
                            <?php endif; ?>
                        </h2>
                    </div>
                    
                    <div class="view-body">
                        <?php if ($vista_actual == 'mensual'): ?>
                            <!-- Vista Mensual -->
                            <div class="month-calendar">
                                <div class="calendar-header">
                                    <div class="calendar-header-day">Lunes</div>
                                    <div class="calendar-header-day">Martes</div>
                                    <div class="calendar-header-day">Miércoles</div>
                                    <div class="calendar-header-day">Jueves</div>
                                    <div class="calendar-header-day">Viernes</div>
                                    <div class="calendar-header-day">Sábado</div>
                                    <div class="calendar-header-day">Domingo</div>
                                </div>
                                
                                <?php foreach ($calendario_mensual as $semana): ?>
                                    <div class="calendar-week">
                                        <?php foreach ($semana as $dia): ?>
                                            <div class="calendar-day 
                                                <?php echo $dia['dia'] === null ? 'empty' : ''; ?>
                                                <?php echo ($dia['fecha'] ?? '') == date('Y-m-d') ? 'today' : ''; ?>
                                                <?php echo !empty($dia['citas']) ? 'has-events' : ''; ?>"
                                                <?php if ($dia['dia'] !== null): ?>
                                                    onclick="seleccionarDia('<?php echo $dia['fecha']; ?>')"
                                                <?php endif; ?>>
                                                
                                                <?php if ($dia['dia'] !== null): ?>
                                                    <div class="day-number"><?php echo $dia['dia']; ?></div>
                                                    <div class="day-events">
                                                        <?php foreach (array_slice($dia['citas'], 0, 3) as $cita): ?>
                                                            <?php 
                                                            $clase_servicio = $cita['tipo_servicio'] ?? 'consultation';
                                                            if (empty($clase_servicio)) {
                                                                $clase_servicio = 'consultation';
                                                            }
                                                            ?>
                                                            <div class="event-item <?php echo $clase_servicio; ?>"
                                                                 onclick="event.stopPropagation(); mostrarDetallesCita(<?php echo htmlspecialchars(json_encode($cita), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                                 title="<?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Cita'); ?> - <?php echo htmlspecialchars($cita['mascota_nombre'] ?? ''); ?>">
                                                                <div class="event-time">
                                                                    <?php echo date('H:i', strtotime($cita['hora_cita'])); ?>
                                                                </div>
                                                                <div class="event-pet">
                                                                    <?php echo htmlspecialchars($cita['mascota_nombre'] ?? 'Sin mascota'); ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php if (count($dia['citas']) > 3): ?>
                                                            <div class="event-item other" 
                                                                 onclick="event.stopPropagation(); seleccionarDia('<?php echo $dia['fecha']; ?>')">
                                                                <div class="event-time">+<?php echo count($dia['citas']) - 3; ?> más</div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        
                        <?php elseif ($vista_actual == 'semanal' && !empty($calendario_semanal)): ?>
                            <!-- Vista Semanal -->
                            <div class="week-calendar">
                                <div class="week-header">
                                    <?php foreach ($calendario_semanal as $dia): ?>
                                        <div class="week-day-header">
                                            <?php echo $dia['nombre_dia']; ?><br>
                                            <span style="font-size: 1.2rem;"><?php echo $dia['dia']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="week-days">
                                    <?php foreach ($calendario_semanal as $dia): ?>
                                        <div class="week-day 
                                            <?php echo $dia['fecha'] == date('Y-m-d') ? 'today' : ''; ?>
                                            <?php echo $dia['fecha'] == $fecha_seleccionada ? 'selected' : ''; ?>"
                                            onclick="seleccionarDia('<?php echo $dia['fecha']; ?>')">
                                            
                                            <div class="week-day-number"><?php echo $dia['dia']; ?></div>
                                            <div class="week-day-name"><?php echo $dia['nombre_dia']; ?></div>
                                            
                                            <div class="week-events">
                                                <?php foreach (array_slice($dia['citas'], 0, 3) as $cita): ?>
                                                    <?php 
                                                    $clase_servicio = $cita['tipo_servicio'] ?? 'consultation';
                                                    if (empty($clase_servicio)) {
                                                        $clase_servicio = 'consultation';
                                                    }
                                                    ?>
                                                    <div class="event-item <?php echo $clase_servicio; ?>"
                                                         onclick="event.stopPropagation(); mostrarDetallesCita(<?php echo htmlspecialchars(json_encode($cita), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                         title="<?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Cita'); ?> - <?php echo htmlspecialchars($cita['mascota_nombre'] ?? ''); ?>">
                                                        <div class="event-time">
                                                            <?php echo date('H:i', strtotime($cita['hora_cita'])); ?>
                                                        </div>
                                                        <div class="event-pet">
                                                            <?php echo htmlspecialchars($cita['mascota_nombre'] ?? ''); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($dia['citas']) > 3): ?>
                                                    <div class="event-item other">
                                                        <div class="event-time">+<?php echo count($dia['citas']) - 3; ?> más</div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        
                        <?php elseif ($vista_actual == 'diaria' && !empty($calendario_diario)): ?>
                            <!-- Vista Diaria -->
                            <div class="day-calendar">
                                <div class="day-header">
                                    <h2 class="day-title"><?php echo $calendario_diario['nombre_dia']; ?></h2>
                                    <p class="day-subtitle"><?php echo date('d/m/Y', strtotime($calendario_diario['fecha'])); ?></p>
                                </div>
                                
                                <?php if (!empty($calendario_diario['citas'])): ?>
                                    <div class="day-events-list">
                                        <?php foreach ($calendario_diario['citas'] as $cita): ?>
                                            <?php 
                                            $clase_servicio = $cita['tipo_servicio'] ?? 'consultation';
                                            if (empty($clase_servicio)) {
                                                $clase_servicio = 'consultation';
                                            }
                                            ?>
                                            <div class="day-event-item <?php echo $clase_servicio; ?>"
                                                 onclick="mostrarDetallesCita(<?php echo htmlspecialchars(json_encode($cita), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <div class="event-header">
                                                    <div class="event-time-large">
                                                        <?php echo date('H:i', strtotime($cita['hora_cita'])); ?>
                                                    </div>
                                                    <div class="event-service <?php echo $clase_servicio; ?>">
                                                        <?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Cita'); ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="event-details">
                                                    <div>
                                                        <div class="event-detail-group">
                                                            <div class="event-detail-label">Mascota</div>
                                                            <div class="event-detail-value"><?php echo htmlspecialchars($cita['mascota_nombre'] ?? 'Sin mascota'); ?></div>
                                                        </div>
                                                        <div class="event-detail-group">
                                                            <div class="event-detail-label">Raza</div>
                                                            <div class="event-detail-value">
                                                                <?php echo htmlspecialchars($cita['mascota_raza'] ?? 'No especificada'); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="event-detail-group">
                                                            <div class="event-detail-label">Cliente</div>
                                                            <div class="event-detail-value"><?php echo htmlspecialchars($cita['cliente_nombre'] ?? 'Sin cliente'); ?></div>
                                                        </div>
                                                        <div class="event-detail-group">
                                                            <div class="event-detail-label">Estado</div>
                                                            <div class="event-detail-value">
                                                                <?php 
                                                                $estados = [
                                                                    'scheduled' => 'Programada',
                                                                    'confirmed' => 'Confirmada',
                                                                    'completed' => 'Completada',
                                                                    'cancelled' => 'Cancelada'
                                                                ];
                                                                echo $estados[$cita['estado']] ?? $cita['estado'] ?? 'Programada'; 
                                                                ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class='bx bx-calendar-x'></i>
                                        <h3>No hay citas programadas para este día</h3>
                                        <p>Puedes agregar una nueva cita usando el botón "Nueva Cita"</p>
                                        <div class="empty-actions">
                                            <a href="agendarcita.php" class="btn btn-primary">
                                                <i class='bx bx-plus'></i>
                                                Programar Nueva Cita
                                            </a>
                                            <button class="btn btn-secondary" onclick="irHoy()">
                                                <i class='bx bx-current-location'></i>
                                                Ir a Hoy
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($todas_las_citas) && $vista_actual == 'mensual'): ?>
                <!-- Mensaje de bienvenida cuando no hay citas -->
                <div class="empty-state fade-in">
                    <i class='bx bx-calendar-plus'></i>
                    <h3>Comienza a Programar Citas</h3>
                    <p>Tu calendario está listo. Agrega la primera cita para comenzar a organizar tu agenda.</p>
                    <div class="empty-actions">
                        <a href="agendarcita.php" class="btn btn-primary">
                            <i class='bx bx-plus'></i>
                            Programar Primera Cita
                        </a>
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

    <!-- Modal de detalles de cita -->
    <div class="modal-overlay" id="modalDetalles">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Detalles de la Cita</h3>
                <button class="modal-close" onclick="cerrarModal()">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Los detalles de la cita se cargarán aquí dinámicamente -->
            </div>
        </div>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        function cambiarVista(vista) {
            const url = new URL(window.location);
            url.searchParams.set('vista', vista);
            
            // Si cambiamos a vista diaria, seleccionar hoy por defecto
            if (vista === 'diaria' && !url.searchParams.has('fecha')) {
                url.searchParams.set('fecha', '<?php echo date("Y-m-d"); ?>');
            }
            
            window.location.href = url.toString();
        }

        function mostrarCitasPendientes() {
            const url = new URL(window.location);
            url.searchParams.set('vista', 'pendientes');
            window.location.href = url.toString();
        }

        function navegarFecha(direccion) {
            const url = new URL(window.location);
            const vista = '<?php echo $vista_actual; ?>';
            
            if (vista === 'mensual') {
                let mesActual = '<?php echo $mes_actual; ?>';
                const fecha = new Date(mesActual + '-01');
                
                if (direccion === 'siguiente') {
                    fecha.setMonth(fecha.getMonth() + 1);
                } else {
                    fecha.setMonth(fecha.getMonth() - 1);
                }
                const nuevoMes = fecha.toISOString().slice(0, 7);
                url.searchParams.set('mes', nuevoMes);
            } else if (vista === 'semanal' || vista === 'diaria') {
                let fechaActual = '<?php echo $fecha_seleccionada; ?>';
                const fecha = new Date(fechaActual);
                
                if (vista === 'semanal') {
                    if (direccion === 'siguiente') {
                        fecha.setDate(fecha.getDate() + 7);
                    } else {
                        fecha.setDate(fecha.getDate() - 7);
                    }
                } else {
                    if (direccion === 'siguiente') {
                        fecha.setDate(fecha.getDate() + 1);
                    } else {
                        fecha.setDate(fecha.getDate() - 1);
                    }
                }
                
                const nuevaFecha = fecha.toISOString().slice(0, 10);
                url.searchParams.set('fecha', nuevaFecha);
            }
            
            window.location.href = url.toString();
        }

        function irHoy() {
            const url = new URL(window.location);
            const vista = '<?php echo $vista_actual; ?>';
            
            if (vista === 'mensual') {
                url.searchParams.set('mes', '<?php echo date("Y-m"); ?>');
            } else if (vista === 'pendientes') {
                // En vista pendientes, no hacer nada
                return;
            } else {
                url.searchParams.set('fecha', '<?php echo date("Y-m-d"); ?>');
            }
            
            window.location.href = url.toString();
        }

        function seleccionarDia(fecha) {
            const url = new URL(window.location);
            url.searchParams.set('fecha', fecha);
            url.searchParams.set('vista', 'diaria');
            window.location.href = url.toString();
        }

        function mostrarDetallesCita(cita) {
            // Formatear la fecha y hora
            const fecha = new Date(cita.fecha_cita + 'T' + cita.hora_cita);
            const fechaFormateada = fecha.toLocaleDateString('es-ES', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const horaFormateada = fecha.toLocaleTimeString('es-ES', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Mapear estados
            const estados = {
                'scheduled': 'Programada',
                'confirmed': 'Confirmada',
                'completed': 'Completada',
                'cancelled': 'Cancelada'
            };
            
            // Determinar clase CSS según el tipo de servicio
            let claseServicio = 'consultation';
            if (cita.tipo_servicio) {
                claseServicio = cita.tipo_servicio;
            }
            
            // Crear el contenido del modal
            const modalBody = document.getElementById('modalBody');
            modalBody.innerHTML = `
                <div class="modal-details">
                    <div>
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Fecha y Hora</div>
                            <div class="modal-detail-value">${fechaFormateada} a las ${horaFormateada}</div>
                        </div>
                        
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Servicio</div>
                            <div class="modal-detail-value">
                                <span class="event-service ${claseServicio}">${cita.servicio_nombre || 'Cita'}</span>
                            </div>
                        </div>
                        
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Estado</div>
                            <div class="modal-detail-value">${estados[cita.estado] || cita.estado || 'Programada'}</div>
                        </div>
                        
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Notas</div>
                            <div class="modal-detail-value">${cita.notas || 'Sin notas adicionales'}</div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Mascota</div>
                            <div class="modal-detail-value">${cita.mascota_nombre || 'Sin mascota'}</div>
                        </div>
                        
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Raza</div>
                            <div class="modal-detail-value">
                                ${cita.mascota_raza || 'No especificada'}
                            </div>
                        </div>
                        
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Cliente</div>
                            <div class="modal-detail-value">${cita.cliente_nombre || 'Sin cliente'}</div>
                        </div>
                        
                        <div class="modal-detail-group">
                            <div class="modal-detail-label">Contacto</div>
                            <div class="modal-detail-value">
                                ${cita.cliente_telefono || 'Sin teléfono'} ${cita.cliente_email ? '<br>' + cita.cliente_email : ''}
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button class="btn btn-secondary" onclick="cerrarModal()">
                        <i class='bx bx-x'></i> Cerrar
                    </button>
                    <button class="btn btn-primary" onclick="editarCita(${cita.id})">
                        <i class='bx bx-edit'></i> Editar Cita
                    </button>
                </div>
            `;
            
            // Mostrar el modal
            document.getElementById('modalDetalles').classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalDetalles').classList.remove('active');
        }

        function editarCita(citaId) {
            window.location.href = 'editar_cita.php?id=' + citaId;
        }

        // Cerrar modal al hacer clic fuera de él
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('modalDetalles');
            if (event.target === modal) {
                cerrarModal();
            }
        });

        // Efectos interactivos para el calendario
        document.addEventListener('DOMContentLoaded', function() {
            const days = document.querySelectorAll('.calendar-day:not(.empty), .week-day');
            
            days.forEach(day => {
                day.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.02)';
                    this.style.zIndex = '2';
                });
                
                day.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                    this.style.zIndex = '1';
                });
            });

            // Hacer clic en estadísticas
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });

            // Animación de entrada para secciones
            const sections = document.querySelectorAll('.fade-in');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100 + 200);
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