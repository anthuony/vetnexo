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
$clientes = [];
$busqueda = '';

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
    
    // Verificar si estamos en modo selección (para registrar mascota)
    $es_modo_seleccion = isset($_GET['select']) && $_GET['select'] == '1';
    $return_to = $_GET['return_to'] ?? 'registrarmascota.php';
    
    // Manejar búsqueda
    if (isset($_GET['buscar']) && !empty($_GET['buscar'])) {
        $busqueda = trim($_GET['buscar']);
        $search_sql = "SELECT * FROM clientes 
                      WHERE nombre LIKE :search OR 
                            telefono LIKE :search2 OR 
                            documento_identidad LIKE :search3 OR 
                            email LIKE :search4
                      ORDER BY nombre";
        $search_stmt = $db->prepare($search_sql);
        $search_term = "%$busqueda%";
        $search_stmt->bindParam(':search', $search_term, PDO::PARAM_STR);
        $search_stmt->bindParam(':search2', $search_term, PDO::PARAM_STR);
        $search_stmt->bindParam(':search3', $search_term, PDO::PARAM_STR);
        $search_stmt->bindParam(':search4', $search_term, PDO::PARAM_STR);
        $search_stmt->execute();
        $clientes = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Obtener todos los clientes
        $clientes_sql = "SELECT * FROM clientes ORDER BY nombre";
        $clientes_stmt = $db->query($clientes_sql);
        $clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener estadísticas
    $total_clientes = count($clientes);
    
    // Obtener conteo de mascotas por cliente
    $mascotas_por_cliente = [];
    if ($total_clientes > 0) {
        $cliente_ids = array_column($clientes, 'id');
        $placeholders = implode(',', array_fill(0, count($cliente_ids), '?'));
        
        $mascotas_sql = "SELECT cliente_id, COUNT(*) as total_mascotas 
                        FROM mascotas 
                        WHERE cliente_id IN ($placeholders) 
                        GROUP BY cliente_id";
        $mascotas_stmt = $db->prepare($mascotas_sql);
        $mascotas_stmt->execute($cliente_ids);
        $mascotas_result = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($mascotas_result as $row) {
            $mascotas_por_cliente[$row['cliente_id']] = $row['total_mascotas'];
        }
    }
    
    // Estadísticas generales
    $stats_sql = "
        SELECT 
            COUNT(DISTINCT c.id) as total_clientes,
            COUNT(DISTINCT m.id) as total_mascotas,
            COUNT(DISTINCT CASE WHEN m.id IS NOT NULL THEN c.id END) as clientes_con_mascotas,
            COALESCE(SUM(CASE WHEN f.estado = 'pending' THEN 1 ELSE 0 END), 0) as facturas_pendientes
        FROM clientes c
        LEFT JOIN mascotas m ON c.id = m.cliente_id
        LEFT JOIN facturas f ON c.id = f.cliente_id AND f.estado = 'pending'
    ";
    $stats_stmt = $db->query($stats_sql);
    $estadisticas = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en listadeclientes.php: " . $e->getMessage());
    $mensaje = "Error al cargar datos: " . $e->getMessage();
    $tipo_mensaje = "error";
    $estadisticas = [
        'total_clientes' => 0,
        'total_mascotas' => 0,
        'clientes_con_mascotas' => 0,
        'facturas_pendientes' => 0
    ];
}

