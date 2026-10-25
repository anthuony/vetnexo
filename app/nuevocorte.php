<?php
// nuevocorte.php
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
    
    // Inicializar variables
    $error = '';
    $success = $_SESSION['av_corte_success']??'';unset($_SESSION['av_corte_success']);
    $mascotas = [];
    $servicios_peluqueria = [];
    $clientes = [];
    $especies = [];

    // Obtener mascotas para el formulario
    $sql = "SELECT m.id, m.nombre, m.raza, m.especie_id, e.nombre as especie_nombre, 
                   cl.nombre as cliente_nombre, cl.id as cliente_id
            FROM mascotas m
            LEFT JOIN especies e ON m.especie_id = e.id
            LEFT JOIN clientes cl ON m.cliente_id = cl.id
            ORDER BY m.nombre";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener servicios de peluquería
    $sql = "SELECT id, nombre 
            FROM servicios 
            WHERE categoria = 'grooming' AND activo = 1
            ORDER BY nombre";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $servicios_peluqueria = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener clientes para nuevo registro
    $sql = "SELECT id, nombre, email, telefono, documento_identidad 
            FROM clientes 
            ORDER BY nombre";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener especies
    $sql = "SELECT id, nombre FROM especies WHERE activo = 1 ORDER BY nombre";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $especies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Variable para controlar si mostrar formulario de nueva mascota
$mostrar_form_nueva_mascota = false;

