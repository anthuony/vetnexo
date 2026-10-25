<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_staff();


// Verificar si el usuario está logueado (versión compatible con ambos formatos)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit();
}

// Definir variables de sesión de forma consistente
$user_id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? null;
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? 'usuario';
$usuario_email = $_SESSION['email'] ?? $_SESSION['usuario_email'] ?? '';

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas');

// Incluir configuración de base de datos
include_once '../config/database.php';

$database = new Database();
$db = $database->conectar();

$mensaje = '';
$error = '';

// Obtener ID de la inspección
$inspeccion_id = $_GET['id'] ?? 0;

if (!$inspeccion_id) {
    header('Location: inspecciones_pendientes.php');
    exit();
}

// Obtener datos de la inspección
$inspeccion = null;
try {
    $sql = "SELECT * FROM inspecciones_fumigacion WHERE id = ? AND estado = 'Pendiente'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$inspeccion_id]);
    $inspeccion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inspeccion) {
        header('Location: inspecciones_pendientes.php');
        exit();
    }
} catch (PDOException $e) {
    $error = "Error al cargar la inspección: " . $e->getMessage();
}

// Obtener servicios de fumigación disponibles
$servicios = [];
try {
    $sql = "SELECT id, nombre_servicio, precio_base, descripcion FROM servicios_fumigacion WHERE activo = 1 ORDER BY nombre_servicio";
    $stmt = $db->query($sql);
    $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si no existe la tabla, crear servicios por defecto
    $servicios = [
        ['id' => 1, 'nombre_servicio' => 'Fumigación General', 'precio_base' => 35.00, 'descripcion' => 'Fumigación completa para control de plagas'],
        ['id' => 2, 'nombre_servicio' => 'Control de Roedores', 'precio_base' => 40.00, 'descripcion' => 'Control y eliminación de roedores'],
        ['id' => 3, 'nombre_servicio' => 'Fumigación de Jardines', 'precio_base' => 30.00, 'descripcion' => 'Tratamiento para plagas en áreas verdes'],
        ['id' => 4, 'nombre_servicio' => 'Control de Termitas', 'precio_base' => 50.00, 'descripcion' => 'Tratamiento especializado para termitas'],
        ['id' => 5, 'nombre_servicio' => 'Fumigación de Cocinas', 'precio_base' => 45.00, 'descripcion' => 'Control de plagas en áreas de cocina'],
    ];
}

