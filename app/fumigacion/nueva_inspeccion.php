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

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $urbanizacion = trim($_POST['urbanizacion'] ?? '');
    $fecha_inspeccion = $_POST['fecha_inspeccion'] ?? '';
    $hora_inspeccion = $_POST['hora_inspeccion'] ?? '';
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    // Validar campos obligatorios
    if (empty($nombre_cliente)) {
        $error = 'Por favor ingrese el nombre del cliente.';
    } elseif (empty($telefono)) {
        $error = 'Por favor ingrese el teléfono del cliente.';
    } elseif (empty($direccion)) {
        $error = 'Por favor ingrese la dirección.';
    } elseif (empty($fecha_inspeccion)) {
        $error = 'Por favor seleccione la fecha de inspección.';
    } else {
        // Validar formato de teléfono (opcional)
        $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
        if (strlen($telefono_limpio) < 10) {
            $error = 'El teléfono debe tener al menos 10 dígitos.';
        } else {
            try {
                $query = "INSERT INTO inspecciones_fumigacion 
                          (nombre_cliente, telefono, direccion, urbanizacion, fecha_solicitud, fecha_inspeccion, hora_inspeccion, observaciones, estado) 
                          VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, 'Pendiente')";
                $stmt = $db->prepare($query);
                $resultado = $stmt->execute([$nombre_cliente, $telefono, $direccion, $urbanizacion, $fecha_inspeccion, $hora_inspeccion, $observaciones]);
                
                if ($resultado) {
                    $mensaje = 'Inspección agendada correctamente.';
                    // Limpiar variables para el formulario
                    $nombre_cliente = $telefono = $direccion = $urbanizacion = $observaciones = '';
                    $fecha_inspeccion = date('Y-m-d');
                    $hora_inspeccion = '';
                } else {
                    $error = 'Error al guardar la inspección.';
                }
            } catch (PDOException $e) {
                $error = 'Error en la base de datos: ' . $e->getMessage();
            }
        }
    }
} else {
    // Valores por defecto para el formulario
    $fecha_inspeccion = date('Y-m-d');
    $hora_inspeccion = '';
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
    <title>Nueva Inspección - Fumigación - VetNexo</title>
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

        .form-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
            width: 100%;
        }

        .form-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .form-header h2 {
            font-size: 1.2rem;
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
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-soft);
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 0.25rem;
        }

        .required-note {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .required-note span {
            color: var(--emergency);
            font-weight: 600;
        }

        .info-note {
            background: var(--azul-soft);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--azul);
            border: 1px solid var(--azul-light);
        }

        .info-note i {
            font-size: 1.5rem;
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
            
            .form-row {
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
                        Nueva Inspección de Fumigación
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Programa una nueva inspección para un cliente
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="index.php" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Volver al Dashboard
                    </a>
                    <a href="inspecciones_pendientes.php" class="btn btn-info">
                        <i class='bx bx-list-ul'></i> Ver Pendientes
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
            
            <!-- Formulario -->
            <div class="form-container fade-in">
                <div class="form-header">
                    <h2>
                        <i class='bx bx-search-alt'></i>
                        Datos de la Inspección
                    </h2>
                </div>
                
                <div class="form-body">
                    <?php if ($mensaje): ?>
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $mensaje; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $error; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="formInspeccion"><?php echo av_csrf_field(); ?>
                        <!-- Nombre del Cliente -->
                        <div class="form-group">
                            <label class="form-label required">Nombre del Cliente</label>
                            <input type="text" 
                                   name="nombre_cliente" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($nombre_cliente ?? ''); ?>" 
                                   required 
                                   placeholder="Ej: Juan Pérez"
                                   autofocus>
                        </div>
                        
                        <!-- Teléfono -->
                        <div class="form-group">
                            <label class="form-label required">Teléfono</label>
                            <input type="text" 
                                   name="telefono" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($telefono ?? ''); ?>" 
                                   required 
                                   placeholder="Ej: 0414-1234567">
                        </div>
                        
                        <!-- Dirección -->
                        <div class="form-group">
                            <label class="form-label required">Dirección</label>
                            <textarea name="direccion" 
                                      class="form-control" 
                                      required 
                                      placeholder="Ej: Calle Principal, Casa N° 10, Sector Las Flores"><?php echo htmlspecialchars($direccion ?? ''); ?></textarea>
                        </div>
                        
                        <!-- Urbanización (opcional) -->
                        <div class="form-group">
                            <label class="form-label">Urbanización / Sector</label>
                            <input type="text" 
                                   name="urbanizacion" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($urbanizacion ?? ''); ?>" 
                                   placeholder="Ej: Lomas del Viento, El Paraíso, etc.">
                            <div class="form-text">Opcional - Para mejor organización</div>
                        </div>
                        
                        <!-- Fecha y Hora -->
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Fecha de Inspección</label>
                                <input type="date" 
                                       name="fecha_inspeccion" 
                                       class="form-control" 
                                       value="<?php echo $fecha_inspeccion; ?>" 
                                       required
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Hora de Inspección</label>
                                <input type="time" 
                                       name="hora_inspeccion" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($hora_inspeccion ?? ''); ?>">
                                <div class="form-text">Opcional - Si tiene hora específica</div>
                            </div>
                        </div>
                        
                        <!-- Observaciones -->
                        <div class="form-group">
                            <label class="form-label">Observaciones / Notas</label>
                            <textarea name="observaciones" 
                                      class="form-control" 
                                      placeholder="Ej: Cliente prefiere horario en la tarde, tiene perro, etc."><?php echo htmlspecialchars($observaciones ?? ''); ?></textarea>
                        </div>
                        
                        <!-- Campos requeridos indicación -->
                        <div class="required-note">
                            <i class='bx bx-info-circle' style="color: var(--info);"></i>
                            Los campos marcados con <span>*</span> son obligatorios
                        </div>
                        
                        <!-- Información adicional -->
                        <div class="info-note">
                            <i class='bx bx-time'></i>
                            <div>
                                <strong>Nota:</strong> Las inspecciones quedarán en estado <strong>"Pendiente"</strong> hasta que las realices.
                            </div>
                        </div>
                        
                        <!-- Botones de acción -->
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-save'></i>
                                Guardar Inspección
                            </button>
                            <button type="reset" class="btn btn-secondary" onclick="return confirm('¿Estás seguro de limpiar el formulario?')">
                                <i class='bx bx-reset'></i>
                                Limpiar
                            </button>
                        </div>
                    </form>
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
    
    <script src="../js/sidebar.js"></script>
    <script>
        // Validación adicional del lado del cliente
        document.getElementById('formInspeccion').addEventListener('submit', function(e) {
            const nombre = document.querySelector('input[name="nombre_cliente"]').value.trim();
            const telefono = document.querySelector('input[name="telefono"]').value.trim();
            const direccion = document.querySelector('textarea[name="direccion"]').value.trim();
            const fecha = document.querySelector('input[name="fecha_inspeccion"]').value;
            
            if (!nombre || !telefono || !direccion || !fecha) {
                e.preventDefault();
                alert('Por favor complete todos los campos obligatorios.');
                return false;
            }
            
            // Validar formato de teléfono (mínimo 10 dígitos)
            const telefonoLimpio = telefono.replace(/\D/g, '');
            if (telefonoLimpio.length < 10) {
                e.preventDefault();
                alert('El teléfono debe tener al menos 10 dígitos.');
                return false;
            }
            
            // Validar que la fecha no sea anterior a hoy
            const fechaSeleccionada = new Date(fecha);
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            
            if (fechaSeleccionada < hoy) {
                e.preventDefault();
                alert('La fecha de inspección no puede ser anterior a hoy.');
                return false;
            }
            
            // Mostrar estado de carga
            this.classList.add('loading');
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Guardando...';
            submitBtn.disabled = true;
        });
        
        // Formatear teléfono mientras se escribe
        document.querySelector('input[name="telefono"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            // Formato: 0414-1234567 o 0414-123-4567
            if (value.length > 0) {
                if (value.length <= 4) {
                    value = value.replace(/(\d{1,4})/, '$1');
                } else if (value.length <= 7) {
                    value = value.replace(/(\d{4})(\d{1,3})/, '$1-$2');
                } else {
                    value = value.replace(/(\d{4})(\d{3})(\d{1,4})/, '$1-$2-$3');
                }
            }
            
            e.target.value = value;
        });

        // Reset con confirmación
        document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
            if (!confirm('¿Estás seguro de limpiar todos los campos del formulario?')) {
                e.preventDefault();
            }
        });

        // Crear partículas dinámicas
        document.addEventListener('DOMContentLoaded', function() {
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