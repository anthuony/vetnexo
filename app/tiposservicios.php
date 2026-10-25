<?php
// tiposservicios.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';

// SOLO verificar si el usuario está logueado
// NO verificar el rol
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Conexión centralizada para hosting
try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    die('Error de conexión a la base de datos. Revise config/env.php.');
}

// Primero, verificar si existe la tabla razas, si no, crearla
try {
    $pdo->query("SELECT 1 FROM razas LIMIT 1");
} catch (PDOException $e) {
    // La tabla no existe, crearla
    $sql_create_razas = "
        CREATE TABLE IF NOT EXISTS razas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            especie_id INT,
            descripcion TEXT,
            caracteristicas TEXT,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (especie_id) REFERENCES especies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_create_razas);
}

// Procesar operaciones para cada tabla
$tablas = [
    'especies' => [
        'nombre' => 'Especies',
        'campos' => ['id', 'nombre', 'activo'],
        'campo_principal' => 'nombre'
    ],
    'categorias_productos' => [
        'nombre' => 'Categorías de Productos',
        'campos' => ['id', 'nombre', 'descripcion', 'activo'],
        'campo_principal' => 'nombre'
    ],
    'razas' => [
        'nombre' => 'Razas de Mascotas',
        'campos' => ['id', 'nombre', 'especie_id', 'descripcion', 'activo'],
        'campo_principal' => 'nombre'
    ]
];

// Determinar tabla activa
$tabla_activa = $_GET['tabla'] ?? 'especies';
if(!isset($tablas[$tabla_activa])) av_fail('Catálogo no válido.');
$datos_tabla = $tablas[$tabla_activa];
if(isset($_POST['editar_item']) || isset($_POST['nuevo_item'])) $_POST['activo']=isset($_POST['activo'])?1:0;