// Obtener técnicos disponibles
$tecnicos = ['Romer Mariño', 'Carlos Rodríguez', 'Héctor', 'Leonel', 'María Pérez', 'José González'];
asort($tecnicos);

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion == 'no_acepta') {
        // Cliente no acepta el servicio - cancelar inspección
        try {
            $sql = "UPDATE inspecciones_fumigacion SET estado = 'Cancelada' WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$inspeccion_id]);
            
            $mensaje = 'Inspección cancelada. Cliente no aceptó el servicio.';
            
            // Redirigir después de 2 segundos
            header("refresh:2;url=inspecciones_pendientes.php");
        } catch (PDOException $e) {
            $error = 'Error al cancelar la inspección: ' . $e->getMessage();
        }
    } elseif ($accion == 'acepta') {
        // Cliente acepta el servicio - crear fumigación
        $servicio_id = $_POST['servicio_id'] ?? '';
        $numero_sesiones = (int)($_POST['numero_sesiones'] ?? 1);
        $precio_sesion = (float)($_POST['precio_sesion'] ?? 0);
        $fecha_primera_sesion = $_POST['fecha_primera_sesion'] ?? '';
        $tecnico_asignado = $_POST['tecnico_asignado'] ?? '';
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        // Validar campos
        $errores = [];
        if (empty($servicio_id)) $errores[] = "Debe seleccionar un tipo de servicio.";
        if ($numero_sesiones < 1 || $numero_sesiones > 10) $errores[] = "El número de sesiones debe ser entre 1 y 10.";
        if ($precio_sesion <= 0) $errores[] = "El precio por sesión debe ser mayor a 0.";
        if (empty($fecha_primera_sesion)) $errores[] = "Debe seleccionar la fecha de la primera sesión.";
        if (empty($tecnico_asignado)) $errores[] = "Debe seleccionar un técnico asignado.";
        
        // Validar que la fecha no sea pasada
        if (!empty($fecha_primera_sesion)) {
            $fecha_seleccionada = new DateTime($fecha_primera_sesion);
            $hoy = new DateTime();
            $hoy->setTime(0, 0, 0);
            
            if ($fecha_seleccionada < $hoy) {
                $errores[] = "La fecha de la primera sesión no puede ser anterior a hoy.";
            }
        }
        
        if (empty($errores)) {
            try {
                $db->beginTransaction();
                
                // Calcular costo total
                $costo_total = $numero_sesiones * $precio_sesion;
                
                // Buscar o crear cliente en la tabla clientes
                $cliente_id = null;
                
                // Buscar si el cliente ya existe por teléfono o nombre
                $sql_buscar = "SELECT id FROM clientes WHERE telefono = ? OR nombre = ? LIMIT 1";
                $stmt_buscar = $db->prepare($sql_buscar);
                $stmt_buscar->execute([$inspeccion['telefono'], $inspeccion['nombre_cliente']]);
                $cliente_existente = $stmt_buscar->fetch(PDO::FETCH_ASSOC);
                
                if ($cliente_existente) {
                    $cliente_id = $cliente_existente['id'];
                    
                    // Actualizar dirección si es necesario
                    $sql_update_cliente = "UPDATE clientes SET direccion = ? WHERE id = ?";
                    $stmt_update_cliente = $db->prepare($sql_update_cliente);
                    $stmt_update_cliente->execute([$inspeccion['direccion'], $cliente_id]);
                } else {
                    // Crear nuevo cliente
                    $sql_insert_cliente = "INSERT INTO clientes (nombre, telefono, direccion, fecha_registro) 
                                          VALUES (?, ?, ?, NOW())";
                    $stmt_insert_cliente = $db->prepare($sql_insert_cliente);
                    $stmt_insert_cliente->execute([
                        $inspeccion['nombre_cliente'],
                        $inspeccion['telefono'],
                        $inspeccion['direccion']
                    ]);
                    $cliente_id = $db->lastInsertId();
                }
                
                // Insertar fumigación realizada
                $sql_insert = "INSERT INTO fumigaciones_realizadas 
                              (inspeccion_id, cliente_id, servicio_id, fecha_programada, 
                               tecnico_asignado, observaciones, costo_total, precio_sesion, 
                               numero_sesiones, sesiones_completadas, estado_pago, usuario_realizo, fecha_registro) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Pendiente', ?, NOW())";
                
                $stmt_insert = $db->prepare($sql_insert);
                $resultado = $stmt_insert->execute([
                    $inspeccion_id,
                    $cliente_id,
                    $servicio_id,
                    $fecha_primera_sesion,
                    $tecnico_asignado,
                    $observaciones,
                    $costo_total,
                    $precio_sesion,
                    $numero_sesiones,
                    $user_id
                ]);
                
                if ($resultado) {
                    $fumigacion_id = $db->lastInsertId();
                    
                    // Actualizar estado de la inspección
                    $sql_update = "UPDATE inspecciones_fumigacion SET estado = 'Realizada' WHERE id = ?";
                    $stmt_update = $db->prepare($sql_update);
                    $stmt_update->execute([$inspeccion_id]);
                    
                    // Agendar la primera sesión en agenda_fumigaciones
                    $sql_agenda = "INSERT INTO agenda_fumigaciones 
                                  (fumigacion_id, numero_sesion, fecha_programada, tecnico, estado) 
                                  VALUES (?, 1, ?, ?, 'Programada')";
                    $stmt_agenda = $db->prepare($sql_agenda);
                    $stmt_agenda->execute([$fumigacion_id, $fecha_primera_sesion, $tecnico_asignado]);
                    
                    // Agendar las sesiones restantes automáticamente (con intervalos de 7 días)
                    for ($i = 2; $i <= $numero_sesiones; $i++) {
                        $fecha_siguiente = date('Y-m-d', strtotime($fecha_primera_sesion . " + " . (($i-1)*7) . " days"));
                        $sql_agenda_siguiente = "INSERT INTO agenda_fumigaciones 
                                                (fumigacion_id, numero_sesion, fecha_programada, tecnico, estado) 
                                                VALUES (?, ?, ?, ?, 'Programada')";
                        $stmt_agenda_siguiente = $db->prepare($sql_agenda_siguiente);
                        $stmt_agenda_siguiente->execute([$fumigacion_id, $i, $fecha_siguiente, $tecnico_asignado]);
                    }
                    
                    $db->commit();
                    
                    $mensaje = '¡Fumigación registrada exitosamente! Se han agendado las ' . $numero_sesiones . ' sesiones.';
                    
                    // Redirigir después de 2 segundos
                    header("refresh:2;url=fumigaciones_activas.php");
                } else {
                    $db->rollBack();
                    $error = 'Error al registrar la fumigación.';
                }
            } catch (PDOException $e) {
                $db->rollBack();
                $error = 'Error en la base de datos: ' . $e->getMessage();
            }
        } else {
            $error = implode('<br>', $errores);
        }
    }
}

