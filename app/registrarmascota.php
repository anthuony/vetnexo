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
$especies = [];
$clientes = [];
$table_columns = [];
$cliente_seleccionado = null;
$modo_rapido = false;

// DEBUG: Activar errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    
    // Obtener especies
    $especies_sql = "SELECT * FROM especies ORDER BY nombre";
    $especies_stmt = $db->query($especies_sql);
    $especies = $especies_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar si hay especies, si no, mostrar mensaje
    if (empty($especies)) {
        $mensaje = "No hay especies registradas en el sistema. Contacte al administrador.";
        $tipo_mensaje = "error";
    }
    
    // Obtener clientes
    $clientes_sql = "SELECT * FROM clientes ORDER BY nombre";
    $clientes_stmt = $db->query($clientes_sql);
    $clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Detectar columnas de la tabla mascotas
    $check_table_sql = "DESCRIBE mascotas";
    $check_table_stmt = $db->query($check_table_sql);
    $table_columns = $check_table_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Si viene cliente_id por GET (desde listadeclientes.php), precargar datos
    if (isset($_GET['cliente_id']) && !empty($_GET['cliente_id'])) {
        $cliente_id = $_GET['cliente_id'];
        $cliente_sql = "SELECT * FROM clientes WHERE id = ?";
        $cliente_stmt = $db->prepare($cliente_sql);
        $cliente_stmt->execute([$cliente_id]);
        $cliente_seleccionado = $cliente_stmt->fetch(PDO::FETCH_ASSOC);
        
        // También obtener mascotas existentes del cliente
        if ($cliente_seleccionado) {
            $mascotas_cliente_sql = "SELECT m.*, e.nombre as especie_nombre 
                                   FROM mascotas m 
                                   LEFT JOIN especies e ON m.especie_id = e.id 
                                   WHERE m.cliente_id = ? 
                                   ORDER BY m.nombre";
            $mascotas_cliente_stmt = $db->prepare($mascotas_cliente_sql);
            $mascotas_cliente_stmt->execute([$cliente_id]);
            $mascotas_del_cliente = $mascotas_cliente_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Contar mascotas para sugerir modo rápido
            $total_mascotas = count($mascotas_del_cliente);
        }
    }
    
    // Verificar si estamos en modo rápido (viene por GET)
    if (isset($_GET['modo_rapido']) && $_GET['modo_rapido'] == '1' && isset($cliente_seleccionado)) {
        $modo_rapido = true;
    }
    
} catch (PDOException $e) {
    $mensaje = "Error al cargar datos: " . $e->getMessage();
    $tipo_mensaje = "error";
}

// Procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    try {
        // Verificar si es modo rápido (registro múltiple)
        $es_modo_rapido = isset($_POST['modo_rapido']) && $_POST['modo_rapido'] == '1';
        
        // Datos comunes
        $cliente_id = $_POST['cliente_id'] ?? '';
        $es_cliente_nuevo = isset($_POST['cliente_nombre']) && !empty($_POST['cliente_nombre']);
        
        // DETERMINAR SI ES CLIENTE NUEVO O EXISTENTE
        if ($es_cliente_nuevo) {
            // REGISTRAR NUEVO CLIENTE
            $cliente_nombre = $_POST['cliente_nombre'] ?? '';
            $cliente_telefono = $_POST['cliente_telefono'] ?? '';
            $cliente_email = $_POST['cliente_email'] ?? '';
            $cliente_direccion = $_POST['cliente_direccion'] ?? '';
            $cliente_documento_identidad = $_POST['cliente_documento_identidad'] ?? '';
            
            if (empty($cliente_nombre)) {
                throw new Exception('El nombre del cliente es obligatorio');
            }
            
            // Verificar si ya existe un cliente con ese nombre/teléfono/documento
            $check_cliente_sql = "SELECT id FROM clientes WHERE telefono = ? OR documento_identidad = ?";
            $check_cliente_stmt = $db->prepare($check_cliente_sql);
            $check_cliente_stmt->execute([$cliente_telefono, $cliente_documento_identidad]);
            $cliente_existente = $check_cliente_stmt->fetch();
            
            if ($cliente_existente) {
                // Usar cliente existente
                $cliente_id = $cliente_existente['id'];
                
                // Obtener datos del cliente existente para mostrarlos
                $cliente_sql = "SELECT * FROM clientes WHERE id = ?";
                $cliente_stmt = $db->prepare($cliente_sql);
                $cliente_stmt->execute([$cliente_id]);
                $cliente_seleccionado = $cliente_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Guardar datos de cliente en sesión para mostrar mensaje
                $_SESSION['cliente_existente_reencontrado'] = [
                    'id' => $cliente_id,
                    'nombre' => $cliente_seleccionado['nombre']
                ];
            } else {
                // Insertar nuevo cliente
                $insert_cliente_sql = "INSERT INTO clientes (nombre, telefono, email, direccion, documento_identidad) 
                                     VALUES (?, ?, ?, ?, ?)";
                $insert_cliente_stmt = $db->prepare($insert_cliente_sql);
                $insert_cliente_stmt->execute([
                    $cliente_nombre,
                    $cliente_telefono,
                    $cliente_email,
                    $cliente_direccion,
                    $cliente_documento_identidad
                ]);
                
                $cliente_id = $db->lastInsertId();
                
                // Obtener datos del nuevo cliente
                $cliente_sql = "SELECT * FROM clientes WHERE id = ?";
                $cliente_stmt = $db->prepare($cliente_sql);
                $cliente_stmt->execute([$cliente_id]);
                $cliente_seleccionado = $cliente_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Guardar datos de cliente en sesión para mostrar mensaje
                $_SESSION['cliente_nuevo_registrado'] = [
                    'id' => $cliente_id,
                    'nombre' => $cliente_nombre
                ];
            }
        }
        
        // Validar que tenemos un cliente_id
        if (empty($cliente_id)) {
            throw new Exception('Debe seleccionar un cliente o registrar uno nuevo');
        }
        
        // Validar que el cliente existe (si no es nuevo)
        if (!$es_cliente_nuevo) {
            $check_cliente_sql = "SELECT id FROM clientes WHERE id = ?";
            $check_cliente_stmt = $db->prepare($check_cliente_sql);
            $check_cliente_stmt->execute([$cliente_id]);
            if (!$check_cliente_stmt->fetch()) {
                throw new Exception('El cliente seleccionado no existe');
            }
            
            // Obtener datos del cliente existente
            $cliente_sql = "SELECT * FROM clientes WHERE id = ?";
            $cliente_stmt = $db->prepare($cliente_sql);
            $cliente_stmt->execute([$cliente_id]);
            $cliente_seleccionado = $cliente_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // MODO RÁPIDO: Registrar múltiples mascotas
        if ($es_modo_rapido) {
            $mascotas_registradas = 0;
            $errores = [];
            
            // Contar cuántas mascotas se van a registrar
            $contador_mascotas = 1;
            while (isset($_POST["nombre_mascota_{$contador_mascotas}"])) {
                $nombre = trim($_POST["nombre_mascota_{$contador_mascotas}"] ?? '');
                $especie_id = $_POST["especie_id_{$contador_mascotas}"] ?? '';
                $raza = $_POST["raza_{$contador_mascotas}"] ?? '';
                $sexo = $_POST["sexo_{$contador_mascotas}"] ?? '';
                if ($sexo === 'M') $sexo = 'macho';
                if ($sexo === 'H') $sexo = 'hembra';
                if (!in_array($sexo, ['macho', 'hembra'], true)) {
                    $errores[] = "Error con mascota '{$nombre}': debe seleccionar Macho o Hembra";
                    $contador_mascotas++;
                    continue;
                }
                
                // Solo procesar si tiene nombre y especie
                if (!empty($nombre) && !empty($especie_id)) {
                    try {
                        // Verificar si la especie existe
                        $check_especie_sql = "SELECT id FROM especies WHERE id = ?";
                        $check_especie_stmt = $db->prepare($check_especie_sql);
                        $check_especie_stmt->execute([$especie_id]);
                        if (!$check_especie_stmt->fetch()) {
                            throw new Exception("Especie no válida");
                        }
                        
                        // Verificar si la mascota ya existe
                        $check_mascota_sql = "SELECT id FROM mascotas WHERE cliente_id = ? AND LOWER(nombre) = LOWER(?)";
                        $check_mascota_stmt = $db->prepare($check_mascota_sql);
                        $check_mascota_stmt->execute([$cliente_id, $nombre]);
                        
                        if ($check_mascota_stmt->fetch()) {
                            // Mascota duplicada, pero la registramos igual (podría ser otra mascota con mismo nombre)
                            // En modo rápido, permitimos duplicados
                        }
                        
                        // Construir consulta INSERT dinámica
                        $columns = ['nombre', 'especie_id', 'cliente_id', 'fecha_registro'];
                        $placeholders = ['?', '?', '?', 'NOW()'];
                        $values = [$nombre, $especie_id, $cliente_id];
                        
                        // Agregar raza si la columna existe
                        if (in_array('raza', $table_columns)) {
                            $columns[] = 'raza';
                            $placeholders[] = '?';
                            $values[] = $raza;
                        }
                        
                        // Agregar sexo si la columna existe
                        if (in_array('sexo', $table_columns)) {
                            $columns[] = 'sexo';
                            $placeholders[] = '?';
                            $values[] = $sexo;
                        }
                        
                        // Insertar mascota
                        $insert_sql = "INSERT INTO mascotas (" . implode(', ', $columns) . ") 
                                      VALUES (" . implode(', ', $placeholders) . ")";
                        
                        $stmt = $db->prepare($insert_sql);
                        $stmt->execute($values);
                        
                        $mascotas_registradas++;
                        
                    } catch (Exception $e) {
                        $errores[] = "Error con mascota '{$nombre}': " . $e->getMessage();
                    }
                }
                
                $contador_mascotas++;
            }
            
            // Mostrar mensaje de éxito
            if ($mascotas_registradas > 0) {
                $mensaje = "¡Se registraron {$mascotas_registradas} mascotas exitosamente!";
                if (!empty($errores)) {
                    $mensaje .= "<br><br>Errores encontrados:<br>" . implode('<br>', $errores);
                }
                $tipo_mensaje = "success";
                
                // Guardar datos para mostrar en modo rápido continuo
                $_SESSION['modo_rapido_cliente_id'] = $cliente_id;
                $_SESSION['modo_rapido_contador'] = $contador_mascotas - 1; // Número de campos usados
                
                // Redirigir para continuar en modo rápido
                header('Location: registrarmascota.php?cliente_id=' . $cliente_id . '&modo_rapido=1&success=1&registradas=' . $mascotas_registradas);
                exit();
            } else {
                throw new Exception('No se registraron mascotas. Complete al menos un nombre y especie.');
            }
            
        } else {
            // MODO NORMAL: Registrar una sola mascota
            
            // Datos de la mascota
            $nombre = $_POST['nombre'] ?? '';
            $especie_id = $_POST['especie_id'] ?? '';
            $raza = $_POST['raza'] ?? '';
            $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? '';
            $sexo = $_POST['sexo'] ?? '';
            $color = $_POST['color'] ?? '';
            $peso = $_POST['peso'] ?? '';
            $notas = $_POST['notas'] ?? '';
            
            // Validaciones básicas de mascota
            if (empty($nombre) || empty($especie_id)) {
                throw new Exception('Los campos nombre y especie son obligatorios');
            }
            
            // Validar que la especie existe
            $check_especie_sql = "SELECT id FROM especies WHERE id = ?";
            $check_especie_stmt = $db->prepare($check_especie_sql);
            $check_especie_stmt->execute([$especie_id]);
            if (!$check_especie_stmt->fetch()) {
                throw new Exception('La especie seleccionada no existe');
            }
            
            // La columna sexo es obligatoria en la base actual.
            if ($sexo === 'M') $sexo = 'macho';
            if ($sexo === 'H') $sexo = 'hembra';
            if (!in_array($sexo, ['macho', 'hembra'], true)) {
                throw new Exception('Debe seleccionar el sexo de la mascota');
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
                } else {
                    throw new Exception('Formato de fecha inválido. Use YYYY-MM-DD');
                }
            }
            
            // Validar peso si se proporciona
            if (!empty($peso)) {
                $peso = str_replace(',', '.', $peso); // Reemplazar coma por punto
                if (!is_numeric($peso) || $peso < 0 || $peso > 500) {
                    throw new Exception('El peso debe ser un número entre 0 y 500 kg');
                }
            }
            
            // Verificar si la mascota ya existe para este cliente (mismo nombre)
            $check_mascota_sql = "SELECT id FROM mascotas WHERE cliente_id = ? AND LOWER(nombre) = LOWER(?)";
            $check_mascota_stmt = $db->prepare($check_mascota_sql);
            $check_mascota_stmt->execute([$cliente_id, $nombre]);
            if ($check_mascota_stmt->fetch()) {
                $warning_msg = "Advertencia: Ya existe una mascota con el nombre '$nombre' para este cliente. ";
                $warning_msg .= "¿Desea continuar de todos modos?";
                
                // Si no se ha confirmado, mostrar advertencia
                if (!isset($_POST['confirmar_duplicado'])) {
                    // Guardar datos en sesión para rellenar formulario después
                    $_SESSION['form_data'] = $_POST;
                    $_SESSION['warning_message'] = $warning_msg;
                    $_SESSION['cliente_id_warning'] = $cliente_id;
                    header('Location: registrarmascota.php?cliente_id=' . $cliente_id . '&warning=duplicate');
                    exit();
                }
            }
            
            // Construir consulta INSERT dinámica basada en las columnas existentes
            $columns = ['nombre', 'especie_id', 'cliente_id', 'fecha_registro'];
            $placeholders = ['?', '?', '?', 'NOW()'];
            $values = [$nombre, $especie_id, $cliente_id];
            
            // Agregar raza si la columna existe
            if (in_array('raza', $table_columns)) {
                $columns[] = 'raza';
                $placeholders[] = '?';
                $values[] = $raza;
            }
            
            // Agregar fecha_nacimiento si la columna existe
            if (in_array('fecha_nacimiento', $table_columns)) {
                $columns[] = 'fecha_nacimiento';
                $placeholders[] = '?';
                $values[] = $fecha_nacimiento ?: null;
            }
            
            // Agregar sexo si la columna existe
            if (in_array('sexo', $table_columns)) {
                $columns[] = 'sexo';
                $placeholders[] = '?';
                $values[] = $sexo;
            }
            
            // Agregar color si la columna existe
            if (in_array('color', $table_columns)) {
                $columns[] = 'color';
                $placeholders[] = '?';
                $values[] = $color;
            }
            
            // Agregar peso si la columna existe
            if (in_array('peso', $table_columns) && !empty($peso)) {
                $columns[] = 'peso';
                $placeholders[] = '?';
                $values[] = $peso;
            }
            
            // Agregar notas si la columna existe
            if (in_array('notas', $table_columns)) {
                $columns[] = 'notas';
                $placeholders[] = '?';
                $values[] = $notas;
            }
            
            // Insertar mascota
            $insert_sql = "INSERT INTO mascotas (" . implode(', ', $columns) . ") 
                          VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $db->prepare($insert_sql);
            $stmt->execute($values);
            
            $mascota_id = $db->lastInsertId();
            
            // Determinar mensaje según si el cliente era nuevo o existente
            if (isset($_SESSION['cliente_nuevo_registrado'])) {
                $cliente_nombre = $_SESSION['cliente_nuevo_registrado']['nombre'];
                $mensaje = "¡Cliente registrado exitosamente!<br>Mascota registrada exitosamente.";
                unset($_SESSION['cliente_nuevo_registrado']);
            } elseif (isset($_SESSION['cliente_existente_reencontrado'])) {
                $cliente_nombre = $_SESSION['cliente_existente_reencontrado']['nombre'];
                $mensaje = "Cliente existente encontrado.<br>Mascota registrada exitosamente.";
                unset($_SESSION['cliente_existente_reencontrado']);
            } else {
                $mensaje = "Mascota registrada exitosamente.";
            }
            
            $tipo_mensaje = "success";
            
            // Limpiar datos de sesión
            unset($_SESSION['form_data']);
            unset($_SESSION['warning_message']);
            unset($_SESSION['cliente_id_warning']);
            
            // Si se registró correctamente, redirigir para mostrar éxito
            header('Location: registrarmascota.php?cliente_id=' . $cliente_id . '&success=1&mascota_id=' . $mascota_id);
            exit();
        }
        
    } catch (Exception $e) {
        $mensaje = "Error al registrar mascota: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $db === null) {
    $mensaje = "Error: No hay conexión a la base de datos";
    $tipo_mensaje = "error";
}

