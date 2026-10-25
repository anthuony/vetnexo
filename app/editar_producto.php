<?php
// editar_producto.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';
require_once 'config/bcv.php';

// Verificar si el usuario está logueado
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

// Verificar si se pasó un ID de producto
if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No se especificó el producto a editar.";
    header('Location: productos.php');
    exit();
}

$producto_id = $_GET['id'];

// Obtener datos del producto a editar
try {
    $sql_producto = "SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre 
                     FROM productos p 
                     LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
                     LEFT JOIN proveedores pr ON p.proveedor_id = pr.id 
                     WHERE p.id = ?";
    $stmt_producto = $pdo->prepare($sql_producto);
    $stmt_producto->execute([$producto_id]);
    $producto = $stmt_producto->fetch();
    
    if (!$producto) {
        $_SESSION['error'] = "Producto no encontrado.";
        header('Location: productos.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("[VetNexo][editar_producto] " . $e->getMessage()); die("No se pudo cargar el producto.");
}

// Obtener datos para los dropdowns (EXCLUYENDO categoría de medicamentos)
try {
    // Categorías (excluyendo medicamentos)
    $sql_categorias = "SELECT id, nombre FROM categorias_productos WHERE activo = 1 AND id != 1 ORDER BY nombre";
    $stmt_categorias = $pdo->query($sql_categorias);
    $categorias = $stmt_categorias->fetchAll();
    
    // Proveedores
    $sql_proveedores = "SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre";
    $stmt_proveedores = $pdo->query($sql_proveedores);
    $proveedores = $stmt_proveedores->fetchAll();
    
} catch (PDOException $e) {
    $categorias = [];
    $proveedores = [];
}

// Obtener datos del usuario de la sesión
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
$bcv_info = bcv_get_rate();
$tasa_bs = (float)($bcv_info['rate'] ?? 0);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Producto - VetNexo</title>
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

        /* Header */
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

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-very-light);
            color: var(--primary-dark);
            border: 2px solid var(--primary);
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-back:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.2);
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

        /* Contenido del Formulario */
        .form-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            border: 1px solid var(--border);
            animation: fadeIn 0.5s ease;
        }

        .form-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .form-header h2 {
            font-size: 1.5rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-header .product-info {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
            background: white;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input {
            width: auto;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--white);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #D32F2F;
        }

        .info-box {
            background: var(--primary-very-light);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .info-box h4 {
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box p {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .info-box .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .info-box .info-item strong {
            color: var(--text-dark);
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border: 1px solid transparent;
            animation: fadeIn 0.5s ease;
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

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
                padding: 1.5rem;
            }
            
            .header-right {
                flex-direction: column;
                width: 100%;
            }
            
            .user-profile {
                align-self: center;
            }
            
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
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
                    <h1><i class='bx bx-edit'></i> Editar Producto</h1>
                    <p>Actualizar información del producto - VetNexo</p>
                </div>
                
                <div class="header-right">
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($usuario_nombre, 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <h3><?php echo htmlspecialchars($usuario_nombre); ?></h3>
                            <span><i class='bx bx-user'></i> Usuario</span>
                        </div>
                    </div>
                    <a href="productos.php" class="btn-back">
                        <i class='bx bx-arrow-back'></i>
                        Volver a Productos
                    </a>
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

            <!-- Información del Producto -->
            <div class="info-box fade-in">
                <h4><i class='bx bx-info-circle'></i> Información Actual del Producto</h4>
                <div class="info-item">
                    <strong>Código:</strong>
                    <span><?php echo htmlspecialchars($producto['codigo']); ?></span>
                </div>
                <div class="info-item">
                    <strong>Nombre:</strong>
                    <span><?php echo htmlspecialchars($producto['nombre']); ?></span>
                </div>
                <div class="info-item">
                    <strong>Categoría:</strong>
                    <span><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></span>
                </div>
                <div class="info-item">
                    <strong>Stock Actual:</strong>
                    <span><?php echo $producto['stock_actual']; ?> unidades</span>
                </div>
                <div class="info-item">
                    <strong>Estado:</strong>
                    <span><?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?></span>
                </div>
            </div>

            <!-- Formulario de Edición -->
            <div class="form-container fade-in">
                <div class="form-header">
                    <h2><i class='bx bx-package'></i> Actualizar Información del Producto</h2>
                    <div class="product-info">
                        ID: <?php echo $producto['id']; ?> | Última actualización: <?php echo date('d/m/Y H:i', strtotime($producto['fecha_actualizacion'] ?? $producto['fecha_creacion'])); ?>
                    </div>
                </div>

                <form method="POST" action="productos.php"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="producto_id" value="<?php echo $producto['id']; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="codigo">Código del Producto *</label>
                            <input type="text" id="codigo" name="codigo" required 
                                   placeholder="Ej: PROD-00125" pattern="[A-Za-z0-9\-]+"
                                   title="Use letras, números y guiones"
                                   value="<?php echo htmlspecialchars($producto['codigo']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="nombre">Nombre del Producto *</label>
                            <input type="text" id="nombre" name="nombre" required 
                                   placeholder="Nombre completo del producto"
                                   value="<?php echo htmlspecialchars($producto['nombre']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="categoria_id">Categoría *</label>
                            <select id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccione una categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?php echo $categoria['id']; ?>" 
                                        <?php echo $producto['categoria_id'] == $categoria['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="proveedor_id">Proveedor</label>
                            <select id="proveedor_id" name="proveedor_id">
                                <option value="">Seleccione un proveedor</option>
                                <?php foreach ($proveedores as $proveedor): ?>
                                    <option value="<?php echo $proveedor['id']; ?>" 
                                        <?php echo $producto['proveedor_id'] == $proveedor['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="descripcion">Descripción</label>
                        <textarea id="descripcion" name="descripcion" placeholder="Descripción detallada del producto..."><?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-row-3">
                        <div class="form-group">
                            <label for="stock_actual">Stock Actual</label>
                            <input type="number" id="stock_actual" name="stock_actual" 
                                   value="<?php echo $producto['stock_actual']; ?>" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="stock_minimo">Stock Mínimo</label>
                            <input type="number" id="stock_minimo" name="stock_minimo" 
                                   value="<?php echo $producto['stock_minimo']; ?>" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="stock_maximo">Stock Máximo</label>
                            <input type="number" id="stock_maximo" name="stock_maximo" 
                                   value="<?php echo $producto['stock_maximo']; ?>" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="precio_compra">Precio de Compra ($)</label>
                            <input type="number" id="precio_compra" name="precio_compra" step="0.01" min="0" 
                                   placeholder="0.00"
                                   value="<?php echo $producto['precio_compra']; ?>">
                        </div>
                        <div class="form-group">
                            <label for="precio_venta">Precio de Venta ($)</label>
                            <input type="number" id="precio_venta" name="precio_venta" step="0.01" min="0" 
                                   placeholder="0.00"
                                   value="<?php echo $producto['precio_venta']; ?>">
                            <small id="precioVentaBs" style="display:block;margin-top:6px;color:#64748b;">
                                <?php echo $tasa_bs > 0 ? '≈ Bs. ' . number_format(((float)$producto['precio_venta']) * $tasa_bs, 2, ',', '.') : 'Tasa BCV no disponible'; ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="ubicacion">Ubicación/Almacén</label>
                            <input type="text" id="ubicacion" name="ubicacion" 
                                   placeholder="Ej: Estante A, Nivel 2"
                                   value="<?php echo htmlspecialchars($producto['ubicacion'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="activo" name="activo" value="1" 
                                       <?php echo $producto['activo'] ? 'checked' : ''; ?>>
                                <label for="activo">Producto Activo</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <a href="productos.php" class="btn btn-outline">
                            <i class='bx bx-x'></i>
                            Cancelar
                        </a>
                        <button type="submit" name="editar_producto" class="btn btn-primary">
                            <i class='bx bx-save'></i>
                            Guardar Cambios
                        </button>
                    </div>
                <div class="form-group"><label><input type="checkbox" name="aplica_iva" <?=!empty($producto['aplica_iva'])?'checked':''?>> Aplicar IVA</label><label>Porcentaje IVA <input type="number" name="porcentaje_iva" min="0" max="100" step="0.01" value="<?=e($producto['porcentaje_iva']??16)?>"></label><label><input type="checkbox" name="destacado" <?=!empty($producto['destacado'])?'checked':''?>> Destacar en tienda</label><label><input type="checkbox" name="en_oferta" <?=!empty($producto['en_oferta'])?'checked':''?>> Mostrar en ofertas</label></div></form>
            </div>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Validar que el stock mínimo no sea mayor al máximo
            const stockMinimo = document.getElementById('stock_minimo');
            const stockMaximo = document.getElementById('stock_maximo');
            
            function validarStock() {
                const min = parseInt(stockMinimo.value);
                const max = parseInt(stockMaximo.value);
                
                if (min > max) {
                    alert('El stock mínimo no puede ser mayor que el stock máximo.');
                    stockMinimo.value = max;
                    stockMinimo.focus();
                    return false;
                }
                return true;
            }
            
            stockMinimo.addEventListener('change', validarStock);
            stockMaximo.addEventListener('change', validarStock);
            
            // Los precios pueden ser definidos libremente por el responsable del inventario.
            // La validación de formato y valores negativos se realiza también en el servidor.
            
            // Animación de entrada
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>

<script>
(function(){
 const input=document.getElementById('precio_venta');
 const out=document.getElementById('precioVentaBs');
 const tasa=<?php echo json_encode($tasa_bs); ?>;
 if(!input||!out||!tasa) return;
 const nf=new Intl.NumberFormat('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2});
 function sync(){ const usd=parseFloat(input.value||0); out.textContent='≈ Bs. '+nf.format(usd*tasa)+' · Tasa BCV '+nf.format(tasa); }
 input.addEventListener('input',sync); sync();
})();
</script>
</body>
</html>