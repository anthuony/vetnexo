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
$mascotas = [];
$vacunas = [];
$vacunas_proximas = [];
$estadisticas = [
    'total_vacunas' => 0,
    'proximas_vencer' => 0,
    'vacunas_hoy' => 0,
    'vacunas_vencidas' => 0,
    'vacunas_mes' => 0
];
$filtro_mascota = $_GET['mascota_id'] ?? '';
$filtro_estado = $_GET['estado'] ?? 'todas';

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
    
    // Obtener todas las mascotas para el filtro
    $mascotas_sql = "SELECT id, nombre FROM mascotas ORDER BY nombre";
    $mascotas_stmt = $db->query($mascotas_sql);
    $mascotas = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Construir consulta base para vacunas
    $sql = "
        SELECT v.*, 
               m.nombre as mascota_nombre,
               m.especie_id,
               e.nombre as especie_nombre,
               c.nombre as cliente_nombre,
               c.telefono as cliente_telefono,
               c.email as cliente_email
        FROM vacunas v
        LEFT JOIN mascotas m ON v.mascota_id = m.id
        LEFT JOIN especies e ON m.especie_id = e.id
        LEFT JOIN clientes c ON m.cliente_id = c.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Aplicar filtros
    if (!empty($filtro_mascota)) {
        $sql .= " AND v.mascota_id = ?";
        $params[] = $filtro_mascota;
    }
    
    if ($filtro_estado === 'proximas') {
        $sql .= " AND v.proxima_dosis IS NOT NULL AND v.proxima_dosis >= CURDATE() AND v.proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($filtro_estado === 'vencidas') {
        $sql .= " AND v.proxima_dosis IS NOT NULL AND v.proxima_dosis < CURDATE()";
    } elseif ($filtro_estado === 'hoy') {
        $sql .= " AND v.proxima_dosis = CURDATE()";
    }
    
    $sql .= " ORDER BY 
        CASE 
            WHEN v.proxima_dosis IS NULL THEN 1
            WHEN v.proxima_dosis < CURDATE() THEN 2
            WHEN v.proxima_dosis = CURDATE() THEN 3
            WHEN v.proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 4
            ELSE 5
        END,
        v.proxima_dosis ASC,
        v.fecha_aplicacion DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $vacunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener vacunas próximas a vencer (próximos 30 días)
    $proximas_sql = "
        SELECT v.*, 
               m.nombre as mascota_nombre,
               e.nombre as especie_nombre,
               c.nombre as cliente_nombre,
               c.telefono as cliente_telefono,
               DATEDIFF(v.proxima_dosis, CURDATE()) as dias_restantes
        FROM vacunas v
        LEFT JOIN mascotas m ON v.mascota_id = m.id
        LEFT JOIN especies e ON m.especie_id = e.id
        LEFT JOIN clientes c ON m.cliente_id = c.id
        WHERE v.proxima_dosis IS NOT NULL 
          AND v.proxima_dosis >= CURDATE() 
          AND v.proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY v.proxima_dosis ASC
    ";
    
    $proximas_stmt = $db->prepare($proximas_sql);
    $proximas_stmt->execute();
    $vacunas_proximas = $proximas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas completas
    $estadisticas['total_vacunas'] = count($vacunas);
    
    $hoy = new DateTime();
    foreach ($vacunas as $vacuna) {
        if (!empty($vacuna['proxima_dosis'])) {
            $proxima_dosis = new DateTime($vacuna['proxima_dosis']);
            $dias_restantes = $hoy->diff($proxima_dosis)->days;
            
            if ($proxima_dosis == $hoy) {
                $estadisticas['vacunas_hoy']++;
            }
            
            if ($proxima_dosis > $hoy && $dias_restantes <= 30) {
                $estadisticas['proximas_vencer']++;
            }
            
            if ($proxima_dosis < $hoy) {
                $estadisticas['vacunas_vencidas']++;
            }
        }
    }
    
    // Vacunas del mes actual
    $mes_sql = "
        SELECT COUNT(*) as total 
        FROM vacunas 
        WHERE MONTH(fecha_aplicacion) = MONTH(CURDATE()) 
          AND YEAR(fecha_aplicacion) = YEAR(CURDATE())
    ";
    $mes_stmt = $db->query($mes_sql);
    $estadisticas['vacunas_mes'] = $mes_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
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
    <title>Control de Vacunas - VetNexo</title>
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

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Botón de Retroceso */
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
            cursor: pointer;
        }

        .back-button:hover {
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #2E7D32);
            color: var(--white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #d32f2f);
            color: var(--white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #d32f2f, #b71c1c);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #1976D2, #0d6efd);
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
            background: linear-gradient(90deg, var(--warning), var(--accent));
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--danger), #D32F2F);
        }

        .stat-card.info::before {
            background: linear-gradient(90deg, var(--info), #1976D2);
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
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #ffebee;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #e3f2fd;
            color: var(--info);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.warning .stat-number {
            color: #856404;
        }

        .stat-card.danger .stat-number {
            color: #721c24;
        }

        .stat-card.info .stat-number {
            color: #0d47a1;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
            color: var(--text-lighter);
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 500;
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

        .filter-actions {
            display: flex;
            gap: 0.75rem;
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

        /* Alert Cards */
        .alertas-grid {
            display: grid;
            gap: 1rem;
        }

        .alerta-card {
            background: linear-gradient(135deg, #fff3cd, #ffe69c);
            border: 1px solid var(--warning);
            border-radius: var(--radius-sm);
            padding: 1.2rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .alerta-card:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .alerta-card.peligro {
            background: linear-gradient(135deg, #f8d7da, #f5c2c7);
            border-color: var(--danger);
        }

        .alerta-card.info {
            background: linear-gradient(135deg, #cff4fc, #b6effb);
            border-color: var(--info);
        }

        .alerta-icon {
            font-size: 1.8rem;
            color: var(--warning);
            flex-shrink: 0;
        }

        .alerta-card.peligro .alerta-icon {
            color: var(--danger);
        }

        .alerta-card.info .alerta-icon {
            color: var(--info);
        }

        .alerta-content {
            flex: 1;
        }

        .alerta-content h4 {
            margin: 0 0 0.25rem 0;
            color: #856404;
            font-weight: 600;
            font-size: 1rem;
        }

        .alerta-card.peligro .alerta-content h4 {
            color: #721c24;
        }

        .alerta-card.info .alerta-content h4 {
            color: #055160;
        }

        .alerta-content p {
            margin: 0.25rem 0;
            color: #856404;
            font-size: 0.9rem;
        }

        .alerta-card.peligro .alerta-content p {
            color: #721c24;
        }

        .alerta-card.info .alerta-content p {
            color: #055160;
        }

        .alerta-content small {
            color: #856404;
            opacity: 0.8;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
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
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 1rem;
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
            gap: 1rem;
        }

        .pet-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .pet-details h4 {
            margin: 0 0 0.2rem 0;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .pet-details p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.8rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
        }

        .badge-secondary {
            background: #e2e8f0;
            color: #2d3748;
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
            font-size: 1.2rem;
            color: var(--text-dark);
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

        /* Notification Dot */
        .notification-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--danger);
            margin-left: 0.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.2);
                opacity: 0.7;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .stats-grid {
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
            
            .header-actions .btn,
            .header-actions .back-button {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-actions .btn {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
            
            .empty-actions {
                flex-direction: column;
            }
            
            .alerta-card {
                flex-direction: column;
                text-align: center;
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
                        <i class='bx bx-syringe'></i>
                        Control de Vacunas
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Gestiona y monitorea el calendario de vacunación
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

            <!-- Mensajes de error -->
            <?php if (isset($error)): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alerta-card peligro">
                            <div class="alerta-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alerta-content">
                                <h4>Error</h4>
                                <p><?php echo htmlspecialchars($error); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-syringe'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['total_vacunas']; ?></div>
                        <div class="stat-label">Total de Vacunas</div>
                        <div class="stat-trend">
                            <i class='bx bx-calendar'></i>
                            <?php echo $estadisticas['vacunas_mes']; ?> este mes
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar-exclamation'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['proximas_vencer']; ?></div>
                        <div class="stat-label">Próximas a Vencer</div>
                        <div class="stat-trend">En los próximos 30 días</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-alt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['vacunas_hoy']; ?></div>
                        <div class="stat-label">Para Hoy</div>
                        <div class="stat-trend">
                            <i class='bx bx-time'></i>
                            Requieren atención inmediata
                        </div>
                    </div>
                    
                    <div class="stat-card info">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-time'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['vacunas_vencidas']; ?></div>
                        <div class="stat-label">Vencidas</div>
                        <div class="stat-trend">Requieren reprogramación</div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters-section fade-in">
                <div class="filters-header">
                    <h2>
                        <i class='bx bx-filter'></i>
                        Filtros de Búsqueda
                    </h2>
                </div>
                <div class="filters-body">
                    <form method="GET" action="">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">Filtrar por Mascota</label>
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
                                <label class="filter-label">Estado</label>
                                <select name="estado" class="form-control select">
                                    <option value="todas" <?php echo ($filtro_estado == 'todas') ? 'selected' : ''; ?>>Todas las vacunas</option>
                                    <option value="proximas" <?php echo ($filtro_estado == 'proximas') ? 'selected' : ''; ?>>Próximas a vencer (30 días)</option>
                                    <option value="vencidas" <?php echo ($filtro_estado == 'vencidas') ? 'selected' : ''; ?>>Vencidas</option>
                                    <option value="hoy" <?php echo ($filtro_estado == 'hoy') ? 'selected' : ''; ?>>Para hoy</option>
                                </select>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class='bx bx-search'></i>
                                    Filtrar
                                </button>
                                <a href="controlvacunas.php" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Alertas Importantes -->
            <?php if (!empty($vacunas_proximas)): ?>
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-error-circle'></i>
                        Alertas de Vacunación
                        <?php if (count($vacunas_proximas) > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </h2>
                    <span class="badge badge-warning"><?php echo count($vacunas_proximas); ?> alertas</span>
                </div>
                
                <div class="section-body">
                    <div class="alertas-grid">
                        <?php foreach ($vacunas_proximas as $vacuna): 
                            $dias_restantes = $vacuna['dias_restantes'];
                            $es_hoy = $dias_restantes == 0;
                            $es_urgente = $dias_restantes <= 7;
                        ?>
                            <div class="alerta-card <?php echo $es_hoy ? 'peligro' : ($es_urgente ? 'peligro' : 'info'); ?>">
                                <div class="alerta-icon">
                                    <i class='bx <?php echo $es_hoy ? 'bx-error' : 'bx-calendar-exclamation'; ?>'></i>
                                </div>
                                <div class="alerta-content">
                                    <h4>
                                        <?php echo htmlspecialchars($vacuna['mascota_nombre']); ?> - 
                                        <?php echo htmlspecialchars($vacuna['nombre_vacuna']); ?>
                                    </h4>
                                    <p>
                                        <strong>Próxima dosis:</strong> 
                                        <?php echo date('d/m/Y', strtotime($vacuna['proxima_dosis'])); ?>
                                        <?php if ($es_hoy): ?>
                                            - <strong class="text-danger">¡PARA HOY!</strong>
                                        <?php elseif ($es_urgente): ?>
                                            - <strong class="text-danger">¡URGENTE! (<?php echo $dias_restantes; ?> días)</strong>
                                        <?php else: ?>
                                            - (<?php echo $dias_restantes; ?> días restantes)
                                        <?php endif; ?>
                                    </p>
                                    <p>
                                        <strong>Dueño:</strong> <?php echo htmlspecialchars($vacuna['cliente_nombre']); ?>
                                        <?php if (!empty($vacuna['cliente_telefono'])): ?>
                                            - <strong>Teléfono:</strong> <?php echo htmlspecialchars($vacuna['cliente_telefono']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <small>
                                        <i class='bx bx-time'></i>
                                        Última aplicación: <?php echo date('d/m/Y', strtotime($vacuna['fecha_aplicacion'])); ?>
                                    </small>
                                </div>
                                <div class="action-buttons" style="flex-shrink: 0;">
                                    <a href="nueva_vacuna.php?mascota_id=<?php echo $vacuna['mascota_id']; ?>" class="btn btn-success btn-sm">
                                        <i class='bx bx-plus'></i>
                                        Programar
                                    </a>
                                    <a href="historial.php?id=<?php echo $vacuna['mascota_id']; ?>" class="btn btn-info btn-sm">
                                        <i class='bx bx-show'></i>
                                        Ver
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Lista de Vacunas -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h2>
                        <i class='bx bx-list-ul'></i>
                        Registro de Vacunas
                    </h2>
                    <a href="nueva_vacuna.php" class="btn btn-primary btn-sm">
                        <i class='bx bx-plus'></i>
                        Nueva Vacuna
                    </a>
                </div>
                
                <div class="section-body">
                    <div class="table-container">
                        <?php if (!empty($vacunas)): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Mascota</th>
                                        <th>Vacuna</th>
                                        <th>Fecha Aplicación</th>
                                        <th>Próxima Dosis</th>
                                        <th>Estado</th>
                                        <th>Veterinario</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vacunas as $vacuna): 
                                        $hoy = new DateTime();
                                        $proxima_dosis = !empty($vacuna['proxima_dosis']) ? new DateTime($vacuna['proxima_dosis']) : null;
                                        
                                        $estado = 'sin_programar';
                                        $estado_badge = 'secondary';
                                        $estado_text = 'Sin programar';
                                        
                                        if ($proxima_dosis) {
                                            if ($proxima_dosis < $hoy) {
                                                $estado = 'vencida';
                                                $estado_badge = 'danger';
                                                $estado_text = 'Vencida';
                                            } elseif ($proxima_dosis == $hoy) {
                                                $estado = 'hoy';
                                                $estado_badge = 'danger';
                                                $estado_text = 'Para hoy';
                                            } elseif ($proxima_dosis <= $hoy->modify('+7 days')) {
                                                $estado = 'proxima';
                                                $estado_badge = 'warning';
                                                $dias = $hoy->diff($proxima_dosis)->days;
                                                $estado_text = 'En ' . $dias . ' día' . ($dias > 1 ? 's' : '');
                                            } else {
                                                $estado = 'programada';
                                                $estado_badge = 'success';
                                                $dias = $hoy->diff($proxima_dosis)->days;
                                                $estado_text = 'En ' . $dias . ' días';
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="pet-info">
                                                    <div class="pet-avatar">
                                                        <?php echo strtoupper(substr($vacuna['mascota_nombre'], 0, 1)); ?>
                                                    </div>
                                                    <div class="pet-details">
                                                        <h4><?php echo htmlspecialchars($vacuna['mascota_nombre']); ?></h4>
                                                        <p><?php echo htmlspecialchars($vacuna['especie_nombre'] ?? 'No especificada'); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($vacuna['nombre_vacuna']); ?></strong>
                                                <?php if (!empty($vacuna['observaciones'])): ?>
                                                    <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($vacuna['observaciones']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($vacuna['fecha_aplicacion'])); ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($vacuna['proxima_dosis'])): ?>
                                                    <?php echo date('d/m/Y', strtotime($vacuna['proxima_dosis'])); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">No programada</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $estado_badge; ?>">
                                                    <?php echo $estado_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo !empty($vacuna['veterinario']) ? htmlspecialchars($vacuna['veterinario']) : 'No especificado'; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="editar_vacuna.php?id=<?php echo $vacuna['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class='bx bx-edit'></i>
                                                        Editar
                                                    </a><?php if(auth_current_role()==='admin'):?><a class="btn btn-danger btn-sm" href="eliminar_registro.php?tipo=vacuna&id=<?php echo $vacuna['id']; ?>">Eliminar</a><?php endif;?>
                                                    <a href="nueva_vacuna.php?mascota_id=<?php echo $vacuna['mascota_id']; ?>" class="btn btn-success btn-sm">
                                                        <i class='bx bx-plus'></i>
                                                        Nueva
                                                    </a>
                                                    <a href="historial.php?id=<?php echo $vacuna['mascota_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class='bx bx-show'></i>
                                                        Ver
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class='bx bx-syringe'></i>
                                <h3>No se encontraron vacunas</h3>
                                <p>No hay vacunas registradas que coincidan con los criterios de búsqueda.</p>
                                <div class="empty-actions">
                                    <a href="nueva_vacuna.php" class="btn btn-primary">
                                        <i class='bx bx-plus'></i>
                                        Registrar Primera Vacuna
                                    </a>
                                    <a href="controlvacunas.php" class="btn btn-secondary">
                                        <i class='bx bx-reset'></i>
                                        Limpiar Filtros
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

            // Auto-submit al cambiar filtros
            const filtros = document.querySelectorAll('select[name="mascota_id"], select[name="estado"]');
            filtros.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });

            // Tooltips simples
            const badges = document.querySelectorAll('.badge, .btn');
            badges.forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });

            // Efecto de carga para las secciones
            const sections = document.querySelectorAll('.content-section, .filters-section, .stats-grid .stat-card');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100 + 300);
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
                        background: ${Math.random() > 0.5 ? 'rgba(46, 125, 50, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                    `;
                    particlesContainer.appendChild(particle);
                }
            }

            // Confirmación antes de eliminar (si se implementa)
            const deleteButtons = document.querySelectorAll('.btn-delete');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('¿Estás seguro de que deseas eliminar este registro? Esta acción no se puede deshacer.')) {
                        e.preventDefault();
                    }
                });
            });

            // Resaltar filas de vacunas vencidas o para hoy
            const rows = document.querySelectorAll('.data-table tbody tr');
            rows.forEach(row => {
                const estadoCell = row.querySelector('.badge-danger, .badge-warning');
                if (estadoCell) {
                    if (estadoCell.classList.contains('badge-danger')) {
                        row.style.backgroundColor = '#fff5f5';
                    } else if (estadoCell.classList.contains('badge-warning')) {
                        row.style.backgroundColor = '#fffaf0';
                    }
                }
            });
        });
    </script>
</body>
</html>