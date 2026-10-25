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
require_once __DIR__.'/config/bcv.php';$tasa_bs=(float)(bcv_get_rate()['rate']??0);

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
        
        // Eliminar producto (desactivar)
        if (isset($_POST['eliminar_producto'])) {
            $producto_id = $_POST['producto_id'];
            
            try {
                $db->prepare('UPDATE productos SET activo=0 WHERE id=?')->execute([$producto_id]);
                $_SESSION['success']='Producto desactivado correctamente. Su historial se conserva.';
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error al eliminar el producto: " . $e->getMessage();
            }
            
            header('Location: productos.php');
            exit();
        }
        
        // Nuevo producto
        if (isset($_POST['nuevo_producto'])) {
            $codigo = $_POST['codigo'];
            $nombre = $_POST['nombre'];
            $categoria_id = $_POST['categoria_id'];
            $descripcion = $_POST['descripcion'] ?? '';
            $stock_actual = $_POST['stock_actual'] ?? 0;
            $stock_minimo = $_POST['stock_minimo'] ?? 5;
            $stock_maximo = $_POST['stock_maximo'] ?? 100;
            $proveedor_id = !empty($_POST['proveedor_id']) ? $_POST['proveedor_id'] : null;
            $precio_compra = $_POST['precio_compra'] ?? 0;
            $precio_venta = $_POST['precio_venta'] ?? 0;
            $aplica_iva = isset($_POST['aplica_iva']) ? 1 : 0;
            $porcentaje_iva = $_POST['porcentaje_iva'] ?? 16.00;
            $ubicacion = $_POST['ubicacion'] ?? '';
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            try {
                // Verificar si el código ya existe
                $sql_verificar = "SELECT COUNT(*) as total FROM productos WHERE codigo = ?";
                $stmt_verificar = $db->prepare($sql_verificar);
                $stmt_verificar->execute([$codigo]);
                $resultado = $stmt_verificar->fetch(PDO::FETCH_ASSOC);
                
                if ($resultado['total'] > 0) {
                    $_SESSION['error'] = "El código del producto ya existe. Use un código único.";
                } else {
                    $sql_nuevo = "INSERT INTO productos (
                        codigo, nombre, categoria_id, descripcion, stock_actual, 
                        stock_minimo, stock_maximo, proveedor_id, precio_compra, 
                        precio_venta, aplica_iva, porcentaje_iva, ubicacion, activo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt_nuevo = $db->prepare($sql_nuevo);
                    $stmt_nuevo->execute([
                        $codigo, $nombre, $categoria_id, $descripcion, 
                        $stock_actual, $stock_minimo, $stock_maximo, 
                        $proveedor_id, $precio_compra, $precio_venta, 
                        $aplica_iva, $porcentaje_iva, $ubicacion, $activo
                    ]);
                    
                    $_SESSION['success'] = "Producto creado correctamente.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error al crear el producto: " . $e->getMessage();
            }
            
            header('Location: productos.php');
            exit();
        }
        
        // Editar producto
        if (isset($_POST['editar_producto'])) {
            $producto_id = filter_input(INPUT_POST, 'producto_id', FILTER_VALIDATE_INT);
            $codigo = trim((string)($_POST['codigo'] ?? ''));
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $categoria_id = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT);
            $descripcion = trim((string)($_POST['descripcion'] ?? ''));
            $stock_actual = filter_var($_POST['stock_actual'] ?? null, FILTER_VALIDATE_INT);
            $stock_minimo = filter_var($_POST['stock_minimo'] ?? null, FILTER_VALIDATE_INT);
            $stock_maximo = filter_var($_POST['stock_maximo'] ?? null, FILTER_VALIDATE_INT);
            $proveedor_id = !empty($_POST['proveedor_id']) ? filter_var($_POST['proveedor_id'], FILTER_VALIDATE_INT) : null;
            $precio_compra = str_replace(',', '.', trim((string)($_POST['precio_compra'] ?? '0')));
            $precio_venta = str_replace(',', '.', trim((string)($_POST['precio_venta'] ?? '0')));
            $ubicacion = trim((string)($_POST['ubicacion'] ?? ''));
            $activo = isset($_POST['activo']) ? 1 : 0;

            $errores = [];
            if (!$producto_id) $errores[] = 'Producto inválido.';
            if ($codigo === '' || !preg_match('/^[A-Za-z0-9-]+$/', $codigo)) $errores[] = 'El código del producto es obligatorio y solo admite letras, números y guiones.';
            if ($nombre === '') $errores[] = 'El nombre del producto es obligatorio.';
            if (!$categoria_id) $errores[] = 'Seleccione una categoría válida.';
            if ($stock_actual === false || $stock_actual < 0) $errores[] = 'El stock actual no puede ser negativo.';
            if ($stock_minimo === false || $stock_minimo < 0) $errores[] = 'El stock mínimo no puede ser negativo.';
            if ($stock_maximo === false || $stock_maximo < 0) $errores[] = 'El stock máximo no puede ser negativo.';
            if ($stock_minimo !== false && $stock_maximo !== false && $stock_minimo > $stock_maximo) $errores[] = 'El stock mínimo no puede ser mayor que el stock máximo.';
            if (!is_numeric($precio_compra) || (float)$precio_compra < 0) $errores[] = 'El precio de compra no es válido.';
            if (!is_numeric($precio_venta) || (float)$precio_venta < 0) $errores[] = 'El precio de venta no es válido.';

            if ($errores) {
                $_SESSION['error'] = implode(' ', $errores);
                header('Location: editar_producto.php?id=' . (int)$producto_id);
                exit();
            }

            try {
                $db->beginTransaction();

                $stmt_actual = $db->prepare("SELECT id, codigo, stock_actual FROM productos WHERE id = ? FOR UPDATE");
                $stmt_actual->execute([$producto_id]);
                $producto_actual = $stmt_actual->fetch(PDO::FETCH_ASSOC);
                if (!$producto_actual) {
                    throw new RuntimeException('El producto ya no existe.');
                }

                $stmt_codigo = $db->prepare("SELECT COUNT(*) FROM productos WHERE codigo = ? AND id <> ?");
                $stmt_codigo->execute([$codigo, $producto_id]);
                if ((int)$stmt_codigo->fetchColumn() > 0) {
                    throw new RuntimeException('El código del producto ya está siendo usado por otro producto.');
                }

                $stmt_categoria = $db->prepare("SELECT COUNT(*) FROM categorias_productos WHERE id = ? AND activo = 1");
                $stmt_categoria->execute([$categoria_id]);
                if ((int)$stmt_categoria->fetchColumn() === 0) {
                    throw new RuntimeException('La categoría seleccionada no existe o está inactiva.');
                }

                if ($proveedor_id) {
                    $stmt_proveedor = $db->prepare("SELECT COUNT(*) FROM proveedores WHERE id = ? AND activo = 1");
                    $stmt_proveedor->execute([$proveedor_id]);
                    if ((int)$stmt_proveedor->fetchColumn() === 0) {
                        throw new RuntimeException('El proveedor seleccionado no existe o está inactivo.');
                    }
                }

                $sql_editar = "UPDATE productos SET
                    codigo = ?, nombre = ?, categoria_id = ?, descripcion = ?,
                    stock_actual = ?, stock_minimo = ?, stock_maximo = ?, proveedor_id = ?,
                    precio_compra = ?, precio_venta = ?, ubicacion = ?, activo = ?, aplica_iva=?, porcentaje_iva=?, destacado=?, en_oferta=?,
                    fecha_actualizacion = CURRENT_TIMESTAMP
                    WHERE id = ?";
                $stmt_editar = $db->prepare($sql_editar);
                $stmt_editar->execute([
                    $codigo, $nombre, $categoria_id, $descripcion,
                    $stock_actual, $stock_minimo, $stock_maximo, $proveedor_id,
                    round((float)$precio_compra, 2), round((float)$precio_venta, 2),
                    $ubicacion, $activo, isset($_POST['aplica_iva'])?1:0, (float)($_POST['porcentaje_iva']??16),isset($_POST['destacado'])?1:0,isset($_POST['en_oferta'])?1:0, $producto_id
                ]);

                $stock_anterior = (int)$producto_actual['stock_actual'];
                if ($stock_anterior !== (int)$stock_actual) {
                    $diferencia = (int)$stock_actual - $stock_anterior;
                    $stmt_mov = $db->prepare("INSERT INTO movimientos_inventario
                        (producto_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, motivo, usuario_id, fecha_movimiento)
                        VALUES (?, 'ajuste', ?, ?, ?, ?, ?, NOW())");
                    $stmt_mov->execute([
                        $producto_id,
                        $diferencia,
                        $stock_anterior,
                        (int)$stock_actual,
                        'Edición manual del producto',
                        $user_id
                    ]);
                }

                $db->commit();
                $_SESSION['success'] = 'Producto actualizado correctamente. Precio de venta: $' . number_format((float)$precio_venta, 2, '.', ',') . '.';
                header('Location: productos.php');
                exit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[VetNexo][editar_producto] ' . $e->getMessage());
                $_SESSION['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo actualizar el producto. Intente nuevamente.';
                header('Location: editar_producto.php?id=' . (int)$producto_id);
                exit();
            }
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
            header('Location: productos.php');exit;
        }
    }
    
    // Obtener datos para los dropdowns
    // Categorías (excluyendo medicamentos - categoría_id = 1)
    $sql_categorias = "SELECT id, nombre FROM categorias_productos WHERE activo = 1 AND id != 1 ORDER BY nombre";
    $stmt_categorias = $db->query($sql_categorias);
    $categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);
    
    // Proveedores activos
    $sql_proveedores = "SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre";
    $stmt_proveedores = $db->query($sql_proveedores);
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener lista de productos (excluyendo medicamentos - categoría_id != 1)
    $sql_productos = "
        SELECT 
            p.*,
            c.nombre as categoria_nombre,
            pr.nombre as proveedor_nombre,
            CASE 
                WHEN p.stock_actual = 0 THEN 'critical'
                WHEN p.stock_actual <= p.stock_minimo THEN 'low'
                WHEN p.stock_actual <= (p.stock_minimo * 1.5) THEN 'warning'
                ELSE 'normal'
            END as estado_stock
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
        WHERE p.activo = 1 
        AND p.categoria_id != 1  -- EXCLUIR medicamentos
        ORDER BY estado_stock, p.nombre
    ";
    $stmt_productos = $db->query($sql_productos);
    $productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas de inventario
    $sql_stats = "
        SELECT 
            COUNT(*) as total_productos,
            COALESCE(SUM(p.stock_actual), 0) as total_stock,
            COALESCE(SUM(p.stock_actual * p.precio_compra), 0) as valor_inventario,
            SUM(CASE WHEN p.stock_actual <= p.stock_minimo THEN 1 ELSE 0 END) as productos_bajo_stock,
            SUM(CASE WHEN p.stock_actual = 0 THEN 1 ELSE 0 END) as productos_agotados,
            COALESCE(AVG(p.stock_actual), 0) as promedio_stock
        FROM productos p
        WHERE p.activo = 1 
        AND p.categoria_id != 1  -- EXCLUIR medicamentos
    ";
    $stmt_stats = $db->query($sql_stats);
    $estadisticas = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    // Productos con stock bajo (para alertas)
    $sql_bajo_stock = "
        SELECT p.id, p.codigo, p.nombre, p.stock_actual, p.stock_minimo, c.nombre as categoria
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        WHERE p.activo = 1 
        AND p.categoria_id != 1
        AND (p.stock_actual <= p.stock_minimo OR p.stock_actual = 0)
        ORDER BY p.stock_actual ASC
        LIMIT 10
    ";
    $stmt_bajo_stock = $db->query($sql_bajo_stock);
    $productos_bajo_stock = $stmt_bajo_stock->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en productos.php: " . $e->getMessage());
    $categorias = [];
    $proveedores = [];
    $productos = [];
    $productos_bajo_stock = [];
    $estadisticas = [
        'total_productos' => 0,
        'total_stock' => 0,
        'valor_inventario' => 0,
        'productos_bajo_stock' => 0,
        'productos_agotados' => 0,
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

$page_title = "Gestión de Productos - VetNexo";
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

        .btn-primary, .btn-secondary, .btn-success, .btn-warning {
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

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--accent-dark));
            color: var(--white);
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

        /* Tabla de Productos */
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

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-category {
            background: var(--primary-soft);
            color: var(--primary-dark);
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

        .badge-iva {
            background: #cce5ff;
            color: #004085;
        }

        .badge-exento {
            background: var(--gray);
            color: var(--text-light);
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
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
            max-width: 600px;
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
                        <i class='bx bx-package'></i>
                        Gestión de Productos
                    </h1>
                    <p>
                        <i class='bx bx-box'></i>
                        Control de inventario y productos
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
                        <div class="stat-number"><?php echo number_format($estadisticas['total_productos']); ?></div>
                        <div class="stat-label">Total Productos</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-cube'></i>
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
                        <div class="stat-number"><?php echo $estadisticas['productos_bajo_stock']; ?></div>
                        <div class="stat-label">Stock Bajo</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-error-alt'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['productos_agotados']; ?></div>
                        <div class="stat-label">Agotados</div>
                    </div>
                </div>
            </div>

            <!-- Alertas de Stock Bajo -->
            <?php if (!empty($productos_bajo_stock)): ?>
            <div class="alerts-section fade-in">
                <div class="alerts-header">
                    <h3>
                        <i class='bx bx-error-circle'></i>
                        Alertas de Stock Bajo
                    </h3>
                </div>
                <div>
                    <?php foreach ($productos_bajo_stock as $alerta): ?>
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
                    <input type="text" id="searchInput" placeholder="Buscar productos por código, nombre, categoría...">
                </div>
                <div class="actions-buttons">
                    <button class="btn-warning btn-sm" onclick="openModal('modalAjustarInventario')">
                        <i class='bx bx-transfer-alt'></i>
                        Ajustar Inventario
                    </button>
                    <button class="btn-primary btn-sm" onclick="openModal('modalNuevoProducto')">
                        <i class='bx bx-plus'></i>
                        Nuevo Producto
                    </button>
                </div>
            </div>

            <!-- Tabla de Productos -->
            <div class="table-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-list-ul'></i>
                        Lista de Productos
                    </h3>
                    <div class="pagination-info">
                        Total: <?php echo count($productos); ?> productos
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="productosTable">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Stock</th>
                                <th>Precio Venta</th>
                                <th>Precio Compra</th>
                                <th>Proveedor</th>
                                <th>Ubicación</th>
                                <th>IVA</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($productos)): ?>
                                <?php foreach ($productos as $producto): ?>
                                    <tr class="fade-in">
                                        <td>
                                            <strong><?php echo htmlspecialchars($producto['codigo']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                            <?php if (!empty($producto['descripcion'])): ?>
                                                <small style="color: var(--text-light);"><?php echo htmlspecialchars(substr($producto['descripcion'], 0, 50)) . (strlen($producto['descripcion']) > 50 ? '...' : ''); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-category"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo $producto['stock_actual']; ?> / <?php echo $producto['stock_minimo']; ?></div>
                                            <span class="badge badge-stock-<?php echo $producto['estado_stock']; ?>">
                                                <?php 
                                                switch($producto['estado_stock']) {
                                                    case 'critical': echo 'AGOTADO'; break;
                                                    case 'low': echo 'BAJO'; break;
                                                    case 'warning': echo 'ALERTA'; break;
                                                    default: echo 'NORMAL';
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong style="color: var(--success);">$<?php echo number_format($producto['precio_venta'], 2); ?></strong><small class="precio-lista-bs" style="display:block;color:#64748b">Bs. <?php echo $tasa_bs > 0 ? number_format(((float)$producto['precio_venta']) * $tasa_bs, 2, ',', '.') : '—'; ?></small>
                                        </td>
                                        <td>
                                            <span style="color: var(--text-light);">$<?php echo number_format($producto['precio_compra'], 2); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($producto['proveedor_nombre'] ?? 'No asignado'); ?></td>
                                        <td><?php echo htmlspecialchars($producto['ubicacion'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($producto['aplica_iva'] == 1): ?>
                                                <span class="badge badge-iva"><?php echo number_format($producto['porcentaje_iva'], 2); ?>%</span>
                                            <?php else: ?>
                                                <span class="badge badge-exento">EXENTO</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-primary" onclick="editarProducto(<?php echo $producto['id']; ?>)" title="Editar Producto">
                                                    <i class='bx bx-edit'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-success" onclick="ajustarStock(<?php echo $producto['id']; ?>, '<?php echo htmlspecialchars($producto['nombre']); ?>', <?php echo $producto['stock_actual']; ?>)" title="Ajustar Stock">
                                                    <i class='bx bx-transfer-alt'></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de desactivar este producto?');"><?php echo av_csrf_field(); ?>
                                                    <input type="hidden" name="producto_id" value="<?php echo $producto['id']; ?>">
                                                    <button type="submit" name="eliminar_producto" class="btn-icon btn-icon-danger" title="Desactivar Producto">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class='bx bx-package'></i>
                                            <h3>No hay productos registrados</h3>
                                            <p>Comienza agregando un nuevo producto</p>
                                            <button class="btn-primary btn-sm" onclick="openModal('modalNuevoProducto')" style="display: inline-flex;">
                                                <i class='bx bx-plus'></i>
                                                Agregar Producto
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

    <!-- Modal Nuevo Producto -->
    <div class="modal" id="modalNuevoProducto">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class='bx bx-plus'></i>
                    Nuevo Producto
                </h3>
                <button class="modal-close" onclick="closeModal('modalNuevoProducto')">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <form method="POST" id="formNuevoProducto"><?php echo av_csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Código *</label>
                            <input type="text" class="form-input" name="codigo" required 
                                   placeholder="Ej: PROD-001" pattern="[A-Za-z0-9\-]+"
                                   title="Use letras, números y guiones">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nombre *</label>
                            <input type="text" class="form-input" name="nombre" required 
                                   placeholder="Nombre del producto">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Categoría *</label>
                            <select class="form-select" name="categoria_id" required>
                                <option value="">Seleccionar categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?php echo $categoria['id']; ?>"><?php echo htmlspecialchars($categoria['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Proveedor</label>
                            <select class="form-select" name="proveedor_id">
                                <option value="">Sin proveedor</option>
                                <?php foreach ($proveedores as $proveedor): ?>
                                    <option value="<?php echo $proveedor['id']; ?>"><?php echo htmlspecialchars($proveedor['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-textarea" name="descripcion" placeholder="Descripción detallada del producto..."></textarea>
                    </div>
                    
                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label">Stock Actual</label>
                            <input type="number" class="form-input" name="stock_actual" value="0" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock Mínimo</label>
                            <input type="number" class="form-input" name="stock_minimo" value="5" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock Máximo</label>
                            <input type="number" class="form-input" name="stock_maximo" value="100" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Precio Compra ($)</label>
                            <input type="number" class="form-input" name="precio_compra" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Precio Venta ($)</label>
                            <input type="number" class="form-input" name="precio_venta" step="0.01" min="0" value="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Ubicación</label>
                            <input type="text" class="form-input" name="ubicacion" placeholder="Ej: Estante A - Nivel 2">
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="aplica_iva" name="aplica_iva" value="1" checked>
                                <label for="aplica_iva">Aplica IVA</label>
                            </div>
                            <div style="margin-top: 0.5rem;">
                                <label class="form-label">Porcentaje IVA (%)</label>
                                <input type="number" class="form-input" id="porcentaje_iva" name="porcentaje_iva" step="0.01" min="0" max="100" value="16.00">
                            </div>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="activo" name="activo" value="1" checked>
                        <label for="activo">Producto activo</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary btn-sm" onclick="closeModal('modalNuevoProducto')">Cancelar</button>
                    <button type="submit" name="nuevo_producto" class="btn-primary btn-sm">Crear Producto</button>
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
                    
                    <div class="form-group">
                        <label class="form-label">Producto *</label>
                        <select class="form-select" id="producto_ajuste" name="producto_id" required onchange="cargarInfoProducto()">
                            <option value="">Seleccionar producto</option>
                            <?php foreach ($productos as $producto): ?>
                                <?php if ($producto['activo'] ?? 1): ?>
                                    <option value="<?php echo $producto['id']; ?>" 
                                            data-stock="<?php echo $producto['stock_actual']; ?>"
                                            data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                        <?php echo htmlspecialchars($producto['codigo']); ?> - <?php echo htmlspecialchars($producto['nombre']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="infoProducto" class="stock-info" style="display: none;">
                        <div><strong id="nombreProducto"></strong></div>
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
                            <option value="caducado">Producto caducado</option>
                            <option value="danado">Producto dañado</option>
                            <option value="conteo">Ajuste por conteo físico</option>
                            <option value="otros">Otros</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-textarea" name="observaciones" placeholder="Observaciones adicionales..."></textarea>
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
        // Variables globales
        let currentProductoId = null;

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de contadores
            animateStats();
            
            // Animación de filas de tabla
            animateTableRows();
            
            // Configurar búsqueda en tiempo real
            setupSearch();
            
            // Configurar validación de formulario de ajuste
            setupFormValidation();
            
            // Control del checkbox de IVA
            setupIvaControl();
            
            // Crear partículas de fondo (opcional)
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
                        if (text.includes('$')) {
                            stat.textContent = '$' + current.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        } else {
                            stat.textContent = Math.floor(current).toLocaleString('es-ES');
                        }
                    }, 30);
                }
            });
        }

        // Animar filas de tabla
        function animateTableRows() {
            const rows = document.querySelectorAll('.data-table tbody tr');
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

        // Configurar búsqueda en tiempo real
        function setupSearch() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#productosTable tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(filter) ? '' : 'none';
                    });
                });
            }
        }

        // Configurar validación de formulario de ajuste
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
                    
                    if (tipo === 'ajuste' && cantidad < 0) {
                        e.preventDefault();
                        showNotification('Para ajustes de stock, la cantidad debe ser el nuevo valor del inventario.', 'error');
                    }
                });
            }
        }

        // Control del checkbox de IVA
        function setupIvaControl() {
            const checkIva = document.getElementById('aplica_iva');
            const porcentajeIva = document.getElementById('porcentaje_iva');
            
            if (checkIva && porcentajeIva) {
                // Estado inicial
                if (!checkIva.checked) {
                    porcentajeIva.disabled = true;
                    porcentajeIva.value = '0.00';
                }
                
                checkIva.addEventListener('change', function() {
                    if (this.checked) {
                        porcentajeIva.disabled = false;
                        porcentajeIva.value = '16.00';
                    } else {
                        porcentajeIva.disabled = true;
                        porcentajeIva.value = '0.00';
                    }
                });
            }
        }

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
                    background: rgba(76, 175, 80, 0.05);
                    border-radius: 50%;
                    animation: float ${Math.random() * 6 + 4}s ease-in-out infinite;
                    animation-delay: ${Math.random() * 5}s;
                `;
                particlesContainer.appendChild(particle);
            }
            
            document.body.appendChild(particlesContainer);
        }

        // Funciones para modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Cargar información del producto en el modal de ajuste
        function cargarInfoProducto() {
            const select = document.getElementById('producto_ajuste');
            const selectedOption = select.options[select.selectedIndex];
            const infoDiv = document.getElementById('infoProducto');
            
            if (selectedOption.value) {
                document.getElementById('nombreProducto').textContent = selectedOption.getAttribute('data-nombre');
                document.getElementById('stockActual').textContent = selectedOption.getAttribute('data-stock');
                document.getElementById('ajuste_producto_id').value = selectedOption.value;
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        }

        // Función para ajustar stock de un producto específico
        function ajustarStock(productoId, productoNombre, stockActual) {
            const select = document.getElementById('producto_ajuste');
            
            // Buscar y seleccionar la opción correcta
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value == productoId) {
                    select.selectedIndex = i;
                    break;
                }
            }
            
            document.getElementById('nombreProducto').textContent = productoNombre;
            document.getElementById('stockActual').textContent = stockActual;
            document.getElementById('ajuste_producto_id').value = productoId;
            document.getElementById('infoProducto').style.display = 'block';
            
            openModal('modalAjustarInventario');
        }

        // Función para editar producto (redirige a página de edición)
        function editarProducto(id) {
            window.location.href = 'editar_producto.php?id=' + id;
        }

        // Sistema de notificaciones
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