// Recuperar datos de sesión para rellenar formulario después de advertencia
if (isset($_SESSION['form_data']) && !empty($_SESSION['form_data'])) {
    $_POST = array_merge($_POST, $_SESSION['form_data']);
}

// Recuperar datos para modo rápido continuo
$modo_rapido_contador = $_SESSION['modo_rapido_contador'] ?? 1;
$modo_rapido_cliente_id = $_SESSION['modo_rapido_cliente_id'] ?? null;

// Limpiar datos de sesión del modo rápido después de usarlos
unset($_SESSION['modo_rapido_contador']);
unset($_SESSION['modo_rapido_cliente_id']);

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
    <title>Registrar Mascota - VetNexo</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
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
            background: linear-gradient(135deg, var(--text-light), var(--text-lighter));
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, var(--text-lighter), #5a6268);
            transform: translateY(-2px);
        }

        .btn-back {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--accent-dark), #E65100);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #1976D2, #0d6efd);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #FFA000);
            color: var(--white);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #FFA000, #FF8F00);
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

        .btn-rapido-toggle {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-rapido-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
        }

        .btn-agregar-mascota {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-agregar-mascota:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
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

        .section-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h3 i {
            color: var(--accent);
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Información del Cliente */
        .cliente-info-box {
            background: linear-gradient(135deg, var(--primary-soft), #c8e6c9);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 2px solid var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cliente-details h3 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cliente-details p {
            margin: 0.25rem 0;
            color: var(--text-dark);
        }

        .cliente-contact {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-dark);
        }

        /* Mascotas del Cliente */
        .mascotas-cliente {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
        }

        .mascotas-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 2px solid var(--primary-soft);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mascotas-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mascotas-count {
            background: var(--primary);
            color: var(--white);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .mascotas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .mascota-card {
            background: var(--primary-soft);
            padding: 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--primary-light);
            transition: all 0.3s ease;
        }

        .mascota-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .mascota-nombre {
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .mascota-details {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .empty-mascotas {
            text-align: center;
            padding: 2rem;
            color: var(--text-light);
        }

        .empty-mascotas i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
        }

        /* Form Container */
        .form-container {
            background: var(--white);
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
        }

        .card-title i {
            color: var(--accent);
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            padding: 1.5rem;
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

        /* Select con búsqueda */
        .select-wrapper {
            position: relative;
        }

        .search-select {
            margin-bottom: 0.5rem;
            background: var(--gray-light);
        }

        /* Select con botón */
        .select-with-button {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .select-with-button .form-control {
            flex: 1;
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

        /* Alert Messages */
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

        .alert-warning {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-color: var(--warning);
            color: #663c00;
        }

        /* Warning Dialog */
        .warning-dialog {
            background: linear-gradient(135deg, #fff3e0, #ffecb3);
            border: 2px solid var(--warning);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .warning-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .warning-icon {
            color: var(--warning);
            font-size: 1.5rem;
        }

        .warning-title {
            font-weight: 600;
            color: var(--warning);
            margin: 0;
            font-size: 1.1rem;
        }

        .warning-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        /* Success Message */
        .success-message {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: var(--radius);
            padding: 2rem;
            border: 2px solid var(--success);
            text-align: center;
        }

        .success-icon {
            color: var(--success);
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .success-message h3 {
            color: var(--primary-dark);
            margin-bottom: 1rem;
        }

        .success-message p {
            color: var(--text-dark);
            margin-bottom: 1.5rem;
        }

        .success-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .action-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            text-decoration: none;
            color: var(--text-dark);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .action-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .action-description {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        /* Modo Rápido */
        .modo-rapido-card {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-radius: var(--radius);
            border: 2px solid var(--info);
        }

        .modo-rapido-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 2px solid rgba(33, 150, 243, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modo-rapido-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1976D2;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modo-rapido-title i {
            color: #1976D2;
        }

        .modo-rapido-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .contador-mascotas {
            background: linear-gradient(135deg, #FFECB3, #FFD54F);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 600;
            color: #5D4037;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .mascota-rapida-item {
            background: var(--white);
            margin: 1.5rem;
            border-radius: var(--radius-sm);
            border: 2px solid #E3F2FD;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .mascota-rapida-item:hover {
            border-color: #90CAF9;
            box-shadow: var(--shadow);
        }

        .mascota-rapida-header {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #E3F2FD, #BBDEFB);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #BBDEFB;
        }

        .mascota-rapida-numero {
            background: var(--info);
            color: var(--white);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .mascota-rapida-eliminar {
            background: #FFEBEE;
            color: #C62828;
            border: none;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mascota-rapida-eliminar:hover {
            background: #FFCDD2;
            transform: scale(1.1);
        }

        .rapido-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        /* Cliente Nuevo Section */
        .cliente-toggle-section {
            text-align: center;
            padding: 1rem;
            background: var(--primary-soft);
            border-radius: var(--radius-sm);
            border: 2px dashed var(--primary-light);
        }

        .cliente-nuevo-card {
            background: linear-gradient(135deg, #fff8e1, #ffecb3);
            border-radius: var(--radius);
            border: 2px solid var(--accent);
            margin-top: 1rem;
        }

        .cliente-nuevo-header {
            padding: 1rem 1.5rem;
            border-bottom: 2px solid rgba(255, 152, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .cliente-nuevo-icon {
            color: var(--accent-dark);
            font-size: 1.3rem;
        }

        .cliente-nuevo-title {
            font-weight: 600;
            color: var(--accent-dark);
            margin: 0;
            font-size: 1.1rem;
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
            
            .mascotas-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-actions {
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
            
            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .cliente-info-box {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .modo-rapido-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .select-with-button {
                flex-direction: column;
            }
            
            .warning-actions {
                flex-direction: column;
            }
            
            .mascotas-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .success-actions {
                flex-direction: column;
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
                        <i class='bx bx-paw'></i>
                        <?php 
                        if ($modo_rapido && isset($cliente_seleccionado)) {
                            echo 'Registro Rápido de Mascotas';
                        } else {
                            echo 'Registrar Nueva Mascota';
                        }
                        ?>
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • 
                        <?php 
                        if ($modo_rapido && isset($cliente_seleccionado)) {
                            echo 'Registre múltiples mascotas rápidamente';
                        } elseif (isset($cliente_seleccionado)) {
                            echo 'Agregue una nueva mascota';
                        } else {
                            echo 'Complete la información de la nueva mascota';
                        }
                        ?>
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

            <!-- Mensajes de éxito/error -->
            <?php if ($mensaje): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'error'; ?>">
                            <?php echo $mensaje; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Mensaje de éxito para modo rápido -->
            <?php if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_GET['registradas'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="success-message">
                            <div class="success-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <h3>¡Mascotas registradas exitosamente!</h3>
                            <p>Se registraron <strong><?php echo (int)($_GET['registradas'] ?? 0); ?> mascotas</strong> para el cliente.</p>
                            <div class="success-actions">
                                <?php if (isset($cliente_seleccionado)): ?>
                                    <a href="registrarmascota.php?cliente_id=<?php echo $cliente_seleccionado['id']; ?>&modo_rapido=1" class="btn btn-primary btn-sm">
                                        <i class='bx bx-plus'></i>
                                        Registrar Más
                                    </a>
                                    <a href="registrarmascota.php?cliente_id=<?php echo $cliente_seleccionado['id']; ?>" class="btn btn-success btn-sm">
                                        <i class='bx bx-list-ul'></i>
                                        Ver Mascotas
                                    </a>
                                <?php endif; ?>
                                <a href="listademascotas.php" class="btn btn-secondary btn-sm">
                                    <i class='bx bx-paw'></i>
                                    Ver Todas
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Mensaje de éxito para modo normal -->
            <?php if (isset($_GET['success']) && $_GET['success'] == '1' && !isset($_GET['registradas'])): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="success-message">
                            <div class="success-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <h3>¡Mascota registrada exitosamente!</h3>
                            <p>ID de la mascota: <strong><?php echo htmlspecialchars($_GET['mascota_id'] ?? ''); ?></strong></p>
                            <div class="success-actions">
                                <a href="anamnesis.php?id=<?php echo (int)($_GET['mascota_id'] ?? 0); ?>" class="btn btn-primary btn-sm">
                                    <i class='bx bx-show'></i>
                                    Ver Ficha
                                </a>
                                <a href="registrarmascota.php?cliente_id=<?php echo (int)($_GET['cliente_id'] ?? 0); ?>" class="btn btn-success btn-sm">
                                    <i class='bx bx-plus'></i>
                                    Registrar Otra
                                </a>
                                <a href="listademascotas.php" class="btn btn-secondary btn-sm">
                                    <i class='bx bx-list-ul'></i>
                                    Ver Mascotas
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Advertencia de duplicado -->
            <?php if (isset($_SESSION['warning_message']) && isset($_GET['warning']) && $_GET['warning'] == 'duplicate'): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="warning-dialog">
                            <div class="warning-header">
                                <i class='bx bx-error warning-icon'></i>
                                <h3 class="warning-title">Advertencia</h3>
                            </div>
                            <p><?php echo $_SESSION['warning_message']; ?></p>
                            <div class="warning-actions">
                                <form method="POST" action=""><?php echo av_csrf_field(); ?>
                                    <input type="hidden" name="confirmar_duplicado" value="1">
                                    <input type="hidden" name="cliente_id" value="<?php echo $_SESSION['cliente_id_warning']; ?>">
                                    <?php foreach ($_SESSION['form_data'] as $key => $value): ?>
                                        <?php if ($key !== 'cliente_id'): ?>
                                            <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class='bx bx-check'></i>
                                        Sí, continuar
                                    </button>
                                </form>
                                <a href="registrarmascota.php?cliente_id=<?php echo $_SESSION['cliente_id_warning']; ?>" class="btn btn-secondary btn-sm">
                                    <i class='bx bx-x'></i>
                                    Cancelar
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error de conexión -->
            <?php if ($db === null): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <strong>Error de conexión:</strong> No se pudo conectar a la base de datos.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Información del Cliente si se seleccionó uno -->
            <?php if (isset($cliente_seleccionado)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="cliente-info-box">
                            <div class="cliente-details">
                                <h3><i class='bx bx-user-circle'></i> <?php echo htmlspecialchars($cliente_seleccionado['nombre']); ?></h3>
                                <?php if (!empty($cliente_seleccionado['documento_identidad'])): ?>
                                    <p><strong>Documento:</strong> <?php echo htmlspecialchars($cliente_seleccionado['documento_identidad']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($cliente_seleccionado['direccion'])): ?>
                                    <p><strong>Dirección:</strong> <?php echo htmlspecialchars($cliente_seleccionado['direccion']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="cliente-contact">
                                <?php if (!empty($cliente_seleccionado['telefono'])): ?>
                                    <div class="contact-item">
                                        <i class='bx bx-phone'></i>
                                        <span><?php echo htmlspecialchars($cliente_seleccionado['telefono']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($cliente_seleccionado['email'])): ?>
                                    <div class="contact-item">
                                        <i class='bx bx-envelope'></i>
                                        <span><?php echo htmlspecialchars($cliente_seleccionado['email']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mostrar mascotas existentes del cliente -->
                <?php if (isset($mascotas_del_cliente) && !empty($mascotas_del_cliente)): ?>
                    <div class="content-section">
                        <div class="mascotas-header">
                            <h3 class="mascotas-title">
                                <i class='bx bx-paw'></i>
                                Mascotas Registradas
                            </h3>
                            <div style="display: flex; gap: 1rem; align-items: center;">
                                <span class="mascotas-count"><?php echo count($mascotas_del_cliente); ?></span>
                                <?php if (!$modo_rapido && count($mascotas_del_cliente) >= 2): ?>
                                    <a href="registrarmascota.php?cliente_id=<?php echo $cliente_seleccionado['id']; ?>&modo_rapido=1" 
                                       class="btn-rapido-toggle">
                                        <i class='bx bx-plus-circle'></i>
                                        Modo Rápido
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mascotas-grid">
                            <?php foreach ($mascotas_del_cliente as $mascota): ?>
                                <div class="mascota-card">
                                    <div class="mascota-nombre">
                                        <i class='bx bxs-dog'></i>
                                        <?php echo htmlspecialchars($mascota['nombre']); ?>
                                    </div>
                                    <div class="mascota-details">
                                        <span><strong>Especie:</strong> <?php echo htmlspecialchars($mascota['especie_nombre']); ?></span>
                                        <?php if (!empty($mascota['raza'])): ?>
                                            <span><strong>Raza:</strong> <?php echo htmlspecialchars($mascota['raza']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($mascota['sexo'])): ?>
                                            <span><strong>Sexo:</strong> <?php echo in_array(strtolower((string)$mascota['sexo']), ['macho','m'], true) ? 'Macho' : 'Hembra'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="content-section">
                        <div class="empty-mascotas">
                            <i class='bx bx-paw'></i>
                            <h3>No hay mascotas registradas</h3>
                            <p>Este cliente aún no tiene mascotas registradas.</p>
                            <?php if (!$modo_rapido): ?>
                                <div style="margin-top: 1rem;">
                                    <a href="registrarmascota.php?cliente_id=<?php echo $cliente_seleccionado['id']; ?>&modo_rapido=1" 
                                       class="btn-rapido-toggle">
                                        <i class='bx bx-plus-circle'></i>
                                        Registrar Múltiples Mascotas
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- MODO RÁPIDO: Formulario para múltiples mascotas -->
            <?php if ($modo_rapido && isset($cliente_seleccionado)): ?>
                <div class="content-section">
                    <div class="modo-rapido-card">
                        <div class="modo-rapido-header">
                            <h2 class="modo-rapido-title">
                                <i class='bx bx-fast-forward-circle'></i>
                                Registro Rápido
                            </h2>
                            <div class="modo-rapido-actions">
                                <div class="contador-mascotas">
                                    <i class='bx bx-paw'></i>
                                    <span id="contador-mascotas">1</span>
                                </div>
                                <button type="button" id="agregar-mascota-btn" class="btn-agregar-mascota">
                                    <i class='bx bx-plus'></i>
                                    Agregar
                                </button>
                            </div>
                        </div>
                        
                        <form id="mascotaFormRapido" method="POST" action=""><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="modo_rapido" value="1">
                            <input type="hidden" name="cliente_id" value="<?php echo $cliente_seleccionado['id']; ?>">
                            
                            <div id="mascotas-rapidas-container">
                                <!-- Las mascotas se añadirán aquí dinámicamente -->
                            </div>
                            
                            <div class="form-actions">
                                <a href="registrarmascota.php?cliente_id=<?php echo $cliente_seleccionado['id']; ?>" class="btn btn-secondary">
                                    <i class='bx bx-x'></i>
                                    Cancelar
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-save'></i>
                                    Registrar Todas
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            
            <!-- MODO NORMAL: Formulario para una sola mascota -->
            <?php else: ?>
                <div class="content-section form-container">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class='bx bx-plus-circle'></i>
                            Información de la Nueva Mascota
                        </h2>
                        <?php if (isset($cliente_seleccionado) && isset($total_mascotas) && $total_mascotas >= 2): ?>
                            <a href="registrarmascota.php?cliente_id=<?php echo $cliente_seleccionado['id']; ?>&modo_rapido=1" 
                               class="btn-rapido-toggle">
                                <i class='bx bx-fast-forward'></i>
                                Modo Rápido
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <form id="mascotaForm" method="POST" action=""><?php echo av_csrf_field(); ?>
                        <div class="form-grid">
                            <!-- Información Básica de la Mascota -->
                            <div class="form-group">
                                <label for="nombre" class="form-label required">Nombre de la Mascota</label>
                                <input type="text" 
                                       id="nombre" 
                                       name="nombre" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                                       required
                                       placeholder="Ej: Max, Luna, etc."
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                                <div class="help-text">Nombre para identificar a la mascota</div>
                            </div>

                            <div class="form-group">
                                <label for="especie_id" class="form-label required">Especie</label>
                                <select id="especie_id" name="especie_id" class="form-control select" required <?php echo $db === null ? 'disabled' : ''; ?>>
                                    <option value="">Seleccione una especie</option>
                                    <?php foreach ($especies as $especie): ?>
                                        <option value="<?php echo $especie['id']; ?>" 
                                            <?php echo (isset($_POST['especie_id']) && $_POST['especie_id'] == $especie['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($especie['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Campo Raza (solo si existe) -->
                            <?php if (in_array('raza', $table_columns)): ?>
                            <div class="form-group">
                                <label for="raza" class="form-label">Raza</label>
                                <input type="text" 
                                       id="raza" 
                                       name="raza" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['raza'] ?? ''); ?>"
                                       placeholder="Ej: Labrador, Siames, etc."
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                            </div>
                            <?php endif; ?>

                            <!-- Campo Fecha Nacimiento (solo si existe) -->
                            <?php if (in_array('fecha_nacimiento', $table_columns)): ?>
                            <div class="form-group">
                                <label for="fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                                <input type="date" 
                                       id="fecha_nacimiento" 
                                       name="fecha_nacimiento" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['fecha_nacimiento'] ?? ''); ?>"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                            </div>
                            <?php endif; ?>

                            <!-- Campo Sexo (solo si existe) -->
                            <?php if (in_array('sexo', $table_columns)): ?>
                            <div class="form-group">
                                <label for="sexo" class="form-label">Sexo</label>
                                <select id="sexo" name="sexo" class="form-control select" required <?php echo $db === null ? 'disabled' : ''; ?>>
                                    <option value="">Seleccione sexo</option>
                                    <option value="macho" <?php echo (isset($_POST['sexo']) && in_array($_POST['sexo'], ['macho','M'], true)) ? 'selected' : ''; ?>>Macho</option>
                                    <option value="hembra" <?php echo (isset($_POST['sexo']) && in_array($_POST['sexo'], ['hembra','H'], true)) ? 'selected' : ''; ?>>Hembra</option>
                                </select>
                            </div>
                            <?php endif; ?>

                            <!-- Campo Color (solo si existe) -->
                            <?php if (in_array('color', $table_columns)): ?>
                            <div class="form-group">
                                <label for="color" class="form-label">Color</label>
                                <input type="text" 
                                       id="color" 
                                       name="color" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['color'] ?? ''); ?>"
                                       placeholder="Ej: Negro, Blanco, Marrón"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                            </div>
                            <?php endif; ?>

                            <!-- Campo Peso (solo si existe) -->
                            <?php if (in_array('peso', $table_columns)): ?>
                            <div class="form-group">
                                <label for="peso" class="form-label">Peso (kg)</label>
                                <input type="number" 
                                       id="peso" 
                                       name="peso" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['peso'] ?? ''); ?>"
                                       step="0.1"
                                       min="0"
                                       max="500"
                                       placeholder="Ej: 5.5"
                                       <?php echo $db === null ? 'disabled' : ''; ?>>
                                <div class="help-text">Peso en kilogramos (0-500)</div>
                            </div>
                            <?php endif; ?>

                            <!-- SELECCIÓN DE CLIENTE -->
                            <div class="form-group full-width">
                                <label for="cliente_id" class="form-label">Propietario (Cliente Existente)</label>
                                <div class="select-wrapper">
                                    <input type="text" 
                                           id="cliente-search" 
                                           class="form-control search-select" 
                                           placeholder="Buscar cliente por nombre, teléfono o documento...">
                                </div>
                                <div class="select-with-button">
                                    <select id="cliente_id" name="cliente_id" class="form-control select" <?php echo $db === null ? 'disabled' : ''; ?>>
                                        <option value="">Seleccione un cliente existente</option>
                                        <?php foreach ($clientes as $cliente): ?>
                                            <option value="<?php echo $cliente['id']; ?>" 
                                                <?php 
                                                    if (isset($cliente_seleccionado) && $cliente['id'] == $cliente_seleccionado['id']) {
                                                        echo 'selected';
                                                    } elseif (isset($_POST['cliente_id']) && $_POST['cliente_id'] == $cliente['id']) {
                                                        echo 'selected';
                                                    }
                                                ?>>
                                                <?php echo htmlspecialchars($cliente['nombre']); ?>
                                                <?php if (!empty($cliente['telefono'])): ?>
                                                    - <?php echo htmlspecialchars($cliente['telefono']); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="buscar-cliente-btn" class="btn btn-success btn-sm">
                                        <i class='bx bx-search'></i>
                                        Buscar
                                    </button>
                                </div>
                            </div>

                            <!-- BOTÓN PARA ALTERNAR ENTRE CLIENTE EXISTENTE/NUEVO -->
                            <div class="form-group full-width">
                                <div class="cliente-toggle-section">
                                    <button type="button" id="toggle-cliente-btn" class="btn btn-success btn-sm">
                                        <i class='bx bx-user-plus'></i> ¿Cliente nuevo? Regístrelo aquí
                                    </button>
                                </div>
                            </div>

                            <!-- SECCIÓN DE CLIENTE NUEVO (oculta inicialmente) -->
                            <div class="form-group full-width">
                                <div id="nuevo-cliente-section" class="cliente-nuevo-card" style="display: none;">
                                    <div class="cliente-nuevo-header">
                                        <i class='bx bx-user-plus cliente-nuevo-icon'></i>
                                        <h3 class="cliente-nuevo-title">Registrar Nuevo Cliente</h3>
                                    </div>
                                    
                                    <div class="form-grid" style="padding: 1.5rem;">
                                        <div class="form-group">
                                            <label class="form-label required">Nombre del Cliente</label>
                                            <input type="text" name="cliente_nombre" class="form-control" 
                                                   placeholder="Nombre completo"
                                                   value="<?php echo htmlspecialchars($_POST['cliente_nombre'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Teléfono</label>
                                            <input type="text" name="cliente_telefono" class="form-control" 
                                                   placeholder="[Clinic phone not configured]"
                                                   value="<?php echo htmlspecialchars($_POST['cliente_telefono'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="cliente_email" class="form-control" 
                                                   placeholder="cliente@email.com"
                                                   value="<?php echo htmlspecialchars($_POST['cliente_email'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Documento de Identidad</label>
                                            <input type="text" name="cliente_documento_identidad" class="form-control" 
                                                   placeholder="[ID not configured]"
                                                   value="<?php echo htmlspecialchars($_POST['cliente_documento_identidad'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="form-group full-width">
                                            <label class="form-label">Dirección</label>
                                            <textarea name="cliente_direccion" class="form-control" rows="2" 
                                                      placeholder="Dirección completa"><?php echo htmlspecialchars($_POST['cliente_direccion'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div style="text-align: center; padding: 0 1.5rem 1.5rem;">
                                        <button type="button" id="volver-cliente-existente-btn" class="btn btn-secondary btn-sm">
                                            <i class='bx bx-arrow-back'></i> Usar cliente existente
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Campo Notas (solo si existe) -->
                            <?php if (in_array('notas', $table_columns)): ?>
                            <div class="form-group full-width">
                                <label for="notas" class="form-label">Notas Adicionales</label>
                                <textarea id="notas" 
                                          name="notas" 
                                          class="form-control" 
                                          placeholder="Información adicional (alergias, comportamientos especiales, etc.)"
                                          <?php echo $db === null ? 'disabled' : ''; ?>><?php echo htmlspecialchars($_POST['notas'] ?? ''); ?></textarea>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Acciones del Formulario -->
                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary" <?php echo $db === null ? 'disabled' : ''; ?>>
                                <i class='bx bx-reset'></i>
                                Limpiar
                            </button>
                            <a href="listademascotas.php" class="btn btn-secondary">
                                <i class='bx bx-x'></i>
                                Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary" <?php echo $db === null ? 'disabled' : ''; ?>>
                                <i class='bx bx-save'></i>
                                Registrar Mascota
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Acciones Rápidas (solo si hay conexión y no está en modo rápido) -->
            <?php if ($db !== null && !$modo_rapido): ?>
                <div class="content-section">
                    <div class="section-header">
                        <h3><i class='bx bx-rocket'></i> Acciones Rápidas</h3>
                    </div>
                    <div class="quick-actions">
                        <a href="listadeclientes.php" class="action-card">
                            <div class="action-icon">
                                <i class='bx bx-group'></i>
                            </div>
                            <div class="action-title">Ver Clientes</div>
                            <div class="action-description">Lista de todos los clientes</div>
                        </a>
                        <a href="agendarcita.php" class="action-card">
                            <div class="action-icon">
                                <i class='bx bx-calendar-plus'></i>
                            </div>
                            <div class="action-title">Agendar Cita</div>
                            <div class="action-description">Programar una nueva cita</div>
                        </a>
                        <a href="listademascotas.php" class="action-card">
                            <div class="action-icon">
                                <i class='bx bx-paw'></i>
                            </div>
                            <div class="action-title">Ver Mascotas</div>
                            <div class="action-description">Lista de todas las mascotas</div>
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

    <script src="js/sidebar.js"></script>
    <script>
        <?php if ($modo_rapido && isset($cliente_seleccionado)): ?>
        // ==============================================
        // JAVASCRIPT PARA MODO RÁPIDO
        // ==============================================
        
        document.addEventListener('DOMContentLoaded', function() {
            const contadorMascotas = document.getElementById('contador-mascotas');
            const contenedorMascotas = document.getElementById('mascotas-rapidas-container');
            const btnAgregarMascota = document.getElementById('agregar-mascota-btn');
            const formularioRapido = document.getElementById('mascotaFormRapido');
            
            let contador = <?php echo $modo_rapido_contador; ?>;
            
            // Función para agregar una mascota rápida
            function agregarMascotaRapida() {
                const mascotaNumero = contador;
                const mascotaDiv = document.createElement('div');
                mascotaDiv.className = 'mascota-rapida-item';
                mascotaDiv.id = 'mascota-rapida-' + mascotaNumero;
                
                mascotaDiv.innerHTML = `
                    <div class="mascota-rapida-header">
                        <div class="mascota-rapida-numero">${mascotaNumero}</div>
                        <div class="mascota-rapida-eliminar" onclick="eliminarMascotaRapida(${mascotaNumero})">
                            <i class='bx bx-trash'></i>
                        </div>
                    </div>
                    <div class="rapido-form-grid">
                        <div class="form-group">
                            <label class="form-label required">Nombre</label>
                            <input type="text" 
                                   name="nombre_mascota_${mascotaNumero}" 
                                   class="form-control" 
                                   placeholder="Nombre de la mascota"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Especie</label>
                            <select name="especie_id_${mascotaNumero}" class="form-control select" required>
                                <option value="">Seleccione especie</option>
                                <?php foreach ($especies as $especie): ?>
                                <option value="<?php echo $especie['id']; ?>"><?php echo htmlspecialchars($especie['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Raza</label>
                            <input type="text" 
                                   name="raza_${mascotaNumero}" 
                                   class="form-control" 
                                   placeholder="Raza (opcional)">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sexo</label>
                            <select name="sexo_${mascotaNumero}" class="form-control select" required>
                                <option value="">Seleccione sexo</option>
                                <option value="macho">Macho</option>
                                <option value="hembra">Hembra</option>
                            </select>
                        </div>
                    </div>
                `;
                
                contenedorMascotas.appendChild(mascotaDiv);
                contador++;
                actualizarContador();
                
                setTimeout(() => {
                    const nombreInput = mascotaDiv.querySelector('input[name^="nombre_mascota_"]');
                    if (nombreInput) nombreInput.focus();
                }, 100);
            }
            
            // Función para eliminar una mascota rápida
            window.eliminarMascotaRapida = function(numero) {
                const mascotaDiv = document.getElementById('mascota-rapida-' + numero);
                if (mascotaDiv) {
                    const totalMascotas = document.querySelectorAll('.mascota-rapida-item').length;
                    if (totalMascotas > 1) {
                        mascotaDiv.style.transform = 'translateX(-100%)';
                        mascotaDiv.style.opacity = '0';
                        setTimeout(() => {
                            mascotaDiv.remove();
                            actualizarContador();
                            renumerarMascotas();
                        }, 300);
                    } else {
                        alert('Debe haber al menos una mascota para registrar.');
                    }
                }
            }
            
            // Función para renumerar las mascotas después de eliminar
            function renumerarMascotas() {
                const mascotas = document.querySelectorAll('.mascota-rapida-item');
                mascotas.forEach((mascota, index) => {
                    const nuevoNumero = index + 1;
                    mascota.id = 'mascota-rapida-' + nuevoNumero;
                    
                    const numeroSpan = mascota.querySelector('.mascota-rapida-numero');
                    if (numeroSpan) numeroSpan.textContent = nuevoNumero;
                    
                    const inputs = mascota.querySelectorAll('[name]');
                    inputs.forEach(input => {
                        const name = input.getAttribute('name');
                        const newName = name.replace(/\d+$/, nuevoNumero);
                        input.setAttribute('name', newName);
                    });
                    
                    const btnEliminar = mascota.querySelector('.mascota-rapida-eliminar');
                    if (btnEliminar) {
                        btnEliminar.setAttribute('onclick', `eliminarMascotaRapida(${nuevoNumero})`);
                    }
                });
                
                contador = mascotas.length + 1;
                actualizarContador();
            }
            
            // Función para actualizar el contador visual
            function actualizarContador() {
                const totalMascotas = document.querySelectorAll('.mascota-rapida-item').length;
                contadorMascotas.textContent = totalMascotas;
            }
            
            // Validación del formulario rápido
            formularioRapido.addEventListener('submit', function(e) {
                let hayMascotasValidas = false;
                const mascotas = document.querySelectorAll('.mascota-rapida-item');
                
                mascotas.forEach((mascota) => {
                    const nombre = mascota.querySelector('input[name^="nombre_mascota_"]').value.trim();
                    const especie = mascota.querySelector('select[name^="especie_id_"]').value;
                    
                    if (nombre && especie) {
                        hayMascotasValidas = true;
                    }
                });
                
                if (!hayMascotasValidas) {
                    e.preventDefault();
                    alert('Debe completar al menos el nombre y especie de una mascota.');
                    return false;
                }
                
                this.classList.add('loading');
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Registrando...';
                submitBtn.disabled = true;
            });
            
            // Crear la primera mascota al cargar
            agregarMascotaRapida();
            
            // Evento para agregar más mascotas
            btnAgregarMascota.addEventListener('click', function() {
                agregarMascotaRapida();
            });
        });
        <?php endif; ?>
        
        <?php if (!$modo_rapido): ?>
        // ==============================================
        // JAVASCRIPT PARA MODO NORMAL
        // ==============================================
        
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('toggle-cliente-btn');
            const volverBtn = document.getElementById('volver-cliente-existente-btn');
            const clienteSelect = document.getElementById('cliente_id');
            const clienteSearch = document.getElementById('cliente-search');
            const nuevoClienteSection = document.getElementById('nuevo-cliente-section');
            const buscarClienteBtn = document.getElementById('buscar-cliente-btn');
            const mascotaForm = document.getElementById('mascotaForm');
            
            // Verificar si ya estamos en modo "cliente nuevo"
            const esClienteNuevo = "<?php echo isset($_POST['cliente_nombre']) && !empty($_POST['cliente_nombre']) ? 'true' : 'false'; ?>";
            
            if (esClienteNuevo === 'true') {
                activarModoClienteNuevo();
            }
            
            // Función para guardar datos del formulario temporalmente
            function guardarDatosFormulario() {
                const datos = {
                    nombre: document.getElementById('nombre')?.value || '',
                    especie_id: document.getElementById('especie_id')?.value || '',
                    <?php if (in_array('raza', $table_columns)): ?>
                    raza: document.getElementById('raza')?.value || '',
                    <?php endif; ?>
                    <?php if (in_array('fecha_nacimiento', $table_columns)): ?>
                    fecha_nacimiento: document.getElementById('fecha_nacimiento')?.value || '',
                    <?php endif; ?>
                    <?php if (in_array('sexo', $table_columns)): ?>
                    sexo: document.getElementById('sexo')?.value || '',
                    <?php endif; ?>
                    <?php if (in_array('color', $table_columns)): ?>
                    color: document.getElementById('color')?.value || '',
                    <?php endif; ?>
                    <?php if (in_array('peso', $table_columns)): ?>
                    peso: document.getElementById('peso')?.value || '',
                    <?php endif; ?>
                    <?php if (in_array('notas', $table_columns)): ?>
                    notas: document.getElementById('notas')?.value || ''
                    <?php endif; ?>
                };
                sessionStorage.setItem('mascotaFormData', JSON.stringify(datos));
            }
            
            // Función para restaurar datos del formulario
            function restaurarDatosFormulario() {
                const datos = sessionStorage.getItem('mascotaFormData');
                if (datos) {
                    const parsed = JSON.parse(datos);
                    
                    if (parsed.nombre) document.getElementById('nombre').value = parsed.nombre;
                    if (parsed.especie_id) document.getElementById('especie_id').value = parsed.especie_id;
                    <?php if (in_array('raza', $table_columns)): ?>
                    if (parsed.raza) document.getElementById('raza').value = parsed.raza;
                    <?php endif; ?>
                    <?php if (in_array('fecha_nacimiento', $table_columns)): ?>
                    if (parsed.fecha_nacimiento) document.getElementById('fecha_nacimiento').value = parsed.fecha_nacimiento;
                    <?php endif; ?>
                    <?php if (in_array('sexo', $table_columns)): ?>
                    if (parsed.sexo) document.getElementById('sexo').value = parsed.sexo;
                    <?php endif; ?>
                    <?php if (in_array('color', $table_columns)): ?>
                    if (parsed.color) document.getElementById('color').value = parsed.color;
                    <?php endif; ?>
                    <?php if (in_array('peso', $table_columns)): ?>
                    if (parsed.peso) document.getElementById('peso').value = parsed.peso;
                    <?php endif; ?>
                    <?php if (in_array('notas', $table_columns)): ?>
                    if (parsed.notas) document.getElementById('notas').value = parsed.notas;
                    <?php endif; ?>
                    
                    sessionStorage.removeItem('mascotaFormData');
                }
            }
            
            restaurarDatosFormulario();
            
            // Función de búsqueda en el selector de clientes
            if (clienteSearch && clienteSelect) {
                clienteSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const options = clienteSelect.querySelectorAll('option');
                    
                    options.forEach(option => {
                        if (option.value === '') return;
                        
                        const text = option.textContent.toLowerCase();
                        if (text.includes(searchTerm) || searchTerm === '') {
                            option.style.display = '';
                        } else {
                            option.style.display = 'none';
                        }
                    });
                });
                
                clienteSearch.addEventListener('focus', function() {
                    this.style.borderColor = 'var(--primary)';
                });
                
                clienteSearch.addEventListener('blur', function() {
                    this.style.borderColor = 'var(--gray)';
                });
            }
            
            // Botón para alternar a cliente nuevo
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    activarModoClienteNuevo();
                });
            }
            
            // Botón para volver a cliente existente
            if (volverBtn) {
                volverBtn.addEventListener('click', function() {
                    desactivarModoClienteNuevo();
                });
            }
            
            // Botón para buscar cliente
            if (buscarClienteBtn) {
                buscarClienteBtn.addEventListener('click', function() {
                    const nombreMascota = document.getElementById('nombre').value;
                    const especieId = document.getElementById('especie_id').value;
                    
                    if (nombreMascota || especieId) {
                        if (confirm('¿Guardar los datos de la mascota antes de buscar?')) {
                            guardarDatosFormulario();
                        }
                    }
                    window.location.href = 'listadeclientes.php?select=1&return_to=registrarmascota.php';
                });
            }
            
            // Cambio de cliente seleccionado
            if (clienteSelect) {
                clienteSelect.addEventListener('change', function() {
                    const clienteId = this.value;
                    if (clienteId) {
                        const nombreMascota = document.getElementById('nombre').value;
                        const especieId = document.getElementById('especie_id').value;
                        
                        if (nombreMascota || especieId) {
                            if (confirm('¿Cambiar de cliente? Los datos se guardarán temporalmente.')) {
                                guardarDatosFormulario();
                                window.location.href = 'registrarmascota.php?cliente_id=' + clienteId;
                            } else {
                                this.value = '';
                                return false;
                            }
                        } else {
                            window.location.href = 'registrarmascota.php?cliente_id=' + clienteId;
                        }
                    }
                });
            }
            
            // Función para activar modo "cliente nuevo"
            function activarModoClienteNuevo() {
                nuevoClienteSection.style.display = 'block';
                if (clienteSelect) {
                    clienteSelect.disabled = true;
                    clienteSelect.style.opacity = '0.5';
                }
                if (toggleBtn) {
                    toggleBtn.style.display = 'none';
                }
                if (clienteSearch) {
                    clienteSearch.disabled = true;
                    clienteSearch.style.opacity = '0.5';
                }
                
                const nombreClienteInput = nuevoClienteSection.querySelector('input[name="cliente_nombre"]');
                if (nombreClienteInput) {
                    nombreClienteInput.setAttribute('required', 'required');
                    nombreClienteInput.focus();
                }
            }
            
            // Función para desactivar modo "cliente nuevo"
            function desactivarModoClienteNuevo() {
                nuevoClienteSection.style.display = 'none';
                if (clienteSelect) {
                    clienteSelect.disabled = false;
                    clienteSelect.style.opacity = '1';
                }
                if (toggleBtn) {
                    toggleBtn.style.display = 'inline-flex';
                }
                if (clienteSearch) {
                    clienteSearch.disabled = false;
                    clienteSearch.style.opacity = '1';
                }
                
                const nombreClienteInput = nuevoClienteSection.querySelector('input[name="cliente_nombre"]');
                if (nombreClienteInput) {
                    nombreClienteInput.removeAttribute('required');
                }
            }
            
            // Validación del formulario
            if (mascotaForm) {
                mascotaForm.addEventListener('submit', function(e) {
                    const nombre = document.getElementById('nombre').value.trim();
                    const especie = document.getElementById('especie_id').value;
                    
                    if (!nombre || !especie) {
                        e.preventDefault();
                        alert('Complete los campos obligatorios: Nombre y Especie');
                        return false;
                    }
                    
                    const clienteId = document.getElementById('cliente_id').value;
                    const clienteNombre = document.querySelector('input[name="cliente_nombre"]')?.value.trim();
                    const estaEnModoClienteNuevo = nuevoClienteSection.style.display === 'block';
                    
                    if (estaEnModoClienteNuevo) {
                        if (!clienteNombre) {
                            e.preventDefault();
                            alert('Complete el nombre del cliente nuevo');
                            return false;
                        }
                    } else {
                        if (!clienteId) {
                            e.preventDefault();
                            alert('Seleccione un cliente existente o registre uno nuevo');
                            return false;
                        }
                    }
                    
                    const fechaNacimiento = document.getElementById('fecha_nacimiento');
                    if (fechaNacimiento && fechaNacimiento.value) {
                        const fecha = new Date(fechaNacimiento.value);
                        const hoy = new Date();
                        if (fecha > hoy) {
                            e.preventDefault();
                            alert('La fecha de nacimiento no puede ser futura');
                            fechaNacimiento.focus();
                            return false;
                        }
                    }
                    
                    const pesoInput = document.getElementById('peso');
                    if (pesoInput && pesoInput.value) {
                        const peso = parseFloat(pesoInput.value.replace(',', '.'));
                        if (peso < 0 || peso > 500) {
                            e.preventDefault();
                            alert('El peso debe estar entre 0 y 500 kg');
                            pesoInput.focus();
                            return false;
                        }
                    }
                    
                    sessionStorage.removeItem('mascotaFormData');
                    
                    this.classList.add('loading');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Registrando...';
                    submitBtn.disabled = true;
                });
            }
            
            // Reset del formulario
            const resetBtn = document.querySelector('button[type="reset"]');
            if (resetBtn) {
                resetBtn.addEventListener('click', function(e) {
                    if (!confirm('¿Limpiar todos los campos?')) {
                        e.preventDefault();
                    } else {
                        sessionStorage.removeItem('mascotaFormData');
                    }
                });
            }
            
            // Validación en tiempo real para campos requeridos
            const requiredFields = document.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                field.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.style.borderColor = '#f56565';
                    } else {
                        this.style.borderColor = 'var(--gray)';
                    }
                });
            });
            
            // Focus en el primer campo
            const firstInput = document.querySelector('input[type="text"]:not([disabled])');
            if (firstInput) {
                firstInput.focus();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>