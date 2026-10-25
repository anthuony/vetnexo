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
    
    // Procesar operaciones POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // Eliminar medicamento (desactivar)
        if (isset($_POST['eliminar_medicamento'])) {
            $producto_id = $_POST['producto_id'];
            
            try {
                $db->prepare('UPDATE productos SET activo=0 WHERE id=?')->execute([$producto_id]);
                $_SESSION['success']='Producto desactivado correctamente. Su historial se conserva.';
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error al eliminar el medicamento: " . $e->getMessage();
            }
            
            header('Location: medicamentos.php');
            exit();
        }
        
        // Nuevo medicamento
        if (isset($_POST['nuevo_medicamento'])) {
            $codigo = $_POST['codigo'];
            $nombre = $_POST['nombre'];
            $principio_activo = $_POST['principio_activo'] ?? '';
            $descripcion = $_POST['descripcion'] ?? '';
            $tipo_medicamento = $_POST['tipo_medicamento'] ?? '';
            $via_administracion = $_POST['via_administracion'] ?? '';
            $fabricante = $_POST['fabricante'] ?? '';
            $proveedor_id = !empty($_POST['proveedor_id']) ? $_POST['proveedor_id'] : null;
            $ubicacion = $_POST['ubicacion'] ?? '';
            $activo = isset($_POST['activo']) ? 1 : 0;
            $con_presentaciones = isset($_POST['con_presentaciones']) ? 1 : 0;
            
            try {
                // Verificar si el código ya existe
                $sql_verificar = "SELECT COUNT(*) as total FROM productos WHERE codigo = ?";
                $stmt_verificar = $db->prepare($sql_verificar);
                $stmt_verificar->execute([$codigo]);
                $resultado = $stmt_verificar->fetch(PDO::FETCH_ASSOC);
                
                if ($resultado['total'] > 0) {
                    $_SESSION['error'] = "El código del medicamento ya existe. Use un código único.";
                } else {
                    $db->beginTransaction();
                    // Insertar producto principal (categoría 1 = medicamentos)
                    $sql_nuevo = "INSERT INTO productos (
                        codigo, nombre, categoria_id, descripcion, 
                        principio_activo, tipo_medicamento, via_administracion, fabricante,
                        proveedor_id, ubicacion, activo, es_medicamento_con_presentaciones
                    ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt_nuevo = $db->prepare($sql_nuevo);
                    $stmt_nuevo->execute([
                        $codigo, $nombre, $descripcion,
                        $principio_activo, $tipo_medicamento, $via_administracion, $fabricante,
                        $proveedor_id, $ubicacion, $activo, $con_presentaciones
                    ]);
                    
                    $medicamento_id = $db->lastInsertId();
                    
                    // Procesar presentaciones si existen
                    if ($con_presentaciones && isset($_POST['presentaciones'])) {
                        $presentaciones = $_POST['presentaciones'];
                        
                        foreach ($presentaciones as $presentacion) {
                            if (!empty($presentacion['nombre_presentacion'])) {
                                $sql_presentacion = "INSERT INTO medicamento_presentaciones (
                                    medicamento_id, nombre_presentacion, peso_animal,
                                    rango_peso_min, rango_peso_max, dosis_recomendada, 
                                    concentracion, unidad_medida, precio_venta, precio_compra, 
                                    stock_actual, stock_minimo, stock_maximo, codigo_barras, activo
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                                
                                $stmt_presentacion = $db->prepare($sql_presentacion);
                                $stmt_presentacion->execute([
                                    $medicamento_id,
                                    $presentacion['nombre_presentacion'],
                                    $presentacion['peso_animal'],
                                    $presentacion['rango_peso_min'] ?? null,
                                    $presentacion['rango_peso_max'] ?? null,
                                    $presentacion['dosis_recomendada'] ?? null,
                                    $presentacion['concentracion'] ?? null,
                                    $presentacion['unidad_medida'] ?? null,
                                    $presentacion['precio_venta'] ?? 0,
                                    $presentacion['precio_compra'] ?? 0,
                                    $presentacion['stock_actual'] ?? 0,
                                    $presentacion['stock_minimo'] ?? 5,
                                    $presentacion['stock_maximo'] ?? 100,
                                    $presentacion['codigo_barras'] ?? null
                                ]);
                            }
                        }
                    } else {
                        // Si no tiene presentaciones, usar los campos regulares
                        $stock_actual = $_POST['stock_actual_simple'] ?? 0;
                        $stock_minimo = $_POST['stock_minimo_simple'] ?? 5;
                        $stock_maximo = $_POST['stock_maximo_simple'] ?? 100;
                        $precio_compra = $_POST['precio_compra_simple'] ?? 0;
                        $precio_venta = $_POST['precio_venta_simple'] ?? 0;
                        
                        $sql_update = "UPDATE productos SET 
                                        stock_actual = ?,
                                        stock_minimo = ?,
                                        stock_maximo = ?,
                                        precio_compra = ?,
                                        precio_venta = ?
                                      WHERE id = ?";
                        
                        $stmt_update = $db->prepare($sql_update);
                        $stmt_update->execute([
                            $stock_actual, $stock_minimo, $stock_maximo,
                            $precio_compra, $precio_venta, $medicamento_id
                        ]);
                    }
                    
                    $db->commit();
                    $_SESSION['success'] = "Medicamento creado correctamente.";
                }
            } catch (PDOException $e) {
                if($db->inTransaction())$db->rollBack();
                $_SESSION['error'] = "Error al crear el medicamento: " . $e->getMessage();
            }
            
            header('Location: medicamentos.php');
            exit();
        }
        
        // Ajustar inventario
        if(isset($_POST['ajustar_inventario'])){
            try{require_once __DIR__.'/config/ventas.php';
                $pid=(int)($_POST['producto_id']??0);$presentation=(int)($_POST['presentacion_id']??0)?:null;$kind=$_POST['tipo_movimiento']??'';$qty=filter_var($_POST['cantidad']??null,FILTER_VALIDATE_INT);$reason=trim($_POST['motivo']??'');
                if(!$pid||$qty===false||$qty<0||$qty>1000000||!in_array($kind,['entrada','salida','ajuste'],true)||!$reason)throw new RuntimeException('Revise el producto, cantidad, tipo y motivo.');
                $db->beginTransaction();$q=$db->prepare('SELECT stock_actual FROM productos WHERE id=? FOR UPDATE');$q->execute([$pid]);$old=$q->fetchColumn();if($old===false)throw new RuntimeException('Producto no encontrado.');
                if($presentation){$q=$db->prepare('SELECT stock_actual FROM medicamento_presentaciones WHERE id=? AND medicamento_id=? FOR UPDATE');$q->execute([$presentation,$pid]);$old=$q->fetchColumn();if($old===false)throw new RuntimeException('Presentación no válida.');}
                $delta=match($kind){'entrada'=>$qty,'salida'=>-$qty,'ajuste'=>$qty-(int)$old};
                av_stock($db,$pid,$delta,$reason,(int)$user_id,$presentation);$db->commit();$_SESSION['success']='Inventario actualizado correctamente.';
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('[inventario] '.$e->getMessage());$_SESSION['error']=$e instanceof PDOException?'No se pudo actualizar el inventario.':$e->getMessage();}
            header('Location: medicamentos.php');exit;
        }
    }
    
    // Obtener datos para los dropdowns
    $sql_proveedores = "SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre";
    $stmt_proveedores = $db->query($sql_proveedores);
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener lista de medicamentos con sus presentaciones
    $sql_medicamentos = "
        SELECT 
            p.*,
            c.nombre as categoria_nombre,
            pr.nombre as proveedor_nombre,
            CASE 
                WHEN p.stock_actual = 0 THEN 'critical'
                WHEN p.stock_actual <= p.stock_minimo THEN 'low'
                WHEN p.stock_actual <= (p.stock_minimo * 1.5) THEN 'warning'
                ELSE 'normal'
            END as estado_stock,
            (SELECT COUNT(*) FROM medicamento_presentaciones mp WHERE mp.medicamento_id = p.id AND mp.activo = 1) as num_presentaciones
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
        WHERE p.activo = 1 
        AND p.categoria_id = 1  -- SOLO medicamentos
        ORDER BY estado_stock, p.nombre
    ";
    $stmt_medicamentos = $db->query($sql_medicamentos);
    $medicamentos = $stmt_medicamentos->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada medicamento, obtener sus presentaciones
    foreach ($medicamentos as &$medicamento) {
        if ($medicamento['es_medicamento_con_presentaciones']) {
            $sql_presentaciones = "
                SELECT * FROM medicamento_presentaciones 
                WHERE medicamento_id = ? AND activo = 1 
                ORDER BY rango_peso_min ASC
            ";
            $stmt_presentaciones = $db->prepare($sql_presentaciones);
            $stmt_presentaciones->execute([$medicamento['id']]);
            $medicamento['presentaciones'] = $stmt_presentaciones->fetchAll(PDO::FETCH_ASSOC);
            
            // Calcular stock total sumando presentaciones
            $medicamento['stock_total'] = array_sum(array_column($medicamento['presentaciones'], 'stock_actual'));
        } else {
            $medicamento['stock_total'] = $medicamento['stock_actual'];
            $medicamento['presentaciones'] = [];
        }
    }
    
    // Estadísticas de inventario
    $sql_stats = "
        SELECT 
            COUNT(*) as total_medicamentos,
            COALESCE(SUM(p.stock_actual), 0) as total_stock_base,
            COALESCE(SUM(p.stock_actual * p.precio_compra), 0) as valor_inventario_base,
            SUM(CASE WHEN p.stock_actual <= p.stock_minimo THEN 1 ELSE 0 END) as medicamentos_bajo_stock,
            SUM(CASE WHEN p.stock_actual = 0 THEN 1 ELSE 0 END) as medicamentos_agotados,
            COALESCE(AVG(p.stock_actual), 0) as promedio_stock
        FROM productos p
        WHERE p.activo = 1 
        AND p.categoria_id = 1
    ";
    $stmt_stats = $db->query($sql_stats);
    $estadisticas = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    // Calcular stock total incluyendo presentaciones
    $sql_presentaciones_stats = "
        SELECT 
            COALESCE(SUM(mp.stock_actual), 0) as total_stock_presentaciones,
            COALESCE(SUM(mp.stock_actual * mp.precio_compra), 0) as valor_inventario_presentaciones
        FROM medicamento_presentaciones mp
        INNER JOIN productos p ON mp.medicamento_id = p.id
        WHERE mp.activo = 1 AND p.activo = 1 AND p.categoria_id = 1
    ";
    $stmt_presentaciones_stats = $db->query($sql_presentaciones_stats);
    $presentaciones_stats = $stmt_presentaciones_stats->fetch(PDO::FETCH_ASSOC);
    
    // Sumar estadísticas
    $estadisticas['total_stock'] = $estadisticas['total_stock_base'] + $presentaciones_stats['total_stock_presentaciones'];
    $estadisticas['valor_inventario'] = $estadisticas['valor_inventario_base'] + $presentaciones_stats['valor_inventario_presentaciones'];
    
    // Medicamentos con stock bajo (para alertas)
    $sql_bajo_stock = "
        SELECT p.id, p.codigo, p.nombre, p.stock_actual, p.stock_minimo, c.nombre as categoria
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        WHERE p.activo = 1 
        AND p.categoria_id = 1
        AND (p.stock_actual <= p.stock_minimo OR p.stock_actual = 0)
        ORDER BY p.stock_actual ASC
        LIMIT 10
    ";
    $stmt_bajo_stock = $db->query($sql_bajo_stock);
    $medicamentos_bajo_stock = $stmt_bajo_stock->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en medicamentos.php: " . $e->getMessage());
    $proveedores = [];
    $medicamentos = [];
    $medicamentos_bajo_stock = [];
    $estadisticas = [
        'total_medicamentos' => 0,
        'total_stock' => 0,
        'valor_inventario' => 0,
        'medicamentos_bajo_stock' => 0,
        'medicamentos_agotados' => 0,
        'promedio_stock' => 0
    ];
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

