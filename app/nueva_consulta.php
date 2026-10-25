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

$mensaje = '';
$tipo_mensaje = '';

// Inicializar variables
$db = null;
$clientes = [];
$mascotas_por_cliente = [];
$mascota_seleccionada = null;
$cliente_seleccionado = null;
$veterinarios = [];
$edad_calculada = '';

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
    
    // Obtener todos los clientes para el selector
    $clientes_sql = "SELECT id, nombre, telefono, documento_identidad FROM clientes ORDER BY nombre";
    $clientes_stmt = $db->query($clientes_sql);
    $clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $petId=(int)($_POST['mascota_id']??$_GET['mascota_id']??0);
    if($petId>0){$q=$db->prepare('SELECT cliente_id FROM mascotas WHERE id=?');$q->execute([$petId]);$owner=$q->fetchColumn();if($owner){$_GET['cliente_id']=$owner;$_GET['mascota_id']=$petId;}}
    // Si se seleccionó un cliente, cargar sus mascotas
    if (isset($_GET['cliente_id']) && !empty($_GET['cliente_id'])) {
        $cliente_id = $_GET['cliente_id'];
        $mascotas_sql = "
            SELECT m.*, e.nombre as especie_nombre 
            FROM mascotas m
            LEFT JOIN especies e ON m.especie_id = e.id
            WHERE m.cliente_id = ?
            ORDER BY m.nombre
        ";
        $mascotas_stmt = $db->prepare($mascotas_sql);
        $mascotas_stmt->execute([$cliente_id]);
        $mascotas_por_cliente = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener datos del cliente seleccionado
        $cliente_sql = "SELECT * FROM clientes WHERE id = ?";
        $cliente_stmt = $db->prepare($cliente_sql);
        $cliente_stmt->execute([$cliente_id]);
        $cliente_seleccionado = $cliente_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Si se seleccionó una mascota, cargar sus datos
    if (isset($_GET['mascota_id']) && !empty($_GET['mascota_id'])) {
        $mascota_id = $_GET['mascota_id'];
        $mascota_sql = "
            SELECT m.*, 
                   e.nombre as especie_nombre,
                   c.id as cliente_id,
                   c.nombre as cliente_nombre,
                   c.telefono as cliente_telefono,
                   c.email as cliente_email,
                   c.direccion as cliente_direccion,
                   c.documento_identidad as cliente_documento
            FROM mascotas m
            LEFT JOIN especies e ON m.especie_id = e.id
            LEFT JOIN clientes c ON m.cliente_id = c.id
            WHERE m.id = ?
        ";
        $mascota_stmt = $db->prepare($mascota_sql);
        $mascota_stmt->execute([$mascota_id]);
        $mascota_seleccionada = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calcular edad automáticamente si hay fecha de nacimiento
        if (!empty($mascota_seleccionada['fecha_nacimiento'])) {
            $nacimiento = new DateTime($mascota_seleccionada['fecha_nacimiento']);
            $hoy = new DateTime();
            $diferencia = $hoy->diff($nacimiento);
            
            if ($diferencia->y > 0) {
                $edad_calculada = $diferencia->y . ' año' . ($diferencia->y > 1 ? 's' : '');
                if ($diferencia->m > 0) {
                    $edad_calculada .= ' y ' . $diferencia->m . ' mes' . ($diferencia->m > 1 ? 'es' : '');
                }
            } elseif ($diferencia->m > 0) {
                $edad_calculada = $diferencia->m . ' mes' . ($diferencia->m > 1 ? 'es' : '');
                if ($diferencia->d > 0) {
                    $edad_calculada .= ' y ' . $diferencia->d . ' día' . ($diferencia->d > 1 ? 's' : '');
                }
            } else {
                $edad_calculada = $diferencia->d . ' día' . ($diferencia->d > 1 ? 's' : '');
            }
        }
    }
    
    // Obtener veterinarios
    $veterinarios_sql = "SELECT id, nombre FROM usuarios WHERE rol IN ('veterinarian', 'veterinario', 'admin') AND estado = 'active' ORDER BY nombre";
    $veterinarios_stmt = $db->query($veterinarios_sql);
    $veterinarios = $veterinarios_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $mensaje = "Error al cargar datos: " . $e->getMessage();
    $tipo_mensaje = "error";
}

// Procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    try {
        // Iniciar transacción
        $db->beginTransaction();
        
        // Verificar que se haya seleccionado una mascota
        $mascota_id = $_POST['mascota_id'] ?? '';
        if (empty($mascota_id)) {
            throw new Exception('Debe seleccionar una mascota');
        }
        
        // Obtener datos de la mascota seleccionada
        $mascota_sql = "SELECT cliente_id FROM mascotas WHERE id = ?";
        $mascota_stmt = $db->prepare($mascota_sql);
        $mascota_stmt->execute([$mascota_id]);
        $mascota_data = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mascota_data) {
            throw new Exception('La mascota seleccionada no existe');
        }
        
        // Datos de la persona a cargo
        $persona_a_cargo = $_POST['persona_a_cargo'] ?? '';
        $cedula_persona = $_POST['cedula_persona'] ?? '';
        $telefono_persona = $_POST['telefono_persona'] ?? '';
        $direccion_persona = $_POST['direccion_persona'] ?? '';
        
        // Datos de la consulta
        $fecha_consulta = $_POST['fecha_consulta'] ?? date('Y-m-d');
        $edad_mascota = $_POST['edad_mascota'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');
        $signos_clinicos = trim($_POST['signos_clinicos'] ?? '');
        $diagnostico = trim($_POST['diagnostico'] ?? '');
        $procedimiento = trim($_POST['procedimiento'] ?? '');
        $tratamiento = trim($_POST['tratamiento'] ?? '');
        $pendientes = trim($_POST['pendientes'] ?? '');
        $valor_total = $_POST['valor_total'] ?? '0';
        $observaciones = trim($_POST['observaciones'] ?? '');
        $veterinario = trim($_POST['veterinario'] ?? '');
        $veterinario_id = $_POST['veterinario_id'] ?? null;
        
        // Validaciones básicas
        if (empty($motivo)) {
            throw new Exception('El campo motivo de consulta es obligatorio');
        }
        
        if (empty($diagnostico)) {
            throw new Exception('El campo diagnóstico es obligatorio');
        }
        
        if (empty($veterinario_id)) {
            throw new Exception('Debe seleccionar un veterinario');
        }
        
        // Validar fecha
        if (!empty($fecha_consulta)) {
            $fecha = DateTime::createFromFormat('Y-m-d', $fecha_consulta);
            if (!$fecha) {
                throw new Exception('Formato de fecha inválido');
            }
        }
        
        // Validar valor total
        if (!empty($valor_total) && !is_numeric($valor_total)) {
            throw new Exception('El valor total debe ser un número válido');
        }
        
        // Insertar consulta
        $insert_sql = "
            INSERT INTO consultas (
                mascota_id, cliente_id, persona_a_cargo, cedula_persona, 
                telefono_persona, direccion_persona, edad_mascota, fecha_consulta,
                motivo, signos_clinicos, diagnostico, procedimiento, tratamiento,
                pendientes, valor_total, observaciones, veterinario, veterinario_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $db->prepare($insert_sql);
        $result = $stmt->execute([
            $mascota_id,
            $mascota_data['cliente_id'],
            $persona_a_cargo,
            $cedula_persona,
            $telefono_persona,
            $direccion_persona,
            $edad_mascota,
            $fecha_consulta,
            $motivo,
            $signos_clinicos,
            $diagnostico,
            $procedimiento,
            $tratamiento,
            $pendientes,
            $valor_total,
            $observaciones,
            $veterinario,
            $veterinario_id
        ]);
        
        if (!$result) {
            throw new Exception('Error al insertar la consulta');
        }
        
        $consulta_id = $db->lastInsertId();
        
        // Confirmar transacción
        $db->commit();
        
        $mensaje = "Consulta registrada exitosamente. ID: " . $consulta_id;
        $tipo_mensaje = "success";
        
        // Redirigir a historial después de 2 segundos
        header("Refresh: 2; URL=historial.php?id=" . $mascota_id);
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        $mensaje = "Error al registrar consulta: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $db === null) {
    $mensaje = "Error: No hay conexión a la base de datos";
    $tipo_mensaje = "error";
}

// Función helper para obtener color según rol
function getRolColor($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return '#FF9800';
        case 'veterinario':
        case 'veterinarian':
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
    <title>Nueva Consulta - VetNexo</title>
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #d32f2f);
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #d32f2f, #b71c1c);
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

        /* Back Button */
        .back-button {
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

        .back-button:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
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

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: var(--success);
            color: #1e4620;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--danger);
            color: #601e1e;
        }

        /* Selector de Cliente/Mascota */
        .selector-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .selector-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            padding: 1.5rem;
        }

        @media (max-width: 768px) {
            .selector-grid {
                grid-template-columns: 1fr;
            }
        }

        .selector-group {
            display: flex;
            flex-direction: column;
        }

        .selector-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
            font-size: 0.95rem;
        }

        .pet-info-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-top: 0.5rem;
            border: 1px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .pet-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.2rem;
        }

        .pet-info-small h4 {
            margin: 0 0 0.2rem 0;
            color: var(--primary-dark);
            font-size: 1rem;
        }

        .pet-info-small p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Formulario */
        .form-container {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .form-section {
            padding: 1.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--accent);
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
            color: var(--danger);
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
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
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

        /* Help Text */
        .help-text {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 0.25rem;
        }

        .info-box {
            background: var(--primary-soft);
            border: 1px solid var(--primary-light);
            border-radius: var(--radius-sm);
            padding: 0.75rem 1rem;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box i {
            color: var(--primary);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding: 1.5rem;
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
            .header-actions .back-button {
                width: 100%;
                justify-content: center;
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
                        <i class='bx bx-plus-circle'></i>
                        Nueva Consulta Médica
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Seleccione el paciente y complete los datos de la consulta
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

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'error'; ?>">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class='bx <?php echo $tipo_mensaje === 'success' ? 'bx-check-circle' : 'bx-error-circle'; ?>' style="font-size: 1.2rem;"></i>
                                <span><?php echo htmlspecialchars($mensaje); ?></span>
                            </div>
                            <?php if ($tipo_mensaje === 'success'): ?>
                                <div style="margin-top: 0.5rem; font-size: 0.9rem;">
                                    <i class='bx bx-loader-circle bx-spin'></i>
                                    Redirigiendo al historial...
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Selector de Cliente y Mascota -->
            <div class="selector-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-search'></i>
                        Seleccionar Paciente
                    </h2>
                    <a href="listadeclientes.php" class="btn btn-primary btn-sm" target="_blank">
                        <i class='bx bx-group'></i>
                        Ver Clientes
                    </a>
                </div>
                
                <div class="selector-grid">
                    <div class="selector-group">
                        <label class="selector-label">1. Seleccione el Cliente (Dueño)</label>
                        <select id="cliente_selector" class="form-control select" onchange="cargarMascotas(this.value)">
                            <option value="">-- Seleccione un cliente --</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?php echo $cliente['id']; ?>" 
                                    <?php echo (isset($_GET['cliente_id']) && $_GET['cliente_id'] == $cliente['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cliente['nombre']); ?> 
                                    <?php echo !empty($cliente['telefono']) ? '- ' . htmlspecialchars($cliente['telefono']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="selector-group">
                        <label class="selector-label">2. Seleccione la Mascota</label>
                        <select id="mascota_selector" name="mascota_id" form="consultaForm" class="form-control select" onchange="mostrarInfoMascota(this.value)">
                            <option value="">-- Primero seleccione un cliente --</option>
                            <?php if (!empty($mascotas_por_cliente)): ?>
                                <?php foreach ($mascotas_por_cliente as $mascota): ?>
                                    <option value="<?php echo $mascota['id']; ?>" 
                                        data-nombre="<?php echo htmlspecialchars($mascota['nombre']); ?>"
                                        data-especie="<?php echo htmlspecialchars($mascota['especie_nombre']); ?>"
                                        data-raza="<?php echo htmlspecialchars($mascota['raza'] ?? 'No especificada'); ?>"
                                        <?php echo (isset($_GET['mascota_id']) && $_GET['mascota_id'] == $mascota['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mascota['nombre']); ?> 
                                        (<?php echo htmlspecialchars($mascota['especie_nombre']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Información de la mascota seleccionada -->
                <?php if ($mascota_seleccionada): ?>
                <div class="section-body" style="padding-top: 0;">
                    <div class="pet-info-card">
                        <div class="pet-avatar-small">
                            <?php echo strtoupper(substr($mascota_seleccionada['nombre'], 0, 1)); ?>
                        </div>
                        <div class="pet-info-small">
                            <h4><?php echo htmlspecialchars($mascota_seleccionada['nombre']); ?></h4>
                            <p>
                                <strong>Especie:</strong> <?php echo htmlspecialchars($mascota_seleccionada['especie_nombre']); ?> | 
                                <strong>Raza:</strong> <?php echo htmlspecialchars($mascota_seleccionada['raza'] ?? 'No especificada'); ?> | 
                                <strong>Dueño:</strong> <?php echo htmlspecialchars($mascota_seleccionada['cliente_nombre']); ?>
                                <?php if (!empty($edad_calculada)): ?>
                                    | <strong>Edad:</strong> <?php echo $edad_calculada; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Formulario de Consulta -->
            <div class="form-container fade-in">
                <form id="consultaForm" method="POST" action=""><?php echo av_csrf_field(); ?>
                    <!-- Datos de la Persona a Cargo -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-user'></i>
                            Datos de la Persona a Cargo
                        </h3>
                        <div class="help-text" style="margin-bottom: 1rem;">Si es diferente al dueño, complete estos campos</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="persona_a_cargo" class="form-label">Nombre de la Persona a Cargo</label>
                                <input type="text" 
                                       id="persona_a_cargo" 
                                       name="persona_a_cargo" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['persona_a_cargo'] ?? ($mascota_seleccionada['cliente_nombre'] ?? '')); ?>"
                                       placeholder="Nombre de quien trae la mascota">
                            </div>

                            <div class="form-group">
                                <label for="cedula_persona" class="form-label">Cédula/RUC</label>
                                <input type="text" 
                                       id="cedula_persona" 
                                       name="cedula_persona" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['cedula_persona'] ?? ($mascota_seleccionada['cliente_documento'] ?? '')); ?>"
                                       placeholder="Número de cédula">
                            </div>

                            <div class="form-group">
                                <label for="telefono_persona" class="form-label">Teléfono</label>
                                <input type="tel" 
                                       id="telefono_persona" 
                                       name="telefono_persona" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['telefono_persona'] ?? ($mascota_seleccionada['cliente_telefono'] ?? '')); ?>"
                                       placeholder="Número de teléfono">
                            </div>

                            <div class="form-group">
                                <label for="direccion_persona" class="form-label">Dirección</label>
                                <input type="text" 
                                       id="direccion_persona" 
                                       name="direccion_persona" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['direccion_persona'] ?? ($mascota_seleccionada['cliente_direccion'] ?? '')); ?>"
                                       placeholder="Dirección completa">
                            </div>
                        </div>
                    </div>

                    <!-- Datos de la Consulta -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-clipboard'></i>
                            Datos de la Consulta
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="fecha_consulta" class="form-label required">Fecha de Consulta</label>
                                <input type="date" 
                                       id="fecha_consulta" 
                                       name="fecha_consulta" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['fecha_consulta'] ?? date('Y-m-d')); ?>"
                                       required>
                            </div>

                            <div class="form-group">
                                <label for="edad_mascota" class="form-label">Edad de la Mascota</label>
                                <input type="text" 
                                       id="edad_mascota" 
                                       name="edad_mascota" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['edad_mascota'] ?? $edad_calculada); ?>"
                                       placeholder="Ej: 2 años, 6 meses">
                                <?php if (!empty($edad_calculada)): ?>
                                    <div class="info-box">
                                        <i class='bx bx-info-circle'></i>
                                        Edad calculada: <strong><?php echo $edad_calculada; ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group full-width">
                                <label for="veterinario_id" class="form-label required">Veterinario</label>
                                <select id="veterinario_id" name="veterinario_id" class="form-control select" required>
                                    <option value="">Seleccione un veterinario</option>
                                    <?php foreach ($veterinarios as $vet): ?>
                                        <option value="<?php echo $vet['id']; ?>" 
                                            <?php echo (isset($_POST['veterinario_id']) && $_POST['veterinario_id'] == $vet['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vet['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="veterinario" name="veterinario" value="<?php echo htmlspecialchars($_POST['veterinario'] ?? ''); ?>">
                            </div>

                            <div class="form-group full-width">
                                <label for="motivo" class="form-label required">Motivo de Consulta</label>
                                <textarea id="motivo" 
                                          name="motivo" 
                                          class="form-control" 
                                          placeholder="Describa el motivo de la consulta..."
                                          required><?php echo htmlspecialchars($_POST['motivo'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="signos_clinicos" class="form-label">Signos Clínicos</label>
                                <textarea id="signos_clinicos" 
                                          name="signos_clinicos" 
                                          class="form-control" 
                                          placeholder="Describa los signos clínicos observados..."><?php echo htmlspecialchars($_POST['signos_clinicos'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="diagnostico" class="form-label required">Diagnóstico</label>
                                <textarea id="diagnostico" 
                                          name="diagnostico" 
                                          class="form-control" 
                                          placeholder="Describa el diagnóstico..."
                                          required><?php echo htmlspecialchars($_POST['diagnostico'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="procedimiento" class="form-label">Procedimiento Realizado</label>
                                <textarea id="procedimiento" 
                                          name="procedimiento" 
                                          class="form-control" 
                                          placeholder="Describa los procedimientos realizados..."><?php echo htmlspecialchars($_POST['procedimiento'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="tratamiento" class="form-label">Tratamiento</label>
                                <textarea id="tratamiento" 
                                          name="tratamiento" 
                                          class="form-control" 
                                          placeholder="Describa el tratamiento indicado..."><?php echo htmlspecialchars($_POST['tratamiento'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="pendientes" class="form-label">Pendientes</label>
                                <textarea id="pendientes" 
                                          name="pendientes" 
                                          class="form-control" 
                                          placeholder="Tareas pendientes, próximas citas, etc..."><?php echo htmlspecialchars($_POST['pendientes'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="valor_total" class="form-label">Valor Total ($)</label>
                                <input type="number" 
                                       id="valor_total" 
                                       name="valor_total" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['valor_total'] ?? '0'); ?>"
                                       step="0.01"
                                       min="0"
                                       placeholder="0.00">
                            </div>

                            <div class="form-group full-width">
                                <label for="observaciones" class="form-label">Observaciones</label>
                                <textarea id="observaciones" 
                                          name="observaciones" 
                                          class="form-control" 
                                          placeholder="Observaciones adicionales..."><?php echo htmlspecialchars($_POST['observaciones'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones del Formulario -->
                    <div class="form-actions">
                        <button type="reset" class="btn btn-danger">
                            <i class='bx bx-reset'></i>
                            Limpiar
                        </button>
                        <a href="listademascotas.php" class="btn btn-secondary">
                            <i class='bx bx-x'></i>
                            Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class='bx bx-save'></i>
                            Registrar Consulta
                        </button>
                    </div>
                </form>
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
        // Función para cargar mascotas cuando se selecciona un cliente
        function cargarMascotas(clienteId) {
            if (clienteId) {
                window.location.href = 'nueva_consulta.php?cliente_id=' + clienteId;
            } else {
                window.location.href = 'nueva_consulta.php';
            }
        }
        
        // Función para mostrar información de la mascota seleccionada
        function mostrarInfoMascota(mascotaId) {
            if (mascotaId) {
                const clienteId = document.getElementById('cliente_selector').value;
                window.location.href = 'nueva_consulta.php?cliente_id=' + clienteId + '&mascota_id=' + mascotaId;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('consultaForm');
            const veterinarioSelect = document.getElementById('veterinario_id');
            const veterinarioInput = document.getElementById('veterinario');
            const mascotaSelector = document.getElementById('mascota_selector');
            
            // Validar que se haya seleccionado una mascota antes de enviar
            if (form) {
                form.addEventListener('submit', function(e) {
                    const mascotaId = document.getElementById('mascota_selector').value;
                    
                    if (!mascotaId) {
                        e.preventDefault();
                        alert('Debe seleccionar una mascota antes de registrar la consulta');
                        return false;
                    }
                    
                    let valid = true;
                    let errorMessage = '';
                    
                    // Validar campos requeridos
                    const motivo = document.getElementById('motivo').value.trim();
                    const diagnostico = document.getElementById('diagnostico').value.trim();
                    const fecha = document.getElementById('fecha_consulta').value;
                    const veterinarioId = document.getElementById('veterinario_id').value;
                    
                    if (!motivo) {
                        valid = false;
                        errorMessage += '• El motivo de consulta es obligatorio\n';
                        document.getElementById('motivo').style.borderColor = '#f56565';
                    }
                    
                    if (!diagnostico) {
                        valid = false;
                        errorMessage += '• El diagnóstico es obligatorio\n';
                        document.getElementById('diagnostico').style.borderColor = '#f56565';
                    }
                    
                    if (!fecha) {
                        valid = false;
                        errorMessage += '• La fecha de consulta es obligatoria\n';
                        document.getElementById('fecha_consulta').style.borderColor = '#f56565';
                    }
                    
                    if (!veterinarioId) {
                        valid = false;
                        errorMessage += '• Debe seleccionar un veterinario\n';
                        veterinarioSelect.style.borderColor = '#f56565';
                    }
                    
                    if (!valid) {
                        e.preventDefault();
                        alert('Por favor, corrija los siguientes errores:\n\n' + errorMessage);
                        return false;
                    }
                    
                    // Mostrar estado de carga
                    this.classList.add('loading');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Registrando...';
                    submitBtn.disabled = true;
                });
            }
            
            // Sincronizar veterinario seleccionado con campo de texto
            if (veterinarioSelect && veterinarioInput) {
                veterinarioSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption && selectedOption.value) {
                        veterinarioInput.value = selectedOption.text;
                    } else {
                        veterinarioInput.value = '';
                    }
                });
                
                // Inicializar el campo de texto con el valor seleccionado
                if (veterinarioSelect.value) {
                    const selectedOption = veterinarioSelect.options[veterinarioSelect.selectedIndex];
                    veterinarioInput.value = selectedOption.text;
                }
            }
            
            // Restaurar estilos al escribir
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.style.borderColor = '';
                });
                
                input.addEventListener('change', function() {
                    this.style.borderColor = '';
                });
            });

            // Reset del formulario con confirmación
            const resetBtn = document.querySelector('button[type="reset"]');
            if (resetBtn) {
                resetBtn.addEventListener('click', function(e) {
                    if (!confirm('¿Está seguro de que desea limpiar todos los campos?')) {
                        e.preventDefault();
                    }
                });
            }

            // Establecer fecha máxima para evitar fechas futuras
            const fechaInput = document.getElementById('fecha_consulta');
            if (fechaInput) {
                const hoy = new Date().toISOString().split('T')[0];
                fechaInput.max = hoy;
            }
            
            // Efecto de partículas adicionales
            const particlesContainer = document.querySelector('.particles');
            if (particlesContainer) {
                for (let i = 0; i < 8; i++) {
                    const particle = document.createElement('div');
                    particle.className = 'particle';
                    particle.style.cssText = `
                        top: ${Math.random() * 100}%;
                        left: ${Math.random() * 100}%;
                        width: ${Math.random() * 8 + 4}px;
                        height: ${Math.random() * 8 + 4}px;
                        animation-delay: ${Math.random() * 5}s;
                        background: ${Math.random() > 0.5 ? 'rgba(46, 125, 50, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                    `;
                    particlesContainer.appendChild(particle);
                }
            }
        });
    </script>
</body>
</html>