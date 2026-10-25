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
$db = null;
$citas = [];
$mascotas = [];
$clientes = [];
$veterinarios = [];
$estadisticas = [
    'total' => 0,
    'completadas' => 0,
    'canceladas' => 0,
    'pendientes' => 0,
    'confirmadas' => 0,
    'en_progreso' => 0,
    'no_show' => 0,
    'este_mes' => 0,
    'este_ano' => 0,
    'total_ingresos' => 0,
    'promedio_citas_dia' => 0
];

// Variables de estado
$sin_datos = false;
$error = '';

// Parámetros de filtro
$filtro_mascota = $_GET['mascota_id'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtro_mes = $_GET['mes'] ?? '';
$filtro_ano = $_GET['ano'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$busqueda = $_GET['search'] ?? '';
$filtro_veterinario = $_GET['veterinario'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$filtro_cliente = $_GET['cliente_id'] ?? '';

// Parámetros de ordenación
$ordenar_por = $_GET['ordenar_por'] ?? 'fecha_cita_desc';
$direccion_orden = 'DESC';

// Determinar ordenación
switch ($ordenar_por) {
    case 'fecha_cita_asc':
        $orden_sql = "c.fecha_cita ASC, c.hora_cita ASC";
        break;
    case 'fecha_cita_desc':
        $orden_sql = "c.fecha_cita DESC, c.hora_cita DESC";
        break;
    case 'mascota_asc':
        $orden_sql = "m.nombre ASC";
        break;
    case 'mascota_desc':
        $orden_sql = "m.nombre DESC";
        break;
    case 'precio_asc':
        $orden_sql = "c.costo_total ASC";
        break;
    case 'precio_desc':
        $orden_sql = "c.costo_total DESC";
        break;
    default:
        $orden_sql = "c.fecha_cita DESC, c.hora_cita DESC";
}

// Parámetros de paginación
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$limite = 50; // Más registros por página para historial completo
$offset = ($pagina_actual - 1) * $limite;

// Arrays para filtros
$anos_disponibles = [];
$meses_disponibles = [];

try {
    // Crear conexión directamente
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
    
    // Verificar si la tabla citas existe
    $check_table = $db->query("SHOW TABLES LIKE 'citas'");
    $tabla_existe = $check_table->rowCount() > 0;
    
    if (!$tabla_existe) {
        $sin_datos = true;
    } else {
        // Obtener lista de mascotas para filtro
        $mascotas_sql = "SELECT id, nombre FROM mascotas ORDER BY nombre";
        $mascotas_stmt = $db->query($mascotas_sql);
        $mascotas = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener lista de clientes para filtro
        $clientes_sql = "SELECT id, nombre FROM clientes ORDER BY nombre";
        $clientes_stmt = $db->query($clientes_sql);
        $clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener lista de veterinarios para filtro
        $veterinarios_sql = "SELECT id, nombre FROM usuarios WHERE rol IN ('veterinarian', 'veterinario', 'admin') AND estado = 'active' ORDER BY nombre";
        $veterinarios_stmt = $db->query($veterinarios_sql);
        $veterinarios = $veterinarios_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Verificar si hay datos en la tabla citas
        $check_data = $db->query("SELECT COUNT(*) as total FROM citas");
        $data_result = $check_data->fetch(PDO::FETCH_ASSOC);
        $tiene_datos = $data_result['total'] > 0;
        
        if ($tiene_datos) {
            // Construir consulta base para TODAS las citas
            $sql = "
                SELECT c.*, 
                       m.nombre as mascota_nombre,
                       m.especie_id,
                       m.sexo as mascota_sexo,
                       m.fecha_nacimiento as mascota_nacimiento,
                       e.nombre as especie_nombre,
                       cl.id as cliente_id,
                       cl.nombre as cliente_nombre,
                       cl.telefono as cliente_telefono,
                       cl.email as cliente_email,
                       cl.direccion as cliente_direccion,
                       s.nombre as servicio_nombre,
                       s.categoria as servicio_categoria,
                       s.precio as servicio_precio,
                       u.nombre as veterinario_nombre,
                       DATE_FORMAT(c.fecha_cita, '%d/%m/%Y') as fecha_formateada,
                       TIME_FORMAT(c.hora_cita, '%H:%i') as hora_formateada,
                       CASE 
                           WHEN c.estado = 'completed' THEN 'Completada'
                           WHEN c.estado = 'cancelled' THEN 'Cancelada'
                           WHEN c.estado = 'scheduled' THEN 'Pendiente'
                           WHEN c.estado = 'confirmed' THEN 'Confirmada'
                           WHEN c.estado = 'in_progress' THEN 'En Progreso'
                           WHEN c.estado = 'no_show' THEN 'No Presentado'
                           ELSE c.estado
                       END as estado_espanol
                FROM citas c
                LEFT JOIN mascotas m ON c.mascota_id = m.id
                LEFT JOIN especies e ON m.especie_id = e.id
                LEFT JOIN clientes cl ON m.cliente_id = cl.id
                LEFT JOIN servicios s ON c.servicio_id = s.id
                LEFT JOIN usuarios u ON c.veterinario_id = u.id
                WHERE 1=1
            ";
            
            $sql_count = "
                SELECT COUNT(*) as total
                FROM citas c
                LEFT JOIN mascotas m ON c.mascota_id = m.id
                LEFT JOIN especies e ON m.especie_id = e.id
                LEFT JOIN clientes cl ON m.cliente_id = cl.id
                LEFT JOIN servicios s ON c.servicio_id = s.id
                LEFT JOIN usuarios u ON c.veterinario_id = u.id
                WHERE 1=1
            ";
            
            $params = [];
            
            // Aplicar filtros
            if (!empty($filtro_mascota)) {
                $sql .= " AND c.mascota_id = ?";
                $sql_count .= " AND c.mascota_id = ?";
                $params[] = $filtro_mascota;
            }
            
            if (!empty($filtro_cliente)) {
                $sql .= " AND cl.id = ?";
                $sql_count .= " AND cl.id = ?";
                $params[] = $filtro_cliente;
            }
            
            if (!empty($filtro_estado)) {
                $sql .= " AND c.estado = ?";
                $sql_count .= " AND c.estado = ?";
                $params[] = $filtro_estado;
            }
            
            if (!empty($filtro_tipo)) {
                $sql .= " AND s.categoria = ?";
                $sql_count .= " AND s.categoria = ?";
                $params[] = $filtro_tipo;
            }
            
            if (!empty($filtro_veterinario)) {
                $sql .= " AND c.veterinario_id = ?";
                $sql_count .= " AND c.veterinario_id = ?";
                $params[] = $filtro_veterinario;
            }
            
            if (!empty($filtro_mes)) {
                $sql .= " AND DATE_FORMAT(c.fecha_cita, '%Y-%m') = ?";
                $sql_count .= " AND DATE_FORMAT(c.fecha_cita, '%Y-%m') = ?";
                $params[] = $filtro_mes;
            }
            
            if (!empty($filtro_ano)) {
                $sql .= " AND YEAR(c.fecha_cita) = ?";
                $sql_count .= " AND YEAR(c.fecha_cita) = ?";
                $params[] = $filtro_ano;
            }
            
            if (!empty($fecha_inicio) && !empty($fecha_fin)) {
                $sql .= " AND DATE(c.fecha_cita) BETWEEN ? AND ?";
                $sql_count .= " AND DATE(c.fecha_cita) BETWEEN ? AND ?";
                $params[] = $fecha_inicio;
                $params[] = $fecha_fin;
            } elseif (!empty($fecha_inicio)) {
                $sql .= " AND DATE(c.fecha_cita) >= ?";
                $sql_count .= " AND DATE(c.fecha_cita) >= ?";
                $params[] = $fecha_inicio;
            } elseif (!empty($fecha_fin)) {
                $sql .= " AND DATE(c.fecha_cita) <= ?";
                $sql_count .= " AND DATE(c.fecha_cita) <= ?";
                $params[] = $fecha_fin;
            }
            
            if (!empty($busqueda)) {
                $sql .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR s.nombre LIKE ? OR c.notas LIKE ?)";
                $sql_count .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR s.nombre LIKE ? OR c.notas LIKE ?)";
                $search_term = '%' . $busqueda . '%';
                for ($i = 0; $i < 4; $i++) {
                    $params[] = $search_term;
                }
            }
            
            // Agregar ORDER BY
            $sql .= " ORDER BY $orden_sql LIMIT $limite OFFSET $offset";
            
            // Obtener total de registros para paginación
            $stmt_count = $db->prepare($sql_count);
            $stmt_count->execute($params);
            $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
            $total_paginas = ceil($total_registros / $limite);
            
            // Obtener citas
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calcular estadísticas COMPLETAS
            $estadisticas['total'] = $total_registros;
            
            // Estadísticas por estado (todas las citas)
            $stats_sql = "SELECT estado, COUNT(*) as total FROM citas GROUP BY estado";
            $stats_stmt = $db->query($stats_sql);
            $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($stats as $stat) {
                switch ($stat['estado']) {
                    case 'completed':
                        $estadisticas['completadas'] = $stat['total'];
                        break;
                    case 'cancelled':
                        $estadisticas['canceladas'] = $stat['total'];
                        break;
                    case 'scheduled':
                        $estadisticas['pendientes'] = $stat['total'];
                        break;
                    case 'confirmed':
                        $estadisticas['confirmadas'] = $stat['total'];
                        break;
                    case 'in_progress':
                        $estadisticas['en_progreso'] = $stat['total'];
                        break;
                    case 'no_show':
                        $estadisticas['no_show'] = $stat['total'];
                        break;
                }
            }
            
            // Citas de este mes
            $mes_actual = date('Y-m');
            $mes_sql = "SELECT COUNT(*) as total FROM citas WHERE DATE_FORMAT(fecha_cita, '%Y-%m') = ?";
            $mes_stmt = $db->prepare($mes_sql);
            $mes_stmt->execute([$mes_actual]);
            $mes_result = $mes_stmt->fetch(PDO::FETCH_ASSOC);
            $estadisticas['este_mes'] = $mes_result['total'];
            
            // Citas de este año
            $ano_actual = date('Y');
            $ano_sql = "SELECT COUNT(*) as total FROM citas WHERE YEAR(fecha_cita) = ?";
            $ano_stmt = $db->prepare($ano_sql);
            $ano_stmt->execute([$ano_actual]);
            $ano_result = $ano_stmt->fetch(PDO::FETCH_ASSOC);
            $estadisticas['este_ano'] = $ano_result['total'];
            
            // Total de ingresos de todas las citas completadas
            $ingresos_sql = "SELECT COALESCE(SUM(costo_total), 0) as total FROM citas WHERE estado = 'completed'";
            $ingresos_stmt = $db->query($ingresos_sql);
            $ingresos_result = $ingresos_stmt->fetch(PDO::FETCH_ASSOC);
            $estadisticas['total_ingresos'] = $ingresos_result['total'];
            
            // Promedio de citas por día
            if ($total_registros > 0) {
                $fechas_sql = "SELECT MIN(fecha_cita) as primera, MAX(fecha_cita) as ultima FROM citas";
                $fechas_stmt = $db->query($fechas_sql);
                $fechas_result = $fechas_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($fechas_result['primera'] && $fechas_result['ultima']) {
                    $primera_fecha = new DateTime($fechas_result['primera']);
                    $ultima_fecha = new DateTime($fechas_result['ultima']);
                    $diferencia_dias = $primera_fecha->diff($ultima_fecha)->days + 1;
                    
                    if ($diferencia_dias > 0) {
                        $estadisticas['promedio_citas_dia'] = round($total_registros / $diferencia_dias, 2);
                    }
                }
            }
            
            // Obtener años disponibles para filtro
            $anos_sql = "SELECT DISTINCT YEAR(fecha_cita) as ano FROM citas ORDER BY ano DESC";
            $anos_stmt = $db->query($anos_sql);
            $anos_disponibles = $anos_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener meses disponibles para filtro
            $meses_sql = "SELECT DISTINCT DATE_FORMAT(fecha_cita, '%Y-%m') as mes, 
                                 DATE_FORMAT(fecha_cita, '%M %Y') as mes_formateado 
                          FROM citas 
                          ORDER BY mes DESC";
            $meses_stmt = $db->query($meses_sql);
            $meses_disponibles = $meses_stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            $sin_datos = true;
        }
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Mapeo de categorías de servicios para filtro
$tipos_cita = [
    'consultation' => 'Consulta',
    'vaccination' => 'Vacunación',
    'surgery' => 'Cirugía',
    'emergency' => 'Urgencia',
    'grooming' => 'Estética',
    'laboratory' => 'Laboratorio',
    'other' => 'Otro'
];

// Mapeo de estados para filtro
$estados_cita = [
    'scheduled' => 'Pendiente',
    'confirmed' => 'Confirmada',
    'in_progress' => 'En Progreso',
    'completed' => 'Completada',
    'cancelled' => 'Cancelada',
    'no_show' => 'No Presentado'
];

// Opciones de ordenación
$opciones_orden = [
    'fecha_cita_desc' => 'Fecha (Más reciente primero)',
    'fecha_cita_asc' => 'Fecha (Más antigua primero)',
    'mascota_asc' => 'Mascota (A-Z)',
    'mascota_desc' => 'Mascota (Z-A)',
    'precio_asc' => 'Precio (Menor a Mayor)',
    'precio_desc' => 'Precio (Mayor a Menor)'
];

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
    <title>Historial Completo de Citas - VetNexo</title>
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

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.warning .stat-icon {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-card.info .stat-icon {
            background: #e3f2fd;
            color: var(--azul);
        }

        .stat-card.success .stat-icon {
            background: #e8f5e9;
            color: var(--success);
        }

        .stat-card.danger .stat-icon {
            background: #ffebee;
            color: var(--emergency);
        }

        .trend-indicator {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(15, 108, 51, 0.1);
            color: var(--success);
        }

        .trend-indicator.negative {
            background: rgba(249, 19, 2, 0.1);
            color: var(--emergency);
        }

        .stat-number {
            font-size: 2rem;
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
            margin-bottom: 0.5rem;
        }

        .stat-subtext {
            color: var(--text-lighter);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .filters-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .filters-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .filters-body {
            padding: 1.5rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
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
            background-size: 16px;
            padding-right: 2.5rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            grid-column: 1 / -1;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Control de resultados por página */
        .results-control {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0.5rem 0;
        }

        .results-info {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .order-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Tabla */
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

        /* Pet Info */
        .pet-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .pet-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .pet-details h4 {
            margin: 0 0 0.2rem 0;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .pet-details p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.8rem;
        }

        .client-info h4 {
            margin: 0 0 0.2rem 0;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .client-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.8rem;
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
            white-space: nowrap;
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

        .badge-secondary {
            background: #f5f5f5;
            color: #5A4B37;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        /* Status Indicators */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-dot.scheduled {
            background: var(--accent);
        }

        .status-dot.confirmed {
            background: var(--azul);
        }

        .status-dot.completed {
            background: var(--success);
        }

        .status-dot.cancelled {
            background: var(--emergency);
        }

        .status-dot.in_progress {
            background: var(--danger);
            animation: pulse 1s infinite;
        }

        .status-dot.no_show {
            background: #9ca3af;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        /* Paginación */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding: 1rem 1.5rem;
            background: var(--white);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
        }

        .pagination-info {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .page-links {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--primary);
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .page-link:hover {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .page-link.disabled:hover {
            background: transparent;
            color: var(--primary);
            border-color: var(--gray);
        }

        /* Export Button */
        .export-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #38b2ac, #319795);
            color: white;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            background: linear-gradient(135deg, #319795, #2c7a7b);
            transform: translateY(-2px);
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
        @media (max-width: 1400px) {
            .filter-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-grid {
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
                grid-template-columns: 1fr;
            }
            
            .results-control {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .pagination {
                flex-direction: column;
                gap: 1rem;
            }
            
            .page-links {
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
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
                        <i class='bx bx-history'></i>
                        Historial Completo de Citas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Registro completo de todas las citas en el sistema
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="javascript:history.back()" class="back-btn">
                        <i class='bx bx-arrow-back'></i> Atrás
                    </a>
                    <a href="citasdeldia.php" class="btn btn-info">
                        <i class='bx bx-calendar'></i> Citas del Día
                    </a>
                    <a href="agendarcita.php" class="btn btn-primary">
                        <i class='bx bx-plus'></i> Nueva Cita
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

            <!-- Mensajes de error o información -->
            <?php if (!empty($error)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($sin_datos): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-info">
                            <div class="alert-icon">
                                <i class='bx bx-info-circle'></i>
                            </div>
                            <div class="alert-content">
                                No hay citas en el sistema. Comienza agregando tu primera cita.
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Estadísticas COMPLETAS -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar'></i>
                            </div>
                            <div class="trend-indicator <?php echo $estadisticas['este_mes'] > 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo $estadisticas['este_mes'] > 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo $estadisticas['este_mes']; ?> este mes
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total']); ?></div>
                        <div class="stat-label">Total de Citas</div>
                        <div class="stat-subtext">Registros históricos</div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="trend-indicator <?php echo $estadisticas['completadas'] > 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo $estadisticas['completadas'] > 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo $estadisticas['total'] > 0 ? round(($estadisticas['completadas'] / $estadisticas['total']) * 100, 1) : 0; ?>%
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['completadas']); ?></div>
                        <div class="stat-label">Completadas</div>
                        <div class="stat-subtext">Tasa de éxito</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-x-circle'></i>
                            </div>
                            <div class="trend-indicator negative">
                                <i class='bx bx-error-alt'></i>
                                <?php echo $estadisticas['total'] > 0 ? round(($estadisticas['canceladas'] / $estadisticas['total']) * 100, 1) : 0; ?>%
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['canceladas']); ?></div>
                        <div class="stat-label">Canceladas</div>
                        <div class="stat-subtext">Tasa de cancelación</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="trend-indicator <?php echo $estadisticas['pendientes'] > 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo $estadisticas['pendientes'] > 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                <?php echo $estadisticas['pendientes']; ?> activas
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['pendientes']); ?></div>
                        <div class="stat-label">Pendientes</div>
                        <div class="stat-subtext">Citas programadas</div>
                    </div>
                    
                    <div class="stat-card info">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                            <div class="trend-indicator <?php echo $estadisticas['total_ingresos'] > 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo $estadisticas['total_ingresos'] > 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                Generados
                            </div>
                        </div>
                        <div class="stat-number">$<?php echo number_format($estadisticas['total_ingresos'], 0); ?></div>
                        <div class="stat-label">Total Ingresos</div>
                        <div class="stat-subtext">De citas completadas</div>
                    </div>
                    
                    <div class="stat-card secondary">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                            <div class="trend-indicator <?php echo $estadisticas['promedio_citas_dia'] > 0 ? '' : 'negative'; ?>">
                                <i class='bx bx-<?php echo $estadisticas['promedio_citas_dia'] > 0 ? 'up-arrow-alt' : 'down-arrow-alt'; ?>'></i>
                                Promedio
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['promedio_citas_dia'], 1); ?></div>
                        <div class="stat-label">Promedio/Día</div>
                        <div class="stat-subtext">Citas por día</div>
                    </div>
                </div>
            </div>

            <!-- Filtros COMPLETOS -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter-alt'></i>
                        Filtros de Búsqueda
                    </h2>
                </div>
                <div class="filters-body">
                    <form method="GET" action="">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">Buscar</label>
                                <input type="text" 
                                       name="search" 
                                       class="form-control" 
                                       placeholder="Mascota, dueño, servicio..."
                                       value="<?php echo htmlspecialchars($busqueda); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Mascota</label>
                                <select name="mascota_id" class="form-control select">
                                    <option value="">Todas las mascotas</option>
                                    <?php foreach ($mascotas as $mascota): ?>
                                        <option value="<?php echo $mascota['id']; ?>" 
                                            <?php echo ($filtro_mascota == $mascota['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($mascota['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Cliente</label>
                                <select name="cliente_id" class="form-control select">
                                    <option value="">Todos los clientes</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>" 
                                            <?php echo ($filtro_cliente == $cliente['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Estado</label>
                                <select name="estado" class="form-control select">
                                    <option value="">Todos los estados</option>
                                    <?php foreach ($estados_cita as $valor => $texto): ?>
                                        <option value="<?php echo $valor; ?>" <?php echo ($filtro_estado == $valor) ? 'selected' : ''; ?>>
                                            <?php echo $texto; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Tipo de Cita</label>
                                <select name="tipo" class="form-control select">
                                    <option value="">Todos los tipos</option>
                                    <?php foreach ($tipos_cita as $valor => $texto): ?>
                                        <option value="<?php echo $valor; ?>" <?php echo ($filtro_tipo == $valor) ? 'selected' : ''; ?>>
                                            <?php echo $texto; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Veterinario</label>
                                <select name="veterinario" class="form-control select">
                                    <option value="">Todos los veterinarios</option>
                                    <?php foreach ($veterinarios as $vet): ?>
                                        <option value="<?php echo $vet['id']; ?>" 
                                            <?php echo ($filtro_veterinario == $vet['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vet['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Año</label>
                                <select name="ano" class="form-control select">
                                    <option value="">Todos los años</option>
                                    <?php foreach ($anos_disponibles as $ano): ?>
                                        <option value="<?php echo $ano['ano']; ?>" 
                                            <?php echo ($filtro_ano == $ano['ano']) ? 'selected' : ''; ?>>
                                            <?php echo $ano['ano']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Mes</label>
                                <select name="mes" class="form-control select">
                                    <option value="">Todos los meses</option>
                                    <?php foreach ($meses_disponibles as $mes): ?>
                                        <option value="<?php echo $mes['mes']; ?>" 
                                            <?php echo ($filtro_mes == $mes['mes']) ? 'selected' : ''; ?>>
                                            <?php echo $mes['mes_formateado']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Fecha Desde</label>
                                <input type="date" name="fecha_inicio" class="form-control" 
                                       value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Fecha Hasta</label>
                                <input type="date" name="fecha_fin" class="form-control" 
                                       value="<?php echo htmlspecialchars($fecha_fin); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Ordenar por</label>
                                <select name="ordenar_por" class="form-control select">
                                    <?php foreach ($opciones_orden as $valor => $texto): ?>
                                        <option value="<?php echo $valor; ?>" <?php echo ($ordenar_por == $valor) ? 'selected' : ''; ?>>
                                            <?php echo $texto; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-filter'></i>
                                    Aplicar Filtros
                                </button>
                                <a href="historialcitas.php" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Limpiar Filtros
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Control de Resultados y Orden -->
            <div class="results-control fade-in">
                <div class="results-info">
                    <?php if ($estadisticas['total'] > 0): ?>
                        Mostrando <?php echo min($limite, count($citas)); ?> de <?php echo number_format($estadisticas['total']); ?> citas
                        <?php if ($filtro_mes || $filtro_ano || $fecha_inicio || $fecha_fin || $filtro_estado || $filtro_mascota || $filtro_cliente || $busqueda): ?>
                            (filtradas)
                        <?php endif; ?>
                    <?php else: ?>
                        No hay citas
                    <?php endif; ?>
                </div>
                <?php if ($estadisticas['total'] > 0): ?>
                <div class="order-control">
                    <a href="exportar_citas.php?<?php echo http_build_query($_GET); ?>" class="export-btn">
                        <i class='bx bx-download'></i>
                        Exportar a Excel
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Lista de Citas COMPLETA -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-list-ul'></i>
                        Registro Completo de Citas
                    </h2>
                    <span class="badge badge-primary">
                        <?php echo number_format($estadisticas['total']); ?> citas en total
                    </span>
                </div>
                
                <div class="section-body">
                    <div class="table-container">
                        <?php if ($sin_datos): ?>
                            <div class="empty-state">
                                <i class='bx bx-calendar-plus'></i>
                                <h3>No hay citas registradas</h3>
                                <p>El sistema de citas está configurado correctamente, pero aún no hay citas programadas.</p>
                                <div class="empty-actions">
                                    <a href="agendarcita.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i>
                                        Agendar Primera Cita
                                    </a>
                                    <a href="calendario.php" class="btn btn-secondary">
                                        <i class='bx bx-calendar'></i>
                                        Ver Calendario
                                    </a>
                                </div>
                            </div>
                        <?php elseif (!empty($citas)): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Hora</th>
                                        <th>Mascota</th>
                                        <th>Dueño</th>
                                        <th>Servicio</th>
                                        <th>Tipo</th>
                                        <th>Estado</th>
                                        <th>Precio</th>
                                        <th>Veterinario</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($citas as $cita): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $cita['fecha_formateada']; ?></strong>
                                            </td>
                                            <td>
                                                <?php echo $cita['hora_formateada']; ?>
                                            </td>
                                            <td>
                                                <div class="pet-info">
                                                    <div class="pet-avatar">
                                                        <?php echo !empty($cita['mascota_nombre']) ? strtoupper(substr($cita['mascota_nombre'], 0, 1)) : '?'; ?>
                                                    </div>
                                                    <div class="pet-details">
                                                        <h4><?php echo htmlspecialchars($cita['mascota_nombre'] ?? 'Sin nombre'); ?></h4>
                                                        <p><?php echo htmlspecialchars($cita['especie_nombre'] ?? 'Sin especie'); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="client-info">
                                                    <h4><?php echo htmlspecialchars($cita['cliente_nombre'] ?? 'Sin cliente'); ?></h4>
                                                    <?php if (!empty($cita['cliente_telefono'])): ?>
                                                    <p><?php echo htmlspecialchars($cita['cliente_telefono']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'Sin servicio'); ?>
                                                <?php if (!empty($cita['notas'])): ?>
                                                <br>
                                                <small style="color: var(--text-light);">
                                                    <i class='bx bx-note'></i> <?php echo htmlspecialchars(substr($cita['notas'], 0, 20)) . (strlen($cita['notas']) > 20 ? '...' : ''); ?>
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary">
                                                    <?php echo $tipos_cita[$cita['servicio_categoria']] ?? 'Otro'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="status-indicator">
                                                    <span class="status-dot <?php echo $cita['estado']; ?>"></span>
                                                    <span class="badge badge-<?php 
                                                        echo $cita['estado'] === 'completed' ? 'success' : 
                                                             ($cita['estado'] === 'confirmed' ? 'info' : 
                                                             ($cita['estado'] === 'cancelled' ? 'danger' : 
                                                             ($cita['estado'] === 'in_progress' ? 'danger' : 
                                                             ($cita['estado'] === 'no_show' ? 'secondary' : 'warning')))); 
                                                    ?>">
                                                        <?php echo $estados_cita[$cita['estado']] ?? $cita['estado']; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($cita['costo_total'])): ?>
                                                    <strong>$<?php echo number_format($cita['costo_total'], 2); ?></strong>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo !empty($cita['veterinario_nombre']) ? htmlspecialchars($cita['veterinario_nombre']) : 'No asignado'; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="editar_cita.php?id=<?php echo $cita['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class='bx bx-edit'></i>
                                                    </a>
                                                    <a href="historial.php?id=<?php echo $cita['mascota_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class='bx bx-show'></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Paginación -->
                            <?php if ($total_paginas > 1): ?>
                            <div class="pagination">
                                <div class="pagination-info">
                                    Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> 
                                    • <?php echo number_format($estadisticas['total']); ?> citas en total
                                </div>
                                <div class="page-links">
                                    <?php if ($pagina_actual > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" class="page-link">
                                            <i class='bx bx-first-page'></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" class="page-link">
                                            <i class='bx bx-chevron-left'></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-link disabled">
                                            <i class='bx bx-first-page'></i>
                                        </span>
                                        <span class="page-link disabled">
                                            <i class='bx bx-chevron-left'></i>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Mostrar páginas cercanas
                                    $start_page = max(1, $pagina_actual - 2);
                                    $end_page = min($total_paginas, $pagina_actual + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>" 
                                           class="page-link <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                           <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pagina_actual < $total_paginas): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" class="page-link">
                                            <i class='bx bx-chevron-right'></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>" class="page-link">
                                            <i class='bx bx-last-page'></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-link disabled">
                                            <i class='bx bx-chevron-right'></i>
                                        </span>
                                        <span class="page-link disabled">
                                            <i class='bx bx-last-page'></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-search-alt'></i>
                                <h3>No se encontraron citas</h3>
                                <p>No hay citas que coincidan con los criterios de búsqueda seleccionados.</p>
                                <div class="empty-actions">
                                    <a href="historialcitas.php" class="btn btn-secondary">
                                        <i class='bx bx-reset'></i>
                                        Ver Todas las Citas
                                    </a>
                                    <a href="agendarcita.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i>
                                        Agendar Nueva Cita
                                    </a>
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
            // Buscar en tiempo real
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.form.submit();
                    }, 800);
                });
            }

            // Auto-submit al cambiar filtros (excepto búsqueda)
            const filtros = document.querySelectorAll('select[name="mascota_id"], select[name="cliente_id"], select[name="estado"], select[name="tipo"], select[name="mes"], select[name="ano"], select[name="veterinario"], select[name="ordenar_por"]');
            filtros.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            // Validación de fechas
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
            const fechaFin = document.querySelector('input[name="fecha_fin"]');
            
            if (fechaInicio && fechaFin) {
                fechaInicio.addEventListener('change', function() {
                    if (fechaFin.value && this.value > fechaFin.value) {
                        alert('La fecha de inicio no puede ser posterior a la fecha fin');
                        this.value = '';
                    }
                });
                
                fechaFin.addEventListener('change', function() {
                    if (fechaInicio.value && this.value < fechaInicio.value) {
                        alert('La fecha fin no puede ser anterior a la fecha de inicio');
                        this.value = '';
                    }
                });
            }
            
            // Exportar datos con confirmación
            const exportBtn = document.querySelector('.export-btn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function(e) {
                    if (!confirm('¿Deseas exportar los datos filtrados a Excel? Esto puede tomar unos momentos.')) {
                        e.preventDefault();
                    }
                });
            }
            
            // Animación de contadores
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
                        stat.textContent = text.replace(number, Math.floor(current).toLocaleString());
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
        });
    </script>
</body>
</html>