// Procesar formulario de nuevo corte
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar si es para registrar nueva mascota
    if (isset($_POST['registrar_mascota'])) {
        // Registrar nueva mascota
        $nombre_mascota = $_POST['nombre_mascota'] ?? '';
        $especie_id = $_POST['especie_id'] ?? '';
        $raza = $_POST['raza'] ?? '';
        $sexo = $_POST['sexo'] ?? 'macho';
        $sexo = in_array($sexo, ['macho', 'hembra'], true) ? $sexo : ($sexo === 'H' ? 'hembra' : 'macho');
        $color = $_POST['color'] ?? '';
        $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? '';
        
        // Datos del dueño
        $nombre_cliente = $_POST['nombre_cliente'] ?? '';
        $telefono_cliente = $_POST['telefono_cliente'] ?? '';
        $email_cliente = $_POST['email_cliente'] ?? '';
        $documento_cliente = $_POST['documento_cliente'] ?? '';
        $direccion_cliente = $_POST['direccion_cliente'] ?? '';
        
        // Validaciones
        if (empty($nombre_mascota) || empty($especie_id) || empty($raza) || empty($nombre_cliente) || empty($telefono_cliente)) {
            $error = "Por favor complete todos los campos obligatorios (*)";
            $mostrar_form_nueva_mascota = true;
        } else {
            try {
                $db->beginTransaction();
                
                // Registrar cliente
                $sql_cliente = "INSERT INTO clientes (nombre, email, telefono, direccion, documento_identidad, fecha_registro) 
                               VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt_cliente = $db->prepare($sql_cliente);
                $stmt_cliente->execute([
                    $nombre_cliente,
                    $email_cliente,
                    $telefono_cliente,
                    $direccion_cliente,
                    $documento_cliente
                ]);
                $cliente_id = $db->lastInsertId();
                
                // Registrar mascota
                $sql_mascota = "INSERT INTO mascotas (nombre, especie_id, raza, fecha_nacimiento, sexo, color, cliente_id, fecha_registro) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt_mascota = $db->prepare($sql_mascota);
                $stmt_mascota->execute([
                    $nombre_mascota,
                    $especie_id,
                    $raza,
                    $fecha_nacimiento ?: null,
                    $sexo,
                    $color,
                    $cliente_id
                ]);
                
                $nueva_mascota_id = $db->lastInsertId();
                
                $db->commit();
                
                // Actualizar lista de mascotas
                $sql = "SELECT m.id, m.nombre, m.raza, m.especie_id, e.nombre as especie_nombre, 
                               cl.nombre as cliente_nombre, cl.id as cliente_id
                        FROM mascotas m
                        LEFT JOIN especies e ON m.especie_id = e.id
                        LEFT JOIN clientes cl ON m.cliente_id = cl.id
                        WHERE m.id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$nueva_mascota_id]);
                $nueva_mascota = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($nueva_mascota) {
                    $mascotas[] = $nueva_mascota;
                    $success = "¡Mascota registrada exitosamente! Ahora puede completar el formulario de corte.";
                    // Pre-seleccionar la nueva mascota
                    $_POST['mascota_id'] = $nueva_mascota_id;
                    $mostrar_form_nueva_mascota = false;
                }
                
            } catch (PDOException $e) {
                $db->rollBack();
                $error = "Error al registrar la mascota: " . $e->getMessage();
                $mostrar_form_nueva_mascota = true;
            }
        }
    } 
    // Si es para registrar el corte
    else if (isset($_POST['registrar_corte'])) {
        $mascota_id = $_POST['mascota_id'] ?? '';
        
        // Si se selecciona "nueva mascota", mostrar formulario
        if ($mascota_id === 'nueva') {
            $mostrar_form_nueva_mascota = true;
            $error = "Por favor, complete los datos de la nueva mascota";
        } else {
            // Procesar corte normal
            $servicio_id = $_POST['servicio_id'] ?? '';
            $fecha_corte = $_POST['fecha_corte'] ?? '';
            $hora_corte = $_POST['hora_corte'] ?? '';
            $observaciones = $_POST['observaciones'] ?? '';
            $estado_pelo = $_POST['estado_pelo'] ?? '';
            $tipo_corte = $_POST['tipo_corte'] ?? '';
            $tinturado = $_POST['tinturado'] ?? '';
            $productos_utilizados = $_POST['productos_utilizados'] ?? '';
            $costo_base = $_POST['costo_base'] ?? 0;
            $costo_adicional = $_POST['costo_adicional'] ?? 0;
            
            // Validaciones básicas
            if (empty($mascota_id) || empty($servicio_id) || empty($fecha_corte) || empty($hora_corte) || empty($costo_base)) {
                $error = "Todos los campos obligatorios (*) deben ser completados";
            } elseif (!is_numeric($costo_base) || $costo_base <= 0) {
                $error = "El costo base debe ser un número mayor a 0";
            } else {
                try {
                    $db->beginTransaction();
                    $v=$db->prepare("SELECT id FROM servicios WHERE id=? AND activo=1 AND categoria='grooming'");$v->execute([$servicio_id]);if(!$v->fetchColumn())throw new RuntimeException('Servicio de peluquería no válido.');
                    // Validar que la fecha no sea futura (más de 30 días)
                    $fecha_actual = date('Y-m-d');
                    $fecha_limite = date('Y-m-d');
                    if ($fecha_corte > $fecha_limite) {
                        throw new Exception("No puede registrar un corte realizado en una fecha futura");
                    }
                    
                    $costo_total = floatval($costo_base) + floatval($costo_adicional);
                    
                    // Obtener cliente_id de la mascota
                    $sql_mascota = "SELECT cliente_id FROM mascotas WHERE id = ?";
                    $stmt_mascota = $db->prepare($sql_mascota);
                    $stmt_mascota->execute([$mascota_id]);
                    $mascota = $stmt_mascota->fetch(PDO::FETCH_ASSOC);
                    
                    if ($mascota) {
                        $cliente_id = $mascota['cliente_id'];
                        
                        // Insertar como cita de peluquería
                        $sql_cita = "INSERT INTO citas (mascota_id, cliente_id, servicio_id, fecha_cita, hora_cita, estado, notas, costo_total) 
                                    VALUES (?, ?, ?, ?, ?, 'completed', ?, ?)";
                        $stmt_cita = $db->prepare($sql_cita);
                        $stmt_cita->execute([
                            $mascota_id, 
                            $cliente_id, 
                            $servicio_id, 
                            $fecha_corte, 
                            $hora_corte, 
                            $observaciones, 
                            $costo_total
                        ]);
                        
                        $cita_id = $db->lastInsertId();
                        
                        // Insertar en tabla de cortes
                        try {
                            // Verificar si existe la tabla cortes_peluqueria
                            $check_table = $db->query("SHOW TABLES LIKE 'cortes_peluqueria'");
                            if ($check_table->rowCount() > 0) {
                                $sql_corte = "INSERT INTO cortes_peluqueria (cita_id, mascota_id, cliente_id, servicio_id, fecha_corte, 
                                             tipo_corte, estado_pelo, tinturado, productos_utilizados, costo_adicional, costo) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                $stmt_corte = $db->prepare($sql_corte);
                                $stmt_corte->execute([
                                    $cita_id,
                                    $mascota_id,
                                    $cliente_id,
                                    $servicio_id,
                                    $fecha_corte,
                                    $tipo_corte,
                                    $estado_pelo,
                                    $tinturado,
                                    $productos_utilizados,
                                    $costo_adicional,
                                    $costo_total
                                ]);
                            }
                        } catch (PDOException $e) {
                            throw $e;
                        }
                        
                        $db->commit();
                        $success = 'Corte de peluquería registrado correctamente.';
                        $_SESSION['av_corte_success']=$success;header('Location: nuevocorte.php');exit;
                        // Limpiar formulario
                        $_POST = array();
                        
                    } else {
                        throw new RuntimeException('Mascota no encontrada');
                    }
                } catch (Exception $e) {
                    if($db->inTransaction())$db->rollBack();
                    $error = "Error al registrar el corte: " . $e->getMessage();
                }
            }
        }
    }
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
    <title>Nuevo Corte - VetNexo</title>
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
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.5s ease;
            margin-bottom: 1rem;
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

        /* Form Container */
        .form-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.6s ease;
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

        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .section-title i {
            color: var(--accent);
            font-size: 1.2rem;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
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
            color: var(--text-dark);
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.15);
        }

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        input[type="number"] {
            -moz-appearance: textfield;
        }

        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
            line-height: 1.5;
        }

        /* Add Mascota Button */
        .add-mascota-btn {
            background: var(--primary-soft);
            border: 2px dashed var(--primary);
            color: var(--primary);
            width: 100%;
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .add-mascota-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 108, 51, 0.2);
        }

        /* Price Display */
        .price-display {
            background: var(--primary-soft);
            border: 2px dashed var(--primary-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin: 1.5rem 0;
            text-align: center;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { border-color: var(--primary-light); }
            50% { border-color: var(--primary); }
            100% { border-color: var(--primary-light); }
        }

        .price-label {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .price-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-soft);
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
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

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--radius);
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: modalSlide 0.3s ease;
        }

        @keyframes modalSlide {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius) var(--radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .modal-header h2 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.3rem;
            color: var(--white);
        }

        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
        }

        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .two-columns {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
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
            
            .form-grid {
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .two-columns {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .modal-body {
                padding: 1.5rem;
            }
            
            .footer-content {
                padding: 0 1rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Modal para nueva mascota -->
    <div class="modal-overlay" id="modalNuevaMascota">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class='bx bx-paw'></i> 
                    Registrar Nueva Mascota
                </h2>
                <button class="modal-close" onclick="cerrarModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="formNuevaMascota"><?php echo av_csrf_field(); ?>
                    <div class="two-columns">
                        <!-- Columna izquierda: Datos de la mascota -->
                        <div>
                            <h3 class="section-title" style="margin-bottom: 1rem;">
                                <i class='bx bx-paw'></i> Datos de la Mascota
                            </h3>
                            
                            <div class="form-group">
                                <label for="nombre_mascota" class="form-label required">Nombre de la Mascota</label>
                                <input type="text" id="nombre_mascota" name="nombre_mascota" 
                                       class="form-control" required 
                                       placeholder="Ej: Firulais, Lola, Toby"
                                       value="<?php echo htmlspecialchars((string)($_POST['nombre_mascota'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="especie_id" class="form-label required">Especie</label>
                                <select id="especie_id" name="especie_id" class="form-control select" required>
                                    <option value="">Seleccione especie</option>
                                    <?php foreach ($especies as $especie): ?>
                                        <option value="<?php echo $especie['id']; ?>"
                                            <?php echo (isset($_POST['especie_id']) && $_POST['especie_id'] == $especie['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($especie['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="raza" class="form-label required">Raza</label>
                                <input type="text" id="raza" name="raza" 
                                       class="form-control" required 
                                       placeholder="Ej: Labrador, Persa, Mestizo"
                                       value="<?php echo htmlspecialchars((string)($_POST['raza'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="sexo" class="form-label">Sexo</label>
                                <select id="sexo" name="sexo" class="form-control select">
                                    <option value="macho" <?php echo (($_POST['sexo'] ?? 'macho') === 'macho') ? 'selected' : ''; ?>>Macho</option>
                                    <option value="hembra" <?php echo (($_POST['sexo'] ?? '') === 'hembra') ? 'selected' : ''; ?>>Hembra</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="color" class="form-label">Color</label>
                                <input type="text" id="color" name="color" 
                                       class="form-control" 
                                       placeholder="Ej: Negro, Blanco, Marrón"
                                       value="<?php echo htmlspecialchars((string)($_POST['color'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                                <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" 
                                       class="form-control" 
                                       max="<?php echo date('Y-m-d'); ?>"
                                       value="<?php echo htmlspecialchars((string)($_POST['fecha_nacimiento'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        
                        <!-- Columna derecha: Datos del dueño -->
                        <div>
                            <h3 class="section-title" style="margin-bottom: 1rem;">
                                <i class='bx bx-user'></i> Datos del Dueño
                            </h3>
                            
                            <div class="form-group">
                                <label for="nombre_cliente" class="form-label required">Nombre del Dueño</label>
                                <input type="text" id="nombre_cliente" name="nombre_cliente" 
                                       class="form-control" required 
                                       placeholder="Nombre completo"
                                       value="<?php echo htmlspecialchars((string)($_POST['nombre_cliente'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="telefono_cliente" class="form-label required">Teléfono</label>
                                <input type="tel" id="telefono_cliente" name="telefono_cliente" 
                                       class="form-control" required 
                                       placeholder="0414-1234567"
                                       value="<?php echo htmlspecialchars((string)($_POST['telefono_cliente'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="email_cliente" class="form-label">Email</label>
                                <input type="email" id="email_cliente" name="email_cliente" 
                                       class="form-control" 
                                       placeholder="correo@ejemplo.com"
                                       value="<?php echo htmlspecialchars((string)($_POST['email_cliente'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="documento_cliente" class="form-label">Documento de Identidad</label>
                                <input type="text" id="documento_cliente" name="documento_cliente" 
                                       class="form-control" 
                                       placeholder="Cédula o RIF"
                                       value="<?php echo htmlspecialchars((string)($_POST['documento_cliente'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="direccion_cliente" class="form-label">Dirección</label>
                                <textarea id="direccion_cliente" name="direccion_cliente" 
                                          class="form-control" 
                                          placeholder="Dirección completa del cliente..." 
                                          rows="4"><?php echo htmlspecialchars((string)($_POST['direccion_cliente'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions" style="margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="cerrarModal()">
                            <i class='bx bx-x'></i> Cancelar
                        </button>
                        <button type="submit" name="registrar_mascota" class="btn btn-success">
                            <i class='bx bx-save'></i> Registrar Mascota
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Partículas de fondo -->
    <div class="particles">
        <div class="particle" style="top: 20%; left: 10%; width: 8px; height: 8px; animation-delay: 0s;"></div>
        <div class="particle" style="top: 60%; left: 80%; width: 12px; height: 12px; animation-delay: 1s;"></div>
        <div class="particle" style="top: 40%; left: 90%; width: 6px; height: 6px; animation-delay: 2s;"></div>
        <div class="particle" style="top: 80%; left: 20%; width: 10px; height: 10px; animation-delay: 3s;"></div>
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
                        <i class="bx bx-scissors"></i> 
                        Registrar Nuevo Corte
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Registra un nuevo servicio de peluquería para VetNexo
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="citaspeluqueria.php" class="btn btn-info">
                        <i class='bx bx-list-ul'></i> Ver Citas
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
            <?php if (!empty($success)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-success">
                            <div class="alert-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo $success; ?>
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

            <!-- Formulario principal -->
            <div class="form-container">
                <div class="form-header">
                    <h2>
                        <i class='bx bx-cut'></i>
                        Datos del Corte de Peluquería
                    </h2>
                </div>
                
                <div class="form-body">
                    <form method="POST" action="" id="formCorte"><?php echo av_csrf_field(); ?>
                        <!-- Información Básica -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-info-circle'></i>
                                Información Básica
                            </h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="mascota_id" class="form-label required">Mascota</label>
                                    <select id="mascota_id" name="mascota_id" class="form-control select" required>
                                        <option value="">Seleccione una mascota</option>
                                        <?php foreach ($mascotas as $mascota): ?>
                                            <option value="<?php echo $mascota['id']; ?>" 
                                                    <?php echo (isset($_POST['mascota_id']) && $_POST['mascota_id'] == $mascota['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($mascota['nombre']); ?> 
                                                (<?php echo htmlspecialchars($mascota['especie_nombre']); ?> - 
                                                <?php echo htmlspecialchars($mascota['cliente_nombre']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="nueva">➕ Registrar nueva mascota...</option>
                                    </select>
                                    <button type="button" class="add-mascota-btn" onclick="mostrarModal()">
                                        <i class='bx bx-plus'></i> Registrar Nueva Mascota
                                    </button>
                                </div>

                                <div class="form-group">
                                    <label for="servicio_id" class="form-label required">Servicio</label>
                                    <select id="servicio_id" name="servicio_id" class="form-control select" required>
                                        <option value="">Seleccione un servicio</option>
                                        <?php foreach ($servicios_peluqueria as $servicio): ?>
                                            <option value="<?php echo $servicio['id']; ?>"
                                                    <?php echo (isset($_POST['servicio_id']) && $_POST['servicio_id'] == $servicio['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($servicio['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="costo_base" class="form-label required">Costo Base ($)</label>
                                    <input type="number" id="costo_base" name="costo_base" 
                                           class="form-control" step="0.01" min="0" required
                                           value="<?php echo htmlspecialchars((string)($_POST['costo_base'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" 
                                           placeholder="0.00">
                                </div>

                                <div class="form-group">
                                    <label for="fecha_corte" class="form-label required">Fecha del Corte</label>
                                    <input type="date" id="fecha_corte" name="fecha_corte" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars((string)($_POST['fecha_corte'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" 
                                           required min="<?php echo date('Y-m-d', strtotime('-1 month')); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="hora_corte" class="form-label required">Hora del Corte</label>
                                    <input type="time" id="hora_corte" name="hora_corte" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars((string)($_POST['hora_corte'] ?? date('H:i')), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Detalles del Corte -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class='bx bx-scissors'></i>
                                Detalles del Corte
                            </h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="estado_pelo" class="form-label">Estado del Pelo</label>
                                    <input type="text" id="estado_pelo" name="estado_pelo" 
                                           class="form-control"
                                           placeholder="Ej: limpio, enredado, sucio, mudando, seco"
                                           value="<?php echo htmlspecialchars((string)($_POST['estado_pelo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="tipo_corte" class="form-label">Tipo de Corte</label>
                                    <input type="text" id="tipo_corte" name="tipo_corte" 
                                           class="form-control"
                                           placeholder="Ej: baño, corto, medio, largo, estilizado, rasurado"
                                           value="<?php echo htmlspecialchars((string)($_POST['tipo_corte'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="tinturado" class="form-label">Tinturado</label>
                                    <input type="text" id="tinturado" name="tinturado" 
                                           class="form-control"
                                           placeholder="Ej: sin tinturado, tinturado orejas, tinturado completo"
                                           value="<?php echo htmlspecialchars((string)($_POST['tinturado'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="costo_adicional" class="form-label">Costo Adicional ($)</label>
                                    <input type="number" id="costo_adicional" name="costo_adicional" 
                                           class="form-control" step="0.01" min="0" 
                                           value="<?php echo htmlspecialchars((string)($_POST['costo_adicional'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>" 
                                           placeholder="0.00">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="productos_utilizados" class="form-label">Productos Utilizados</label>
                                <textarea id="productos_utilizados" name="productos_utilizados" 
                                          class="form-control"
                                          placeholder="Lista de productos utilizados durante el corte..."><?php echo htmlspecialchars((string)($_POST['productos_utilizados'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="observaciones" class="form-label">Observaciones</label>
                                <textarea id="observaciones" name="observaciones" 
                                          class="form-control"
                                          placeholder="Observaciones adicionales sobre el corte..."><?php echo htmlspecialchars((string)($_POST['observaciones'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>

                        <!-- Resumen de Costo -->
                        <div class="price-display">
                            <div class="price-label">Costo Total Estimado</div>
                            <div class="price-value" id="costoTotal">$0.00</div>
                        </div>

                        <!-- Acciones del Formulario -->
                        <div class="form-actions">
                            <a href="citaspeluqueria.php" class="btn btn-secondary">
                                <i class='bx bx-x'></i>
                                Cancelar
                            </a>
                            <button type="submit" name="registrar_corte" class="btn btn-primary">
                                <i class='bx bx-save'></i>
                                Registrar Corte
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

    <script src="js/sidebar.js"></script>
    <script>
        // Función para mostrar el modal
        function mostrarModal() {
            document.getElementById('modalNuevaMascota').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        // Función para cerrar el modal
        function cerrarModal() {
            document.getElementById('modalNuevaMascota').style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Limpiar el select del formulario principal si se seleccionó "nueva"
            const mascotaSelect = document.getElementById('mascota_id');
            if (mascotaSelect.value === 'nueva') {
                mascotaSelect.value = '';
            }
        }

        // Cerrar modal al hacer clic fuera
        document.getElementById('modalNuevaMascota').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModal();
            }
        });

        // Cerrar modal con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModal();
            }
        });

        // Validación del formulario de nueva mascota
        document.getElementById('formNuevaMascota')?.addEventListener('submit', function(e) {
            const nombreMascota = document.getElementById('nombre_mascota').value.trim();
            const especie = document.getElementById('especie_id').value;
            const raza = document.getElementById('raza').value.trim();
            const nombreCliente = document.getElementById('nombre_cliente').value.trim();
            const telefonoCliente = document.getElementById('telefono_cliente').value.trim();
            
            if (!nombreMascota || !especie || !raza || !nombreCliente || !telefonoCliente) {
                e.preventDefault();
                alert('Por favor complete todos los campos obligatorios (*)');
                return false;
            }
            
            // Validar formato de teléfono (mínimo 10 dígitos)
            if (telefonoCliente.replace(/\D/g, '').length < 10) {
                e.preventDefault();
                alert('Por favor ingrese un número de teléfono válido (mínimo 10 dígitos)');
                return false;
            }
            
            return true;
        });

        // Manejar selección de "nueva mascota" en el select
        document.getElementById('mascota_id').addEventListener('change', function() {
            if (this.value === 'nueva') {
                mostrarModal();
            }
        });

        // Calcular costo total en tiempo real
        function calcularCostoTotal() {
            const costoBase = parseFloat(document.getElementById('costo_base').value) || 0;
            const costoAdicional = parseFloat(document.getElementById('costo_adicional').value) || 0;
            const costoTotal = costoBase + costoAdicional;
            
            const costoTotalElement = document.getElementById('costoTotal');
            if (costoTotalElement) {
                costoTotalElement.textContent = '$' + costoTotal.toFixed(2);
            }
        }

        // Event listeners para cambios en precio
        const costoBaseInput = document.getElementById('costo_base');
        const costoAdicionalInput = document.getElementById('costo_adicional');
        
        if (costoBaseInput) {
            costoBaseInput.addEventListener('input', calcularCostoTotal);
            costoBaseInput.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
        }
        
        if (costoAdicionalInput) {
            costoAdicionalInput.addEventListener('input', calcularCostoTotal);
            costoAdicionalInput.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
        }

        // Validar que el costo base sea mayor a 0
        document.getElementById('formCorte').addEventListener('submit', function(e) {
            const costoBase = document.getElementById('costo_base').value;
            if (!costoBase || parseFloat(costoBase) <= 0) {
                e.preventDefault();
                alert('El costo base debe ser mayor a 0');
                document.getElementById('costo_base').focus();
                return false;
            }
            
            const mascotaId = document.getElementById('mascota_id').value;
            if (!mascotaId || mascotaId === 'nueva') {
                e.preventDefault();
                alert('Debe seleccionar una mascota');
                document.getElementById('mascota_id').focus();
                return false;
            }
        });

        // Calcular costo inicial
        document.addEventListener('DOMContentLoaded', function() {
            calcularCostoTotal();
            
            const fechaInput = document.getElementById('fecha_corte');
            if (fechaInput) {
                const hoy = new Date().toISOString().split('T')[0];
                
                fechaInput.addEventListener('change', function() {
                    const maxDate = new Date();
                    maxDate.setMonth(maxDate.getMonth() + 1);
                    const maxDateStr = maxDate.toISOString().split('T')[0];
                    
                    if (this.value > maxDateStr) {
                        alert('No puede seleccionar una fecha mayor a un mes en el futuro');
                        this.value = hoy;
                    }
                });
            }
            
            // Mostrar modal si hubo error al registrar mascota
            <?php if ($mostrar_form_nueva_mascota): ?>
                mostrarModal();
            <?php endif; ?>
            
            // Auto-formatear teléfono
            const telefonoInput = document.getElementById('telefono_cliente');
            if (telefonoInput) {
                telefonoInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length > 0) {
                        if (value.length <= 4) {
                            value = value.replace(/(\d{0,4})/, '$1');
                        } else if (value.length <= 7) {
                            value = value.replace(/(\d{4})(\d{0,3})/, '$1-$2');
                        } else {
                            value = value.replace(/(\d{4})(\d{3})(\d{0,4})/, '$1-$2-$3');
                        }
                    }
                    e.target.value = value;
                });
            }

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