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

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas'); // UTC-4

// Incluir configuración de base de datos
include 'config/database.php';

// Inicializar variables
$db = null;
$mascotas = [];
$veterinarios = [];
$servicios = [];
$success = false;
$error = '';

// Obtener parámetros si viene de una mascota específica
$mascota_id_param = $_GET['mascota_id'] ?? '';

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
    
    // Obtener lista de mascotas con más información
    $mascotas_sql = "
        SELECT m.id, m.nombre, m.especie_id, m.raza, m.sexo, m.fecha_nacimiento,
               e.nombre as especie_nombre, 
               c.id as cliente_id, c.nombre as cliente_nombre, c.telefono as cliente_telefono
        FROM mascotas m 
        LEFT JOIN especies e ON m.especie_id = e.id 
        LEFT JOIN clientes c ON m.cliente_id = c.id 
        WHERE m.id IS NOT NULL
        ORDER BY m.nombre";
    $mascotas_stmt = $db->query($mascotas_sql);
    $mascotas = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener lista de veterinarios activos
    $veterinarios_sql = "
        SELECT id, nombre, email, telefono 
        FROM usuarios 
        WHERE rol IN ('veterinarian', 'veterinario', 'admin') 
        AND estado = 'active' 
        ORDER BY nombre";
    $veterinarios_stmt = $db->query($veterinarios_sql);
    $veterinarios = $veterinarios_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener lista de servicios disponibles
    $servicios_sql = "
        SELECT id, nombre, categoria, precio, duracion, descripcion 
        FROM servicios 
        WHERE activo = 1 
        ORDER BY categoria, nombre";
    $servicios_stmt = $db->query($servicios_sql);
    $servicios = $servicios_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar servicios por categoría
    $servicios_por_categoria = [];
    foreach ($servicios as $servicio) {
        $categoria = $servicio['categoria'] ?? 'otros';
        if (!isset($servicios_por_categoria[$categoria])) {
            $servicios_por_categoria[$categoria] = [];
        }
        $servicios_por_categoria[$categoria][] = $servicio;
    }
    
    // Verificar si hay solo un veterinario para selección automática
    $veterinario_por_defecto = '';
    if (count($veterinarios) === 1) {
        $veterinario_por_defecto = $veterinarios[0]['id'];
    }
    
    // Procesar el formulario cuando se envía
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Iniciar transacción
        $db->beginTransaction();
        
        // Validar y sanitizar datos
        $mascota_id = filter_input(INPUT_POST, 'mascota_id', FILTER_VALIDATE_INT);
        $fecha_cita = trim(strip_tags((string)($_POST['fecha_cita']??'')));
        $hora_cita = trim(strip_tags((string)($_POST['hora_cita']??'')));
        $servicio_id = filter_input(INPUT_POST, 'servicio_id', FILTER_VALIDATE_INT);
        $veterinario_id = filter_input(INPUT_POST, 'veterinario_id', FILTER_VALIDATE_INT);
        $motivo = trim(trim(strip_tags((string)($_POST['motivo']??''))));
        $sintomas = trim(trim(strip_tags((string)($_POST['sintomas']??''))));
        $observaciones = trim(trim(strip_tags((string)($_POST['observaciones']??''))));
        $prioridad = trim(strip_tags((string)($_POST['prioridad']??'')));
        $duracion_estimada = trim(strip_tags((string)($_POST['duracion_estimada']??'')));
        
        // Validaciones
        if (!$mascota_id) {
            throw new Exception("Debe seleccionar una mascota válida.");
        }
        
        if (empty($fecha_cita)) {
            throw new Exception("La fecha de la cita es obligatoria.");
        }
        
        if (empty($hora_cita)) {
            throw new Exception("La hora de la cita es obligatoria.");
        }
        
        if (!$servicio_id) {
            throw new Exception("Debe seleccionar un servicio.");
        }
        
        if (empty($motivo)) {
            throw new Exception("El motivo de la cita es obligatorio.");
        }
        
        // Validar que la fecha y hora no sean en el pasado
        $fecha_hora_actual = date('Y-m-d H:i:s');
        $fecha_hora_cita_completa = $fecha_cita . ' ' . $hora_cita . ':00';
        
        // Dar un margen de 30 minutos para citas de hoy
        if ($fecha_hora_cita_completa < $fecha_hora_actual) {
            // Si es el mismo día, verificar si ya pasó más de 30 minutos
            $fecha_actual = date('Y-m-d');
            if ($fecha_cita == $fecha_actual) {
                $diferencia = strtotime($fecha_hora_actual) - strtotime($fecha_hora_cita_completa);
                $minutos_pasados = floor($diferencia / 60);
                
                if ($minutos_pasados > 30) {
                    throw new Exception("No se pueden programar citas con más de 30 minutos de retraso.");
                }
                // Si pasaron menos de 30 minutos, permitir la cita
            } else {
                throw new Exception("No se pueden programar citas en fechas pasadas.");
            }
        }
        
        // Validar horario laboral (7:00 - 19:00)
        $hora = (int)date('H', strtotime($hora_cita));
        $minuto = (int)date('i', strtotime($hora_cita));
        
        if ($hora < 7 || ($hora == 19 && $minuto > 0) || $hora > 19) {
            throw new Exception("El horario de atención es de 7:00 AM a 7:00 PM.");
        }
        
        // Verificar si es fin de semana
        $dia_semana = date('N', strtotime($fecha_cita));
        if ($dia_semana >= 6) { // 6 = sábado, 7 = domingo
            // Verificar horario de fin de semana (8:00 - 14:00)
            if ($hora < 8 || $hora > 14) {
                throw new Exception("Los fines de semana el horario es de 8:00 AM a 2:00 PM.");
            }
        }
        
        // Obtener información de la mascota
        $mascota_sql = "SELECT m.*, c.nombre as cliente_nombre, c.telefono as cliente_telefono 
                       FROM mascotas m 
                       LEFT JOIN clientes c ON m.cliente_id = c.id 
                       WHERE m.id = ?";
        $mascota_stmt = $db->prepare($mascota_sql);
        $mascota_stmt->execute([$mascota_id]);
        $mascota_data = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mascota_data) {
            throw new Exception("La mascota seleccionada no existe.");
        }
        
        $cliente_id = $mascota_data['cliente_id'];
        
        // Obtener información del servicio
        $servicio_sql = "SELECT nombre, precio, duracion FROM servicios WHERE id = ? AND activo=1";
        $servicio_stmt = $db->prepare($servicio_sql);
        $servicio_stmt->execute([$servicio_id]);
        $servicio_data = $servicio_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$servicio_data) {
            throw new Exception("El servicio seleccionado no existe.");
        }
        
        // Obtener nombre del veterinario
        $nombre_veterinario = '';
        if ($veterinario_id) {
            $vet_sql = "SELECT nombre FROM usuarios WHERE id = ? AND estado='active' AND rol IN ('admin','veterinarian') FOR UPDATE";
            $vet_stmt = $db->prepare($vet_sql);
            $vet_stmt->execute([$veterinario_id]);
            $veterinario_data = $vet_stmt->fetch(PDO::FETCH_ASSOC);
            if(!$veterinario_data)throw new RuntimeException('Veterinario no disponible.');
            $nombre_veterinario = $veterinario_data['nombre'];
        }
        
        // Verificar disponibilidad del veterinario
        if ($veterinario_id) {
            $check_disponibilidad_sql = "
                SELECT id FROM citas 
                WHERE veterinario_id = ? 
                AND DATE(fecha_cita) = ? 
                AND hora_cita = ?
                AND estado NOT IN ('cancelled', 'completed')
            ";
            $check_stmt = $db->prepare($check_disponibilidad_sql);
            $check_stmt->execute([$veterinario_id, $fecha_cita, $hora_cita]);
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("El veterinario ya tiene una cita programada para este horario.");
            }
        }
        
        // Calcular duración si no se especificó
        if (empty($duracion_estimada) && !empty($servicio_data['duracion'])) {
            $duracion_estimada = $servicio_data['duracion'];
        }
        
        // Preparar notas detalladas
        $notas_detalladas = "MOTIVO: " . $motivo . "\n";
        if ($sintomas) $notas_detalladas .= "SÍNTOMAS: " . $sintomas . "\n";
        if ($observaciones) $notas_detalladas .= "OBSERVACIONES: " . $observaciones . "\n";
        if ($prioridad) $notas_detalladas .= "PRIORIDAD: " . $prioridad . "\n";
        $notas_detalladas .= "SERVICIO: " . $servicio_data['nombre'] . "\n";
        $notas_detalladas .= "DURACIÓN ESTIMADA: " . ($duracion_estimada ? $duracion_estimada . " minutos" : "No especificada") . "\n";
        $notas_detalladas .= "VETERINARIO: " . ($nombre_veterinario ?: "Por asignar");
        
        // Insertar la cita
        $insert_sql = "
            INSERT INTO citas 
            (mascota_id, cliente_id, servicio_id, veterinario_id, 
             fecha_cita, hora_cita, estado, notas, costo_total, fecha_creacion) 
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW())
        ";
        
        $insert_stmt = $db->prepare($insert_sql);
        $insert_result = $insert_stmt->execute([
            $mascota_id,
            $cliente_id,
            $servicio_id,
            $veterinario_id,
            $fecha_cita,
            $hora_cita,
            $notas_detalladas,
            $servicio_data['precio']
        ]);
        
        if (!$insert_result) {
            throw new Exception("Error al insertar la cita en la base de datos.");
        }
        
        $cita_id = $db->lastInsertId();
        
        // Registrar en el historial médico si hay síntomas
        if ($sintomas) {
            // Verificar estructura de la tabla consultas
            $check_consulta_table = $db->query("SHOW TABLES LIKE 'consultas'");
            if ($check_consulta_table->rowCount() > 0) {
                $historial_sql = "
                    INSERT INTO consultas 
                    (mascota_id, cliente_id, fecha_consulta, motivo, signos_clinicos, 
                     veterinario_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ";
                
                $historial_stmt = $db->prepare($historial_sql);
                $historial_stmt->execute([
                    $mascota_id,
                    $cliente_id,
                    $fecha_cita,
                    $motivo,
                    $sintomas,
                    $veterinario_id
                ]);
            }
        }
        
        // Confirmar transacción
        $db->commit();
        
        $success = true;
        $success_message = "Cita agendada exitosamente. ID de cita: #{$cita_id}";
        
        // Redirigir después de 3 segundos
        header("Refresh: 3; URL=calendario.php");
        
    }
    
} catch (Exception $e) {
    // Revertir transacción en caso de error
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    $error = $e->getMessage();
} catch (PDOException $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    $error = "Error de base de datos: " . $e->getMessage();
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
    <title>Agendar Cita - VetNexo</title>
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

        /* Form Container */
        .form-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .form-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .form-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .form-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .form-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .required::after {
            content: " *";
            color: var(--emergency);
        }

        .form-control {
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

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Priority Options */
        .priority-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }

        .priority-option {
            padding: 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .priority-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .priority-option.selected {
            border-color: var(--primary);
            background: var(--primary-soft);
            font-weight: 600;
        }

        .priority-normal {
            color: var(--primary);
        }

        .priority-urgent {
            color: var(--accent);
        }

        .priority-emergency {
            color: var(--emergency);
        }

        /* Service Categories */
        .category-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .category-tab {
            padding: 0.5rem 1rem;
            background: var(--gray-light);
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            border: 1px solid var(--gray);
        }

        .category-tab:hover {
            background: var(--primary-light);
            color: white;
        }

        .category-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary-dark);
        }

        .service-list {
            max-height: 250px;
            overflow-y: auto;
            border: 2px solid var(--gray-light);
            border-radius: var(--radius-sm);
            padding: 0.5rem;
        }

        .service-list::-webkit-scrollbar {
            width: 6px;
        }

        .service-list::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 3px;
        }

        .service-list::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 3px;
        }

        .service-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-light);
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .service-item:hover {
            background: var(--primary-soft);
            transform: translateX(3px);
        }

        .service-item.selected {
            background: var(--primary-soft);
            border-left-color: var(--primary);
        }

        .service-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .service-price {
            color: var(--primary);
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .service-description {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .service-duration {
            color: var(--text-light);
            font-size: 0.8rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Preview Panel */
        .preview-panel {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            position: sticky;
            top: 2rem;
            height: fit-content;
        }

        .preview-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .preview-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .preview-body {
            padding: 1.5rem;
        }

        .preview-item {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed var(--gray);
        }

        .preview-item:last-child {
            border-bottom: none;
        }

        .preview-label {
            font-weight: 600;
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .preview-value {
            color: var(--text-dark);
            font-size: 1rem;
            font-weight: 500;
        }

        .preview-value.empty {
            color: var(--text-lighter);
            font-style: italic;
        }

        /* Pet Info Card */
        .pet-info-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-top: 1rem;
            border: 1px solid var(--primary-light);
        }

        .pet-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .pet-name {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1.1rem;
        }

        .pet-age {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .pet-details {
            color: var(--text-dark);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-soft);
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
        @media (max-width: 1200px) {
            .form-container {
                grid-template-columns: 1fr;
            }
            
            .preview-panel {
                position: static;
            }
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .form-grid {
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .priority-options {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
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
                        <i class='bx bx-calendar-plus'></i>
                        Agendar Nueva Cita
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Complete todos los campos para programar una cita veterinaria
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="calendario.php" class="btn btn-secondary">
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

            <!-- Mensajes de éxito/error -->
            <?php if ($success): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <strong>¡Éxito!</strong> <?php echo htmlspecialchars($success_message); ?>
                                <br><small>Redirigiendo al calendario...</small>
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

            <!-- Formulario -->
            <form method="POST" action="" id="citaForm"><?php echo av_csrf_field(); ?>
                <div class="form-container">
                    <!-- Columna principal -->
                    <div class="form-section">
                        <div class="form-header">
                            <h3>
                                <i class='bx bx-info-circle'></i>
                                Información de la Cita
                            </h3>
                        </div>
                        <div class="form-body">
                            <!-- Fecha y Hora -->
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required" for="fecha_cita">Fecha</label>
                                    <input type="date" id="fecha_cita" name="fecha_cita" 
                                           class="form-control" 
                                           min="<?php echo date('Y-m-d'); ?>" 
                                           value="<?php echo htmlspecialchars($_POST['fecha_cita'] ?? date('Y-m-d')); ?>" 
                                           required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required" for="hora_cita">Hora</label>
                                    <select id="hora_cita" name="hora_cita" class="form-control select" required>
                                        <option value="">-- Seleccione hora --</option>
                                        <?php 
                                        // Generar horarios cada 30 minutos
                                        $hora_inicio = 7; // 7:00 AM
                                        $hora_fin = 19;   // 7:00 PM
                                        
                                        for ($hora = $hora_inicio; $hora < $hora_fin; $hora++): 
                                            for ($minuto = 0; $minuto < 60; $minuto += 30):
                                                if ($hora == $hora_fin && $minuto > 0) break;
                                                $hora_str = str_pad($hora, 2, '0', STR_PAD_LEFT);
                                                $minuto_str = str_pad($minuto, 2, '0', STR_PAD_LEFT);
                                                $time_str = "{$hora_str}:{$minuto_str}";
                                                $time_display = ($hora < 12 ? "{$hora}:{$minuto_str} AM" : 
                                                               ($hora == 12 ? "12:{$minuto_str} PM" : 
                                                               ($hora - 12) . ":{$minuto_str} PM"));
                                        ?>
                                            <option value="<?php echo $time_str; ?>"
                                                <?php echo (isset($_POST['hora_cita']) && $_POST['hora_cita'] == $time_str) ? 'selected' : ''; ?>>
                                                <?php echo $time_display; ?>
                                            </option>
                                        <?php endfor; endfor; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Mascota -->
                            <div class="form-group full-width">
                                <label class="form-label required" for="mascota_id">Mascota</label>
                                <select id="mascota_id" name="mascota_id" class="form-control select" required>
                                    <option value="">-- Seleccione una mascota --</option>
                                    <?php foreach ($mascotas as $mascota): 
                                        $edad = '';
                                        if ($mascota['fecha_nacimiento']) {
                                            $nacimiento = new DateTime($mascota['fecha_nacimiento']);
                                            $hoy = new DateTime();
                                            $edad = $nacimiento->diff($hoy)->y . ' años';
                                        }
                                    ?>
                                        <option value="<?php echo $mascota['id']; ?>" 
                                            <?php echo (isset($_POST['mascota_id']) && $_POST['mascota_id'] == $mascota['id']) ? 'selected' : ''; ?>
                                            data-cliente="<?php echo htmlspecialchars($mascota['cliente_nombre']); ?>"
                                            data-especie="<?php echo htmlspecialchars($mascota['especie_nombre']); ?>"
                                            data-raza="<?php echo htmlspecialchars($mascota['raza'] ?? 'Desconocida'); ?>"
                                            data-sexo="<?php echo htmlspecialchars($mascota['sexo'] ?? ''); ?>"
                                            data-edad="<?php echo $edad; ?>">
                                            <?php echo htmlspecialchars($mascota['nombre'] . ' (' . $mascota['especie_nombre'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- Información de la mascota seleccionada -->
                                <div id="petInfo" style="display: none;" class="pet-info-card">
                                    <div class="pet-info-header">
                                        <div>
                                            <span class="pet-name" id="petName"></span>
                                            <span class="pet-age" id="petAge"></span>
                                        </div>
                                    </div>
                                    <div class="pet-details" id="petDetails"></div>
                                </div>
                            </div>

                            <!-- Veterinario -->
                            <div class="form-group full-width">
                                <label class="form-label" for="veterinario_id">Veterinario</label>
                                <select id="veterinario_id" name="veterinario_id" class="form-control select">
                                    <option value="">-- Seleccione veterinario (opcional) --</option>
                                    <?php foreach ($veterinarios as $vet): ?>
                                        <option value="<?php echo $vet['id']; ?>"
                                            <?php echo (isset($_POST['veterinario_id']) && $_POST['veterinario_id'] == $vet['id']) || $veterinario_por_defecto == $vet['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vet['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Prioridad -->
                            <div class="form-group full-width">
                                <label class="form-label">Prioridad</label>
                                <div class="priority-options">
                                    <div class="priority-option priority-normal" data-priority="normal">
                                        <i class='bx bx-time'></i>
                                        Normal
                                    </div>
                                    <div class="priority-option priority-urgent" data-priority="urgent">
                                        <i class='bx bx-alarm'></i>
                                        Urgente
                                    </div>
                                    <div class="priority-option priority-emergency" data-priority="emergency">
                                        <i class='bx bx-plus-medical'></i>
                                        Emergencia
                                    </div>
                                </div>
                                <input type="hidden" id="prioridad" name="prioridad" value="<?php echo htmlspecialchars($_POST['prioridad'] ?? 'normal'); ?>">
                            </div>

                            <!-- Duración estimada -->
                            <div class="form-group full-width">
                                <label class="form-label" for="duracion_estimada">Duración estimada</label>
                                <select id="duracion_estimada" name="duracion_estimada" class="form-control select">
                                    <option value="">-- Seleccione duración (opcional) --</option>
                                    <option value="15" <?php echo (isset($_POST['duracion_estimada']) && $_POST['duracion_estimada'] == '15') ? 'selected' : ''; ?>>15 minutos</option>
                                    <option value="30" <?php echo (isset($_POST['duracion_estimada']) && $_POST['duracion_estimada'] == '30') ? 'selected' : ''; ?>>30 minutos</option>
                                    <option value="45" <?php echo (isset($_POST['duracion_estimada']) && $_POST['duracion_estimada'] == '45') ? 'selected' : ''; ?>>45 minutos</option>
                                    <option value="60" <?php echo (isset($_POST['duracion_estimada']) && $_POST['duracion_estimada'] == '60') ? 'selected' : ''; ?>>60 minutos</option>
                                    <option value="90" <?php echo (isset($_POST['duracion_estimada']) && $_POST['duracion_estimada'] == '90') ? 'selected' : ''; ?>>90 minutos</option>
                                    <option value="120" <?php echo (isset($_POST['duracion_estimada']) && $_POST['duracion_estimada'] == '120') ? 'selected' : ''; ?>>120 minutos</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Columna de Servicios y Vista Previa -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <!-- Servicios -->
                        <div class="form-section">
                            <div class="form-header">
                                <h3>
                                    <i class='bx bx-plus-medical'></i>
                                    Servicio
                                </h3>
                            </div>
                            <div class="form-body">
                                <div class="category-tabs" id="categoryTabs">
                                    <div class="category-tab active" data-category="all">
                                        <i class='bx bx-cube'></i>
                                        Todos
                                    </div>
                                    <?php foreach ($servicios_por_categoria as $categoria => $servicios_cat): 
                                        $categoria_nombre = ucfirst($categoria);
                                        $icon = 'bx-cube';
                                        switch($categoria) {
                                            case 'consultation': $icon = 'bx-clinic'; break;
                                            case 'vaccination': $icon = 'bx-syringe'; break;
                                            case 'surgery': $icon = 'bx-plus-medical'; break;
                                            case 'emergency': $icon = 'bx-first-aid'; break;
                                            case 'grooming': $icon = 'bx-cut'; break;
                                            case 'laboratory': $icon = 'bx-test-tube'; break;
                                        }
                                    ?>
                                        <div class="category-tab" data-category="<?php echo $categoria; ?>">
                                            <i class='bx <?php echo $icon; ?>'></i>
                                            <?php echo htmlspecialchars($categoria_nombre); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="service-list" id="serviceList">
                                    <?php foreach ($servicios as $servicio): ?>
                                        <div class="service-item" 
                                             data-service-id="<?php echo $servicio['id']; ?>"
                                             data-category="<?php echo $servicio['categoria'] ?? 'otros'; ?>"
                                             data-price="<?php echo $servicio['precio']; ?>"
                                             data-duration="<?php echo $servicio['duracion']; ?>">
                                            <div>
                                                <span class="service-name"><?php echo htmlspecialchars($servicio['nombre']); ?></span>
                                                <span class="service-price">$<?php echo number_format($servicio['precio'], 2); ?></span>
                                            </div>
                                            <?php if ($servicio['descripcion']): ?>
                                                <div class="service-description">
                                                    <?php echo htmlspecialchars($servicio['descripcion']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($servicio['duracion']): ?>
                                                <div class="service-duration">
                                                    <i class='bx bx-time'></i> Duración: <?php echo htmlspecialchars($servicio['duracion']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <input type="hidden" id="servicio_id" name="servicio_id" value="<?php echo htmlspecialchars($_POST['servicio_id'] ?? ''); ?>">
                            </div>
                        </div>

                        <!-- Vista Previa -->
                        <div class="preview-panel">
                            <div class="preview-header">
                                <h3>
                                    <i class='bx bx-show'></i>
                                    Vista Previa
                                </h3>
                            </div>
                            <div class="preview-body">
                                <div class="preview-item">
                                    <div class="preview-label">Mascota</div>
                                    <div class="preview-value" id="previewPet">No seleccionada</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Dueño</div>
                                    <div class="preview-value" id="previewOwner">No seleccionado</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Fecha y Hora</div>
                                    <div class="preview-value" id="previewDateTime">No seleccionada</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Veterinario</div>
                                    <div class="preview-value" id="previewVet">Por asignar</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Servicio</div>
                                    <div class="preview-value" id="previewService">No seleccionado</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Precio</div>
                                    <div class="preview-value" id="previewPrice">$0.00</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Prioridad</div>
                                    <div class="preview-value" id="previewPriority">Normal</div>
                                </div>
                                
                                <div class="preview-item">
                                    <div class="preview-label">Duración estimada</div>
                                    <div class="preview-value" id="previewDuration">No especificada</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detalles de la consulta -->
                <div class="form-section" style="margin-top: 1.5rem;">
                    <div class="form-header">
                        <h3>
                            <i class='bx bx-notepad'></i>
                            Detalles de la Consulta
                        </h3>
                    </div>
                    <div class="form-body">
                        <div class="form-group full-width">
                            <label class="form-label required" for="motivo">Motivo de la consulta</label>
                            <textarea id="motivo" name="motivo" class="form-control" 
                                      placeholder="Describa el motivo principal de la consulta..."
                                      required><?php echo htmlspecialchars($_POST['motivo'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label" for="sintomas">Síntomas observados</label>
                            <textarea id="sintomas" name="sintomas" class="form-control" 
                                      placeholder="Describa los síntomas que presenta la mascota..."><?php echo htmlspecialchars($_POST['sintomas'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label" for="observaciones">Observaciones adicionales</label>
                            <textarea id="observaciones" name="observaciones" class="form-control" 
                                      placeholder="Observaciones adicionales, historial previo, medicamentos actuales..."><?php echo htmlspecialchars($_POST['observaciones'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Acciones -->
                <div class="form-actions">
                    <a href="calendario.php" class="btn btn-secondary">
                        <i class='bx bx-x'></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class='bx bx-calendar-check'></i> Confirmar Cita
                    </button>
                </div>
            </form>

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
            // Datos de mascotas
            const mascotaSelect = document.getElementById('mascota_id');
            const petInfo = document.getElementById('petInfo');
            const petName = document.getElementById('petName');
            const petAge = document.getElementById('petAge');
            const petDetails = document.getElementById('petDetails');
            
            // Elementos de vista previa
            const previewPet = document.getElementById('previewPet');
            const previewOwner = document.getElementById('previewOwner');
            const previewDateTime = document.getElementById('previewDateTime');
            const previewVet = document.getElementById('previewVet');
            const previewService = document.getElementById('previewService');
            const previewPrice = document.getElementById('previewPrice');
            const previewPriority = document.getElementById('previewPriority');
            const previewDuration = document.getElementById('previewDuration');
            
            // Servicios
            const serviceItems = document.querySelectorAll('.service-item');
            const serviceIdInput = document.getElementById('servicio_id');
            const categoryTabs = document.querySelectorAll('.category-tab');
            const serviceList = document.getElementById('serviceList');
            
            // Prioridad
            const priorityOptions = document.querySelectorAll('.priority-option');
            const priorityInput = document.getElementById('prioridad');
            
            // Otras entradas
            const fechaInput = document.getElementById('fecha_cita');
            const horaSelect = document.getElementById('hora_cita');
            const vetSelect = document.getElementById('veterinario_id');
            const duracionSelect = document.getElementById('duracion_estimada');
            const motivoTextarea = document.getElementById('motivo');
            
            // Mostrar información de la mascota seleccionada
            mascotaSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption.value) {
                    const cliente = selectedOption.getAttribute('data-cliente');
                    const especie = selectedOption.getAttribute('data-especie');
                    const raza = selectedOption.getAttribute('data-raza');
                    const sexo = selectedOption.getAttribute('data-sexo');
                    const edad = selectedOption.getAttribute('data-edad');
                    
                    // Actualizar card de información
                    petName.textContent = selectedOption.textContent.split(' (')[0];
                    petAge.textContent = edad ? `, ${edad}` : '';
                    
                    let details = `<strong>Dueño:</strong> ${cliente}<br>`;
                    details += `<strong>Especie:</strong> ${especie}<br>`;
                    if (raza && raza !== 'Desconocida') details += `<strong>Raza:</strong> ${raza}<br>`;
                    if (sexo) {
                        const sexoNormalizado = String(sexo).toLowerCase();
                        const sexoTexto = (sexoNormalizado === 'macho' || sexoNormalizado === 'm') ? 'Macho' : ((sexoNormalizado === 'hembra' || sexoNormalizado === 'h') ? 'Hembra' : sexo);
                        details += `<strong>Sexo:</strong> ${sexoTexto}`;
                    }
                    
                    petDetails.innerHTML = details;
                    petInfo.style.display = 'block';
                    
                    // Actualizar vista previa
                    previewPet.textContent = selectedOption.textContent.split(' (')[0];
                    previewOwner.textContent = cliente;
                } else {
                    petInfo.style.display = 'none';
                    previewPet.textContent = 'No seleccionada';
                    previewOwner.textContent = 'No seleccionado';
                }
            });
            
            // Filtrar servicios por categoría
            categoryTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Remover clase activa de todas las pestañas
                    categoryTabs.forEach(t => t.classList.remove('active'));
                    // Agregar clase activa a la pestaña clickeada
                    this.classList.add('active');
                    
                    const category = this.getAttribute('data-category');
                    
                    // Mostrar/ocultar servicios según categoría
                    serviceItems.forEach(item => {
                        if (category === 'all' || item.getAttribute('data-category') === category) {
                            item.style.display = 'block';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            });
            
            // Seleccionar servicio
            serviceItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Remover clase seleccionada de todos los servicios
                    serviceItems.forEach(i => i.classList.remove('selected'));
                    // Agregar clase seleccionada al servicio clickeado
                    this.classList.add('selected');
                    
                    const serviceId = this.getAttribute('data-service-id');
                    const serviceName = this.querySelector('.service-name').textContent;
                    const servicePrice = this.getAttribute('data-price');
                    const serviceDuration = this.getAttribute('data-duration');
                    
                    // Actualizar input oculto
                    serviceIdInput.value = serviceId;
                    
                    // Actualizar vista previa
                    previewService.textContent = serviceName;
                    previewPrice.textContent = `$${parseFloat(servicePrice).toFixed(2)}`;
                    if (serviceDuration) {
                        previewDuration.textContent = `${serviceDuration} minutos`;
                    } else {
                        previewDuration.textContent = 'No especificada';
                    }
                });
            });
            
            // Seleccionar prioridad
            priorityOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // Remover clase seleccionada de todas las opciones
                    priorityOptions.forEach(o => o.classList.remove('selected'));
                    // Agregar clase seleccionada a la opción clickeada
                    this.classList.add('selected');
                    
                    const priority = this.getAttribute('data-priority');
                    priorityInput.value = priority;
                    
                    // Actualizar vista previa
                    let priorityText = 'Normal';
                    if (priority === 'urgent') priorityText = 'Urgente';
                    if (priority === 'emergency') priorityText = 'Emergencia';
                    previewPriority.textContent = priorityText;
                });
            });
            
            // Actualizar vista previa en tiempo real
            fechaInput.addEventListener('change', updateDateTimePreview);
            horaSelect.addEventListener('change', updateDateTimePreview);
            vetSelect.addEventListener('change', updateVetPreview);
            duracionSelect.addEventListener('change', updateDurationPreview);
            
            function updateDateTimePreview() {
                const fecha = fechaInput.value;
                const hora = horaSelect.value;
                
                if (fecha && hora) {
                    const fechaObj = new Date(fecha + 'T12:00:00');
                    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                    const fechaFormateada = fechaObj.toLocaleDateString('es-ES', options);
                    
                    // Formatear hora (ej: 14:30 -> 2:30 PM)
                    const [hours, minutes] = hora.split(':');
                    const horaFormateada = `${parseInt(hours) % 12 || 12}:${minutes} ${hours >= 12 ? 'PM' : 'AM'}`;
                    
                    previewDateTime.textContent = `${fechaFormateada} - ${horaFormateada}`;
                } else {
                    previewDateTime.textContent = 'No seleccionada';
                }
            }
            
            function updateVetPreview() {
                const selectedOption = vetSelect.options[vetSelect.selectedIndex];
                previewVet.textContent = selectedOption.value ? selectedOption.textContent : 'Por asignar';
            }
            
            function updateDurationPreview() {
                const selectedOption = duracionSelect.options[duracionSelect.selectedIndex];
                previewDuration.textContent = selectedOption.value ? selectedOption.textContent : 'No especificada';
            }
            
            // Inicializar valores
            if (mascotaSelect.value) mascotaSelect.dispatchEvent(new Event('change'));
            updateDateTimePreview();
            updateVetPreview();
            updateDurationPreview();
            
            // Establecer prioridad por defecto
            const prioridadInicial = priorityInput.value;
            priorityOptions.forEach(option => {
                if (option.getAttribute('data-priority') === prioridadInicial) {
                    option.classList.add('selected');
                }
            });
            
            // Seleccionar servicio por defecto si existe
            if (serviceIdInput.value) {
                serviceItems.forEach(item => {
                    if (item.getAttribute('data-service-id') === serviceIdInput.value) {
                        item.click();
                    }
                });
            } else if (serviceItems.length > 0) {
                // Seleccionar primer servicio por defecto
                serviceItems[0].click();
            }
            
            // Mostrar todos los servicios inicialmente
            categoryTabs[0]?.click();
            
            // Validar formulario antes de enviar
            document.getElementById('citaForm').addEventListener('submit', function(e) {
                let isValid = true;
                let errorMessage = '';
                
                // Validar mascota
                if (!mascotaSelect.value) {
                    isValid = false;
                    errorMessage += '• Debe seleccionar una mascota\n';
                }
                
                // Validar fecha y hora
                const fechaSeleccionada = fechaInput.value;
                const horaSeleccionada = horaSelect.value;
                
                if (!fechaSeleccionada) {
                    isValid = false;
                    errorMessage += '• Debe seleccionar una fecha\n';
                }
                
                if (!horaSeleccionada) {
                    isValid = false;
                    errorMessage += '• Debe seleccionar una hora\n';
                }
                
                if (fechaSeleccionada && horaSeleccionada) {
                    const fechaCita = new Date(fechaSeleccionada + 'T' + horaSeleccionada + ':00');
                    const ahora = new Date();
                    
                    // Si la cita es en el pasado
                    if (fechaCita < ahora) {
                        // Verificar si es el mismo día
                        const hoy = new Date().toDateString();
                        const diaCita = fechaCita.toDateString();
                        
                        if (hoy === diaCita) {
                            // Dar margen de 30 minutos para citas de hoy
                            const diferencia = ahora - fechaCita;
                            const minutosDiferencia = Math.floor(diferencia / (1000 * 60));
                            
                            if (minutosDiferencia > 30) {
                                isValid = false;
                                errorMessage += '• No se pueden programar citas con más de 30 minutos de retraso\n';
                            }
                        } else {
                            // Es un día anterior
                            isValid = false;
                            errorMessage += '• No se pueden programar citas en fechas pasadas\n';
                        }
                    }
                }
                
                // Validar servicio
                if (!serviceIdInput.value) {
                    isValid = false;
                    errorMessage += '• Debe seleccionar un servicio\n';
                }
                
                // Validar motivo
                if (!motivoTextarea.value.trim()) {
                    isValid = false;
                    errorMessage += '• El motivo de la consulta es obligatorio\n';
                }
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Por favor corrija los siguientes errores:\n\n' + errorMessage);
                    return false;
                }
                
                // Confirmación final
                return confirm('¿Está seguro de agendar esta cita?\n\n' +
                             'Una vez confirmada, se registrará en el sistema.');
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