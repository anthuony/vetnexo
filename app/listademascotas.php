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
// Incluir funciones comunes
if (!function_exists('getRolColor')) {
    include_once 'config/funciones.php';
}

// Inicializar variables
$db = null;
$mascotas = [];
$especies = [];
$estadisticas = [
    'total_mascotas' => 0,
    'salud_excelente' => 0,
    'mascotas_activas' => 0,
    'esterilizadas' => 0,
    'con_peluqueria' => 0
];
$table_columns = [];

try {
    $db = getDBConnection();
    
    // Obtener información del usuario actual para el avatar
    $stmt = $db->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $usuario_nombre = $user_data['nombre'];
        $usuario_email = $user_data['email'];
        $usuario_rol = $user_data['rol'];
    }
    
    // Detectar columnas de la tabla mascotas
    $check_table_sql = "DESCRIBE mascotas";
    $check_table_stmt = $db->query($check_table_sql);
    $table_columns = $check_table_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Obtener especies para el filtro
    $especies_sql = "SELECT * FROM especies ORDER BY nombre";
    $especies_stmt = $db->query($especies_sql);
    $especies = $especies_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Construir consulta base CORREGIDA - AHORA INCLUYE CÉDULA Y SEXO
    $sql = "
        SELECT m.*, 
               e.nombre as especie_nombre,
               c.nombre as cliente_nombre,
               c.documento_identidad as cliente_cedula,
               c.telefono as cliente_telefono,
               c.email as cliente_email,
               c.direccion as cliente_direccion
        FROM mascotas m
        LEFT JOIN especies e ON m.especie_id = e.id
        LEFT JOIN clientes c ON m.cliente_id = c.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Aplicar filtros
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $sql .= " AND (m.nombre LIKE ? OR m.raza LIKE ? OR m.color LIKE ? OR c.nombre LIKE ? OR c.documento_identidad LIKE ?)";
        $search_term = '%' . $_GET['search'] . '%';
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    }
    
    if (isset($_GET['especie']) && !empty($_GET['especie'])) {
        $sql .= " AND m.especie_id = ?";
        $params[] = $_GET['especie'];
    }
    
    // Solo filtrar por estado_salud si la columna existe
    if (isset($_GET['estado_salud']) && !empty($_GET['estado_salud']) && in_array('estado_salud', $table_columns)) {
        $sql .= " AND m.estado_salud = ?";
        $params[] = $_GET['estado_salud'];
    }
    
    // Solo filtrar por estado si la columna existe
    if (isset($_GET['estado']) && !empty($_GET['estado']) && in_array('estado', $table_columns)) {
        $sql .= " AND m.estado = ?";
        $params[] = $_GET['estado'];
    }
    
    $sql .= " ORDER BY m.fecha_registro DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas
    $estadisticas['total_mascotas'] = count($mascotas);
    
    // Solo contar salud excelente si la columna existe
    if (in_array('estado_salud', $table_columns)) {
        foreach ($mascotas as $mascota) {
            if (isset($mascota['estado_salud']) && $mascota['estado_salud'] === 'excelente') {
                $estadisticas['salud_excelente']++;
            }
        }
    }
    
    // Solo contar mascotas activas si la columna existe
    if (in_array('estado', $table_columns)) {
        foreach ($mascotas as $mascota) {
            if (isset($mascota['estado']) && $mascota['estado'] === 'activo') {
                $estadisticas['mascotas_activas']++;
            }
        }
    }
    
    // Solo contar esterilizadas si la columna existe
    if (in_array('esterilizado', $table_columns)) {
        foreach ($mascotas as $mascota) {
            if (isset($mascota['esterilizado']) && $mascota['esterilizado'] == 1) {
                $estadisticas['esterilizadas']++;
            }
        }
    }
    
    // Contar mascotas que han ido a peluquería
    foreach ($mascotas as $mascota) {
        $peluqueria_sql = "SELECT COUNT(*) FROM cortes_peluqueria WHERE mascota_id = ?";
        $peluqueria_stmt = $db->prepare($peluqueria_sql);
        $peluqueria_stmt->execute([$mascota['id']]);
        $total_peluqueria = $peluqueria_stmt->fetchColumn();
        
        if ($total_peluqueria > 0) {
            $estadisticas['con_peluqueria']++;
        }
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Mascotas - VetNexo</title>
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --verde-bosque: #0F6C33;
            --verde-bosque-dark: #0a4d25;
            --amarillo-intenso: #FBEF16;
            --rojo-corazon: #F91302;
            --marron-chocolate: #5A4B37;
            --blanco-puro: #FFFFFF;
            --naranja-vibrante: #F99B07;
            --azul-real: #2261BD;
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
            color: var(--verde-bosque-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .welcome-section h1 i {
            color: var(--naranja-vibrante);
        }

        .welcome-section p {
            color: var(--text-light);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Botones */
        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--verde-bosque), var(--verde-bosque-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--verde-bosque-dark), #085d2a);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--naranja-vibrante), var(--rojo-corazon));
            color: var(--white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--rojo-corazon), #d0021b);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--azul-real), #1a4b8c);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #1a4b8c, #123a6b);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--azul-real), #1a4b8c);
            color: var(--white);
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--naranja-vibrante), #e68900);
            color: var(--white);
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn-back {
            background: linear-gradient(135deg, var(--marron-chocolate), #3f3529);
            color: var(--white);
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #3f3529, #2e271f);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
            background: linear-gradient(90deg, var(--verde-bosque), var(--naranja-vibrante));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: rgba(15, 108, 51, 0.1);
            color: var(--verde-bosque);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Filtros */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--gray);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
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
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .filter-label i {
            color: var(--naranja-vibrante);
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--verde-bosque);
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Tabla */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--gray);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(15, 108, 51, 0.1);
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--verde-bosque-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--radius-sm);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: rgba(15, 108, 51, 0.05);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--verde-bosque-dark);
            border-bottom: 2px solid var(--verde-bosque);
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray);
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: rgba(249, 155, 7, 0.03);
        }

        /* Pet Info */
        .pet-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .pet-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--verde-bosque), var(--verde-bosque-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(15, 108, 51, 0.3);
        }

        .pet-details h4 {
            margin: 0 0 0.25rem 0;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 1rem;
        }

        .pet-details p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Client Info */
        .client-info h4 {
            margin: 0 0 0.25rem 0;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .client-info p {
            margin: 0.2rem 0;
            color: var(--text-light);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(15, 108, 51, 0.15);
            color: var(--verde-bosque-dark);
        }

        .badge-warning {
            background: rgba(249, 155, 7, 0.15);
            color: #b45f06;
        }

        .badge-danger {
            background: rgba(249, 19, 2, 0.15);
            color: var(--rojo-corazon);
        }

        .badge-info {
            background: rgba(34, 97, 189, 0.15);
            color: var(--azul-real);
        }

        .badge-secondary {
            background: rgba(90, 75, 55, 0.15);
            color: var(--marron-chocolate);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
            text-align: center;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--gray);
        }

        .empty-state h4 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 2px solid rgba(15, 108, 51, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8fdf8, var(--white));
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            color: var(--verde-bosque-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-light);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(249, 19, 2, 0.1);
            color: var(--rojo-corazon);
        }

        .modal-body {
            padding: 2rem;
        }

        .pet-profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid rgba(15, 108, 51, 0.1);
        }

        .pet-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--verde-bosque), var(--verde-bosque-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 700;
            font-size: 2.5rem;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(15, 108, 51, 0.3);
            border: 4px solid var(--white);
            outline: 2px solid var(--amarillo-intenso);
        }

        .pet-profile-info h3 {
            font-size: 1.8rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .pet-profile-info .species {
            font-size: 1.2rem;
            color: var(--verde-bosque-dark);
            margin-bottom: 0.5rem;
        }

        .pet-profile-info .breed {
            color: var(--text-light);
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        .status-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .modal-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-card {
            background: rgba(15, 108, 51, 0.03);
            border-radius: var(--radius-sm);
            padding: 1.25rem;
            border: 1px solid var(--gray);
        }

        .detail-card h4 {
            font-size: 1rem;
            color: var(--verde-bosque-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(249, 155, 7, 0.2);
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }

        .detail-item:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            color: var(--text-light);
        }

        .detail-value {
            color: var(--text-dark);
            font-weight: 500;
            text-align: right;
        }

        .owner-info-section {
            background: rgba(249, 155, 7, 0.03);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            border: 1px solid var(--gray);
            margin-top: 1.5rem;
        }

        .owner-info-section h4 {
            font-size: 1.1rem;
            color: var(--verde-bosque-dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .owner-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .owner-detail {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--white);
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray);
        }

        .owner-detail i {
            color: var(--naranja-vibrante);
            font-size: 1.2rem;
            width: 24px;
        }

        .owner-detail span {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .owner-detail strong {
            display: block;
            color: var(--text-dark);
            font-weight: 600;
            margin-top: 0.25rem;
            font-size: 0.9rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(15, 108, 51, 0.1);
        }

        .modal-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* Footer */
        .main-footer {
            background: linear-gradient(135deg, var(--verde-bosque), var(--verde-bosque-dark));
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

        /* Responsive */
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

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
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn {
                width: 100%;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .pet-profile-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .modal-actions {
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
            <!-- Header -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-paw'></i>
                        Lista de Mascotas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestión de pacientes
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-back">
                        <i class='bx bx-arrow-back'></i>
                        Volver al Dashboard
                    </a>
                    <a href="registrarmascota.php" class="btn btn-primary">
                        <i class='bx bx-plus'></i>
                        Nueva Mascota
                    </a>
                </div>
            </header>

            <!-- Mensajes de error -->
            <?php if (isset($error)): ?>
                <div style="background: #ffebee; border-left: 4px solid var(--rojo-corazon); color: #b71c1c; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; display: flex; align-items: center; gap: 1rem;">
                    <i class='bx bx-error-circle' style="font-size: 1.5rem;"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-paw'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['total_mascotas']; ?></div>
                        <div class="stat-label">Total de Mascotas</div>
                    </div>
                    
                    <?php if (in_array('estado_salud', $table_columns)): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--success);">
                                <i class='bx bx-heart'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['salud_excelente']; ?></div>
                        <div class="stat-label">Salud Excelente</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (in_array('estado', $table_columns)): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--warning);">
                                <i class='bx bx-run'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['mascotas_activas']; ?></div>
                        <div class="stat-label">Mascotas Activas</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (in_array('esterilizado', $table_columns)): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--azul-real);">
                                <i class='bx bx-check-shield'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['esterilizadas']; ?></div>
                        <div class="stat-label">Esterilizadas</div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--naranja-vibrante);">
                                <i class='bx bx-scissors'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['con_peluqueria']; ?></div>
                        <div class="stat-label">Con Peluquería</div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters-section">
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label"><i class='bx bx-search'></i> Buscar</label>
                            <input type="text" 
                                   name="search" 
                                   class="form-control" 
                                   placeholder="Nombre, raza, color o dueño..."
                                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label"><i class='bx bx-category'></i> Especie</label>
                            <select name="especie" class="form-control">
                                <option value="">Todas las especies</option>
                                <?php foreach ($especies as $especie): ?>
                                    <option value="<?php echo $especie['id']; ?>" 
                                        <?php echo (isset($_GET['especie']) && $_GET['especie'] == $especie['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($especie['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if (in_array('estado_salud', $table_columns)): ?>
                        <div class="filter-group">
                            <label class="filter-label"><i class='bx bx-health'></i> Estado Salud</label>
                            <select name="estado_salud" class="form-control">
                                <option value="">Todos</option>
                                <option value="excelente" <?php echo (isset($_GET['estado_salud']) && $_GET['estado_salud'] == 'excelente') ? 'selected' : ''; ?>>Excelente</option>
                                <option value="bueno" <?php echo (isset($_GET['estado_salud']) && $_GET['estado_salud'] == 'bueno') ? 'selected' : ''; ?>>Bueno</option>
                                <option value="regular" <?php echo (isset($_GET['estado_salud']) && $_GET['estado_salud'] == 'regular') ? 'selected' : ''; ?>>Regular</option>
                                <option value="critico" <?php echo (isset($_GET['estado_salud']) && $_GET['estado_salud'] == 'critico') ? 'selected' : ''; ?>>Crítico</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array('estado', $table_columns)): ?>
                        <div class="filter-group">
                            <label class="filter-label"><i class='bx bx-toggle-left'></i> Estado</label>
                            <select name="estado" class="form-control">
                                <option value="">Todos</option>
                                <option value="activo" <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'activo') ? 'selected' : ''; ?>>Activo</option>
                                <option value="inactivo" <?php echo (isset($_GET['estado']) && $_GET['estado'] == 'inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-filter-alt'></i>
                                Filtrar
                            </button>
                            <a href="listademascotas.php" class="btn btn-secondary">
                                <i class='bx bx-reset'></i>
                                Limpiar
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tabla de Mascotas -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class='bx bx-list-ul'></i>
                        Mascotas Registradas
                    </h3>
                    <span class="badge badge-info">Total: <?php echo count($mascotas); ?></span>
                </div>
                
                <div class="table-container">
                    <?php if (!empty($mascotas)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Mascota</th>
                                    <th>Dueño</th>
                                    <th>Especie/Raza</th>
                                    <th>Edad</th>
                                    <?php if (in_array('estado_salud', $table_columns)): ?>
                                    <th>Salud</th>
                                    <?php endif; ?>
                                    <?php if (in_array('estado', $table_columns)): ?>
                                    <th>Estado</th>
                                    <?php endif; ?>
                                    <?php if (in_array('esterilizado', $table_columns)): ?>
                                    <th>Esterilizado</th>
                                    <?php endif; ?>
                                    <th>Peluquería</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mascotas as $index => $mascota): 
                                    // Calcular edad si hay fecha de nacimiento
                                    $edad = 'No especificada';
                                    if (!empty($mascota['fecha_nacimiento']) && $mascota['fecha_nacimiento'] != '0000-00-00') {
                                        $fecha_nac = new DateTime($mascota['fecha_nacimiento']);
                                        $hoy = new DateTime();
                                        $diff = $fecha_nac->diff($hoy);
                                        if ($diff->y > 0) {
                                            $edad = $diff->y . ' año' . ($diff->y > 1 ? 's' : '');
                                        } elseif ($diff->m > 0) {
                                            $edad = $diff->m . ' mes' . ($diff->m > 1 ? 'es' : '');
                                        } else {
                                            $edad = $diff->d . ' día' . ($diff->d > 1 ? 's' : '');
                                        }
                                    }
                                    
                                    // Verificar si ha ido a peluquería
                                    $peluqueria_sql = "SELECT COUNT(*) as total, MAX(fecha_corte) as ultimo_corte 
                                                       FROM cortes_peluqueria 
                                                       WHERE mascota_id = ?";
                                    $peluqueria_stmt = $db->prepare($peluqueria_sql);
                                    $peluqueria_stmt->execute([$mascota['id']]);
                                    $peluqueria_data = $peluqueria_stmt->fetch(PDO::FETCH_ASSOC);
                                    $total_peluqueria = $peluqueria_data['total'];
                                    $ultimo_corte = $peluqueria_data['ultimo_corte'];
                                    
                                    // Formatear sexo para mostrar
                                    $sexo_text = '';
                                    if (!empty($mascota['sexo'])) {
                                        $sexo_lower = strtolower($mascota['sexo']);
                                        if ($sexo_lower === 'macho' || $sexo_lower === 'm' || $sexo_lower === 'male') {
                                            $sexo_text = '♂ Macho';
                                        } elseif ($sexo_lower === 'hembra' || $sexo_lower === 'h' || $sexo_lower === 'female') {
                                            $sexo_text = '♀ Hembra';
                                        } else {
                                            $sexo_text = ucfirst($mascota['sexo']);
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <div class="pet-info">
                                                <div class="pet-avatar">
                                                    <?php echo strtoupper(substr($mascota['nombre'], 0, 1)); ?>
                                                </div>
                                                <div class="pet-details">
                                                    <h4><?php echo htmlspecialchars($mascota['nombre']); ?></h4>
                                                    <p>
                                                        <?php echo $sexo_text; ?>
                                                        <?php if (!empty($mascota['color'])): ?>
                                                         • <i class='bx bx-palette'></i> <?php echo htmlspecialchars($mascota['color']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="client-info">
                                                <h4><?php echo htmlspecialchars($mascota['cliente_nombre'] ?: 'Sin dueño'); ?></h4>
                                                <?php if (!empty($mascota['cliente_cedula'])): ?>
                                                <p><i class='bx bx-id-card'></i> <?php echo htmlspecialchars($mascota['cliente_cedula']); ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($mascota['cliente_telefono'])): ?>
                                                <p><i class='bx bx-phone'></i> <?php echo htmlspecialchars($mascota['cliente_telefono']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($mascota['especie_nombre'] ?: 'No especificada'); ?></strong>
                                            <?php if (!empty($mascota['raza'])): ?>
                                            <br><span style="color: var(--text-light); font-size: 0.85rem;"><?php echo htmlspecialchars($mascota['raza']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $edad; ?></td>
                                        
                                        <?php if (in_array('estado_salud', $table_columns)): ?>
                                        <td>
                                            <?php if (!empty($mascota['estado_salud'])): ?>
                                                <span class="badge badge-<?php 
                                                    if ($mascota['estado_salud'] === 'excelente') echo 'success';
                                                    elseif ($mascota['estado_salud'] === 'bueno') echo 'info';
                                                    elseif ($mascota['estado_salud'] === 'regular') echo 'warning';
                                                    elseif ($mascota['estado_salud'] === 'critico') echo 'danger';
                                                    else echo 'secondary';
                                                ?>">
                                                    <?php echo htmlspecialchars(ucfirst($mascota['estado_salud'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">No especificado</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array('estado', $table_columns)): ?>
                                        <td>
                                            <?php if (!empty($mascota['estado'])): ?>
                                                <span class="badge badge-<?php echo strtolower($mascota['estado']) === 'activo' ? 'success' : 'danger'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($mascota['estado'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">No especificado</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array('esterilizado', $table_columns)): ?>
                                        <td>
                                            <?php if (isset($mascota['esterilizado']) && $mascota['esterilizado'] == 1): ?>
                                                <span class="badge badge-success">Sí</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <?php if ($total_peluqueria > 0): ?>
                                                <div>
                                                    <span class="badge badge-info">
                                                        <i class='bx bx-scissors'></i> <?php echo $total_peluqueria; ?>
                                                    </span>
                                                    <?php if (!empty($ultimo_corte) && $ultimo_corte != '0000-00-00'): ?>
                                                    <br>
                                                    <small style="color: var(--text-light); font-size: 0.7rem;">
                                                        <?php echo date('d/m/Y', strtotime($ultimo_corte)); ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Nunca</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm view-pet-btn" data-index="<?php echo $index; ?>">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <a href="editar_mascota.php?id=<?php echo $mascota['id']; ?>" class="btn btn-warning btn-sm">
                                                    <i class='bx bx-edit'></i>
                                                </a><?php if(auth_current_role()==='admin'):?><a class="btn btn-danger btn-sm" href="eliminar_registro.php?tipo=mascota&id=<?php echo $mascota['id']; ?>">Eliminar</a><?php endif;?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-paw'></i>
                            <h4>No se encontraron mascotas</h4>
                            <p>No hay mascotas registradas que coincidan con los criterios de búsqueda.</p>
                            <a href="registrarmascota.php" class="btn btn-primary">
                                <i class='bx bx-plus'></i>
                                Registrar Primera Mascota
                            </a>
                        </div>
                    <?php endif; ?>
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

    <!-- Modal para detalles de mascota -->
    <div class="modal-overlay" id="petModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2><i class='bx bx-paw'></i> Detalles de la Mascota</h2>
                <button type="button" class="modal-close" id="closeModal">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Contenido dinámico -->
            </div>
        </div>
    </div>

    <script>
        // Datos de las mascotas
        const mascotasData = <?php echo json_encode($mascotas); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('petModal');
            const modalContent = document.getElementById('modalContent');
            const closeBtn = document.getElementById('closeModal');
            const viewButtons = document.querySelectorAll('.view-pet-btn');
            
            // Función para calcular edad
            function calcularEdad(fechaNacimiento) {
                if (!fechaNacimiento || fechaNacimiento === '0000-00-00') return 'No especificada';
                const fechaNac = new Date(fechaNacimiento);
                const hoy = new Date();
                const diff = hoy - fechaNac;
                const edadAnios = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
                
                if (edadAnios > 0) return edadAnios + ' año' + (edadAnios > 1 ? 's' : '');
                const edadMeses = Math.floor(diff / (30.44 * 24 * 60 * 60 * 1000));
                if (edadMeses > 0) return edadMeses + ' mes' + (edadMeses > 1 ? 'es' : '');
                const edadDias = Math.floor(diff / (24 * 60 * 60 * 1000));
                return edadDias + ' día' + (edadDias > 1 ? 's' : '');
            }
            
            // Función para formatear fecha
            function formatearFecha(fecha) {
                if (!fecha || fecha === '0000-00-00') return 'No especificada';
                const date = new Date(fecha);
                return date.toLocaleDateString('es-ES', {
                    day: '2-digit', month: '2-digit', year: 'numeric'
                });
            }
            
            // Función para formatear sexo
            function formatearSexo(sexo) {
                if (!sexo) return 'No especificado';
                const s = sexo.toLowerCase();
                if (s === 'macho' || s === 'm' || s === 'male') return 'Macho';
                if (s === 'hembra' || s === 'h' || s === 'female') return 'Hembra';
                return sexo.charAt(0).toUpperCase() + sexo.slice(1);
            }
            
            // Función para mostrar detalles
            function mostrarDetalles(index) {
                const m = mascotasData[index];
                const edad = calcularEdad(m.fecha_nacimiento);
                
                let html = `
                    <div class="pet-profile-header">
                        <div class="pet-avatar-large">${m.nombre.charAt(0).toUpperCase()}</div>
                        <div class="pet-profile-info">
                            <h3>${m.nombre}</h3>
                            <div class="species">${m.especie_nombre || 'No especificada'}</div>
                            ${m.raza ? `<div class="breed">${m.raza}</div>` : ''}
                        </div>
                    </div>
                    
                    <div class="modal-grid">
                        <div class="detail-card">
                            <h4><i class='bx bx-id-card'></i> Básicos</h4>
                            <div class="detail-item"><span class="detail-label">Sexo:</span> <span class="detail-value">${formatearSexo(m.sexo)}</span></div>
                            <div class="detail-item"><span class="detail-label">Color:</span> <span class="detail-value">${m.color || 'No especificado'}</span></div>
                            <div class="detail-item"><span class="detail-label">Edad:</span> <span class="detail-value">${edad}</span></div>
                            <div class="detail-item"><span class="detail-label">Nacimiento:</span> <span class="detail-value">${formatearFecha(m.fecha_nacimiento)}</span></div>
                            <div class="detail-item"><span class="detail-label">Peso:</span> <span class="detail-value">${m.peso ? m.peso + ' kg' : 'No especificado'}</span></div>
                        </div>
                        
                        <div class="detail-card">
                            <h4><i class='bx bx-plus-medical'></i> Médico</h4>
                            ${m.alergias ? `<div class="detail-item"><span class="detail-label">Alergias:</span> <span class="detail-value">${m.alergias}</span></div>` : ''}
                            ${m.historial_medico ? `<div class="detail-item"><span class="detail-label">Historial:</span> <span class="detail-value">${m.historial_medico}</span></div>` : ''}
                            ${m.notas ? `<div class="detail-item"><span class="detail-label">Notas:</span> <span class="detail-value">${m.notas}</span></div>` : ''}
                            <div class="detail-item"><span class="detail-label">Registro:</span> <span class="detail-value">${formatearFecha(m.fecha_registro)}</span></div>
                        </div>
                    </div>
                    
                    <div class="owner-info-section">
                        <h4><i class='bx bx-user'></i> Dueño</h4>
                        <div class="owner-details">
                            <div class="owner-detail"><i class='bx bx-user-circle'></i><div><span>Nombre</span><strong>${m.cliente_nombre || 'No especificado'}</strong></div></div>
                            ${m.cliente_cedula ? `<div class="owner-detail"><i class='bx bx-id-card'></i><div><span>Cédula</span><strong>${m.cliente_cedula}</strong></div></div>` : ''}
                            ${m.cliente_telefono ? `<div class="owner-detail"><i class='bx bx-phone'></i><div><span>Teléfono</span><strong>${m.cliente_telefono}</strong></div></div>` : ''}
                            ${m.cliente_email ? `<div class="owner-detail"><i class='bx bx-envelope'></i><div><span>Email</span><strong>${m.cliente_email}</strong></div></div>` : ''}
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <a href="editar_mascota.php?id=${m.id}" class="btn btn-warning"><i class='bx bx-edit'></i> Editar</a>
                        <a href="historial.php?id=${m.id}" class="btn btn-info"><i class='bx bx-notepad'></i> Historial</a>
                        <button class="btn btn-secondary" id="closeModalBtn"><i class='bx bx-x'></i> Cerrar</button>
                    </div>
                `;
                
                modalContent.innerHTML = html;
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                
                document.getElementById('closeModalBtn').addEventListener('click', cerrarModal);
            }
            
            function cerrarModal() {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
            
            viewButtons.forEach(btn => {
                btn.addEventListener('click', () => mostrarDetalles(btn.dataset.index));
            });
            
            closeBtn.addEventListener('click', cerrarModal);
            
            modal.addEventListener('click', (e) => {
                if (e.target === modal) cerrarModal();
            });
            
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('active')) cerrarModal();
            });
        });
    </script>
</body>
</html>