$page_title = "Gestión de Medicamentos - VetNexo";
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
            justify-content: flex-end;
        }

        .btn-primary, .btn-secondary, .btn-warning {
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

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--accent-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.4);
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

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--warning), var(--accent));
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--danger), #ff6b6b);
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
            background: var(--accent-soft);
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #ffebee;
            color: var(--danger);
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

        /* Alertas de Stock */
        .alerts-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .alerts-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
        }

        .alerts-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alerts-header h3 i {
            color: var(--accent);
        }

        .alert-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            transition: background 0.3s ease;
        }

        .alert-item:last-child {
            border-bottom: none;
        }

        .alert-item:hover {
            background: var(--gray-light);
        }

        .alert-item.critical {
            background: #ffebee;
        }

        .alert-item.warning {
            background: var(--accent-soft);
        }

        .alert-item-info {
            flex: 1;
        }

        .alert-item-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .alert-item-subtitle {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-item-stock {
            text-align: right;
            min-width: 200px;
        }

        .stock-level {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .stock-level.critical {
            color: var(--danger);
        }

        .stock-level.warning {
            color: var(--warning);
        }

        .stock-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--white);
        }

        /* Actions Bar */
        .actions-bar {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 1.2rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: var(--shadow);
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
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

        .actions-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Tabla de Medicamentos */
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

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
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

        .data-table tr.presentacion-row {
            background: #f8f9fa;
        }

        .data-table tr.presentacion-row:hover td {
            background: #e9ecef;
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-stock-normal {
            background: #d4edda;
            color: #155724;
        }

        .badge-stock-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
        }

        .badge-stock-low {
            background: #fff3cd;
            color: #856404;
        }

        .badge-stock-critical {
            background: #ffebee;
            color: var(--danger);
        }

        .badge-presentaciones {
            background: #cce5ff;
            color: #004085;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-principio {
            background: var(--primary-soft);
            color: var(--primary-dark);
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

        .btn-icon-warning {
            background: var(--accent-soft);
            color: var(--warning);
        }

        .btn-icon-warning:hover {
            background: var(--warning);
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

        .btn-icon-info {
            background: #cce5ff;
            color: var(--info);
        }

        .btn-icon-info:hover {
            background: var(--info);
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
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
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
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--danger);
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
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        /* Formulario */
        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        /* Presentaciones */
        .presentaciones-container {
            margin: 1rem 0;
        }

        .presentacion-item {
            background: var(--gray-light);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .presentacion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray);
        }

        .presentacion-title {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .btn-remove-presentacion {
            background: var(--danger);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.3rem 0.6rem;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s ease;
        }

        .btn-remove-presentacion:hover {
            background: #D32F2F;
        }

        /* Stock Info */
        .stock-info {
            background: var(--primary-soft);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
        }

        .stock-current {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .hidden {
            display: none;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .actions-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            
            .alert-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .alert-item-stock {
                text-align: left;
                width: 100%;
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
                        <i class='bx bx-plus-medical'></i>
                        Gestión de Medicamentos
                    </h1>
                    <p>
                        <i class='bx bx-capsule'></i>
                        Control de medicamentos y fármacos veterinarios
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

            <!-- Alertas de Operaciones -->
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

            <!-- Estadísticas -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-package'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_medicamentos']); ?></div>
                        <div class="stat-label">Total Medicamentos</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-layer'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($estadisticas['total_stock']); ?></div>
                        <div class="stat-label">Unidades en Stock</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['medicamentos_bajo_stock']; ?></div>
                        <div class="stat-label">Stock Bajo</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-alt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['medicamentos_agotados']; ?></div>
                        <div class="stat-label">Agotados</div>
                    </div>
                </div>
            </div>

            <!-- Alertas de Stock Bajo -->
            <?php if (!empty($medicamentos_bajo_stock)): ?>
            <div class="alerts-section fade-in">
                <div class="alerts-header">
                    <h3>
                        <i class='bx bx-error-circle'></i>
                        Alertas de Stock Bajo
                    </h3>
                </div>
                <div>
                    <?php foreach ($medicamentos_bajo_stock as $alerta): ?>
                        <div class="alert-item <?php echo $alerta['stock_actual'] == 0 ? 'critical' : 'warning'; ?>">
                            <div class="alert-item-info">
                                <div class="alert-item-title">
                                    <?php echo htmlspecialchars($alerta['nombre']); ?>
                                </div>
                                <div class="alert-item-subtitle">
                                    <span><i class='bx bx-code-alt'></i> <?php echo htmlspecialchars($alerta['codigo']); ?></span>
                                    <span><i class='bx bx-category'></i> <?php echo htmlspecialchars($alerta['categoria']); ?></span>
                                </div>
                            </div>
                            <div class="alert-item-stock">
                                <div class="stock-level <?php echo $alerta['stock_actual'] == 0 ? 'critical' : 'warning'; ?>">
                                    Stock: <?php echo $alerta['stock_actual']; ?> / Mín: <?php echo $alerta['stock_minimo']; ?>
                                </div>
                                <span class="stock-badge">
                                    <?php echo $alerta['stock_actual'] == 0 ? 'AGOTADO' : 'STOCK BAJO'; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Barra de Acciones -->
            <div class="actions-bar fade-in">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" id="searchInput" placeholder="Buscar medicamentos...">
                </div>
                <div class="actions-buttons">
                    <button class="btn-warning btn-sm" onclick="openModal('modalAjustarInventario')">
                        <i class='bx bx-transfer-alt'></i>
                        Ajustar Inventario
                    </button>
                    <button class="btn-primary btn-sm" onclick="openModal('modalNuevoMedicamento')">
                        <i class='bx bx-plus'></i>
                        Nuevo Medicamento
                    </button>
                </div>
            </div>

            <!-- Tabla de Medicamentos -->
            <div class="table-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-ul'></i>
                        Lista de Medicamentos
                    </h3>
                    <div class="pagination-info">
                        Total: <?php echo count($medicamentos); ?> medicamentos
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="medicamentosTable">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre / Principio Activo</th>
                                <th>Presentaciones</th>
                                <th>Stock Total</th>
                                <th>Precios</th>
                                <th>Proveedor</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($medicamentos)): ?>
                                <?php foreach ($medicamentos as $medicamento): ?>
                                    <tr class="fade-in">
                                        <td>
                                            <strong><?php echo htmlspecialchars($medicamento['codigo']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($medicamento['nombre']); ?></div>
                                            <?php if (!empty($medicamento['principio_activo'])): ?>
                                                <span class="badge badge-principio" style="margin-top: 0.2rem;">
                                                    <i class='bx bx-flask'></i> <?php echo htmlspecialchars($medicamento['principio_activo']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($medicamento['descripcion'])): ?>
                                                <small style="color: var(--text-light); display: block; margin-top: 0.2rem;">
                                                    <?php echo htmlspecialchars(substr($medicamento['descripcion'], 0, 50)) . (strlen($medicamento['descripcion']) > 50 ? '...' : ''); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($medicamento['es_medicamento_con_presentaciones'] && $medicamento['num_presentaciones'] > 0): ?>
                                                <span class="badge badge-presentaciones">
                                                    <i class='bx bx-layer'></i> <?php echo $medicamento['num_presentaciones']; ?> presentaciones
                                                </span>
                                            <?php else: ?>
                                                <small>Presentación única</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; font-size: 1.1rem;">
                                                <?php echo $medicamento['stock_total']; ?>
                                            </div>
                                            <?php if (!$medicamento['es_medicamento_con_presentaciones']): ?>
                                                <span class="badge badge-stock-<?php echo $medicamento['estado_stock']; ?>">
                                                    <?php 
                                                    switch($medicamento['estado_stock']) {
                                                        case 'critical': echo 'AGOTADO'; break;
                                                        case 'low': echo 'BAJO'; break;
                                                        case 'warning': echo 'ALERTA'; break;
                                                        default: echo 'NORMAL';
                                                    }
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($medicamento['es_medicamento_con_presentaciones'] && !empty($medicamento['presentaciones'])): ?>
                                                <div>
                                                    <small>Desde: $<?php 
                                                    $min_precio = min(array_column($medicamento['presentaciones'], 'precio_venta'));
                                                    echo number_format($min_precio, 2);
                                                    ?></small>
                                                </div>
                                            <?php else: ?>
                                                <div>
                                                    <strong style="color: var(--success);">$<?php echo number_format($medicamento['precio_venta'], 2); ?></strong>
                                                </div>
                                                <small>Compra: $<?php echo number_format($medicamento['precio_compra'], 2); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($medicamento['proveedor_nombre'] ?? 'No asignado'); ?></td>
                                        <td>
                                            <span class="badge badge-active">Activo</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-primary" onclick="editarMedicamento(<?php echo $medicamento['id']; ?>)" title="Editar">
                                                    <i class='bx bx-edit'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-warning" onclick="ajustarStock(<?php echo $medicamento['id']; ?>, '<?php echo htmlspecialchars($medicamento['nombre']); ?>', <?php echo $medicamento['stock_total']; ?>)" title="Ajustar Stock">
                                                    <i class='bx bx-transfer-alt'></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de desactivar este medicamento?');"><?php echo av_csrf_field(); ?>
                                                    <input type="hidden" name="producto_id" value="<?php echo $medicamento['id']; ?>">
                                                    <button type="submit" name="eliminar_medicamento" class="btn-icon btn-icon-danger" title="Desactivar">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    
                                    <!-- Mostrar presentaciones si existen -->
                                    <?php if ($medicamento['es_medicamento_con_presentaciones'] && !empty($medicamento['presentaciones'])): ?>
                                        <?php foreach ($medicamento['presentaciones'] as $presentacion): ?>
                                            <tr class="presentacion-row">
                                                <td></td>
                                                <td style="padding-left: 2rem;">
                                                    <small>
                                                        <i class='bx bx-chevron-right' style="color: var(--primary);"></i>
                                                        <strong><?php echo htmlspecialchars($presentacion['nombre_presentacion']); ?></strong>
                                                    </small>
                                                    <?php if ($presentacion['concentracion']): ?>
                                                        <br><small style="color: var(--text-light);">Conc: <?php echo htmlspecialchars($presentacion['concentracion']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($presentacion['dosis_recomendada']): ?>
                                                        <br><small style="color: var(--text-light);">Dosis: <?php echo htmlspecialchars($presentacion['dosis_recomendada']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <strong>Peso:</strong> <?php echo htmlspecialchars($presentacion['peso_animal']); ?>
                                                        <?php if ($presentacion['rango_peso_min'] && $presentacion['rango_peso_max']): ?>
                                                            (<?php echo $presentacion['rango_peso_min']; ?>-<?php echo $presentacion['rango_peso_max']; ?> kg)
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600;">
                                                        <?php echo $presentacion['stock_actual']; ?>
                                                    </div>
                                                    <small>Mín: <?php echo $presentacion['stock_minimo']; ?></small>
                                                </td>
                                                <td>
                                                    <small>Venta: $<?php echo number_format($presentacion['precio_venta'], 2); ?></small>
                                                    <br>
                                                    <small>Compra: $<?php echo number_format($presentacion['precio_compra'], 2); ?></small>
                                                </td>
                                                <td></td>
                                                <td>
                                                    <?php if ($presentacion['activo']): ?>
                                                        <span class="badge badge-active" style="font-size: 0.7rem;">Activa</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn-icon btn-icon-warning" style="width: 28px; height: 28px;" 
                                                            onclick="ajustarStockPresentacion(<?php echo $medicamento['id']; ?>, <?php echo $presentacion['id']; ?>, '<?php echo htmlspecialchars($presentacion['nombre_presentacion']); ?>', <?php echo $presentacion['stock_actual']; ?>)" 
                                                            title="Ajustar Stock de Presentación">
                                                        <i class='bx bx-transfer-alt' style="font-size: 0.9rem;"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class='bx bx-plus-medical'></i>
                                            <h3>No hay medicamentos registrados</h3>
                                            <p>Comienza agregando un nuevo medicamento</p>
                                            <button class="btn-primary btn-sm" onclick="openModal('modalNuevoMedicamento')" style="display: inline-flex;">
                                                <i class='bx bx-plus'></i>
                                                Agregar Medicamento
                                            </button>
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

    <!-- Modal Nuevo Medicamento -->
    <div class="modal" id="modalNuevoMedicamento">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class='bx bx-plus-medical'></i>
                    Nuevo Medicamento
                </h3>
                <button class="modal-close" onclick="closeModal('modalNuevoMedicamento')">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <form method="POST" id="formNuevoMedicamento"><?php echo av_csrf_field(); ?>
                <div class="modal-body">
                    <!-- Información General -->
                    <fieldset style="border: 1px solid var(--gray); padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius-sm);">
                        <legend style="font-weight: 600; color: var(--primary-dark); padding: 0 0.5rem;">Información General</legend>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Código *</label>
                                <input type="text" class="form-input" name="codigo" required 
                                       placeholder="Ej: MED-00125" pattern="[A-Za-z0-9\-]+"
                                       title="Use letras, números y guiones">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Nombre *</label>
                                <input type="text" class="form-input" name="nombre" required 
                                       placeholder="Nombre comercial">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Principio Activo *</label>
                                <input type="text" class="form-input" name="principio_activo" required 
                                       placeholder="Ej: Enrofloxacina">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="tipo_medicamento">
                                    <option value="">Seleccionar tipo</option>
                                    <option value="antibiotico">Antibiótico</option>
                                    <option value="analgesico">Analgésico</option>
                                    <option value="antiparasitario">Antiparasitario</option>
                                    <option value="vacuna">Vacuna</option>
                                    <option value="antiinflamatorio">Antiinflamatorio</option>
                                    <option value="anestesico">Anestésico</option>
                                    <option value="suplemento">Suplemento</option>
                                    <option value="otros">Otros</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Vía Administración</label>
                                <select class="form-select" name="via_administracion">
                                    <option value="">Seleccionar vía</option>
                                    <option value="oral">Oral</option>
                                    <option value="inyectable">Inyectable</option>
                                    <option value="topico">Tópico</option>
                                    <option value="oftalmico">Oftálmico</option>
                                    <option value="otico">Ótico</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fabricante</label>
                                <input type="text" class="form-input" name="fabricante" 
                                       placeholder="Laboratorio">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-textarea" name="descripcion" 
                                      placeholder="Descripción detallada..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Proveedor</label>
                                <select class="form-select" name="proveedor_id">
                                    <option value="">Sin proveedor</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo $proveedor['id']; ?>">
                                            <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Ubicación</label>
                                <input type="text" class="form-input" name="ubicacion" 
                                       placeholder="Ej: Estante A - Nivel 2">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="checkbox-group">
                                <input type="checkbox" id="con_presentaciones" name="con_presentaciones" value="1" onchange="togglePresentaciones()">
                                <label for="con_presentaciones">Tiene presentaciones por peso</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" id="activo" name="activo" value="1" checked>
                                <label for="activo">Activo</label>
                            </div>
                        </div>
                    </fieldset>
                    
                    <!-- Sección sin presentaciones -->
                    <div id="seccion_sin_presentaciones">
                        <fieldset style="border: 1px solid var(--gray); padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius-sm);">
                            <legend style="font-weight: 600; color: var(--primary-dark); padding: 0 0.5rem;">Stock y Precios</legend>
                            
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label class="form-label">Stock Actual</label>
                                    <input type="number" class="form-input" name="stock_actual_simple" value="0" min="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Stock Mínimo</label>
                                    <input type="number" class="form-input" name="stock_minimo_simple" value="5" min="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Stock Máximo</label>
                                    <input type="number" class="form-input" name="stock_maximo_simple" value="100" min="0">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Precio Compra ($)</label>
                                    <input type="number" class="form-input" name="precio_compra_simple" step="0.01" min="0" value="0.00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Precio Venta ($)</label>
                                    <input type="number" class="form-input" name="precio_venta_simple" step="0.01" min="0" value="0.00">
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    
                    <!-- Sección con presentaciones -->
                    <div id="seccion_con_presentaciones" style="display: none;">
                        <fieldset style="border: 1px solid var(--gray); padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--radius-sm);">
                            <legend style="font-weight: 600; color: var(--primary-dark); padding: 0 0.5rem;">
                                Presentaciones
                                <button type="button" class="btn-primary btn-sm" style="float: right; padding: 0.3rem 0.8rem;" onclick="agregarPresentacion()">
                                    <i class='bx bx-plus'></i> Agregar
                                </button>
                            </legend>
                            
                            <div id="presentaciones-container" class="presentaciones-container">
                                <!-- Las presentaciones se agregarán aquí -->
                            </div>
                        </fieldset>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary btn-sm" onclick="closeModal('modalNuevoMedicamento')">Cancelar</button>
                    <button type="submit" name="nuevo_medicamento" class="btn-primary btn-sm">Crear Medicamento</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Ajustar Inventario -->
    <div class="modal" id="modalAjustarInventario">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class='bx bx-transfer-alt'></i>
                    Ajustar Inventario
                </h3>
                <button class="modal-close" onclick="closeModal('modalAjustarInventario')">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <form method="POST" id="formAjustarInventario"><?php echo av_csrf_field(); ?>
                <div class="modal-body">
                    <input type="hidden" name="producto_id" id="ajuste_producto_id">
                    <input type="hidden" name="presentacion_id" id="ajuste_presentacion_id">
                    
                    <div class="form-group">
                        <label class="form-label">Medicamento *</label>
                        <select class="form-select" id="medicamento_ajuste" name="producto_id" required onchange="cargarInfoMedicamentoAjuste()">
                            <option value="">Seleccionar medicamento</option>
                            <?php foreach ($medicamentos as $medicamento): ?>
                                <?php if ($medicamento['activo'] ?? 1): ?>
                                    <option value="<?php echo $medicamento['id']; ?>" 
                                            data-tipo="<?php echo $medicamento['es_medicamento_con_presentaciones'] ? 'con_presentaciones' : 'simple'; ?>">
                                        <?php echo htmlspecialchars($medicamento['codigo']); ?> - <?php echo htmlspecialchars($medicamento['nombre']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="seleccion_presentacion_ajuste" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Presentación</label>
                            <select class="form-select" id="presentacion_ajuste" name="presentacion_id" onchange="cargarInfoPresentacion()">
                                <option value="">Seleccionar presentación</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="infoMedicamento" class="stock-info" style="display: none;">
                        <div><strong id="nombreMedicamento"></strong></div>
                        <div class="stock-current">Stock actual: <span id="stockActual">0</span> unidades</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Tipo de Movimiento *</label>
                            <select class="form-select" id="tipo_movimiento" name="tipo_movimiento" required>
                                <option value="entrada">Entrada de Stock</option>
                                <option value="salida">Salida de Stock</option>
                                <option value="ajuste">Ajuste de Stock</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad *</label>
                            <input type="number" class="form-input" id="cantidad" name="cantidad" min="1" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Motivo *</label>
                        <select class="form-select" id="motivo" name="motivo" required>
                            <option value="">Seleccionar motivo</option>
                            <option value="compra">Compra/Reabastecimiento</option>
                            <option value="venta">Venta a cliente</option>
                            <option value="devolucion">Devolución</option>
                            <option value="caducado">Medicamento caducado</option>
                            <option value="danado">Medicamento dañado</option>
                            <option value="conteo">Ajuste por conteo físico</option>
                            <option value="otros">Otros</option>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary btn-sm" onclick="closeModal('modalAjustarInventario')">Cancelar</button>
                    <button type="submit" name="ajustar_inventario" class="btn-primary btn-sm">Aplicar Ajuste</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Contador para presentaciones
        let presentacionCounter = 0;
        
        // Funciones para modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Limpiar formulario
            if (modalId === 'modalNuevoMedicamento') {
                document.getElementById('formNuevoMedicamento').reset();
                document.getElementById('presentaciones-container').innerHTML = '';
                presentacionCounter = 0;
                togglePresentaciones();
            }
        }

        // Toggle entre presentaciones y medicamento simple
        function togglePresentaciones() {
            const conPresentaciones = document.getElementById('con_presentaciones').checked;
            const seccionSin = document.getElementById('seccion_sin_presentaciones');
            const seccionCon = document.getElementById('seccion_con_presentaciones');
            
            if (conPresentaciones) {
                seccionSin.style.display = 'none';
                seccionCon.style.display = 'block';
                if (presentacionCounter === 0) {
                    agregarPresentacion();
                }
            } else {
                seccionSin.style.display = 'block';
                seccionCon.style.display = 'none';
            }
        }

        // Agregar nueva presentación
        function agregarPresentacion() {
            presentacionCounter++;
            const container = document.getElementById('presentaciones-container');
            
            const presentacionHTML = `
                <div class="presentacion-item" id="presentacion-${presentacionCounter}">
                    <div class="presentacion-header">
                        <div class="presentacion-title">Presentación ${presentacionCounter}</div>
                        <button type="button" class="btn-remove-presentacion" onclick="removerPresentacion(${presentacionCounter})">
                            <i class='bx bx-trash'></i> Eliminar
                        </button>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nombre de Presentación *</label>
                            <input type="text" class="form-input" name="presentaciones[${presentacionCounter}][nombre_presentacion]" required 
                                   placeholder="Ej: Comprimidos 50mg, Suspensión 10ml">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Peso Animal *</label>
                            <select class="form-select" name="presentaciones[${presentacionCounter}][peso_animal]" required>
                                <option value="">Seleccionar peso</option>
                                <option value="pequeño">Pequeño (0-10 kg)</option>
                                <option value="mediano">Mediano (10-25 kg)</option>
                                <option value="grande">Grande (25-40 kg)</option>
                                <option value="muy_grande">Muy Grande (+40 kg)</option>
                                <option value="cachorro">Cachorro/Gatito</option>
                                <option value="adulto">Adulto</option>
                                <option value="senior">Senior</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Rango Mínimo (kg)</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][rango_peso_min]" 
                                   step="0.1" min="0" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rango Máximo (kg)</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][rango_peso_max]" 
                                   step="0.1" min="0" placeholder="10">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Dosis Recomendada</label>
                            <input type="text" class="form-input" name="presentaciones[${presentacionCounter}][dosis_recomendada]" 
                                   placeholder="Ej: 1 comprimido cada 12h">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Concentración</label>
                            <input type="text" class="form-input" name="presentaciones[${presentacionCounter}][concentracion]" 
                                   placeholder="Ej: 50mg/ml">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Unidad de Medida</label>
                            <input type="text" class="form-input" name="presentaciones[${presentacionCounter}][unidad_medida]" 
                                   placeholder="Ej: comprimidos, ml, mg">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Código de Barras</label>
                            <input type="text" class="form-input" name="presentaciones[${presentacionCounter}][codigo_barras]" 
                                   placeholder="Código único">
                        </div>
                    </div>
                    
                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label">Stock Actual</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][stock_actual]" 
                                   value="0" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock Mínimo</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][stock_minimo]" 
                                   value="5" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock Máximo</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][stock_maximo]" 
                                   value="100" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Precio Compra ($)</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][precio_compra]" 
                                   step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Precio Venta ($)</label>
                            <input type="number" class="form-input" name="presentaciones[${presentacionCounter}][precio_venta]" 
                                   step="0.01" min="0" value="0.00" required>
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', presentacionHTML);
        }

        // Remover presentación
        function removerPresentacion(id) {
            const presentacion = document.getElementById(`presentacion-${id}`);
            if (presentacion) {
                presentacion.remove();
            }
        }

        // Función para cargar información del medicamento en el ajuste
        function cargarInfoMedicamentoAjuste() {
            const select = document.getElementById('medicamento_ajuste');
            const selectedOption = select.options[select.selectedIndex];
            const tipo = selectedOption.getAttribute('data-tipo');
            const seleccionPresentacion = document.getElementById('seleccion_presentacion_ajuste');
            const infoDiv = document.getElementById('infoMedicamento');
            const presentacionSelect = document.getElementById('presentacion_ajuste');
            
            // Limpiar
            presentacionSelect.innerHTML = '<option value="">Seleccionar presentación</option>';
            infoDiv.style.display = 'none';
            
            if (selectedOption.value) {
                if (tipo === 'con_presentaciones') {
                    seleccionPresentacion.style.display = 'block';
                    // Cargar presentaciones del medicamento seleccionado
                    <?php foreach ($medicamentos as $med): ?>
                        if (selectedOption.value == <?php echo $med['id']; ?> && <?php echo json_encode($med['presentaciones'] ?? []); ?>.length > 0) {
                            <?php foreach ($med['presentaciones'] ?? [] as $pres): ?>
                                presentacionSelect.innerHTML += '<option value="<?php echo $pres['id']; ?>" data-stock="<?php echo $pres['stock_actual']; ?>" data-nombre="<?php echo htmlspecialchars($pres['nombre_presentacion']); ?>"><?php echo htmlspecialchars($pres['nombre_presentacion']); ?> (Stock: <?php echo $pres['stock_actual']; ?>)</option>';
                            <?php endforeach; ?>
                        }
                    <?php endforeach; ?>
                } else {
                    seleccionPresentacion.style.display = 'none';
                    document.getElementById('nombreMedicamento').textContent = selectedOption.text;
                    // Obtener stock actual del medicamento
                    <?php foreach ($medicamentos as $med): ?>
                        if (selectedOption.value == <?php echo $med['id']; ?>) {
                            document.getElementById('stockActual').textContent = <?php echo $med['stock_total']; ?>;
                        }
                    <?php endforeach; ?>
                    infoDiv.style.display = 'block';
                }
            } else {
                seleccionPresentacion.style.display = 'none';
            }
        }

        function cargarInfoPresentacion() {
            const select = document.getElementById('presentacion_ajuste');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                document.getElementById('nombreMedicamento').textContent = selectedOption.getAttribute('data-nombre');
                document.getElementById('stockActual').textContent = selectedOption.getAttribute('data-stock');
                document.getElementById('infoMedicamento').style.display = 'block';
            }
        }

        // Función para ajustar stock de un medicamento
        function ajustarStock(medicamentoId, medicamentoNombre, stockActual) {
            const select = document.getElementById('medicamento_ajuste');
            
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value == medicamentoId) {
                    select.selectedIndex = i;
                    break;
                }
            }
            
            document.getElementById('nombreMedicamento').textContent = medicamentoNombre;
            document.getElementById('stockActual').textContent = stockActual;
            document.getElementById('ajuste_producto_id').value = medicamentoId;
            document.getElementById('seleccion_presentacion_ajuste').style.display = 'none';
            document.getElementById('infoMedicamento').style.display = 'block';
            
            openModal('modalAjustarInventario');
        }

        function ajustarStockPresentacion(medicamentoId, presentacionId, presentacionNombre, stockActual) {
            const select = document.getElementById('medicamento_ajuste');
            
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value == medicamentoId) {
                    select.selectedIndex = i;
                    break;
                }
            }
            
            // Simular cambio para cargar presentaciones
            cargarInfoMedicamentoAjuste();
            
            // Seleccionar la presentación después de un breve retraso
            setTimeout(() => {
                const presSelect = document.getElementById('presentacion_ajuste');
                for (let i = 0; i < presSelect.options.length; i++) {
                    if (presSelect.options[i].value == presentacionId) {
                        presSelect.selectedIndex = i;
                        break;
                    }
                }
                cargarInfoPresentacion();
            }, 100);
            
            document.getElementById('ajuste_presentacion_id').value = presentacionId;
            openModal('modalAjustarInventario');
        }

        function editarMedicamento(id) {
            window.location.href = 'editar_medicamento.php?id=' + id;
        }

        // Búsqueda en tiempo real
        document.getElementById('searchInput').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#medicamentosTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de estadísticas
            animateStats();
            
            // Animación de filas de tabla
            animateTableRows();
            
            // Validación de formulario de ajuste
            setupFormValidation();
            
            // Crear partículas de fondo
            createParticles();
        });

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
                        if (text.includes('$')) {
                            stat.textContent = '$' + current.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        } else {
                            stat.textContent = Math.floor(current).toLocaleString('es-ES');
                        }
                    }, 30);
                }
            });
        }

        function animateTableRows() {
            const rows = document.querySelectorAll('.data-table tbody tr:not(.presentacion-row)');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(10px)';
                
                setTimeout(() => {
                    row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50 + 100);
            });
        }

        function setupFormValidation() {
            const formAjuste = document.getElementById('formAjustarInventario');
            if (formAjuste) {
                formAjuste.addEventListener('submit', function(e) {
                    const cantidad = parseInt(document.getElementById('cantidad').value);
                    const tipo = document.getElementById('tipo_movimiento').value;
                    const stockActual = parseInt(document.getElementById('stockActual').textContent);
                    
                    if (tipo === 'salida' && cantidad > stockActual) {
                        e.preventDefault();
                        showNotification('No puede sacar más unidades de las que existen en stock.', 'error');
                    }
                });
            }
        }

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

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: var(--radius-sm);
                background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#F44336' : type === 'warning' ? '#FFC107' : '#2196F3'};
                color: white;
                font-weight: 600;
                z-index: 10000;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 400px;
                box-shadow: var(--shadow-lg);
            `;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Cerrar modales al hacer clic fuera
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target == modal) {
                    closeModal(modal.id);
                }
            }
        }

        // Cerrar modal con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style*="display: flex"]');
                modals.forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });

        // Estilo adicional para animación de partículas
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
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>