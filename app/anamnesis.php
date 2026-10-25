<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Incluir configuración de base de datos
include 'config/database.php';

$db = getDBConnection();

// Obtener datos del usuario
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'usuario';

// Variables para el formulario
$success_msg = '';
$error_msg = '';

// Obtener listas para los selectores
$clientes = [];
$mascotas = [];
$veterinarios = [];

try {
    // Obtener clientes activos
    $clientes_sql = "SELECT id, nombre, documento_identidad, telefono FROM clientes ORDER BY nombre";
    $clientes_stmt = $db->query($clientes_sql);
    $clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener veterinarios
    $veterinarios_sql = "SELECT id, nombre FROM usuarios WHERE rol = 'veterinarian' AND estado = 'active' ORDER BY nombre";
    $veterinarios_stmt = $db->query($veterinarios_sql);
    $veterinarios = $veterinarios_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_msg = "Error al cargar datos: " . $e->getMessage();
}

// Función para obtener mascotas de un cliente
function getMascotasByCliente($db, $cliente_id) {
    try {
        $sql = "SELECT id, nombre, especie_id, raza FROM mascotas WHERE cliente_id = ? ORDER BY nombre";
        $stmt = $db->prepare($sql);
        $stmt->execute([$cliente_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Función para obtener especies
function getEspecies($db) {
    try {
        $sql = "SELECT id, nombre FROM especies WHERE activo = 1";
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Recoger datos del formulario
        $cliente_id = $_POST['cliente_id'] ?? null;
        $mascota_id = $_POST['mascota_id'] ?? null;
        $fecha_consulta = $_POST['fecha_consulta'] ?? date('Y-m-d');
        $veterinario_id = $_POST['veterinario_id'] ?? null;
        $edad_mascota = $_POST['edad_mascota'] ?? '';
        $persona_a_cargo = $_POST['persona_a_cargo'] ?? '';
        $cedula_persona = $_POST['cedula_persona'] ?? '';
        $telefono_persona = $_POST['telefono_persona'] ?? '';
        $direccion_persona = $_POST['direccion_persona'] ?? '';
        
        // Motivo y síntomas
        $motivo = $_POST['motivo'] ?? '';
        $signos_clinicos = $_POST['signos_clinicos'] ?? '';
        
        // Diagnóstico y procedimiento
        $diagnostico = $_POST['diagnostico'] ?? '';
        $diagnostico_detallado = $_POST['diagnostico_detallado'] ?? '';
        $procedimiento = $_POST['procedimiento'] ?? '';
        $examenes_solicitados = $_POST['examenes_solicitados'] ?? '';
        
        // Tratamiento
        $tratamiento_medicamentos = $_POST['tratamiento_medicamentos'] ?? '';
        $tratamiento_dosis = $_POST['tratamiento_dosis'] ?? '';
        $tratamiento_duracion = $_POST['tratamiento_duracion'] ?? '';
        $tratamiento_indicaciones = $_POST['tratamiento_indicaciones'] ?? '';
        
        // Observaciones y seguimiento
        $observaciones = $_POST['observaciones'] ?? '';
        $pendientes = $_POST['pendientes'] ?? '';
        $proxima_cita = $_POST['proxima_cita'] ?? null;
        $recomendaciones = $_POST['recomendaciones'] ?? '';
        
        // Costos
        $costo_consulta = $_POST['costo_consulta'] ?? 0;
        $costo_examenes = $_POST['costo_examenes'] ?? 0;
        $costo_medicamentos = $_POST['costo_medicamentos'] ?? 0;
        $valor_total = $costo_consulta + $costo_examenes + $costo_medicamentos;
        
        // Obtener información del veterinario
        $veterinario_nombre = '';
        if ($veterinario_id) {
            $vet_sql = "SELECT nombre FROM usuarios WHERE id = ?";
            $vet_stmt = $db->prepare($vet_sql);
            $vet_stmt->execute([$veterinario_id]);
            $vet_data = $vet_stmt->fetch(PDO::FETCH_ASSOC);
            $veterinario_nombre = $vet_data['nombre'] ?? '';
        }
        
        // Insertar en la tabla de consultas
        $insert_sql = "
            INSERT INTO consultas (
                mascota_id, cliente_id, veterinario_id, veterinario,
                edad_mascota, persona_a_cargo, cedula_persona, telefono_persona, direccion_persona,
                fecha_consulta, motivo, signos_clinicos, diagnostico, procedimiento,
                pendientes, valor_total, tratamiento, observaciones,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                NOW(), NOW()
            )
        ";
        
        $tratamiento_completo = "";
        if (!empty($tratamiento_medicamentos)) {
            $tratamiento_completo = "MEDICAMENTOS:\n" . $tratamiento_medicamentos . "\n\n";
            if (!empty($tratamiento_dosis)) {
                $tratamiento_completo .= "DOSIS: " . $tratamiento_dosis . "\n";
            }
            if (!empty($tratamiento_duracion)) {
                $tratamiento_completo .= "DURACIÓN: " . $tratamiento_duracion . "\n";
            }
            if (!empty($tratamiento_indicaciones)) {
                $tratamiento_completo .= "\nINDICACIONES:\n" . $tratamiento_indicaciones;
            }
        }
        
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([
            $mascota_id, $cliente_id, $veterinario_id, $veterinario_nombre,
            $edad_mascota, $persona_a_cargo, $cedula_persona, $telefono_persona, $direccion_persona,
            $fecha_consulta, $motivo, $signos_clinicos, $diagnostico . "\n\n" . $diagnostico_detallado, 
            $procedimiento . "\n\nEXÁMENES SOLICITADOS:\n" . $examenes_solicitados,
            $pendientes . "\n\nPRÓXIMA CITA: " . ($proxima_cita ?: 'Por definir') . "\n\nRECOMENDACIONES:\n" . $recomendaciones,
            $valor_total, $tratamiento_completo, $observaciones
        ]);
        
        $consulta_id = $db->lastInsertId();
        
        // Registrar la factura si hay costo
        if ($valor_total > 0) {
            try {
                // Obtener el último número de factura
                $factura_sql = "SELECT MAX(CAST(SUBSTRING(numero_factura, 2) AS UNSIGNED)) as ultimo_num FROM facturas";
                $factura_stmt = $db->query($factura_sql);
                $ultimo_num = $factura_stmt->fetch(PDO::FETCH_ASSOC)['ultimo_num'] ?? 0;
                $nuevo_numero = str_pad($ultimo_num + 1, 6, '0', STR_PAD_LEFT);
                $numero_factura = 'F' . $nuevo_numero;
                
                // Insertar factura
                $factura_insert_sql = "
                    INSERT INTO facturas (
                        numero_factura, cliente_id, fecha_emision, subtotal,
                        impuestos, total, estado, metodo_pago, notas, usuario_id
                    ) VALUES (?, ?, NOW(), ?, 0, ?, 'pending', 'cash', ?, ?)
                ";
                
                $factura_stmt = $db->prepare($factura_insert_sql);
                $factura_stmt->execute([
                    $numero_factura, $cliente_id, $valor_total, $valor_total,
                    "Consulta #" . $consulta_id . " - " . date('d/m/Y'), $_SESSION['usuario_id']
                ]);
                
                $factura_id = $db->lastInsertId();
                
                // Agregar servicio de consulta a la factura
                if ($costo_consulta > 0) {
                    $detalle_sql = "
                        INSERT INTO factura_detalles (factura_id, tipo_item, servicio_id, descripcion, cantidad, precio_unitario, subtotal)
                        VALUES (?, 'servicio', 1, 'Consulta General - Anamnesis', 1, ?, ?)
                    ";
                    $detalle_stmt = $db->prepare($detalle_sql);
                    $detalle_stmt->execute([$factura_id, $costo_consulta, $costo_consulta]);
                }
                
            } catch (PDOException $e) {
                // Si hay error con la factura, continuamos con la consulta
                error_log("Error al crear factura: " . $e->getMessage());
            }
        }
        
        $success_msg = "Anamnesis registrada exitosamente. N° de registro: " . $consulta_id;
        
        // Limpiar el formulario después de guardar exitosamente
        $_POST = [];
        
    } catch (PDOException $e) {
        $error_msg = "Error al guardar la anamnesis: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anamnesis - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #2E7D32;
            --primary-light: #81C784;
            --primary-very-light: #E8F5E9;
            --accent: #FF9800;
            --accent-dark: #F57C00;
            --secondary: #2196F3;
            --secondary-dark: #1976D2;
            --danger: #F44336;
            --warning: #FFC107;
            --success: #4CAF50;
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --text-lighter: #888888;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --border: #E0E0E0;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 40px rgba(0, 0, 0, 0.12);
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
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text-dark);
            line-height: 1.6;
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
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
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
            color: var(--primary);
        }

        .welcome-section p {
            color: var(--text-light);
            font-size: 1rem;
        }

        /* Botón de Retroceso */
        .back-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            background: var(--primary-very-light);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .back-button:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
        }

        /* Form Container - Estilo Dashboard */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Alert Messages - Estilo Dashboard */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: fadeIn 0.5s ease;
            border: 1px solid transparent;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-color: #f5c6cb;
        }

        .alert i {
            font-size: 1.5rem;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 2.5rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .form-section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .form-section-header h3 {
            color: var(--primary-dark);
            font-size: 1.3rem;
            font-weight: 600;
        }

        .form-section-header i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.95rem;
            color: var(--text-dark);
            background: var(--white);
            transition: all 0.3s ease;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        /* Checkbox Group */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
            margin-bottom: 1rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            transition: background-color 0.3s ease;
        }

        .checkbox-item:hover {
            background: var(--primary-very-light);
        }

        .checkbox-item input[type="checkbox"] {
            width: auto;
            transform: scale(1.2);
        }

        /* Costo Section - Estilo Dashboard */
        .costo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .costo-item {
            background: var(--primary-very-light);
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .costo-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .costo-item label {
            color: var(--primary-dark);
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .costo-total {
            background: linear-gradient(135deg, var(--primary-very-light), #dcedc8);
            border: 2px solid var(--primary);
            font-weight: 600;
        }

        .costo-total label {
            color: var(--primary-dark);
            font-size: 1.1rem;
        }

        /* Button Group - Estilo Dashboard */
        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-very-light);
        }

        .btn {
            padding: 0.85rem 2rem;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-secondary {
            background: var(--gray-light);
            color: var(--text-dark);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .btn-icon {
            font-size: 1.2rem;
        }

        /* Footer Minimalista */
        .main-footer {
            background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e9 100%);
            color: var(--text-dark);
            padding: 1.5rem 0;
            margin-top: auto;
            border-top: 1px solid rgba(76, 175, 80, 0.2);
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .footer-content {
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .footer-simple {
            font-size: 0.9rem;
            color: var(--text-light);
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 25px;
            border: 1px solid rgba(76, 175, 80, 0.2);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-sm);
        }

        .footer-text {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .footer-text strong {
            color: var(--primary-dark);
            font-weight: 600;
        }

        /* Loading State */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Auto-resize textareas */
        textarea {
            min-height: 100px;
            max-height: 300px;
        }

        /* Patient Info Preview */
        .patient-preview {
            background: var(--primary-very-light);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-top: 0.5rem;
            display: none;
            border: 1px dashed var(--primary);
            animation: fadeIn 0.3s ease;
        }

        .patient-preview.active {
            display: block;
        }

        .preview-info {
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .preview-info strong {
            color: var(--primary-dark);
        }

        /* Animación de partículas */
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
            background: rgba(76, 175, 80, 0.05);
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
                padding: 1.5rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .costo-grid {
                grid-template-columns: 1fr;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .footer-content {
                padding: 0 1rem;
            }
            
            .footer-simple {
                font-size: 0.8rem;
                padding: 0.6rem 1rem;
            }
            
            .footer-text {
                flex-direction: column;
                gap: 0.25rem;
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
                    <h1><i class='bx bx-clipboard'></i> Anamnesis</h1>
                    <p>Registro completo de la historia clínica del paciente</p>
                </div>
                
                <!-- Botón de Retroceso -->
                <a href="javascript:history.back()" class="back-button">
                    <i class='bx bx-arrow-back'></i> Volver Atrás
                </a>
            </header>

            <!-- Alert Messages -->
            <?php if ($success_msg): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle'></i>
                    <div><?php echo $success_msg; ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-error">
                    <i class='bx bx-error-circle'></i>
                    <div><?php echo $error_msg; ?></div>
                </div>
            <?php endif; ?>

            <!-- Anamnesis Form -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class='bx bx-notepad'></i>
                        Formulario de Anamnesis
                    </h3>
                     
                </div>

                <form id="anamnesisForm" method="POST" action=""><?php echo av_csrf_field(); ?>
                    
                    <!-- Sección 1: Datos del Paciente y Cliente -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class='bx bx-user-circle'></i>
                            <h3>Datos del Paciente y Cliente</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="cliente_id" class="required">Cliente</label>
                                <select id="cliente_id" name="cliente_id" required 
                                        onchange="cargarMascotas(this.value)">
                                    <option value="">Seleccionar cliente...</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>" 
                                                <?php echo ($_POST['cliente_id'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                            <?php echo $cliente['documento_identidad'] ? " - " . htmlspecialchars($cliente['documento_identidad']) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="mascota_id" class="required">Paciente</label>
                                <select id="mascota_id" name="mascota_id" required>
                                    <option value="">Seleccionar mascota...</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="fecha_consulta">Fecha de Consulta</label>
                                <input type="date" id="fecha_consulta" name="fecha_consulta" 
                                       value="<?php echo htmlspecialchars((string)($_POST['fecha_consulta'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="veterinario_id" class="required">Veterinario Responsable</label>
                                <select id="veterinario_id" name="veterinario_id" required>
                                    <option value="">Seleccionar veterinario...</option>
                                    <?php foreach ($veterinarios as $vet): ?>
                                        <option value="<?php echo $vet['id']; ?>" 
                                                <?php echo ($_POST['veterinario_id'] ?? '') == $vet['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vet['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="edad_mascota">Edad del Paciente</label>
                                <input type="text" id="edad_mascota" name="edad_mascota" 
                                       placeholder="Ej: 3 años, 6 meses"
                                       value="<?php echo htmlspecialchars($_POST['edad_mascota'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <!-- Información de la Persona a Cargo -->
                        <div class="form-section-header" style="margin-top: 2rem;">
                            <i class='bx bx-user-voice'></i>
                            <h3>Persona que Asiste con el Paciente</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="persona_a_cargo">Nombre Completo</label>
                                <input type="text" id="persona_a_cargo" name="persona_a_cargo" 
                                       placeholder="Nombre de quien trae al paciente"
                                       value="<?php echo htmlspecialchars($_POST['persona_a_cargo'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="cedula_persona">Cédula de Identidad</label>
                                <input type="text" id="cedula_persona" name="cedula_persona" 
                                       placeholder="Número de cédula"
                                       value="<?php echo htmlspecialchars($_POST['cedula_persona'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="telefono_persona">Teléfono</label>
                                <input type="tel" id="telefono_persona" name="telefono_persona" 
                                       placeholder="Número de contacto"
                                       value="<?php echo htmlspecialchars($_POST['telefono_persona'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="direccion_persona">Dirección</label>
                                <textarea id="direccion_persona" name="direccion_persona" 
                                          placeholder="Dirección de residencia"><?php echo htmlspecialchars($_POST['direccion_persona'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sección 2: Motivo de Consulta y Síntomas -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class='bx bx-heart-circle'></i>
                            <h3>Motivo de Consulta y Síntomas</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="motivo" class="required">Motivo de Consulta</label>
                                <textarea id="motivo" name="motivo" required 
                                          placeholder="Describa el motivo principal de la consulta..."><?php echo htmlspecialchars($_POST['motivo'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="signos_clinicos">Signos Clínicos Observados</label>
                                <div class="checkbox-group">
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="fiebre"> Fiebre
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="vomito"> Vómito
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="diarrea"> Diarrea
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="tos"> Tos
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="estornudos"> Estornudos
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="letargia"> Letargia
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="polidipsia"> Polidipsia
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="poliuria"> Poliuria
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="polifagia"> Polifagia
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="cojera"> Cojera
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="prurito"> Prurito
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="alopecia"> Alopecia
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="secrecion_ocular"> Secreción Ocular
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="signos[]" value="secrecion_nasal"> Secreción Nasal
                                    </label>
                                </div>
                                <textarea id="signos_clinicos" name="signos_clinicos" 
                                          placeholder="Describa los signos clínicos observados en detalle..."><?php echo htmlspecialchars($_POST['signos_clinicos'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sección 3: Diagnóstico y Procedimientos -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class='bx bx-search-alt'></i>
                            <h3>Diagnóstico y Procedimientos</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="diagnostico" class="required">Diagnóstico Presuntivo</label>
                                <select id="diagnostico" name="diagnostico" required>
                                    <option value="">Seleccionar diagnóstico...</option>
                                    <option value="infeccion_respiratoria" <?php echo ($_POST['diagnostico'] ?? '') == 'infeccion_respiratoria' ? 'selected' : ''; ?>>Infección Respiratoria</option>
                                    <option value="infeccion_urinaria" <?php echo ($_POST['diagnostico'] ?? '') == 'infeccion_urinaria' ? 'selected' : ''; ?>>Infección Urinaria</option>
                                    <option value="gastroenteritis" <?php echo ($_POST['diagnostico'] ?? '') == 'gastroenteritis' ? 'selected' : ''; ?>>Gastroenteritis</option>
                                    <option value="dermatitis_alergica" <?php echo ($_POST['diagnostico'] ?? '') == 'dermatitis_alergica' ? 'selected' : ''; ?>>Dermatitis Alérgica</option>
                                    <option value="otitis" <?php echo ($_POST['diagnostico'] ?? '') == 'otitis' ? 'selected' : ''; ?>>Otitis</option>
                                    <option value="artritis" <?php echo ($_POST['diagnostico'] ?? '') == 'artritis' ? 'selected' : ''; ?>>Artritis</option>
                                    <option value="control_rutina" <?php echo ($_POST['diagnostico'] ?? '') == 'control_rutina' ? 'selected' : ''; ?>>Control de Rutina</option>
                                    <option value="vacunacion" <?php echo ($_POST['diagnostico'] ?? '') == 'vacunacion' ? 'selected' : ''; ?>>Vacunación</option>
                                    <option value="desparasitacion" <?php echo ($_POST['diagnostico'] ?? '') == 'desparasitacion' ? 'selected' : ''; ?>>Desparasitación</option>
                                    <option value="otro" <?php echo ($_POST['diagnostico'] ?? '') == 'otro' ? 'selected' : ''; ?>>Otro</option>
                                </select>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="diagnostico_detallado">Diagnóstico Detallado</label>
                                <textarea id="diagnostico_detallado" name="diagnostico_detallado" 
                                          placeholder="Describa el diagnóstico en detalle..."><?php echo htmlspecialchars($_POST['diagnostico_detallado'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="procedimiento">Procedimientos Realizados</label>
                                <div class="checkbox-group">
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="examen_fisico"> Examen Físico
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="auscultacion"> Auscultación
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="palpacion"> Palpación
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="temperatura"> Toma de Temperatura
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="peso"> Toma de Peso
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="inyeccion"> Inyección
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="curacion"> Curación
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="procedimientos[]" value="sutura"> Sutura
                                    </label>
                                </div>
                                <textarea id="procedimiento" name="procedimiento" 
                                          placeholder="Describa los procedimientos realizados..."><?php echo htmlspecialchars($_POST['procedimiento'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="examenes_solicitados">Exámenes Solicitados/Realizados</label>
                                <div class="checkbox-group">
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="examenes[]" value="hematologia"> Hematología Completa
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="examenes[]" value="bioquimica"> Bioquímica Sanguínea
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="examenes[]" value="parasitologico"> Examen Parasitológico
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="examenes[]" value="ecografia"> Ecografía
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="examenes[]" value="radiografia"> Radiografía
                                    </label>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="examenes[]" value="pruebas_alergia"> Pruebas de Alergia
                                    </label>
                                </div>
                                <textarea id="examenes_solicitados" name="examenes_solicitados" 
                                          placeholder="Detalle los exámenes solicitados o realizados..."><?php echo htmlspecialchars($_POST['examenes_solicitados'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sección 4: Tratamiento -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class='bx bx-capsule'></i>
                            <h3>Tratamiento</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="tratamiento_medicamentos">Medicamentos Prescritos</label>
                                <textarea id="tratamiento_medicamentos" name="tratamiento_medicamentos" 
                                          placeholder="Lista de medicamentos prescritos..."><?php echo htmlspecialchars($_POST['tratamiento_medicamentos'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="tratamiento_dosis">Dosis y Frecuencia</label>
                                <textarea id="tratamiento_dosis" name="tratamiento_dosis" 
                                          placeholder="Ej: 1 tableta cada 12 horas"><?php echo htmlspecialchars($_POST['tratamiento_dosis'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="tratamiento_duracion">Duración del Tratamiento</label>
                                <textarea id="tratamiento_duracion" name="tratamiento_duracion" 
                                          placeholder="Ej: 7 días"><?php echo htmlspecialchars($_POST['tratamiento_duracion'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="tratamiento_indicaciones">Indicaciones Especiales</label>
                                <textarea id="tratamiento_indicaciones" name="tratamiento_indicaciones" 
                                          placeholder="Indicaciones específicas para el dueño..."><?php echo htmlspecialchars($_POST['tratamiento_indicaciones'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sección 5: Observaciones y Seguimiento -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class='bx bx-notepad'></i>
                            <h3>Observaciones y Seguimiento</h3>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="observaciones">Observaciones Generales</label>
                                <textarea id="observaciones" name="observaciones" 
                                          placeholder="Observaciones adicionales..."><?php echo htmlspecialchars($_POST['observaciones'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="pendientes">Pendientes</label>
                                <textarea id="pendientes" name="pendientes" 
                                          placeholder="Tareas pendientes..."><?php echo htmlspecialchars($_POST['pendientes'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="proxima_cita">Próxima Cita Programada</label>
                                <input type="date" id="proxima_cita" name="proxima_cita" 
                                       value="<?php echo htmlspecialchars((string)($_POST['proxima_cita'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="recomendaciones">Recomendaciones</label>
                                <textarea id="recomendaciones" name="recomendaciones" 
                                          placeholder="Recomendaciones para el cuidado..."><?php echo htmlspecialchars($_POST['recomendaciones'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sección 6: Costos -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class='bx bx-dollar-circle'></i>
                            <h3>Costos</h3>
                        </div>
                        
                        <div class="costo-grid">
                            <div class="costo-item">
                                <label for="costo_consulta">Costo de Consulta</label>
                                <input type="number" id="costo_consulta" name="costo_consulta" 
                                       step="0.01" min="0" 
                                       value="<?php echo htmlspecialchars((string)($_POST['costo_consulta'] ?? '20.00'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="costo-item">
                                <label for="costo_examenes">Costo de Exámenes</label>
                                <input type="number" id="costo_examenes" name="costo_examenes" 
                                       step="0.01" min="0" 
                                       value="<?php echo htmlspecialchars((string)($_POST['costo_examenes'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="costo-item">
                                <label for="costo_medicamentos">Costo de Medicamentos</label>
                                <input type="number" id="costo_medicamentos" name="costo_medicamentos" 
                                       step="0.01" min="0" 
                                       value="<?php echo htmlspecialchars((string)($_POST['costo_medicamentos'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="costo-item costo-total">
                                <label>TOTAL</label>
                                <input type="text" id="valor_total" readonly 
                                       value="$0.00" style="font-weight: bold; font-size: 1.2rem;">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Botones de Acción -->
                    <div class="button-group">
                        <button type="button" class="btn btn-secondary" onclick="limpiarFormulario()">
                            <i class='bx bx-reset btn-icon'></i> Limpiar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class='bx bx-save btn-icon'></i> Guardar Anamnesis
                        </button>
                    </div>
                </form>
            </div>

            <!-- Footer Minimalista -->
            <footer class="main-footer">
                <div class="footer-content">
                    <div class="footer-simple">
                        <span class="footer-text">© 2026 VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></span>
                    </div>
                </div>
            </footer>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Cargar mascotas cuando se selecciona un cliente
        function cargarMascotas(clienteId) {
            const mascotaSelect = document.getElementById('mascota_id');
            mascotaSelect.innerHTML = '<option value="">Cargando mascotas...</option>';
            
            if (!clienteId) {
                mascotaSelect.innerHTML = '<option value="">Seleccionar mascota...</option>';
                return;
            }
            
            fetch(`api/get_mascotas.php?cliente_id=${clienteId}`)
                .then(response => response.json())
                .then(data => {
                    mascotaSelect.innerHTML = '<option value="">Seleccionar mascota...</option>';
                    data.forEach(mascota => {
                        const option = document.createElement('option');
                        option.value = mascota.id;
                        option.textContent = mascota.nombre + ' - ' + mascota.raza;
                        mascotaSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    mascotaSelect.innerHTML = '<option value="">Error al cargar mascotas</option>';
                });
        }
        
        // Calcular total automáticamente
        function calcularTotal() {
            const costoConsulta = parseFloat(document.getElementById('costo_consulta').value) || 0;
            const costoExamenes = parseFloat(document.getElementById('costo_examenes').value) || 0;
            const costoMedicamentos = parseFloat(document.getElementById('costo_medicamentos').value) || 0;
            const total = costoConsulta + costoExamenes + costoMedicamentos;
            
            document.getElementById('valor_total').value = '$' + total.toFixed(2);
        }
        
        // Actualizar checkboxes en textarea
        function actualizarCheckboxes(checkboxesName, textareaId) {
            const checkboxes = document.querySelectorAll(`input[name="${checkboxesName}[]"]:checked`);
            const valores = Array.from(checkboxes).map(cb => cb.value);
            
            const textarea = document.getElementById(textareaId);
            let textoActual = textarea.value;
            
            // Remover valores ya presentes
            valores.forEach(valor => {
                const regex = new RegExp(valor + '[,.]?\\s*', 'gi');
                textoActual = textoActual.replace(regex, '');
            });
            
            // Agregar nuevos valores
            if (valores.length > 0) {
                if (textoActual.trim()) {
                    textoActual += ', ' + valores.join(', ');
                } else {
                    textoActual = valores.join(', ');
                }
            }
            
            textarea.value = textoActual;
        }
        
        // Limpiar formulario
        function limpiarFormulario() {
            if (confirm('¿Está seguro de que desea limpiar todo el formulario?')) {
                document.getElementById('anamnesisForm').reset();
                document.getElementById('mascota_id').innerHTML = '<option value="">Seleccionar mascota...</option>';
                document.getElementById('valor_total').value = '$0.00';
            }
        }
        
        // Función para autocompletar datos del cliente
        function autocompletarDatosCliente() {
            const clienteId = document.getElementById('cliente_id').value;
            if (!clienteId) return;
            
            fetch(`api/get_cliente.php?id=${clienteId}`)
                .then(response => response.json())
                .then(cliente => {
                    if (cliente) {
                        // Autocompletar datos de la persona a cargo si están vacíos
                        const personaInput = document.getElementById('persona_a_cargo');
                        if (!personaInput.value) {
                            personaInput.value = cliente.nombre;
                        }
                        
                        const cedulaInput = document.getElementById('cedula_persona');
                        if (!cedulaInput.value && cliente.documento_identidad) {
                            cedulaInput.value = cliente.documento_identidad;
                        }
                        
                        const telefonoInput = document.getElementById('telefono_persona');
                        if (!telefonoInput.value && cliente.telefono) {
                            telefonoInput.value = cliente.telefono;
                        }
                    }
                });
        }
        
        // Inicializar eventos
        document.addEventListener('DOMContentLoaded', function() {
            // Calcular total inicial
            calcularTotal();
            
            // Actualizar total cuando cambian los costos
            ['costo_consulta', 'costo_examenes', 'costo_medicamentos'].forEach(id => {
                document.getElementById(id).addEventListener('input', calcularTotal);
            });
            
            // Actualizar textareas con checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const name = this.getAttribute('name');
                    if (name.includes('signos')) {
                        actualizarCheckboxes('signos', 'signos_clinicos');
                    } else if (name.includes('procedimientos')) {
                        actualizarCheckboxes('procedimientos', 'procedimiento');
                    } else if (name.includes('examenes')) {
                        actualizarCheckboxes('examenes', 'examenes_solicitados');
                    }
                });
            });
            
            // Validar formulario antes de enviar
            document.getElementById('anamnesisForm').addEventListener('submit', function(e) {
                const clienteId = document.getElementById('cliente_id').value;
                const mascotaId = document.getElementById('mascota_id').value;
                const veterinarioId = document.getElementById('veterinario_id').value;
                const diagnostico = document.getElementById('diagnostico').value;
                const motivo = document.getElementById('motivo').value;
                
                if (!clienteId || !mascotaId || !veterinarioId || !diagnostico || !motivo) {
                    e.preventDefault();
                    alert('Por favor complete todos los campos requeridos (*)');
                    return false;
                }
                
                // Mostrar loading
                this.classList.add('loading');
            });
            
            // Si hay un cliente seleccionado en el POST, cargar sus mascotas
            const clienteId = document.getElementById('cliente_id').value;
            if (clienteId) {
                cargarMascotas(clienteId);
                
                // Establecer la mascota seleccionada después de cargarlas
                setTimeout(() => {
                    const mascotaId = "<?php echo (int)($_POST['mascota_id'] ?? 0); ?>";
                    if (mascotaId) {
                        document.getElementById('mascota_id').value = mascotaId;
                    }
                }, 500);
            }
            
            // Llamar a autocompletar cuando se selecciona cliente
            document.getElementById('cliente_id').addEventListener('change', autocompletarDatosCliente);
            
            // Crear partículas dinámicas
            const particlesContainer = document.querySelector('.particles');
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
                    background: ${Math.random() > 0.5 ? 'rgba(76, 175, 80, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        });
    </script>
</body>
</html>