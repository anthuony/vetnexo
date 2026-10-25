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

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas'); // UTC-4

// Inicializar variables
$citas = [];
$estadisticas = [
    'total' => 0,
    'scheduled' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'cancelled' => 0
];
$error = '';
$mensaje = '';

// Obtener fecha (hoy por defecto, o fecha específica si se proporciona)
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$fecha_formateada = date('d/m/Y', strtotime($fecha));

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDBConnection();
        
        if (isset($_POST['completar_cita'])) {
            $cita_id = $_POST['cita_id'];
            $sql = "UPDATE citas SET estado = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cita_id]);
            $mensaje = "Cita marcada como completada correctamente.";
        }
        
        if (isset($_POST['cancelar_cita'])) {
            $cita_id = $_POST['cita_id'];
            $sql = "UPDATE citas SET estado = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cita_id]);
            $mensaje = "Cita cancelada correctamente.";
        }
        
        if (isset($_POST['confirmar_cita'])) {
            $cita_id = $_POST['cita_id'];
            $sql = "UPDATE citas SET estado = 'confirmed', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cita_id]);
            $mensaje = "Cita confirmada correctamente.";
        }
        
        if (isset($_POST['iniciar_cita'])) {
            $cita_id = $_POST['cita_id'];
            $sql = "UPDATE citas SET estado = 'in_progress', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cita_id]);
            $mensaje = "Cita marcada como en progreso.";
        }
        
    } catch (PDOException $e) {
        $error = "Error al procesar la acción: " . $e->getMessage();
    }
}

