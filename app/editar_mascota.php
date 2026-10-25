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
$mascota = null;
$cliente = null;
$especies = [];
$error = '';
$success = '';

// Verificar si se proporcionó un ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: listademascotas.php');
    exit();
}

$mascota_id = $_GET['id'];

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
    
    // Obtener datos de la mascota y el cliente con todas las columnas posibles
    $sql = "
        SELECT m.*, 
               c.id as cliente_id,
               c.nombre as cliente_nombre,
               c.telefono as cliente_telefono,
               c.email as cliente_email,
               c.direccion as cliente_direccion,
               c.documento_identidad as cliente_documento,
               e.nombre as especie_nombre
        FROM mascotas m
        LEFT JOIN clientes c ON m.cliente_id = c.id
        LEFT JOIN especies e ON m.especie_id = e.id
        WHERE m.id = ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$mascota_id]);
    $mascota = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mascota) {
        $error = "Mascota no encontrada.";
    } else {
        // Obtener especies para el select
        $especies_sql = "SELECT * FROM especies ORDER BY nombre";
        $especies_stmt = $db->query($especies_sql);
        $especies = $especies_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Detectar columnas de la tabla mascotas
        $check_table_sql = "DESCRIBE mascotas";
        $check_table_stmt = $db->query($check_table_sql);
        $table_columns = $check_table_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Iniciar transacción
        $db->beginTransaction();
        
        // Datos del cliente
        $cliente_nombre = $_POST['cliente_nombre'] ?? '';
        $cliente_telefono = $_POST['cliente_telefono'] ?? '';
        $cliente_email = $_POST['cliente_email'] ?? '';
        $cliente_direccion = $_POST['cliente_direccion'] ?? '';
        $cliente_documento = $_POST['cliente_documento'] ?? '';
        
        if (empty($cliente_nombre)) {
            throw new Exception('El nombre del cliente es obligatorio');
        }
        
        // Actualizar cliente
        $update_cliente_sql = "
            UPDATE clientes 
            SET nombre = ?, telefono = ?, email = ?, direccion = ?, documento_identidad = ?
            WHERE id = ?
        ";
        $stmt_cliente = $db->prepare($update_cliente_sql);
        $stmt_cliente->execute([
            $cliente_nombre,
            $cliente_telefono,
            $cliente_email,
            $cliente_direccion,
            $cliente_documento,
            $mascota['cliente_id']
        ]);
        
        // Datos de la mascota
        $mascota_nombre = $_POST['mascota_nombre'] ?? '';
        $especie_id = $_POST['especie_id'] ?? '';
        $raza = $_POST['raza'] ?? '';
        $color = $_POST['color'] ?? '';
        $sexo = $_POST['sexo'] ?? '';
        $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? '';
        $peso = $_POST['peso'] ?? '';
        $alergias = $_POST['alergias'] ?? '';
        $notas_medicas = $_POST['notas_medicas'] ?? '';
        
        if (empty($mascota_nombre) || empty($especie_id)) {
            throw new Exception('Los campos nombre y especie son obligatorios');
        }
        
        if ($sexo === 'M') $sexo = 'macho';
        if ($sexo === 'H') $sexo = 'hembra';
        if (!in_array($sexo, ['macho', 'hembra'], true)) {
            throw new Exception('Debe seleccionar un sexo válido');
        }
        
        // Validar fecha de nacimiento si se proporciona
        if (!empty($fecha_nacimiento)) {
            $fecha_parts = explode('-', $fecha_nacimiento);
            if (count($fecha_parts) === 3) {
                $anio = $fecha_parts[0];
                $mes = $fecha_parts[1];
                $dia = $fecha_parts[2];
                
                if (!checkdate($mes, $dia, $anio)) {
                    throw new Exception('Fecha de nacimiento inválida');
                }
                
                // Verificar que no sea una fecha futura
                $fecha_nacimiento_obj = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento);
                $hoy = new DateTime();
                if ($fecha_nacimiento_obj > $hoy) {
                    throw new Exception('La fecha de nacimiento no puede ser futura');
                }
            }
        }
        
        // Validar peso si se proporciona
        if (!empty($peso)) {
            $peso = str_replace(',', '.', $peso);
            if (!is_numeric($peso) || $peso < 0 || $peso > 500) {
                throw new Exception('El peso debe ser un número entre 0 y 500 kg');
            }
        }
        
        // Construir consulta UPDATE dinámica basada en las columnas existentes
        $update_fields = [];
        $update_values = [];
        
        // Campos que siempre deben existir
        $update_fields[] = "nombre = ?";
        $update_values[] = $mascota_nombre;
        
        $update_fields[] = "especie_id = ?";
        $update_values[] = $especie_id;
        
        // Campos opcionales según la estructura de la tabla
        if (in_array('raza', $table_columns)) {
            $update_fields[] = "raza = ?";
            $update_values[] = $raza;
        }
        
        if (in_array('color', $table_columns)) {
            $update_fields[] = "color = ?";
            $update_values[] = $color;
        }
        
        if (in_array('sexo', $table_columns)) {
            $update_fields[] = "sexo = ?";
            $update_values[] = $sexo;
        }
        
        if (in_array('fecha_nacimiento', $table_columns)) {
            $update_fields[] = "fecha_nacimiento = ?";
            $update_values[] = $fecha_nacimiento ?: null;
        }
        
        if (in_array('peso', $table_columns)) {
            $update_fields[] = "peso = ?";
            $update_values[] = $peso ?: null;
        }
        
        if (in_array('alergias', $table_columns)) {
            $update_fields[] = "alergias = ?";
            $update_values[] = $alergias;
        }
        
        if (in_array('notas', $table_columns)) {
            $update_fields[] = "notas = ?";
            $update_values[] = $notas_medicas;
        }
        
        // Agregar fecha de actualización si la columna existe
        if (in_array('fecha_actualizacion', $table_columns)) {
            $update_fields[] = "fecha_actualizacion = NOW()";
        }
        
        // Agregar WHERE al final
        $update_values[] = $mascota_id;
        
        // Construir y ejecutar la consulta
        $update_mascota_sql = "UPDATE mascotas SET " . implode(', ', $update_fields) . " WHERE id = ?";
        
        $stmt_mascota = $db->prepare($update_mascota_sql);
        $stmt_mascota->execute($update_values);
        
        // Confirmar transacción
        $db->commit();
        
        $success = "Mascota y dueño actualizados correctamente.";
        
        // Recargar datos actualizados
        $stmt = $db->prepare($sql);
        $stmt->execute([$mascota_id]);
        $mascota = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error al actualizar: " . $e->getMessage();
    }
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
    <title>Editar Mascota - VetNexo</title>
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

        .btn-back {
            background: linear-gradient(135deg, var(--text-light), var(--text-lighter));
            color: var(--white);
        }

        .btn-back:hover {
            background: linear-gradient(135deg, var(--text-lighter), #5a6268);
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
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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

        /* Formulario */
        .form-container {
            background: var(--white);
        }

        .form-section {
            padding: 1.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.1rem;
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

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding: 1.5rem;
            border-top: 2px solid var(--primary-soft);
        }

        /* Tarjeta de información */
        .info-card {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            border: 1px solid var(--primary-light);
            margin-bottom: 1.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 1rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
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
            
            .header-actions .btn {
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
            
            .info-grid {
                grid-template-columns: 1fr;
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
                        <i class='bx bx-edit'></i>
                        Editar Mascota
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Actualice la información de la mascota y su dueño
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
            <?php if ($error): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <i class='bx bx-error-circle'></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <i class='bx bx-check-circle'></i>
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($mascota): ?>
            <!-- Tarjeta de información resumen -->
            <div class="content-section">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-info-circle'></i>
                        Resumen de la Mascota
                    </h2>
                    <span class="badge badge-success">
                        <i class='bx bx-paw'></i>
                        ID: <?php echo $mascota['id']; ?>
                    </span>
                </div>
                <div class="section-body">
                    <div class="info-card">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Nombre</span>
                                <span class="info-value"><?php echo htmlspecialchars($mascota['nombre']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Especie</span>
                                <span class="info-value"><?php echo htmlspecialchars($mascota['especie_nombre'] ?? 'No especificada'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Dueño</span>
                                <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_nombre']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Teléfono</span>
                                <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_telefono'] ?? 'No registrado'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formulario de edición -->
            <div class="content-section form-container">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-pencil'></i>
                        Formulario de Edición
                    </h2>
                    <a href="listademascotas.php" class="btn btn-back btn-sm">
                        <i class='bx bx-arrow-back'></i>
                        Volver
                    </a>
                </div>

                <form method="POST" action="" id="editForm"><?php echo av_csrf_field(); ?>
                    <!-- Sección Dueño -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-user'></i>
                            Información del Dueño
                        </h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="form-label required">Nombre completo</label>
                                <input type="text" 
                                       name="cliente_nombre" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['cliente_nombre'] ?? ''); ?>"
                                       required
                                       placeholder="Nombre completo del dueño">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Documento de Identidad</label>
                                <input type="text" 
                                       name="cliente_documento" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['cliente_documento'] ?? ''); ?>"
                                       placeholder="[ID not configured]">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Teléfono</label>
                                <input type="tel" 
                                       name="cliente_telefono" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['cliente_telefono'] ?? ''); ?>"
                                       required
                                       placeholder="[Clinic phone not configured]">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" 
                                       name="cliente_email" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['cliente_email'] ?? ''); ?>"
                                       placeholder="correo@ejemplo.com">
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Dirección</label>
                                <textarea name="cliente_direccion" 
                                          class="form-control" 
                                          placeholder="Dirección completa"><?php echo htmlspecialchars($mascota['cliente_direccion'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Sección Mascota -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-paw'></i>
                            Información de la Mascota
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Nombre de la mascota</label>
                                <input type="text" 
                                       name="mascota_nombre" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['nombre']); ?>"
                                       required
                                       placeholder="Nombre de la mascota">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Especie</label>
                                <select name="especie_id" class="form-control select" required>
                                    <option value="">Seleccionar especie</option>
                                    <?php foreach ($especies as $especie): ?>
                                        <option value="<?php echo $especie['id']; ?>" 
                                            <?php echo $mascota['especie_id'] == $especie['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($especie['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Raza</label>
                                <input type="text" 
                                       name="raza" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['raza'] ?? ''); ?>"
                                       placeholder="Raza de la mascota">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Color</label>
                                <input type="text" 
                                       name="color" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['color'] ?? ''); ?>"
                                       placeholder="Color principal">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Sexo</label>
                                <select name="sexo" class="form-control select" required>
                                    <option value="macho" <?php echo in_array(strtolower((string)$mascota['sexo']), ['macho','m'], true) ? 'selected' : ''; ?>>Macho</option>
                                    <option value="hembra" <?php echo in_array(strtolower((string)$mascota['sexo']), ['hembra','h'], true) ? 'selected' : ''; ?>>Hembra</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Fecha de nacimiento</label>
                                <input type="date" 
                                       name="fecha_nacimiento" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($mascota['fecha_nacimiento'] ?? ''); ?>">
                                <div class="help-text">Formato: YYYY-MM-DD</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Peso (kg)</label>
                                <input type="number" 
                                       name="peso" 
                                       class="form-control" 
                                       step="0.1"
                                       min="0"
                                       max="500"
                                       value="<?php echo htmlspecialchars($mascota['peso'] ?? ''); ?>"
                                       placeholder="Ej: 5.5">
                                <div class="help-text">Peso en kilogramos (0-500)</div>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Alergias</label>
                                <textarea name="alergias" 
                                          class="form-control" 
                                          placeholder="Alergias conocidas (si aplica)"><?php echo htmlspecialchars($mascota['alergias'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Notas médicas</label>
                                <textarea name="notas_medicas" 
                                          class="form-control" 
                                          placeholder="Información médica adicional, tratamientos, observaciones"><?php echo htmlspecialchars($mascota['notas'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="form-actions">
                        <a href="listademascotas.php" class="btn btn-secondary">
                            <i class='bx bx-x'></i>
                            Cancelar
                        </a>
                        <button type="reset" class="btn btn-danger" onclick="return confirm('¿Restaurar los valores originales?')">
                            <i class='bx bx-reset'></i>
                            Restaurar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class='bx bx-save'></i>
                            Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
            <?php else: ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <i class='bx bx-error-circle'></i>
                            No se pudo cargar la información de la mascota.
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

    <script src="js/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editForm');
            
            // Validación del formulario
            if (form) {
                form.addEventListener('submit', function(e) {
                    let valid = true;
                    let errorMessage = '';
                    
                    // Validar campos requeridos del cliente
                    const clienteNombre = document.querySelector('[name="cliente_nombre"]');
                    const clienteTelefono = document.querySelector('[name="cliente_telefono"]');
                    
                    if (!clienteNombre.value.trim()) {
                        valid = false;
                        errorMessage += '• El nombre del cliente es obligatorio\n';
                        clienteNombre.style.borderColor = '#f56565';
                    } else {
                        clienteNombre.style.borderColor = '';
                    }
                    
                    if (!clienteTelefono.value.trim()) {
                        valid = false;
                        errorMessage += '• El teléfono del cliente es obligatorio\n';
                        clienteTelefono.style.borderColor = '#f56565';
                    } else {
                        clienteTelefono.style.borderColor = '';
                    }
                    
                    // Validar campos requeridos de la mascota
                    const mascotaNombre = document.querySelector('[name="mascota_nombre"]');
                    const especieId = document.querySelector('[name="especie_id"]');
                    
                    if (!mascotaNombre.value.trim()) {
                        valid = false;
                        errorMessage += '• El nombre de la mascota es obligatorio\n';
                        mascotaNombre.style.borderColor = '#f56565';
                    } else {
                        mascotaNombre.style.borderColor = '';
                    }
                    
                    if (!especieId.value) {
                        valid = false;
                        errorMessage += '• La especie es obligatoria\n';
                        especieId.style.borderColor = '#f56565';
                    } else {
                        especieId.style.borderColor = '';
                    }
                    
                    // Validar fecha de nacimiento si se proporciona
                    const fechaNacimiento = document.querySelector('[name="fecha_nacimiento"]');
                    if (fechaNacimiento && fechaNacimiento.value) {
                        const fecha = new Date(fechaNacimiento.value);
                        const hoy = new Date();
                        if (fecha > hoy) {
                            valid = false;
                            errorMessage += '• La fecha de nacimiento no puede ser futura\n';
                            fechaNacimiento.style.borderColor = '#f56565';
                        }
                    }
                    
                    // Validar peso si se proporciona
                    const pesoInput = document.querySelector('[name="peso"]');
                    if (pesoInput && pesoInput.value) {
                        const peso = parseFloat(pesoInput.value.replace(',', '.'));
                        if (peso < 0 || peso > 500) {
                            valid = false;
                            errorMessage += '• El peso debe estar entre 0 y 500 kg\n';
                            pesoInput.style.borderColor = '#f56565';
                        }
                    }
                    
                    if (!valid) {
                        e.preventDefault();
                        alert('Por favor, corrija los siguientes errores:\n\n' + errorMessage);
                        return false;
                    }
                    
                    // Mostrar estado de carga
                    this.classList.add('loading');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Guardando...';
                    submitBtn.disabled = true;
                });
                
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
            }
            
            // Validación en tiempo real para peso
            const pesoInput = document.querySelector('[name="peso"]');
            if (pesoInput) {
                pesoInput.addEventListener('input', function() {
                    if (this.value) {
                        const peso = parseFloat(this.value.replace(',', '.'));
                        if (peso < 0 || peso > 500) {
                            this.style.borderColor = '#f56565';
                        } else {
                            this.style.borderColor = '';
                        }
                    }
                });
            }
            
            // Validación en tiempo real para fecha
            const fechaInput = document.querySelector('[name="fecha_nacimiento"]');
            if (fechaInput) {
                fechaInput.addEventListener('change', function() {
                    if (this.value) {
                        const fecha = new Date(this.value);
                        const hoy = new Date();
                        if (fecha > hoy) {
                            this.style.borderColor = '#f56565';
                            alert('La fecha de nacimiento no puede ser futura');
                        } else {
                            this.style.borderColor = '';
                        }
                    }
                });
            }
            
            // Confirmación antes de restaurar
            const resetBtn = document.querySelector('button[type="reset"]');
            if (resetBtn) {
                resetBtn.addEventListener('click', function(e) {
                    if (!confirm('¿Está seguro de que desea restaurar los valores originales?')) {
                        e.preventDefault();
                    }
                });
            }
            
            // Tooltips para campos requeridos (opcional)
            const requiredLabels = document.querySelectorAll('.required');
            requiredLabels.forEach(label => {
                label.setAttribute('title', 'Campo obligatorio');
            });
        });
    </script>
</body>
</html>