// Función helper para obtener color según rol
function getRolColor($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return '#F99B07';
        case 'veterinario':
        case 'veterinarian':
            return '#0F6C33';
        case 'asistente':
            return '#2261BD';
        default:
            return '#5A4B37';
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
    <title>Realizar Inspección - Fumigación - VetNexo</title>
    <link rel="icon" type="image/png" href="../includes/img/logo.svg">
    
    <link href='../assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="../css/sidebar.css" rel="stylesheet">
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
            --emergency-soft: #ffebee;    /* Rojo suave */
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            --amarillo-soft: #fff9c4;     /* Amarillo suave */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            --marron-soft: #f5f0e8;       /* Marrón suave */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            --azul-soft: #e3f2fd;         /* Azul suave */
            
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
            line-height: 1.6;
            overflow-x: hidden;
            min-height: 100vh;
        }

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

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

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

        .client-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
            margin-bottom: 1.5rem;
        }

        .client-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .info-item i {
            font-size: 1.2rem;
            color: var(--primary);
            width: 24px;
            margin-top: 0.2rem;
        }

        .info-item strong {
            display: block;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }

        .info-item span {
            color: var(--text-light);
            font-size: 0.9rem;
        }

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

        .alert-warning {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-color: var(--accent);
            color: #663c00;
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

        .alert-warning .alert-icon {
            color: var(--accent);
        }

        .decision-buttons {
            display: flex;
            gap: 1rem;
            margin: 2rem 0;
            flex-wrap: wrap;
            justify-content: center;
        }

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

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(15, 108, 51, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, var(--emergency-dark), #a50c01);
            transform: translateY(-2px);
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

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .form-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid var(--primary-soft);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .required::after {
            content: " *";
            color: var(--emergency);
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .info-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--primary-light);
        }

        .info-card p {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .info-card i {
            color: var(--primary);
        }

        .total-display {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            text-align: center;
            margin: 1rem 0;
            border: 2px dashed var(--primary-light);
        }

        .total-display .label {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
        }

        .total-display .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-dark);
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
            background: rgba(15, 108, 51, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(180deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content { margin-left: 250px; padding: 1.5rem; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1rem; }
            
            .dashboard-header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
                padding: 1.5rem;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .back-btn,
            .header-actions .btn-primary {
                width: 100%;
                justify-content: center;
            }
            
            .client-info {
                grid-template-columns: 1fr;
            }
            
            .decision-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
    <link rel="stylesheet" href="../css/app-optimized.css?v=20260812c">
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
        <?php include '../sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-search-alt'></i>
                        Realizar Inspección
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Registra el resultado de la inspección
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="inspecciones_pendientes.php" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver a Pendientes
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
            <?php if ($mensaje): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $mensaje; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $error; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($inspeccion): ?>
                <!-- Datos del Cliente -->
                <div class="content-section fade-in">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-user'></i>
                            Datos de la Inspección
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <div class="client-card">
                            <div class="client-info">
                                <div class="info-item">
                                    <i class='bx bx-user'></i>
                                    <div>
                                        <strong>Cliente</strong>
                                        <span><?php echo htmlspecialchars($inspeccion['nombre_cliente']); ?></span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class='bx bx-phone'></i>
                                    <div>
                                        <strong>Teléfono</strong>
                                        <span><?php echo htmlspecialchars($inspeccion['telefono']); ?></span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class='bx bx-map'></i>
                                    <div>
                                        <strong>Dirección</strong>
                                        <span><?php echo htmlspecialchars($inspeccion['direccion']); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($inspeccion['urbanizacion'])): ?>
                                <div class="info-item">
                                    <i class='bx bx-building-house'></i>
                                    <div>
                                        <strong>Urbanización</strong>
                                        <span><?php echo htmlspecialchars($inspeccion['urbanizacion']); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <i class='bx bx-calendar'></i>
                                    <div>
                                        <strong>Fecha Inspección</strong>
                                        <span><?php echo date('d/m/Y', strtotime($inspeccion['fecha_inspeccion'])); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($inspeccion['hora_inspeccion'])): ?>
                                <div class="info-item">
                                    <i class='bx bx-time'></i>
                                    <div>
                                        <strong>Hora</strong>
                                        <span><?php echo $inspeccion['hora_inspeccion']; ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($inspeccion['observaciones'])): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--primary-light);">
                                <div style="display: flex; gap: 0.5rem;">
                                    <i class='bx bx-note' style="color: var(--primary);"></i>
                                    <div>
                                        <strong style="display: block; margin-bottom: 0.3rem;">Observaciones</strong>
                                        <span style="color: var(--text-light);"><?php echo nl2br(htmlspecialchars($inspeccion['observaciones'])); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Botones de decisión -->
                        <div class="decision-buttons">
                            <button type="button" class="btn btn-success" onclick="mostrarFormulario(true)">
                                <i class='bx bx-check-circle'></i>
                                Cliente ACEPTA el servicio
                            </button>
                            <button type="button" class="btn btn-danger" onclick="mostrarFormulario(false)">
                                <i class='bx bx-x-circle'></i>
                                Cliente NO ACEPTA el servicio
                            </button>
                        </div>

                        <!-- Formulario para cliente NO acepta -->
                        <div id="formNoAcepta" style="display: none;">
                            <div class="alert alert-warning">
                                <div class="alert-icon">
                                    <i class='bx bx-info-circle'></i>
                                </div>
                                <div class="alert-content">
                                    <strong>¿Estás seguro?</strong><br>
                                    Esta acción marcará la inspección como cancelada.
                                </div>
                            </div>
                            <form method="POST"><?php echo av_csrf_field(); ?>
                                <input type="hidden" name="accion" value="no_acepta">
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-danger">
                                        <i class='bx bx-check'></i>
                                        Confirmar cancelación
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="ocultarFormularios()">
                                        <i class='bx bx-x'></i>
                                        Volver
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Formulario para cliente acepta -->
                        <div id="formAcepta" style="display: none;">
                            <div class="form-section">
                                <h3 class="section-title" style="margin-bottom: 1.5rem;">
                                    <i class='bx bx-spray-can'></i>
                                    Registrar Servicio de Fumigación
                                </h3>

                                <div class="info-card">
                                    <p><i class='bx bx-info-circle'></i> Completa los detalles del servicio acordado con el cliente.</p>
                                    <p><i class='bx bx-time'></i> Las sesiones se agendarán automáticamente con intervalos de 7 días.</p>
                                </div>

                                <form method="POST" id="formAceptaSubmit"><?php echo av_csrf_field(); ?>
                                    <input type="hidden" name="accion" value="acepta">

                                    <div class="form-group">
                                        <label class="form-label required">Tipo de Servicio</label>
                                        <select name="servicio_id" id="servicio_id" class="form-control select" required onchange="actualizarPrecioBase()">
                                            <option value="">-- Seleccione un servicio --</option>
                                            <?php foreach ($servicios as $servicio): ?>
                                                <option value="<?php echo $servicio['id']; ?>" 
                                                        data-precio="<?php echo $servicio['precio_base']; ?>">
                                                    <?php echo htmlspecialchars($servicio['nombre_servicio']); ?> - 
                                                    $<?php echo number_format($servicio['precio_base'], 2); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label required">Número de Sesiones</label>
                                            <select name="numero_sesiones" id="numero_sesiones" class="form-control select" required onchange="calcularTotal()">
                                                <option value="1">1 sesión</option>
                                                <option value="2">2 sesiones</option>
                                                <option value="3" selected>3 sesiones</option>
                                                <option value="4">4 sesiones</option>
                                                <option value="5">5 sesiones</option>
                                                <option value="6">6 sesiones</option>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label required">Precio por Sesión ($)</label>
                                            <input type="number" name="precio_sesion" id="precio_sesion" 
                                                   class="form-control" required step="0.01" min="1"
                                                   value="35.00" onchange="calcularTotal()">
                                        </div>
                                    </div>

                                    <div class="total-display">
                                        <div class="label">Total del Servicio</div>
                                        <div class="value" id="total_mostrado">$105.00</div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label required">Fecha Primera Sesión</label>
                                            <input type="date" name="fecha_primera_sesion" id="fecha_primera_sesion" 
                                                   class="form-control" required
                                                   min="<?php echo date('Y-m-d'); ?>"
                                                   value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label required">Técnico Asignado</label>
                                            <select name="tecnico_asignado" class="form-control select" required>
                                                <option value="">-- Seleccione un técnico --</option>
                                                <?php foreach ($tecnicos as $tecnico): ?>
                                                    <option value="<?php echo htmlspecialchars($tecnico); ?>">
                                                        <?php echo htmlspecialchars($tecnico); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Observaciones / Notas</label>
                                        <textarea name="observaciones" class="form-control" 
                                                  placeholder="Detalles importantes sobre el servicio, productos a utilizar, etc."></textarea>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class='bx bx-save'></i>
                                            Guardar y Agendar
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="ocultarFormularios()">
                                            <i class='bx bx-x'></i>
                                            Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
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

    <script src="../js/sidebar.js"></script>
    <script>
        function mostrarFormulario(acepta) {
            document.getElementById('formNoAcepta').style.display = 'none';
            document.getElementById('formAcepta').style.display = 'none';
            
            if (acepta) {
                document.getElementById('formAcepta').style.display = 'block';
            } else {
                document.getElementById('formNoAcepta').style.display = 'block';
            }
            
            // Scroll al formulario
            setTimeout(() => {
                document.getElementById(acepta ? 'formAcepta' : 'formNoAcepta').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }

        function ocultarFormularios() {
            document.getElementById('formNoAcepta').style.display = 'none';
            document.getElementById('formAcepta').style.display = 'none';
        }

        function actualizarPrecioBase() {
            const select = document.getElementById('servicio_id');
            const selected = select.options[select.selectedIndex];
            const precio = selected ? selected.getAttribute('data-precio') : 35;
            
            if (precio) {
                document.getElementById('precio_sesion').value = parseFloat(precio).toFixed(2);
                calcularTotal();
            }
        }

        function calcularTotal() {
            const numSesiones = parseInt(document.getElementById('numero_sesiones').value) || 1;
            const precioSesion = parseFloat(document.getElementById('precio_sesion').value) || 0;
            const total = numSesiones * precioSesion;
            
            document.getElementById('total_mostrado').textContent = '$' + total.toFixed(2);
        }

        // Validar formulario antes de enviar
        document.getElementById('formAceptaSubmit')?.addEventListener('submit', function(e) {
            const servicio = document.getElementById('servicio_id').value;
            const precio = document.getElementById('precio_sesion').value;
            const fecha = document.getElementById('fecha_primera_sesion').value;
            const tecnico = document.querySelector('select[name="tecnico_asignado"]').value;
            
            if (!servicio) {
                e.preventDefault();
                alert('Por favor seleccione un tipo de servicio.');
                document.getElementById('servicio_id').focus();
                return false;
            }
            
            if (!precio || parseFloat(precio) <= 0) {
                e.preventDefault();
                alert('El precio por sesión debe ser mayor a 0.');
                document.getElementById('precio_sesion').focus();
                return false;
            }
            
            if (!fecha) {
                e.preventDefault();
                alert('Por favor seleccione la fecha de la primera sesión.');
                document.getElementById('fecha_primera_sesion').focus();
                return false;
            }
            
            if (!tecnico) {
                e.preventDefault();
                alert('Por favor seleccione un técnico asignado.');
                document.querySelector('select[name="tecnico_asignado"]').focus();
                return false;
            }
            
            // Mostrar estado de carga
            this.classList.add('loading');
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Guardando...';
            submitBtn.disabled = true;
        });

        // Validar fecha no sea anterior a hoy
        document.getElementById('fecha_primera_sesion')?.addEventListener('change', function() {
            const fechaSeleccionada = new Date(this.value);
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            
            if (fechaSeleccionada < hoy) {
                alert('La fecha no puede ser anterior a hoy.');
                this.value = '<?php echo date('Y-m-d', strtotime('+1 day')); ?>';
            }
        });

        // Inicializar cálculo
        document.addEventListener('DOMContentLoaded', function() {
            calcularTotal();
            
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