// Procesar eliminación de cliente (solo si es POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_cliente']) && isset($db)) {
    try {
        $cliente_id = $_POST['cliente_id'];
        
        foreach(['usuarios','pedidos','inspecciones_fumigacion','fumigaciones_realizadas'] as $table){$q=$db->prepare("SELECT COUNT(*) FROM `$table` WHERE cliente_id=?");$q->execute([$cliente_id]);if($q->fetchColumn())throw new RuntimeException('El cliente tiene cuentas, pedidos o servicios relacionados.');}
        // Verificar si el cliente tiene mascotas registradas
        $check_mascotas_sql = "SELECT COUNT(*) as total FROM mascotas WHERE cliente_id = ?";
        $check_mascotas_stmt = $db->prepare($check_mascotas_sql);
        $check_mascotas_stmt->execute([$cliente_id]);
        $result = $check_mascotas_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['total'] > 0) {
            $_SESSION['error'] = "No se puede eliminar el cliente porque tiene mascotas registradas. Primero elimine o reasigne las mascotas.";
        } else {
            // Verificar si el cliente tiene facturas
            $check_facturas_sql = "SELECT COUNT(*) as total FROM facturas WHERE cliente_id = ?";
            $check_facturas_stmt = $db->prepare($check_facturas_sql);
            $check_facturas_stmt->execute([$cliente_id]);
            $facturas_result = $check_facturas_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($facturas_result['total'] > 0) {
                $_SESSION['error'] = "No se puede eliminar el cliente porque tiene facturas asociadas.";
            } else {
                // Eliminar cliente
                $delete_sql = "DELETE FROM clientes WHERE id = ?";
                $delete_stmt = $db->prepare($delete_sql);
                $delete_stmt->execute([$cliente_id]);
                
                $_SESSION['success'] = "Cliente eliminado exitosamente.";
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error al eliminar cliente: " . $e->getMessage();
    }
    
    header('Location: listadeclientes.php' . ($es_modo_seleccion ? '?select=1&return_to=' . urlencode($return_to) : ''));
    exit();
}

// Función para obtener color del rol
function getRolColor($rol) {
    switch ($rol) {
        case 'admin':
            return '#F44336';
        case 'veterinarian':
            return '#2196F3';
        case 'reception':
            return '#FF9800';
        case 'assistant':
            return '#9C27B0';
        default:
            return '#4CAF50';
    }
}

// Función para obtener texto del rol
function getRolTexto($rol) {
    switch ($rol) {
        case 'admin':
            return 'Administrador';
        case 'veterinarian':
            return 'Veterinario';
        case 'reception':
            return 'Recepcionista';
        case 'assistant':
            return 'Asistente';
        default:
            return 'Usuario';
    }
}