try {
    // Conexión centralizada para hosting
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
    
    // Obtener citas del día específico
    $citas_sql = "
        SELECT c.*, 
               m.nombre as mascota_nombre,
               m.raza as mascota_raza,
               m.peso as mascota_peso,
               m.sexo as mascota_sexo,
               cl.nombre as cliente_nombre,
               cl.telefono as cliente_telefono,
               cl.email as cliente_email,
               s.nombre as servicio_nombre,
               s.categoria as tipo_servicio,
               s.precio as servicio_precio,
               TIMEDIFF(c.hora_cita, CURTIME()) as tiempo_restante
        FROM citas c
        LEFT JOIN mascotas m ON c.mascota_id = m.id
        LEFT JOIN clientes cl ON m.cliente_id = cl.id
        LEFT JOIN servicios s ON c.servicio_id = s.id
        WHERE DATE(c.fecha_cita) = ?
        ORDER BY c.hora_cita ASC
    ";
    
    $citas_stmt = $pdo->prepare($citas_sql);
    $citas_stmt->execute([$fecha]);
    $citas = $citas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas
    $estadisticas['total'] = count($citas);
    
    foreach ($citas as $cita) {
        switch ($cita['estado']) {
            case 'scheduled':
                $estadisticas['scheduled']++;
                break;
            case 'confirmed':
                $estadisticas['confirmed']++;
                break;
            case 'completed':
                $estadisticas['completed']++;
                break;
            case 'in_progress':
                $estadisticas['in_progress']++;
                break;
            case 'cancelled':
                $estadisticas['cancelled']++;
                break;
        }
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Función para formatear el tiempo restante
function formatearTiempoRestante($tiempo_restante) {
    if ($tiempo_restante === null) return '';
    
    $signo = substr($tiempo_restante, 0, 1);
    if ($signo === '-') {
        return 'En curso';
    }
    
    list($horas, $minutos, $segundos) = explode(':', $tiempo_restante);
    $horas = (int)$horas;
    $minutos = (int)$minutos;
    
    if ($horas > 0) {
        return "En $horas h $minutos min";
    } elseif ($minutos > 0) {
        return "En $minutos min";
    } else {
        return 'Próxima';
    }
}

// Función para determinar la urgencia
function determinarUrgencia($tiempo_restante, $estado) {
    if ($estado === 'completed') return 'completada';
    if ($estado === 'cancelled') return 'cancelada';
    if ($estado === 'in_progress') return 'en_curso';
    
    if ($tiempo_restante === null) return 'normal';
    
    $signo = substr($tiempo_restante, 0, 1);
    if ($signo === '-') return 'en_curso';
    
    list($horas, $minutos, $segundos) = explode(':', $tiempo_restante);
    $minutos_totales = ((int)$horas * 60) + (int)$minutos;
    
    if ($minutos_totales <= 30) return 'urgente';
    if ($minutos_totales <= 60) return 'proxima';
    
    return 'normal';
}

// Función para obtener el nombre del estado en español
function obtenerEstadoEspanol($estado) {
    $estados = [
        'scheduled' => 'Programada',
        'confirmed' => 'Confirmada',
        'in_progress' => 'En Progreso',
        'completed' => 'Completada',
        'cancelled' => 'Cancelada',
        'no_show' => 'No Presentado'
    ];
    
    return $estados[$estado] ?? $estado;
}

// Función para obtener la clase CSS del estado (usando colores de la marca)
function obtenerClaseEstado($estado) {
    $clases = [
        'scheduled' => 'warning',
        'confirmed' => 'info',
        'in_progress' => 'primary',
        'completed' => 'success',
        'cancelled' => 'secondary'
    ];
    
    return $clases[$estado] ?? 'secondary';
}

// Función para obtener la clase CSS del servicio (usando colores de la marca)
function obtenerClaseServicio($tipo_servicio) {
    $clases = [
        'consultation' => 'primary',
        'vaccination' => 'success',
        'surgery' => 'danger',
        'emergency' => 'danger',
        'grooming' => 'info',
        'laboratory' => 'warning',
        'other' => 'secondary'
    ];
    
    return $clases[$tipo_servicio] ?? 'secondary';
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
    <title>Citas del Día - VetNexo</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
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

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            border-color: var(--azul);
            color: #0c5460;
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

        .alert-info .alert-icon {
            color: var(--azul);
        }

        /* Navegación de Fecha */
        .date-navigation {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .date-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .date-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .date-body {
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .current-date {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .date-picker-container {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date-picker-input {
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            color: var(--text-dark);
            background: var(--white);
            width: 180px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .date-picker-input:hover,
        .date-picker-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.1);
        }

        .date-picker-btn {
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date-picker-btn:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            text-align: center;
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

        .stat-card.info::before {
            background: linear-gradient(90deg, var(--azul), var(--azul-light));
        }

        .stat-card.success::before {
            background: linear-gradient(90deg, var(--success), var(--primary));
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--emergency), #c00f02);
        }

        .stat-card.secondary::before {
            background: linear-gradient(90deg, #6c757d, #495057);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.warning .stat-number {
            color: var(--accent-dark);
        }

        .stat-card.info .stat-number {
            color: var(--azul);
        }

        .stat-card.success .stat-number {
            color: var(--primary-dark);
        }

        .stat-card.danger .stat-number {
            color: var(--emergency);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Lista de citas */
        .citas-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .cita-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
            position: relative;
        }

        .cita-card:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .cita-card.urgente {
            border-left: 4px solid var(--emergency);
        }

        .cita-card.proxima {
            border-left: 4px solid var(--accent);
        }

        .cita-card.en_curso {
            border-left: 4px solid var(--azul);
        }

        .cita-card.completada {
            border-left: 4px solid var(--success);
            opacity: 0.8;
        }

        .cita-card.cancelada {
            border-left: 4px solid var(--text-lighter);
            opacity: 0.6;
        }

        .cita-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .cita-time {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .time-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .time-badge.urgente {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
        }

        .time-badge.proxima {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
        }

        .time-badge.en_curso {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
        }

        .time-badge.completada {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
        }

        .tiempo-restante {
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 600;
            padding: 0.3rem 0.8rem;
            background: var(--primary-soft);
            border-radius: 15px;
        }

        .cita-status {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .badge-success {
            background: #e8f5e9;
            color: #0F6C33;
        }

        .badge-warning {
            background: #fff3e0;
            color: #F99B07;
        }

        .badge-danger {
            background: #ffebee;
            color: #F91302;
        }

        .badge-info {
            background: #e3f2fd;
            color: #2261BD;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .badge-secondary {
            background: #f5f5f5;
            color: #5A4B37;
        }

        /* Cita Content */
        .cita-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .cita-info h3 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-dark);
            font-size: 1.2rem;
        }

        .cita-info p {
            margin: 0.25rem 0;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .cita-details {
            display: grid;
            gap: 0.5rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.3rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .detail-value {
            color: var(--text-light);
            text-align: right;
            font-size: 0.85rem;
        }

        /* Cita Actions */
        .cita-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--primary-soft);
            flex-wrap: wrap;
        }

        .action-form {
            display: inline;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            text-align: center;
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
            font-size: 1.2rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
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
                grid-template-columns: repeat(3, 1fr);
            }
            
            .cita-content {
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
            
            .header-actions .btn,
            .header-actions .back-btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .date-body {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-controls {
                flex-direction: column;
                width: 100%;
            }
            
            .date-picker-container {
                width: 100%;
            }
            
            .date-picker-input {
                width: 100%;
            }
            
            .cita-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .cita-actions {
                flex-direction: column;
            }
            
            .action-form {
                width: 100%;
            }
            
            .action-form .btn {
                width: 100%;
            }
            
            .empty-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .cita-time {
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
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-calendar-check'></i>
                        Citas del Día
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Resumen y gestión de citas programadas
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="agendarcita.php" class="btn btn-primary">
                        <i class='bx bx-plus'></i> Nueva Cita
                    </a>
                    <a href="calendario.php" class="btn btn-info">
                        <i class='bx bx-calendar'></i> Ver Calendario
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
            <?php if (!empty($mensaje)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo htmlspecialchars($mensaje); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Navegación de Fecha -->
            <div class="date-navigation fade-in">
                <div class="date-header">
                    <h2>
                        <i class='bx bx-calendar'></i>
                        Seleccionar Fecha
                    </h2>
                </div>
                <div class="date-body">
                    <div class="current-date">
                        <i class='bx bx-calendar'></i>
                        <?php echo $fecha_formateada; ?>
                    </div>
                    <div class="date-controls">
                        <button class="btn btn-secondary btn-sm" onclick="cambiarFecha('anterior')">
                            <i class='bx bx-chevron-left'></i> Ayer
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="irHoy()">
                            <i class='bx bx-current-location'></i> Hoy
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="cambiarFecha('siguiente')">
                            Mañana <i class='bx bx-chevron-right'></i>
                        </button>
                        
                        <div class="date-picker-container">
                            <input type="date" 
                                   id="datePicker" 
                                   class="date-picker-input" 
                                   value="<?php echo $fecha; ?>"
                                   max="<?php echo date('Y-m-d', strtotime('+1 year')); ?>"
                                   min="<?php echo date('Y-m-d', strtotime('-5 years')); ?>">
                            <button class="date-picker-btn btn-sm" onclick="irAFechaSeleccionada()">
                                <i class='bx bx-calendar-check'></i> Ir
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['total']; ?></div>
                        <div class="stat-label">Total Citas</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-number"><?php echo $estadisticas['scheduled']; ?></div>
                        <div class="stat-label">Programadas</div>
                    </div>
                    
                    <div class="stat-card info">
                        <div class="stat-number"><?php echo $estadisticas['confirmed']; ?></div>
                        <div class="stat-label">Confirmadas</div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-number"><?php echo $estadisticas['completed']; ?></div>
                        <div class="stat-label">Completadas</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-number"><?php echo $estadisticas['in_progress']; ?></div>
                        <div class="stat-label">En Progreso</div>
                    </div>

                    <div class="stat-card secondary">
                        <div class="stat-number"><?php echo $estadisticas['cancelled']; ?></div>
                        <div class="stat-label">Canceladas</div>
                    </div>
                </div>
            </div>

            <!-- Lista de Citas -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-list-check'></i>
                        Citas para <?php echo $fecha_formateada; ?>
                    </h2>
                    <span class="badge badge-info"><?php echo $estadisticas['total']; ?> citas</span>
                </div>
                
                <div class="section-body">
                    <div class="citas-list">
                        <?php if (!empty($citas)): ?>
                            <?php foreach ($citas as $cita): 
                                $urgencia = determinarUrgencia($cita['tiempo_restante'], $cita['estado']);
                                $tiempo_formateado = formatearTiempoRestante($cita['tiempo_restante']);
                                $estado_espanol = obtenerEstadoEspanol($cita['estado']);
                                $clase_estado = obtenerClaseEstado($cita['estado']);
                                $clase_servicio = obtenerClaseServicio($cita['tipo_servicio']);
                            ?>
                                <div class="cita-card <?php echo $urgencia; ?>">
                                    <div class="cita-header">
                                        <div class="cita-time">
                                            <span class="time-badge <?php echo $urgencia; ?>">
                                                <?php echo date('H:i', strtotime($cita['hora_cita'])); ?>
                                            </span>
                                            <?php if ($tiempo_formateado): ?>
                                            <span class="tiempo-restante">
                                                <i class='bx bx-time'></i>
                                                <?php echo $tiempo_formateado; ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="cita-status">
                                            <span class="badge badge-<?php echo $clase_estado; ?>">
                                                <?php echo $estado_espanol; ?>
                                            </span>
                                            <span class="badge badge-<?php echo $clase_servicio; ?>">
                                                <?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Consulta'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="cita-content">
                                        <div class="cita-info">
                                            <h3>
                                                <i class='bx bx-paw'></i>
                                                <?php echo htmlspecialchars($cita['mascota_nombre']); ?>
                                            </h3>
                                            <p><strong>Raza:</strong> <?php echo htmlspecialchars($cita['mascota_raza'] ?? 'No especificada'); ?></p>
                                            <p><strong>Sexo:</strong> <?php 
                                                $sexo = $cita['mascota_sexo'] ?? '';
                                                if (in_array(strtolower((string)$sexo), ['macho','m'], true)) echo 'Macho';
                                                elseif (in_array(strtolower((string)$sexo), ['hembra','h'], true)) echo 'Hembra';
                                                else echo 'No especificado';
                                            ?></p>
                                            <?php if (!empty($cita['mascota_peso'])): ?>
                                            <p><strong>Peso:</strong> <?php echo htmlspecialchars($cita['mascota_peso']); ?> kg</p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="cita-details">
                                            <div class="detail-item">
                                                <span class="detail-label">Dueño:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($cita['cliente_nombre']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Teléfono:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($cita['cliente_telefono']); ?></span>
                                            </div>
                                            <?php if (!empty($cita['cliente_email'])): ?>
                                            <div class="detail-item">
                                                <span class="detail-label">Email:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($cita['cliente_email']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="detail-item">
                                                <span class="detail-label">Precio:</span>
                                                <span class="detail-value">$<?php echo number_format($cita['servicio_precio'] ?? 0, 2); ?></span>
                                            </div>
                                            <?php if (!empty($cita['notas'])): ?>
                                            <div class="detail-item">
                                                <span class="detail-label">Notas:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars(substr($cita['notas'], 0, 50)) . (strlen($cita['notas']) > 50 ? '...' : ''); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="cita-actions">
                                        <?php if ($cita['estado'] === 'scheduled'): ?>
                                            <form method="POST" class="action-form"><?php echo av_csrf_field(); ?>
                                                <input type="hidden" name="cita_id" value="<?php echo $cita['id']; ?>">
                                                <button type="submit" name="confirmar_cita" class="btn btn-info btn-sm">
                                                    <i class='bx bx-check-circle'></i> Confirmar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($cita['estado'], ['scheduled', 'confirmed'])): ?>
                                            <form method="POST" class="action-form"><?php echo av_csrf_field(); ?>
                                                <input type="hidden" name="cita_id" value="<?php echo $cita['id']; ?>">
                                                <button type="submit" name="iniciar_cita" class="btn btn-primary btn-sm">
                                                    <i class='bx bx-play-circle'></i> Iniciar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($cita['estado'] === 'in_progress'): ?>
                                            <form method="POST" class="action-form"><?php echo av_csrf_field(); ?>
                                                <input type="hidden" name="cita_id" value="<?php echo $cita['id']; ?>">
                                                <button type="submit" name="completar_cita" class="btn btn-success btn-sm">
                                                    <i class='bx bx-check'></i> Completar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if (!in_array($cita['estado'], ['completed', 'cancelled'])): ?>
                                            <form method="POST" class="action-form"><?php echo av_csrf_field(); ?>
                                                <input type="hidden" name="cita_id" value="<?php echo $cita['id']; ?>">
                                                <button type="submit" name="cancelar_cita" class="btn btn-danger btn-sm" onclick="return confirm('¿Está seguro de que desea cancelar esta cita?')">
                                                    <i class='bx bx-x'></i> Cancelar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <a href="editar_cita.php?id=<?php echo $cita['id']; ?>" class="btn btn-warning btn-sm">
                                            <i class='bx bx-edit'></i> Editar
                                        </a>
                                        
                                        <a href="historial.php?id=<?php echo $cita['mascota_id']; ?>" class="btn btn-secondary btn-sm">
                                            <i class='bx bx-show'></i> Historial
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <h3>No hay citas para este día</h3>
                                <p>No se han encontrado citas programadas para la fecha <?php echo $fecha_formateada; ?>.</p>
                                <div class="empty-actions">
                                    <a href="agendarcita.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i>
                                        Agendar Nueva Cita
                                    </a>
                                    <button class="btn btn-secondary" onclick="irHoy()">
                                        <i class='bx bx-current-location'></i>
                                        Ver Hoy
                                    </button>
                                </div>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de contadores para estadísticas
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseInt(text.replace(/[^0-9]/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = text.replace(number, Math.floor(current));
                    }, 30);
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

            // Animación de aparición para las citas
            const citas = document.querySelectorAll('.cita-card');
            citas.forEach((cita, index) => {
                cita.style.opacity = '0';
                cita.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    cita.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    cita.style.opacity = '1';
                    cita.style.transform = 'translateY(0)';
                }, index * 100 + 200);
            });
        });

        function cambiarFecha(direccion) {
            const url = new URL(window.location);
            let fechaActual = '<?php echo $fecha; ?>';
            const fecha = new Date(fechaActual);
            
            if (direccion === 'siguiente') {
                fecha.setDate(fecha.getDate() + 1);
            } else {
                fecha.setDate(fecha.getDate() - 1);
            }
            
            const nuevaFecha = fecha.toISOString().slice(0, 10);
            url.searchParams.set('fecha', nuevaFecha);
            window.location.href = url.toString();
        }

        function irHoy() {
            const url = new URL(window.location);
            url.searchParams.set('fecha', '<?php echo date("Y-m-d"); ?>');
            window.location.href = url.toString();
        }

        function irAFechaSeleccionada() {
            const datePicker = document.getElementById('datePicker');
            const fechaSeleccionada = datePicker.value;
            
            if (fechaSeleccionada) {
                const url = new URL(window.location);
                url.searchParams.set('fecha', fechaSeleccionada);
                window.location.href = url.toString();
            }
        }

        // Permitir presionar Enter en el date picker
        document.getElementById('datePicker').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                irAFechaSeleccionada();
            }
        });

        // Mostrar un mensaje cuando el usuario seleccione una fecha futura muy lejana
        document.getElementById('datePicker').addEventListener('change', function() {
            const fechaSeleccionada = new Date(this.value);
            const hoy = new Date();
            const diferenciaDias = Math.floor((fechaSeleccionada - hoy) / (1000 * 60 * 60 * 24));
            
            if (diferenciaDias > 30) {
                if (confirm(`Has seleccionado una fecha ${diferenciaDias} días en el futuro. ¿Deseas continuar?`)) {
                    irAFechaSeleccionada();
                } else {
                    this.value = '<?php echo $fecha; ?>';
                }
            } else {
                // Si la fecha es válida, ir directamente
                irAFechaSeleccionada();
            }
        });

        // Auto-refresh cada 5 minutos para ver citas en tiempo real
        let refreshInterval = setInterval(() => {
            window.location.reload();
        }, 300000); // 5 minutos

        // Limpiar intervalo cuando se navega fuera
        window.addEventListener('beforeunload', function() {
            clearInterval(refreshInterval);
        });
    </script>
</body>
</html>