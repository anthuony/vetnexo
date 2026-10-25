<?php
// citaspeluqueria.php
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
    
    // La base real almacena el precio final de la cita en citas.costo_total.

    // Inicializar variables
    $error = '';
    $servicios_peluqueria = [];
    $citas_peluqueria = [];
    $mascotas = [];

    // Obtener solo servicios de peluquería
    $sql = "SELECT id, nombre, precio, duracion FROM servicios WHERE categoria = 'grooming' AND activo = 1";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $servicios_peluqueria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Procesar formulario de nueva cita
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_cita'])) {
        $mascota_id = $_POST['mascota_id'] ?? '';
        $servicio_id = $_POST['servicio_id'] ?? '';
        $fecha_cita = $_POST['fecha_cita'] ?? '';
        $hora_cita = $_POST['hora_cita'] ?? '';
        $notas = $_POST['notas'] ?? '';
        $precio_personalizado = $_POST['precio_personalizado'] ?? '';
        
        // Si hay precio personalizado, usarlo en lugar del precio del servicio
        if (!empty($precio_personalizado)) {
            $precio_final = $precio_personalizado;
        } else {
            // Obtener precio del servicio seleccionado
            $sql_precio = "SELECT precio FROM servicios WHERE id = ?";
            $stmt_precio = $db->prepare($sql_precio);
            $stmt_precio->execute([$servicio_id]);
            $servicio = $stmt_precio->fetch(PDO::FETCH_ASSOC);
            $precio_final = $servicio['precio'] ?? 0;
        }
        
        // Validaciones básicas
        if (empty($mascota_id) || empty($servicio_id) || empty($fecha_cita) || empty($hora_cita)) {
            $error = "Todos los campos obligatorios deben ser completados";
        } else {
            // Validar que la fecha no sea pasada
            $hoy = date('Y-m-d');
            if ($fecha_cita < $hoy) {
                $error = "No se pueden agendar citas en fechas pasadas";
            } else {
                // Obtener cliente_id de la mascota
                $sql_mascota = "SELECT cliente_id FROM mascotas WHERE id = ?";
                $stmt_mascota = $db->prepare($sql_mascota);
                $stmt_mascota->execute([$mascota_id]);
                $mascota = $stmt_mascota->fetch(PDO::FETCH_ASSOC);
                
                if ($mascota) {
                    $cliente_id = $mascota['cliente_id'];
                    
                    // Verificar disponibilidad de horario
                    $check_sql = "SELECT id FROM citas WHERE fecha_cita = ? AND hora_cita = ? AND estado NOT IN ('cancelled', 'completed')";
                    $check_stmt = $db->prepare($check_sql);
                    $check_stmt->execute([$fecha_cita, $hora_cita]);
                    
                    if ($check_stmt->rowCount() > 0) {
                        $error = "Ya existe una cita programada para este horario";
                    } else {
                        $sql = "INSERT INTO citas
                                (mascota_id, cliente_id, servicio_id, fecha_cita, hora_cita, estado, notas, costo_total)
                                VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?)";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([
                            (int)$mascota_id,
                            $cliente_id,
                            (int)$servicio_id,
                            $fecha_cita,
                            $hora_cita,
                            $notas,
                            round((float)$precio_final, 2)
                        ]);
                        
                        $_SESSION['mensaje'] = "Cita de peluquería agendada correctamente";
                        header('Location: citaspeluqueria.php');
                        exit();
                    }
                } else {
                    $error = "Mascota no encontrada";
                }
            }
        }
    }

    // Procesar cancelación de cita
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_cita'])) {
        $cita_id = $_POST['cita_id'] ?? '';
        
        if (!empty($cita_id)) {
            $sql = "UPDATE citas SET estado = 'cancelled' WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$cita_id]);
            
            $_SESSION['mensaje'] = "Cita cancelada correctamente";
            header('Location: citaspeluqueria.php');
            exit();
        }
    }

    // Obtener citas de peluquería. costo_total conserva cualquier precio personalizado.
    $sql = "SELECT c.*, m.nombre as mascota_nombre, s.nombre as servicio_nombre, s.precio as servicio_precio,
                   COALESCE(c.costo_total, s.precio) AS precio_final,
                   cl.nombre as cliente_nombre, cl.documento_identidad, cl.telefono, cl.direccion
            FROM citas c
            INNER JOIN mascotas m ON c.mascota_id = m.id
            INNER JOIN servicios s ON c.servicio_id = s.id
            INNER JOIN clientes cl ON c.cliente_id = cl.id
            WHERE s.categoria = 'grooming'
            ORDER BY c.fecha_cita DESC, c.hora_cita DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $citas_peluqueria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener mascotas para el formulario
    $sql = "SELECT id, nombre, cliente_id FROM mascotas ORDER BY nombre";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("[VetNexo][citaspeluqueria] " . $e->getMessage()); die("No se pudieron cargar las citas de peluquería.");
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
    <title>Citas de Peluquería - VetNexo</title>
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
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.5s ease;
            gap: 1rem;
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
            white-space: nowrap;
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

        /* Grid Principal Mejorado */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
            width: 100%;
            box-sizing: border-box;
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
            box-sizing: border-box;
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
            min-height: 100px;
            resize: vertical;
            max-width: 100%;
            min-width: 100%;
        }

        /* Custom Price Styles */
        .price-option {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            border: 1px solid var(--primary-light);
        }

        .custom-price-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
        }

        .custom-price-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .custom-price-toggle label {
            margin: 0;
            font-weight: 500;
            color: var(--text-dark);
            cursor: pointer;
            font-size: 0.9rem;
        }

        .custom-price-input {
            display: none;
            margin-top: 0.5rem;
        }

        .custom-price-input.show {
            display: block;
        }

        .custom-price-input input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
        }

        .custom-price-input input:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Service List */
        .service-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .service-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }

        .service-item:hover {
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
        }

        .service-item:last-child {
            border-bottom: none;
        }

        .service-info h4 {
            margin: 0;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 1rem;
        }

        .service-info p {
            margin: 0.25rem 0 0 0;
            color: var(--text-light);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .service-price {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 1.1rem;
            background: var(--primary-soft);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
        }

        /* Table Styles */
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

        .badge-scheduled {
            background: #fff3e0;
            color: #F99B07;
        }

        .badge-confirmed {
            background: #e3f2fd;
            color: #2261BD;
        }

        .badge-in_progress {
            background: #ffebee;
            color: #F91302;
            animation: pulse 1s infinite;
        }

        .badge-completed {
            background: #e8f5e9;
            color: #0F6C33;
        }

        .badge-cancelled {
            background: #f5f5f5;
            color: #5A4B37;
        }

        .badge-no_show {
            background: #f5f5f5;
            color: #5A4B37;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .cancel-form {
            display: inline;
        }

        /* Alertas */
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
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn,
            .header-actions .back-btn {
                width: 100%;
                justify-content: center;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .footer-content {
                padding: 0 1rem;
            }
            
            .footer-simple {
                font-size: 0.8rem;
                padding: 0.6rem 1rem;
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
                        <i class='bx bx-scissors'></i>
                        Citas de Peluquería
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestiona las citas de peluquería de VetNexo
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
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
            <?php if (isset($_SESSION['mensaje'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $_SESSION['mensaje']; unset($_SESSION['mensaje']); ?>
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
                                <?php echo $error; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Notificación sobre facturación -->
            <div class="content-section">
                <div class="section-body">
                    <div class="alert alert-info">
                        <div class="alert-icon">
                            <i class='bx bx-info-circle'></i>
                        </div>
                        <div class="alert-content">
                            <strong>Facturación Rápida:</strong> Puedes generar facturas directamente desde las citas usando el botón <i class='bx bx-receipt'></i> en la tabla de citas programadas.
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Formulario de Nueva Cita -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-calendar-plus'></i>
                            Agendar Nueva Cita
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <form method="POST" action=""><?php echo av_csrf_field(); ?>
                            <div class="form-group">
                                <label for="mascota_id" class="form-label required">Mascota</label>
                                <select id="mascota_id" name="mascota_id" class="form-control select" required>
                                    <option value="">Seleccione una mascota</option>
                                    <?php foreach ($mascotas as $mascota): ?>
                                        <option value="<?php echo $mascota['id']; ?>">
                                            <?php echo htmlspecialchars($mascota['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="servicio_id" class="form-label required">Servicio de Peluquería</label>
                                <select id="servicio_id" name="servicio_id" class="form-control select" required onchange="updatePriceDisplay(this)">
                                    <option value="">Seleccione un servicio</option>
                                    <?php foreach ($servicios_peluqueria as $servicio): ?>
                                        <option value="<?php echo $servicio['id']; ?>" data-precio="<?php echo $servicio['precio']; ?>">
                                            <?php echo htmlspecialchars($servicio['nombre']); ?> - $<?php echo $servicio['precio']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                    
                                <!-- Opción de precio personalizado -->
                                <div class="price-option">
                                    <div class="custom-price-toggle">
                                        <input type="checkbox" id="precio_personalizado_check" name="precio_personalizado_check" onchange="toggleCustomPrice(this)">
                                        <label for="precio_personalizado_check">Usar otro precio</label>
                                    </div>
                                    <div class="custom-price-input" id="customPriceContainer">
                                        <input type="number" id="precio_personalizado" name="precio_personalizado" 
                                               placeholder="Ingrese el precio personalizado" min="0" step="0.01" class="form-control">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="fecha_cita" class="form-label required">Fecha</label>
                                <input type="date" id="fecha_cita" name="fecha_cita" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="hora_cita" class="form-label required">Hora</label>
                                <input type="time" id="hora_cita" name="hora_cita" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="notas" class="form-label">Notas</label>
                                <textarea id="notas" name="notas" class="form-control" placeholder="Observaciones o detalles especiales..."></textarea>
                            </div>

                            <button type="submit" name="agregar_cita" class="btn btn-success">
                                <i class='bx bx-calendar-check'></i>
                                Agendar Cita
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Servicios Disponibles -->
                <div class="content-section">
                    <div class="section-header">
                        <h3>
                            <i class='bx bx-cut'></i>
                            Servicios Disponibles
                        </h3>
                    </div>
                    
                    <div class="section-body">
                        <ul class="service-list">
                            <?php if (!empty($servicios_peluqueria)): ?>
                                <?php foreach ($servicios_peluqueria as $servicio): ?>
                                    <li class="service-item">
                                        <div class="service-info">
                                            <h4><?php echo htmlspecialchars($servicio['nombre']); ?></h4>
                                            <p>
                                                <i class='bx bx-time'></i>
                                                Duración: <?php echo $servicio['duracion']; ?> minutos
                                            </p>
                                        </div>
                                        <div class="service-price">$<?php echo $servicio['precio']; ?></div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="service-item">
                                    <div class="service-info">
                                        <h4>No hay servicios de peluquería disponibles</h4>
                                        <p>Contacte al administrador del sistema</p>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Lista de Citas Programadas -->
            <div class="content-section">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-list-ul'></i>
                        Citas de Peluquería Programadas
                    </h2>
                    <span class="badge badge-scheduled"><?php echo count($citas_peluqueria); ?> citas</span>
                </div>
                
                <div class="section-body">
                    <div class="table-container">
                        <?php if (empty($citas_peluqueria)): ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-x'></i>
                                <h3>No hay citas de peluquería programadas</h3>
                                <p>Agenda tu primera cita usando el formulario de arriba</p>
                                <div class="empty-actions">
                                    <button class="btn btn-primary" onclick="document.getElementById('mascota_id').focus()">
                                        <i class='bx bx-plus'></i>
                                        Agendar Ahora
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Hora</th>
                                        <th>Mascota</th>
                                        <th>Cliente</th>
                                        <th>Servicio</th>
                                        <th>Precio</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($citas_peluqueria as $cita): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($cita['fecha_cita'])); ?></td>
                                            <td><?php echo date('H:i', strtotime($cita['hora_cita'])); ?></td>
                                            <td><?php echo htmlspecialchars($cita['mascota_nombre'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($cita['cliente_nombre'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($cita['servicio_nombre'] ?? ''); ?></td>
                                            <td>
                                                <strong>
                                                    $<?php 
                                                    $precio_mostrar = isset($cita['precio_final']) ? $cita['precio_final'] : $cita['servicio_precio'];
                                                    echo number_format($precio_mostrar, 2);
                                                    ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $cita['estado']; ?>">
                                                    <?php 
                                                    $estados = [
                                                        'scheduled' => 'Programada',
                                                        'confirmed' => 'Confirmada',
                                                        'in_progress' => 'En Progreso',
                                                        'completed' => 'Completada',
                                                        'cancelled' => 'Cancelada',
                                                        'no_show' => 'No Presentado'
                                                    ];
                                                    echo $estados[$cita['estado']] ?? $cita['estado'];
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="editar_cita.php?id=<?php echo $cita['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class='bx bx-edit'></i>
                                                    </a>
                                                    <form method="POST" action="" class="cancel-form"><?php echo av_csrf_field(); ?>
                                                        <input type="hidden" name="cita_id" value="<?php echo $cita['id']; ?>">
                                                        <input type="hidden" name="cancelar_cita" value="1">
                                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Está seguro de que desea cancelar esta cita?')">
                                                            <i class='bx bx-x'></i>
                                                        </button>
                                                    </form>
                                                    <button class="btn btn-success btn-sm" 
                                                            onclick="facturarCita(
                                                                <?php echo $cita['id']; ?>, 
                                                                '<?php echo htmlspecialchars($cita['mascota_nombre'] ?? ''); ?>', 
                                                                '<?php echo htmlspecialchars($cita['cliente_nombre'] ?? ''); ?>', 
                                                                '<?php echo htmlspecialchars($cita['servicio_nombre'] ?? ''); ?>', 
                                                                <?php echo $cita['servicio_id']; ?>,
                                                                <?php echo isset($cita['precio_final']) ? $cita['precio_final'] : $cita['servicio_precio']; ?>,
                                                                '<?php echo htmlspecialchars($cita['documento_identidad'] ?? ''); ?>',
                                                                '<?php echo htmlspecialchars($cita['telefono'] ?? ''); ?>',
                                                                '<?php echo htmlspecialchars($cita['direccion'] ?? ''); ?>'
                                                            )">
                                                        <i class='bx bx-receipt'></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
            // Validación de fecha y hora
            const fechaInput = document.getElementById('fecha_cita');
            const hoy = new Date().toISOString().split('T')[0];
            fechaInput.setAttribute('min', hoy);
            
            // Prevenir fechas pasadas
            fechaInput.addEventListener('change', function() {
                if (this.value < hoy) {
                    alert('No puede seleccionar una fecha pasada');
                    this.value = hoy;
                }
            });

            // Efecto de carga para las secciones
            const sections = document.querySelectorAll('.content-section');
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

        function editarCita(id) {
            window.location.href = 'editar_cita.php?id=' + id;
        }

        // Función para mostrar/ocultar precio personalizado
        function toggleCustomPrice(checkbox) {
            const customPriceContainer = document.getElementById('customPriceContainer');
            const customPriceInput = document.getElementById('precio_personalizado');
            
            if (checkbox.checked) {
                customPriceContainer.classList.add('show');
                customPriceInput.focus();
            } else {
                customPriceContainer.classList.remove('show');
                customPriceInput.value = '';
            }
        }

        // Función para actualizar la visualización del precio
        function updatePriceDisplay(select) {
            const selectedOption = select.options[select.selectedIndex];
            const customPriceCheckbox = document.getElementById('precio_personalizado_check');
            
            if (selectedOption.value && !customPriceCheckbox.checked) {
                const precio = selectedOption.getAttribute('data-precio');
                console.log('Precio del servicio seleccionado:', precio);
            }
        }

        // Función para facturar cita
        function facturarCita(citaId, mascotaNombre, clienteNombre, servicioNombre, servicioId, servicioPrecio, clienteDocumento, clienteTelefono, clienteDireccion) {
            // Asegurar que los valores no sean undefined o null
            mascotaNombre = mascotaNombre || '';
            clienteNombre = clienteNombre || '';
            servicioNombre = servicioNombre || '';
            clienteDocumento = clienteDocumento || '';
            clienteTelefono = clienteTelefono || '';
            clienteDireccion = clienteDireccion || '';
            
            // Guardar datos en sessionStorage para usar en nueva factura
            const citaData = {
                id: citaId,
                mascota: mascotaNombre,
                cliente: clienteNombre,
                servicio: servicioNombre,
                servicio_id: servicioId,
                servicio_precio: servicioPrecio,
                cliente_documento: clienteDocumento,
                cliente_telefono: clienteTelefono,
                cliente_direccion: clienteDireccion,
                tipo: 'peluqueria'
            };
            
            sessionStorage.setItem('citaParaFacturar', JSON.stringify(citaData));
            
            // Mostrar opciones al usuario
            const opcion = confirm('¿Desea generar una factura para esta cita?\n\n' +
                                 'Cliente: ' + clienteNombre + '\n' +
                                 'Servicio: ' + servicioNombre + '\n' +
                                 'Precio: $' + servicioPrecio + '\n\n' +
                                 'Click en Aceptar para ir a Nueva Factura\n' +
                                 'Click en Cancelar para solo guardar los datos');
            
            if (opcion) {
                // Redirigir a nueva factura
                window.location.href = 'nuevafactura.php?cita_id=' + citaId + '&tipo=peluqueria';
            } else {
                // Solo guardar datos y mostrar mensaje
                alert('Datos de la cita guardados. Serán precargados automáticamente cuando vaya a "Nueva Factura".');
            }
        }
    </script>
</body>
</html>