$page_title = $es_modo_seleccion ? "Seleccionar Cliente - VetNexo" : "Lista de Clientes - VetNexo";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
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
            --purple: #9C27B0;
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

        /* Page Actions */
        .page-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn-primary, .btn-secondary, .btn-success, .btn-info, .btn-danger {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #2E7D32);
            color: var(--white);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1976D2);
            color: var(--white);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(33, 150, 243, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #D32F2F);
            color: var(--white);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 67, 54, 0.4);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid var(--primary-light);
        }

        .alert-error {
            background: #ffebee;
            color: var(--danger);
            border: 1px solid #ffcdd2;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Estadísticas */
        .stats-section {
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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

        /* Search Section */
        .search-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 1.2rem 1.5rem;
            box-shadow: var(--shadow);
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 300px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-lighter);
        }

        .search-box input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.5rem;
            border: 1px solid var(--gray);
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .search-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Tabla de Clientes */
        .table-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
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

        .badge-count {
            background: var(--white);
            color: var(--primary-dark);
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 1px solid var(--gray);
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }

        .data-table tr:hover td {
            background: var(--gray-light);
        }

        .client-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .client-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.1rem;
        }

        .client-info {
            flex: 1;
        }

        .client-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.2rem;
        }

        .client-id {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .contact-item i {
            font-size: 1rem;
            color: var(--primary);
        }

        .contact-item a {
            color: var(--text-light);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .contact-item a:hover {
            color: var(--primary);
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-mascotas {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-pendientes {
            background: #ffebee;
            color: var(--danger);
        }

        .mascotas-badge {
            background: var(--primary-soft);
            color: var(--primary-dark);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: transparent;
        }

        .btn-icon-primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-icon-primary:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-success {
            background: #d4edda;
            color: var(--success);
        }

        .btn-icon-success:hover {
            background: var(--success);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-info {
            background: #cce5ff;
            color: var(--info);
        }

        .btn-icon-info:hover {
            background: var(--info);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-danger {
            background: #ffebee;
            color: var(--danger);
        }

        .btn-icon-danger:hover {
            background: var(--danger);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1200;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 1rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
        }

        .modal-header i {
            font-size: 1.8rem;
            color: var(--danger);
        }

        .modal-header h3 {
            font-size: 1.2rem;
            color: var(--primary-dark);
            margin: 0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--gray);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
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

        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
            
            .page-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-box {
                width: 100%;
            }
            
            .search-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .contact-info {
                align-items: flex-start;
            }
            
            .modal-footer {
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
                        <i class='bx bx-group'></i>
                        <?php echo $es_modo_seleccion ? 'Seleccionar Cliente' : 'Lista de Clientes'; ?>
                    </h1>
                    <p>
                        <i class='bx bx-user'></i>
                        <?php echo $es_modo_seleccion 
                            ? 'Seleccione un cliente para registrar una nueva mascota'
                            : 'Administre la información de todos los clientes registrados'; ?>
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

            <!-- Page Actions -->
            <div class="page-actions fade-in">
                <?php if ($es_modo_seleccion): ?>
                    <a href="<?php echo htmlspecialchars($return_to); ?>" class="btn-secondary btn-sm">
                        <i class='bx bx-arrow-back'></i>
                        Volver
                    </a>
                <?php else: ?>
                    <a href="registrarmascota.php" class="btn-primary btn-sm">
                        <i class='bx bx-plus'></i>
                        Nuevo Cliente y Mascota
                    </a>
                    <a href="listademascotas.php" class="btn-success btn-sm">
                        <i class='bx bx-paw'></i>
                        Ver Mascotas
                    </a>
                <?php endif; ?>
            </div>

            <!-- Alertas de Sesión -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success fade-in">
                    <i class='bx bx-check-circle'></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error fade-in">
                    <i class='bx bx-error-circle'></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Estadísticas (solo si no es modo selección) -->
            <?php if (!$es_modo_seleccion): ?>
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-group'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_clientes']); ?></div>
                        <div class="stat-label">Total Clientes</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-paw'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_mascotas']); ?></div>
                        <div class="stat-label">Mascotas Registradas</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-user-check'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['clientes_con_mascotas']); ?></div>
                        <div class="stat-label">Clientes con Mascotas</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['facturas_pendientes']; ?></div>
                        <div class="stat-label">Facturas Pendientes</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Búsqueda -->
            <div class="search-section fade-in">
                <form method="GET" action="" class="search-form">
                    <div class="search-box">
                        <i class='bx bx-search'></i>
                        <input type="text" 
                               name="buscar" 
                               placeholder="Buscar por nombre, teléfono, documento de identidad o email..."
                               value="<?php echo htmlspecialchars($busqueda); ?>">
                    </div>
                    <div class="search-actions">
                        <button type="submit" class="btn-primary btn-sm">
                            <i class='bx bx-search'></i>
                            Buscar
                        </button>
                        <?php if (!empty($busqueda)): ?>
                            <a href="listadeclientes.php<?php echo $es_modo_seleccion ? '?select=1&return_to=' . urlencode($return_to) : ''; ?>" class="btn-secondary btn-sm">
                                <i class='bx bx-x'></i>
                                Limpiar
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if ($es_modo_seleccion): ?>
                        <input type="hidden" name="select" value="1">
                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tabla de Clientes -->
            <div class="table-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-ul'></i>
                        <?php echo $es_modo_seleccion ? 'Seleccione un Cliente' : 'Lista de Clientes'; ?>
                    </h3>
                    <span class="badge-count">
                        <?php echo count($clientes); ?> clientes
                    </span>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="clientesTable">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Contacto</th>
                                <th>Documento</th>
                                <th>Mascotas</th>
                                <th>Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($clientes)): ?>
                                <?php foreach ($clientes as $cliente): ?>
                                    <tr class="fade-in">
                                        <td>
                                            <div class="client-cell">
                                                <div class="client-avatar">
                                                    <?php echo strtoupper(substr($cliente['nombre'], 0, 1)); ?>
                                                </div>
                                                <div class="client-info">
                                                    <div class="client-name">
                                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                                    </div>
                                                    <?php if (!empty($cliente['direccion'])): ?>
                                                        <div class="client-id">
                                                            <i class='bx bx-map'></i> 
                                                            <?php echo htmlspecialchars(substr($cliente['direccion'], 0, 30)) . (strlen($cliente['direccion'] ?? '') > 30 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="contact-info">
                                                <?php if (!empty($cliente['telefono'])): ?>
                                                    <div class="contact-item">
                                                        <i class='bx bx-phone'></i>
                                                        <a href="tel:<?php echo htmlspecialchars($cliente['telefono']); ?>">
                                                            <?php echo htmlspecialchars($cliente['telefono']); ?>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($cliente['email'])): ?>
                                                    <div class="contact-item">
                                                        <i class='bx bx-envelope'></i>
                                                        <a href="mailto:<?php echo htmlspecialchars($cliente['email']); ?>">
                                                            <?php echo htmlspecialchars($cliente['email']); ?>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (empty($cliente['telefono']) && empty($cliente['email'])): ?>
                                                    <span style="color: var(--text-light);">Sin contacto</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($cliente['documento_identidad'])): ?>
                                                <span style="font-weight: 500;"><?php echo htmlspecialchars($cliente['documento_identidad']); ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-light);">No registrado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $total_mascotas = $mascotas_por_cliente[$cliente['id']] ?? 0;
                                            if ($total_mascotas > 0): 
                                            ?>
                                                <span class="mascotas-badge">
                                                    <i class='bx bx-paw'></i>
                                                    <?php echo $total_mascotas; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-light);">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="color: var(--text-light);">
                                                <?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($es_modo_seleccion): ?>
                                                <a href="<?php echo $return_to; ?>?cliente_id=<?php echo $cliente['id']; ?>" 
                                                   class="btn-primary btn-sm" style="padding: 0.5rem 1rem; border-radius: 50px;">
                                                    <i class='bx bx-check'></i>
                                                    Seleccionar
                                                </a>
                                            <?php else: ?>
                                                <div class="action-buttons">
                                                    <a href="registrarmascota.php?cliente_id=<?php echo $cliente['id']; ?>" 
                                                       class="btn-icon btn-icon-success" title="Agregar Mascota">
                                                        <i class='bx bx-plus'></i>
                                                    </a>
                                                    <a href="editarcliente.php?id=<?php echo $cliente['id']; ?>" 
                                                       class="btn-icon btn-icon-primary" title="Editar Cliente">
                                                        <i class='bx bx-edit'></i>
                                                    </a>
                                                    <button type="button" 
                                                            class="btn-icon btn-icon-danger btn-eliminar" 
                                                            data-id="<?php echo $cliente['id']; ?>"
                                                            data-nombre="<?php echo htmlspecialchars($cliente['nombre']); ?>"
                                                            title="Eliminar Cliente">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class='bx bx-user-x'></i>
                                            <h3>No se encontraron clientes</h3>
                                            <p>
                                                <?php if (!empty($busqueda)): ?>
                                                    No hay resultados para "<?php echo htmlspecialchars($busqueda); ?>"
                                                <?php else: ?>
                                                    No hay clientes registrados en el sistema
                                                <?php endif; ?>
                                            </p>
                                            <?php if (!$es_modo_seleccion): ?>
                                                <a href="registrarmascota.php" class="btn-primary btn-sm" style="display: inline-flex;">
                                                    <i class='bx bx-plus'></i>
                                                    Registrar Primer Cliente
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

    <!-- Modal de Confirmación para Eliminar -->
    <div class="modal" id="modalEliminar">
        <div class="modal-content">
            <div class="modal-header">
                <i class='bx bx-error-circle'></i>
                <h3>Confirmar Eliminación</h3>
            </div>
            <div class="modal-body">
                <p>¿Está seguro de que desea eliminar al cliente <strong id="clienteNombreModal"></strong>?</p>
                <p style="margin-top: 1rem; color: var(--danger); font-size: 0.9rem;">
                    <i class='bx bx-info-circle'></i> Esta acción no se puede deshacer.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary btn-sm" onclick="cerrarModal()">
                    Cancelar
                </button>
                <form id="formEliminar" method="POST" action="" style="display: inline;"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="cliente_id" id="clienteIdEliminar">
                    <input type="hidden" name="eliminar_cliente" value="1">
                    <button type="submit" class="btn-danger btn-sm">
                        <i class='bx bx-trash'></i>
                        Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Variables globales
        let currentClienteId = null;
        const modalEliminar = document.getElementById('modalEliminar');

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de estadísticas
            animateStats();
            
            // Configurar botones de eliminar
            setupDeleteButtons();
            
            // Configurar búsqueda en tiempo real
            setupSearch();
            
            // Crear partículas de fondo
            createParticles();
        });

        // Animar estadísticas
        function animateStats() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.-]+/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = Math.floor(current).toLocaleString('es-ES');
                    }, 30);
                }
            });
        }

        // Configurar botones de eliminar
        function setupDeleteButtons() {
            const deleteButtons = document.querySelectorAll('.btn-eliminar');
            deleteButtons.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const clienteId = this.getAttribute('data-id');
                    const clienteNombre = this.getAttribute('data-nombre');
                    
                    document.getElementById('clienteNombreModal').textContent = clienteNombre;
                    document.getElementById('clienteIdEliminar').value = clienteId;
                    document.getElementById('formEliminar').action = window.location.href;
                    
                    abrirModal();
                });
            });
        }

        // Configurar búsqueda en tiempo real
        function setupSearch() {
            const searchInput = document.querySelector('input[name="buscar"]');
            if (searchInput) {
                // Guardar búsqueda en sessionStorage
                searchInput.addEventListener('input', function() {
                    sessionStorage.setItem('lastClientSearch', this.value);
                });

                // Auto-focus
                setTimeout(() => {
                    if (!searchInput.value) {
                        searchInput.focus();
                    }
                }, 300);
            }
        }

        // Funciones del modal
        function abrirModal() {
            modalEliminar.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function cerrarModal() {
            modalEliminar.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Cerrar modal al hacer clic fuera
        modalEliminar.addEventListener('click', function(e) {
            if (e.target === modalEliminar) {
                cerrarModal();
            }
        });

        // Cerrar modal con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalEliminar.style.display === 'flex') {
                cerrarModal();
            }
        });

        // Confirmación antes de eliminar
        document.getElementById('formEliminar').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Eliminando...';
            submitBtn.disabled = true;
        });

        // Crear partículas de fondo
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.className = 'particles';
            particlesContainer.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                pointer-events: none;
                z-index: -1;
            `;
            
            for (let i = 0; i < 15; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    position: absolute;
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    background: rgba(46, 125, 50, 0.05);
                    border-radius: 50%;
                    animation: float ${Math.random() * 6 + 4}s ease-in-out infinite;
                    animation-delay: ${Math.random() * 5}s;
                `;
                particlesContainer.appendChild(particle);
            }
            
            document.body.appendChild(particlesContainer);
        }

        // Animación de entrada para filas
        const style = document.createElement('style');
        style.textContent = `
            @keyframes float {
                0%, 100% {
                    transform: translateY(0px) rotate(0deg);
                }
                50% {
                    transform: translateY(-10px) rotate(180deg);
                }
            }
            
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .data-table tbody tr {
                animation: fadeInUp 0.5s ease forwards;
                opacity: 0;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>