// Procesar eliminación
if (isset($_POST['eliminar_item'])) {
    $item_id = $_POST['item_id'];
    
    try {
        // Verificar si hay registros relacionados antes de eliminar
        $verificar_sql = "";
        switch ($tabla_activa) {
            case 'especies':
                $verificar_sql = "SELECT COUNT(*) as total FROM mascotas WHERE especie_id = ?";
                break;
            case 'categorias_productos':
                $verificar_sql = "SELECT COUNT(*) as total FROM productos WHERE categoria_id = ?";
                break;
            case 'razas':
                $verificar_sql = "SELECT COUNT(*) as total FROM mascotas WHERE raza = (SELECT nombre FROM razas WHERE id = ?)";
                break;
        }
        
        if ($verificar_sql) {
            $stmt_verificar = $pdo->prepare($verificar_sql);
            $stmt_verificar->execute([$item_id]);
            $resultado = $stmt_verificar->fetch();
            
            if ($resultado['total'] > 0) {
                $_SESSION['error'] = "No se puede eliminar porque hay registros relacionados.";
            } else {
                // Usar comillas backticks para el nombre de la tabla
                $sql_eliminar = "DELETE FROM `$tabla_activa` WHERE id = ?";
                $stmt_eliminar = $pdo->prepare($sql_eliminar);
                $stmt_eliminar->execute([$item_id]);
                $_SESSION['success'] = "Registro eliminado correctamente.";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al eliminar: " . $e->getMessage();
    }
    
    header("Location: tiposservicios.php?tabla=$tabla_activa");
    exit();
}

// Procesar edición
if (isset($_POST['editar_item'])) {
    $item_id = $_POST['item_id'];
    
    try {
        $campos_update = [];
        $valores = [];
        
        foreach ($_POST as $campo => $valor) {
            if ($campo !== 'id' && in_array($campo,$datos_tabla['campos'],true)) {
                $campos_update[] = "`$campo` = ?";
                $valores[] = $valor;
            }
        }
        
        $valores[] = $item_id;
        // Usar comillas backticks para el nombre de la tabla
        $sql_editar = "UPDATE `$tabla_activa` SET " . implode(', ', $campos_update) . " WHERE id = ?";
        $stmt_editar = $pdo->prepare($sql_editar);
        $stmt_editar->execute($valores);
        
        $_SESSION['success'] = "Registro actualizado correctamente.";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al actualizar: " . $e->getMessage();
    }
    
    header("Location: tiposservicios.php?tabla=$tabla_activa");
    exit();
}

// Procesar nuevo registro
if (isset($_POST['nuevo_item'])) {
    try {
        $campos = [];
        $placeholders = [];
        $valores = [];
        
        foreach ($_POST as $campo => $valor) {
            if ($campo !== 'id' && in_array($campo,$datos_tabla['campos'],true)) {
                $campos[] = "`$campo`";
                $placeholders[] = '?';
                $valores[] = $valor;
            }
        }
        
        // Usar comillas backticks para el nombre de la tabla
        $sql_nuevo = "INSERT INTO `$tabla_activa` (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt_nuevo = $pdo->prepare($sql_nuevo);
        $stmt_nuevo->execute($valores);
        
        $_SESSION['success'] = "Registro creado correctamente.";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al crear: " . $e->getMessage();
    }
    
    header("Location: tiposservicios.php?tabla=$tabla_activa");
    exit();
}

// Obtener especies para el dropdown de razas
$especies = [];
try {
    $sql_especies = "SELECT id, nombre FROM especies WHERE activo = 1 ORDER BY nombre";
    $stmt_especies = $pdo->query($sql_especies);
    $especies = $stmt_especies->fetchAll();
} catch (PDOException $e) {
    // Si hay error, continuar sin especies
}

// Obtener datos de la tabla activa
try {
    if ($tabla_activa == 'razas') {
        $sql_datos = "SELECT r.*, e.nombre as especie_nombre 
                      FROM razas r 
                      LEFT JOIN especies e ON r.especie_id = e.id 
                      ORDER BY e.nombre, r.nombre";
        $stmt_datos = $pdo->query($sql_datos);
    } else {
        // Usar comillas backticks para el nombre de la tabla
        $sql_datos = "SELECT * FROM `$tabla_activa` ORDER BY id";
        $stmt_datos = $pdo->query($sql_datos);
    }
    $datos = $stmt_datos->fetchAll();
    
    // Obtener estadísticas generales
    $sql_stats = "
        SELECT 
            (SELECT COUNT(*) FROM especies WHERE activo = 1) as especies_activas,
            (SELECT COUNT(*) FROM categorias_productos WHERE activo = 1) as categorias_activas,
            (SELECT COUNT(*) FROM razas WHERE activo = 1) as razas_activas,
            (SELECT COUNT(*) FROM servicios WHERE activo = 1) as servicios_activos,
            (SELECT COUNT(*) FROM mascotas) as total_mascotas,
            (SELECT COUNT(*) FROM clientes) as total_clientes,
            (SELECT COUNT(DISTINCT raza) FROM mascotas) as razas_utilizadas
    ";
    $stmt_stats = $pdo->query($sql_stats);
    $estadisticas = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    error_log("[VetNexo][tiposservicios] " . $e->getMessage()); die("No se pudieron cargar los datos del módulo.");
}

// Obtener datos del usuario de la sesión
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_email = $_SESSION['usuario_email'] ?? 'usuario@ejemplo.com';
$usuario_rol = $_SESSION['usuario_rol'] ?? 'usuario';
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datos Maestros - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
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

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-very-light);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.2rem;
        }

        .user-info h3 {
            font-size: 0.95rem;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
        }

        .user-info span {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Botón de Atrás */
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
        }

        .back-button:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
        }

        /* Alertas */
        .alert {
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-weight: 500;
            border: 1px solid;
        }

        .alert-success {
            background: var(--primary-very-light);
            border-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .alert-error {
            background: #FFEBEE;
            border-color: #FFCDD2;
            color: #C62828;
        }

        /* Estadísticas Mejoradas */
        .stats-section {
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
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
            background: var(--primary-very-light);
            color: var(--primary);
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
            margin-bottom: 0.5rem;
        }

        .stat-subtext {
            color: var(--text-lighter);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Navegación de Tablas */
        .tab-navigation {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--white);
            color: var(--text-light);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9rem;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            border-color: var(--primary);
        }

        .tab-btn:hover:not(.active) {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        /* Secciones de Contenido */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            height: fit-content;
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

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--white);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #D32F2F);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #D32F2F, #C62828);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        /* Table Styles Mejorados */
        .table-responsive {
            overflow-x: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background-color: var(--primary-very-light);
            font-weight: 600;
            color: var(--primary-dark);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr:hover {
            background-color: var(--primary-very-light);
        }

        /* Badges Mejorados */
        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-active {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #E0E0E0, #BDBDBD);
            color: #757575;
        }

        .badge-especie {
            background: linear-gradient(135deg, #E3F2FD, #90CAF9);
            color: #1565C0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--radius);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-lg);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .close {
            position: absolute;
            right: 1rem;
            top: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--text-dark);
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .checkbox-group input {
            width: auto;
        }

        /* Estados Vacíos */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            text-align: center;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
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
                padding: 1.5rem;
            }
            
            .user-profile {
                align-self: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-navigation {
                flex-direction: column;
                width: 100%;
            }
            
            .tab-btn {
                width: 100%;
                text-align: center;
            }
            
            .section-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            th, td {
                padding: 0.75rem 0.5rem;
            }
        }

        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
                    <h1><i class='bx bx-cog'></i> Datos Maestros del Sistema</h1>
                    <p>Administra las configuraciones básicas de VetNexo</p>
                </div>
                
                <div class="header-actions">
                    <!-- Botón de Atrás -->
                    <a href="dashboard.php" class="back-button">
                        <i class='bx bx-arrow-back'></i>
                        Volver al Dashboard
                    </a>
                    
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <h3><?php echo htmlspecialchars($usuario_nombre); ?></h3>
                            <span><i class='bx bx-user-circle'></i> <?php echo htmlspecialchars($usuario_rol ?? 'Usuario'); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Alertas -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Resto del código HTML (sin cambios) -->
            <?php
            // Obtener especies para el dropdown de razas
            $especies = [];
            try {
                $sql_especies = "SELECT id, nombre FROM especies WHERE activo = 1 ORDER BY nombre";
                $stmt_especies = $pdo->query($sql_especies);
                $especies = $stmt_especies->fetchAll();
            } catch (PDOException $e) {
                // Si hay error, continuar sin especies
            }

            // Obtener datos de la tabla activa
            try {
                if ($tabla_activa == 'razas') {
                    $sql_datos = "SELECT r.*, e.nombre as especie_nombre 
                                FROM razas r 
                                LEFT JOIN especies e ON r.especie_id = e.id 
                                ORDER BY e.nombre, r.nombre";
                } else {
                    $sql_datos = "SELECT * FROM $tabla_activa ORDER BY id";
                }
                $stmt_datos = $pdo->query($sql_datos);
                $datos = $stmt_datos->fetchAll();
                
                // Obtener estadísticas generales
                $sql_stats = "
                    SELECT 
                        (SELECT COUNT(*) FROM especies WHERE activo = 1) as especies_activas,
                        (SELECT COUNT(*) FROM categorias_productos WHERE activo = 1) as categorias_activas,
                        (SELECT COUNT(*) FROM razas WHERE activo = 1) as razas_activas,
                        (SELECT COUNT(*) FROM servicios WHERE activo = 1) as servicios_activos,
                        (SELECT COUNT(*) FROM mascotas) as total_mascotas,
                        (SELECT COUNT(*) FROM clientes) as total_clientes,
                        (SELECT COUNT(DISTINCT raza) FROM mascotas) as razas_utilizadas
                ";
                $stmt_stats = $pdo->query($sql_stats);
                $estadisticas = $stmt_stats->fetch();
                
            } catch (PDOException $e) {
                error_log("[VetNexo][tiposservicios] " . $e->getMessage()); die("No se pudieron cargar los datos del módulo.");
            }

            // Obtener datos del usuario de la sesión
            $usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
            $usuario_email = $_SESSION['usuario_email'] ?? 'usuario@ejemplo.com';
            $usuario_rol = $_SESSION['usuario_rol'] ?? 'usuario';
            ?>

            <!-- Estadísticas Generales -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-category'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['especies_activas']; ?></div>
                        <div class="stat-label">Especies Activas</div>
                        <div class="stat-subtext">
                            <i class='bx bx-paw'></i> Base de datos de animales
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--secondary);">
                                <i class='bx bx-package'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['categorias_activas']; ?></div>
                        <div class="stat-label">Categorías de Productos</div>
                        <div class="stat-subtext">
                            <i class='bx bx-shopping-bag'></i> Organización de inventario
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--accent);">
                                <i class='bx bx-dna'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['razas_activas']; ?></div>
                        <div class="stat-label">Razas Registradas</div>
                        <div class="stat-subtext">
                            <i class='bx bx-list-check'></i> <?php echo $estadisticas['razas_utilizadas']; ?> en uso
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--success);">
                                <i class='bx bx-plus-medical'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['servicios_activos']; ?></div>
                        <div class="stat-label">Servicios Activos</div>
                        <div class="stat-subtext">
                            <i class='bx bx-stethoscope'></i> Disponibles para citas
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="color: var(--warning);">
                                <i class='bx bx-heart'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['total_mascotas']; ?></div>
                        <div class="stat-label">Mascotas Registradas</div>
                        <div class="stat-subtext">
                            <i class='bx bx-user'></i> <?php echo $estadisticas['total_clientes']; ?> clientes
                        </div>
                    </div>
                </div>
            </div>

            <!-- Navegación entre Tablas -->
            <div class="tab-navigation">
                <?php foreach ($tablas as $tabla_key => $tabla_info): ?>
                    <a href="?tabla=<?php echo $tabla_key; ?>" 
                       class="tab-btn <?php echo $tabla_activa == $tabla_key ? 'active' : ''; ?>">
                        <i class='bx 
                            <?php echo $tabla_key == 'especies' ? 'bx-category' : 
                                   ($tabla_key == 'categorias_productos' ? 'bx-package' : 'bx-dna'); ?>'>
                        </i>
                        <?php echo $tabla_info['nombre']; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Contenido Principal -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class='bx 
                            <?php echo $tabla_activa == 'especies' ? 'bx-category' : 
                                   ($tabla_activa == 'categorias_productos' ? 'bx-package' : 'bx-dna'); ?>'>
                        </i>
                        <?php echo $datos_tabla['nombre']; ?>
                    </h3>
                    <div class="header-actions">
                        <div class="search-box">
                            <input type="text" id="searchInput" placeholder="Buscar..." 
                                   style="padding: 0.6rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border); width: 250px;">
                        </div>
                        <button class="btn btn-primary" onclick="openModal('modalNuevoItem')">
                            <i class='bx bx-plus'></i>
                            Nuevo Registro
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($datos)): ?>
                    <div class="table-responsive">
                        <table id="datosTable">
                            <thead>
                                <tr>
                                    <?php if ($tabla_activa == 'razas'): ?>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Especie</th>
                                        <th>Descripción</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    <?php else: ?>
                                        <?php foreach ($datos_tabla['campos'] as $campo): ?>
                                            <th><?php echo ucfirst(str_replace('_', ' ', $campo)); ?></th>
                                        <?php endforeach; ?>
                                        <th>Acciones</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($datos as $fila): ?>
                                    <tr>
                                        <?php if ($tabla_activa == 'razas'): ?>
                                            <td><?php echo $fila['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($fila['nombre']); ?></strong></td>
                                            <td>
                                                <?php if ($fila['especie_nombre']): ?>
                                                    <span class="badge badge-especie"><?php echo htmlspecialchars($fila['especie_nombre']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($fila['descripcion'] ?? '', 0, 50) . (strlen($fila['descripcion'] ?? '') > 50 ? '...' : '')); ?></td>
                                            <td>
                                                <span class="badge <?php echo $fila['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                    <?php echo $fila['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                </span>
                                            </td>
                                        <?php else: ?>
                                            <?php foreach ($datos_tabla['campos'] as $campo): ?>
                                                <td>
                                                    <?php if ($campo == 'activo'): ?>
                                                        <span class="badge <?php echo $fila[$campo] ? 'badge-active' : 'badge-inactive'; ?>">
                                                            <?php echo $fila[$campo] ? 'Activo' : 'Inactivo'; ?>
                                                        </span>
                                                    <?php elseif (strpos($campo, 'descripcion') !== false): ?>
                                                        <?php echo htmlspecialchars(substr($fila[$campo] ?? '', 0, 50) . (strlen($fila[$campo] ?? '') > 50 ? '...' : '')); ?>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($fila[$campo] ?? '-'); ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <button class="btn btn-outline btn-sm" 
                                                        onclick="editarItem(<?php echo $fila['id']; ?>)">
                                                    <i class='bx bx-edit'></i> Editar
                                                </button>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('¿Estás seguro de eliminar este registro?');"><?php echo av_csrf_field(); ?>
                                                    <input type="hidden" name="item_id" value="<?php echo $fila['id']; ?>">
                                                    <button type="submit" name="eliminar_item" class="btn btn-danger btn-sm">
                                                        <i class='bx bx-trash'></i> Eliminar
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-table'></i>
                        <h4>No hay registros en esta tabla</h4>
                        <p>Comienza creando el primer registro haciendo clic en "Nuevo Registro"</p>
                    </div>
                <?php endif; ?>
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

    <!-- Modal Nuevo Item -->
    <div id="modalNuevoItem" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('modalNuevoItem')">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: var(--primary-dark);">
                <i class='bx bx-plus'></i> Nuevo Registro - <?php echo $datos_tabla['nombre']; ?>
            </h2>
            
            <form method="POST" id="formNuevoItem"><?php echo av_csrf_field(); ?>
                <?php foreach ($datos_tabla['campos'] as $campo): ?>
                    <?php if ($campo != 'id'): ?>
                        <div class="form-group">
                            <label for="nuevo_<?php echo $campo; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $campo)); ?>
                                <?php if ($campo == $datos_tabla['campo_principal']): ?>*<?php endif; ?>
                            </label>
                            
                            <?php if ($campo == 'activo'): ?>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="nuevo_<?php echo $campo; ?>" name="<?php echo $campo; ?>" value="1" checked>
                                    <label for="nuevo_<?php echo $campo; ?>">Activo</label>
                                </div>
                            <?php elseif ($campo == 'especie_id'): ?>
                                <select id="nuevo_<?php echo $campo; ?>" name="<?php echo $campo; ?>">
                                    <option value="">Seleccione una especie</option>
                                    <?php foreach ($especies as $especie): ?>
                                        <option value="<?php echo $especie['id']; ?>"><?php echo htmlspecialchars($especie['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (strpos($campo, 'descripcion') !== false): ?>
                                <textarea id="nuevo_<?php echo $campo; ?>" name="<?php echo $campo; ?>" placeholder="Ingrese la descripción..."></textarea>
                            <?php else: ?>
                                <input type="text" id="nuevo_<?php echo $campo; ?>" name="<?php echo $campo; ?>" 
                                       <?php if ($campo == $datos_tabla['campo_principal']): ?>required<?php endif; ?>
                                       placeholder="Ingrese <?php echo str_replace('_', ' ', $campo); ?>...">
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevoItem')">Cancelar</button>
                    <button type="submit" name="nuevo_item" class="btn btn-primary">Crear Registro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Editar Item -->
    <?php if (isset($_GET['editar'])): ?>
        <?php
        $item_id = $_GET['editar'];
        if ($tabla_activa == 'razas') {
            $sql_item = "SELECT r.*, e.nombre as especie_nombre 
                         FROM razas r 
                         LEFT JOIN especies e ON r.especie_id = e.id 
                         WHERE r.id = ?";
        } else {
            $sql_item = "SELECT * FROM $tabla_activa WHERE id = ?";
        }
        $stmt_item = $pdo->prepare($sql_item);
        $stmt_item->execute([$item_id]);
        $item = $stmt_item->fetch();
        ?>
        
        <?php if ($item): ?>
        <div id="modalEditarItem" class="modal" style="display: block;">
            <div class="modal-content">
                <span class="close" onclick="closeModalAndRedirect()">&times;</span>
                <h2 style="margin-bottom: 1.5rem; color: var(--primary-dark);">
                    <i class='bx bx-edit'></i> Editar Registro - <?php echo $datos_tabla['nombre']; ?>
                </h2>
                
                <form method="POST"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    
                    <?php foreach ($datos_tabla['campos'] as $campo): ?>
                        <?php if ($campo != 'id'): ?>
                            <div class="form-group">
                                <label for="editar_<?php echo $campo; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $campo)); ?>
                                    <?php if ($campo == $datos_tabla['campo_principal']): ?>*<?php endif; ?>
                                </label>
                                
                                <?php if ($campo == 'activo'): ?>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="editar_<?php echo $campo; ?>" name="<?php echo $campo; ?>" value="1" <?php echo $item[$campo] ? 'checked' : ''; ?>>
                                        <label for="editar_<?php echo $campo; ?>">Activo</label>
                                    </div>
                                <?php elseif ($campo == 'especie_id'): ?>
                                    <select id="editar_<?php echo $campo; ?>" name="<?php echo $campo; ?>">
                                        <option value="">Seleccione una especie</option>
                                        <?php foreach ($especies as $especie): ?>
                                            <option value="<?php echo $especie['id']; ?>" <?php echo $item[$campo] == $especie['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($especie['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif (strpos($campo, 'descripcion') !== false): ?>
                                    <textarea id="editar_<?php echo $campo; ?>" name="<?php echo $campo; ?>" placeholder="Ingrese la descripción..."><?php echo htmlspecialchars($item[$campo] ?? ''); ?></textarea>
                                <?php else: ?>
                                    <input type="text" id="editar_<?php echo $campo; ?>" name="<?php echo $campo; ?>" 
                                           value="<?php echo htmlspecialchars($item[$campo] ?? ''); ?>"
                                           <?php if ($campo == $datos_tabla['campo_principal']): ?>required<?php endif; ?>
                                           placeholder="Ingrese <?php echo str_replace('_', ' ', $campo); ?>...">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModalAndRedirect()">Cancelar</button>
                        <button type="submit" name="editar_item" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <script src="js/sidebar.js"></script>
    <script>
        // Funciones para modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function closeModalAndRedirect() {
            window.location.href = 'tiposservicios.php?tabla=<?php echo $tabla_activa; ?>';
        }

        // Función para editar item
        function editarItem(id) {
            window.location.href = 'tiposservicios.php?tabla=<?php echo $tabla_activa; ?>&editar=' + id;
        }

        // Búsqueda en tiempo real
        document.getElementById('searchInput').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#datosTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target == modal) {
                    if (modal.id === 'modalEditarItem') {
                        closeModalAndRedirect();
                    } else {
                        modal.style.display = 'none';
                    }
                }
            }
        }

        // Animación de contadores para estadísticas
        document.addEventListener('DOMContentLoaded', function() {
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

            // Animación de entrada para las secciones
            const sections = document.querySelectorAll('.content-section');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100 + 300);
            });

            // Auto-enfocar el primer campo en el modal nuevo
            const modalNuevo = document.getElementById('modalNuevoItem');
            if (modalNuevo && modalNuevo.style.display === 'block') {
                const firstInput = modalNuevo.querySelector('input, textarea, select');
                if (firstInput) firstInput.focus();
            }
        });
    </script>
</body>
</html>