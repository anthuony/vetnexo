<?php
// editar_medicamento.php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Verificar si se proporcionó un ID
if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No se especificó el medicamento a editar.";
    header('Location: medicamentos.php');
    exit();
}

$medicamento_id = intval($_GET['id']);

// Conexión centralizada para hosting
try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    die('Error de conexión a la base de datos. Revise config/env.php.');
}

// Obtener datos del medicamento
try {
    $sql_medicamento = "
        SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
        WHERE p.id = ? AND p.categoria_id = 1
    ";
    $stmt_medicamento = $pdo->prepare($sql_medicamento);
    $stmt_medicamento->execute([$medicamento_id]);
    $medicamento = $stmt_medicamento->fetch();
    
    if (!$medicamento) {
        $_SESSION['error'] = "Medicamento no encontrado.";
        header('Location: medicamentos.php');
        exit();
    }
    
    // Obtener presentaciones del medicamento
    $sql_presentaciones = "
        SELECT * FROM medicamento_presentaciones 
        WHERE medicamento_id = ? AND activo = 1
        ORDER BY rango_peso_min ASC
    ";
    $stmt_presentaciones = $pdo->prepare($sql_presentaciones);
    $stmt_presentaciones->execute([$medicamento_id]);
    $presentaciones = $stmt_presentaciones->fetchAll();
    
} catch (PDOException $e) {
    error_log("[VetNexo][editar_medicamento] " . $e->getMessage()); die("No se pudo cargar el medicamento.");
}

// Obtener datos para dropdowns
try {
    // Proveedores
    $sql_proveedores = "SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre";
    $stmt_proveedores = $pdo->query($sql_proveedores);
    $proveedores = $stmt_proveedores->fetchAll();
    
} catch (PDOException $e) {
    $proveedores = [];
}

// Procesar actualización de medicamento
if (isset($_POST['actualizar_medicamento'])) {
    $codigo = $_POST['codigo'];
    $nombre = $_POST['nombre'];
    $principio_activo = $_POST['principio_activo'];
    $descripcion = $_POST['descripcion'];
    $tipo_medicamento = $_POST['tipo_medicamento'];
    $via_administracion = $_POST['via_administracion'];
    $fabricante = $_POST['fabricante'];
    $proveedor_id = !empty($_POST['proveedor_id'])?$_POST['proveedor_id']:null;
    $ubicacion = $_POST['ubicacion'];
    $activo = isset($_POST['activo']) ? 1 : 0;
    $con_presentaciones = isset($_POST['con_presentaciones']) ? 1 : 0;
    
    try {
        $pdo->beginTransaction();
        // Actualizar información general del medicamento
        $sql_actualizar = "UPDATE productos SET 
                            codigo = ?, 
                            nombre = ?, 
                            descripcion = ?, 
                            principio_activo = ?,
                            tipo_medicamento = ?,
                            via_administracion = ?,
                            fabricante = ?,
                            proveedor_id = ?, 
                            ubicacion = ?, 
                            activo = ?,
                            es_medicamento_con_presentaciones = ?,
                            fecha_actualizacion = CURRENT_TIMESTAMP 
                          WHERE id = ?";
        
        $stmt_actualizar = $pdo->prepare($sql_actualizar);
        $stmt_actualizar->execute([
            $codigo, $nombre, $descripcion,
            $principio_activo, $tipo_medicamento, $via_administracion, $fabricante,
            $proveedor_id, $ubicacion, $activo, $con_presentaciones, $medicamento_id
        ]);
        
        // Si no tiene presentaciones, actualizar stock y precios generales
        if (!$con_presentaciones) {
            $stock_actual = $_POST['stock_actual_simple'] ?? 0;
            $stock_minimo = $_POST['stock_minimo_simple'] ?? 5;
            $stock_maximo = $_POST['stock_maximo_simple'] ?? 100;
            $precio_compra = $_POST['precio_compra_simple'] ?? 0;
            $precio_venta = $_POST['precio_venta_simple'] ?? 0;
            
            $sql_update_stock = "UPDATE productos SET 
                                stock_actual = ?,
                                stock_minimo = ?,
                                stock_maximo = ?,
                                precio_compra = ?,
                                precio_venta = ?
                              WHERE id = ?";
            
            $stmt_update_stock = $pdo->prepare($sql_update_stock);
            $stmt_update_stock->execute([
                $stock_actual, $stock_minimo, $stock_maximo,
                $precio_compra, $precio_venta, $medicamento_id
            ]);
            
            // Desactivar todas las presentaciones existentes
            $sql_desactivar_presentaciones = "UPDATE medicamento_presentaciones SET activo = 0 WHERE medicamento_id = ?";
            $stmt_desactivar_presentaciones = $pdo->prepare($sql_desactivar_presentaciones);
            $stmt_desactivar_presentaciones->execute([$medicamento_id]);
        } else {
            // Para medicamentos con presentaciones, mantener stock general en 0
            $sql_reset_stock = "UPDATE productos SET 
                                stock_actual = 0,
                                stock_minimo = 0,
                                stock_maximo = 0,
                                precio_compra = 0,
                                precio_venta = 0
                              WHERE id = ?";
            
            $stmt_reset_stock = $pdo->prepare($sql_reset_stock);
            $stmt_reset_stock->execute([$medicamento_id]);
            
            // Procesar presentaciones
            if (isset($_POST['presentaciones'])) {
                $presentaciones_post = $_POST['presentaciones'];
                
                // Primero, marcar todas las presentaciones existentes como inactivas
                $sql_desactivar = "UPDATE medicamento_presentaciones SET activo = 0 WHERE medicamento_id = ?";
                $stmt_desactivar = $pdo->prepare($sql_desactivar);
                $stmt_desactivar->execute([$medicamento_id]);
                
                foreach ($presentaciones_post as $presentacion_id => $presentacion_data) {
                    if (!empty($presentacion_data['nombre_presentacion'])) {
                        // Verificar si es una presentación existente (ID numérico) o nueva (string como "new_X")
                        if (is_numeric($presentacion_id) && $presentacion_id > 0) {
                            // Actualizar presentación existente
                            $sql_update_presentacion = "UPDATE medicamento_presentaciones SET 
                                nombre_presentacion = ?,
                                peso_animal = ?,
                                rango_peso_min = ?,
                                rango_peso_max = ?,
                                dosis_recomendada = ?,
                                concentracion = ?,
                                unidad_medida = ?,
                                precio_venta = ?,
                                precio_compra = ?,
                                stock_actual = ?,
                                stock_minimo = ?,
                                stock_maximo = ?,
                                codigo_barras = ?,
                                activo = 1
                            WHERE id = ? AND medicamento_id = ?";
                            
                            $stmt_update = $pdo->prepare($sql_update_presentacion);
                            $stmt_update->execute([
                                $presentacion_data['nombre_presentacion'],
                                $presentacion_data['peso_animal'],
                                $presentacion_data['rango_peso_min'] ?? null,
                                $presentacion_data['rango_peso_max'] ?? null,
                                $presentacion_data['dosis_recomendada'] ?? null,
                                $presentacion_data['concentracion'] ?? null,
                                $presentacion_data['unidad_medida'] ?? null,
                                $presentacion_data['precio_venta'] ?? 0,
                                $presentacion_data['precio_compra'] ?? 0,
                                $presentacion_data['stock_actual'] ?? 0,
                                $presentacion_data['stock_minimo'] ?? 5,
                                $presentacion_data['stock_maximo'] ?? 100,
                                $presentacion_data['codigo_barras'] ?? null,
                                $presentacion_id,
                                $medicamento_id
                            ]);
                        } else {
                            // Insertar nueva presentación
                            $sql_insert_presentacion = "INSERT INTO medicamento_presentaciones (
                                medicamento_id, nombre_presentacion, peso_animal,
                                rango_peso_min, rango_peso_max, dosis_recomendada, 
                                concentracion, unidad_medida, precio_venta, precio_compra, 
                                stock_actual, stock_minimo, stock_maximo, codigo_barras, activo
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            
                            $stmt_insert = $pdo->prepare($sql_insert_presentacion);
                            $stmt_insert->execute([
                                $medicamento_id,
                                $presentacion_data['nombre_presentacion'],
                                $presentacion_data['peso_animal'],
                                $presentacion_data['rango_peso_min'] ?? null,
                                $presentacion_data['rango_peso_max'] ?? null,
                                $presentacion_data['dosis_recomendada'] ?? null,
                                $presentacion_data['concentracion'] ?? null,
                                $presentacion_data['unidad_medida'] ?? null,
                                $presentacion_data['precio_venta'] ?? 0,
                                $presentacion_data['precio_compra'] ?? 0,
                                $presentacion_data['stock_actual'] ?? 0,
                                $presentacion_data['stock_minimo'] ?? 5,
                                $presentacion_data['stock_maximo'] ?? 100,
                                $presentacion_data['codigo_barras'] ?? null,
                                1
                            ]);
                        }
                    }
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Medicamento actualizado correctamente.";
        header('Location: medicamentos.php');
        exit();
        
    } catch (PDOException $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $error = "Error al actualizar el medicamento: " . $e->getMessage();
    }
}

// Obtener datos del usuario de la sesión
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Medicamento - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --primary-light: #81C784;
            --primary-very-light: #E8F5E9;
            --accent: #FF9800;
            --accent-dark: #F57C00;
            --yellow: #FFC107;
            --red: #F44336;
            --red-dark: #D32F2F;
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --border: #E0E0E0;
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }

        body {
            background: var(--background);
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: var(--text-dark);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            background: var(--background);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .welcome h1 {
            font-size: 2rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .welcome p {
            color: var(--text-light);
            font-size: 1.1rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background: var(--primary-very-light);
            border: 1px solid var(--primary-light);
            color: var(--primary-dark);
        }

        .alert-error {
            background: #FFEBEE;
            border: 1px solid #FFCDD2;
            color: #C62828;
        }

        .alert-warning {
            background: #FFF8E1;
            border: 1px solid #FFECB3;
            color: #FF8F00;
        }

        /* Formulario */
        .form-container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 2rem;
            border: 1px solid var(--border);
            max-width: 900px;
            margin: 0 auto;
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
            border-radius: 8px;
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

        .checkbox-group input {
            width: auto;
        }

        /* Botones */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
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

        .btn-warning {
            background: var(--accent);
            color: white;
        }

        .btn-warning:hover {
            background: var(--accent-dark);
        }

        .btn-danger {
            background: var(--red);
            color: white;
        }

        .btn-danger:hover {
            background: var(--red-dark);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.9rem;
        }

        /* Presentaciones Styles */
        .presentaciones-container {
            margin: 1.5rem 0;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
        }

        .presentacion-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .presentacion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .presentacion-title {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .btn-remove-presentacion {
            background: var(--red);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 0.8rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
        }

        /* Estilos para mostrar/ocultar secciones */
        .hidden {
            display: none !important;
        }

        .fieldset {
            border: 1px solid var(--border);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
        }

        .legend {
            font-weight: 600;
            color: var(--primary-dark);
            padding: 0 0.5rem;
            font-size: 1.1rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Editar Medicamento</h1>
                    <p>Modificar información del medicamento</p>
                </div>
                <a href="medicamentos.php" class="btn btn-outline">
                    <i class='bx bx-arrow-back'></i>
                    Volver a Medicamentos
                </a>
            </div>

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

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST" id="formEditarMedicamento"><?php echo av_csrf_field(); ?>
                    <!-- Información General -->
                    <fieldset class="fieldset">
                        <legend class="legend">Información General</legend>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="codigo">Código del Medicamento *</label>
                                <input type="text" id="codigo" name="codigo" required 
                                       value="<?php echo htmlspecialchars($medicamento['codigo']); ?>"
                                       placeholder="Ej: MED-00125" pattern="[A-Za-z0-9\-]+"
                                       title="Use letras, números y guiones">
                            </div>
                            <div class="form-group">
                                <label for="nombre">Nombre del Medicamento *</label>
                                <input type="text" id="nombre" name="nombre" required 
                                       value="<?php echo htmlspecialchars($medicamento['nombre']); ?>"
                                       placeholder="Nombre comercial del medicamento">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="principio_activo">Principio Activo *</label>
                                <input type="text" id="principio_activo" name="principio_activo" required 
                                       value="<?php echo htmlspecialchars($medicamento['principio_activo'] ?? ''); ?>"
                                       placeholder="Ej: Enrofloxacina, Carprofeno, etc.">
                            </div>
                            <div class="form-group">
                                <label for="tipo_medicamento">Tipo de Medicamento</label>
                                <select id="tipo_medicamento" name="tipo_medicamento">
                                    <option value="">Seleccionar tipo</option>
                                    <option value="antibiotico" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'antibiotico' ? 'selected' : ''; ?>>Antibiótico</option>
                                    <option value="analgesico" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'analgesico' ? 'selected' : ''; ?>>Analgésico</option>
                                    <option value="antiparasitario" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'antiparasitario' ? 'selected' : ''; ?>>Antiparasitario</option>
                                    <option value="vacuna" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'vacuna' ? 'selected' : ''; ?>>Vacuna</option>
                                    <option value="antiinflamatorio" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'antiinflamatorio' ? 'selected' : ''; ?>>Antiinflamatorio</option>
                                    <option value="anestesico" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'anestesico' ? 'selected' : ''; ?>>Anestésico</option>
                                    <option value="suplemento" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'suplemento' ? 'selected' : ''; ?>>Suplemento</option>
                                    <option value="otros" <?php echo ($medicamento['tipo_medicamento'] ?? '') == 'otros' ? 'selected' : ''; ?>>Otros</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="via_administracion">Vía de Administración</label>
                                <select id="via_administracion" name="via_administracion">
                                    <option value="">Seleccionar vía</option>
                                    <option value="oral" <?php echo ($medicamento['via_administracion'] ?? '') == 'oral' ? 'selected' : ''; ?>>Oral</option>
                                    <option value="inyectable" <?php echo ($medicamento['via_administracion'] ?? '') == 'inyectable' ? 'selected' : ''; ?>>Inyectable</option>
                                    <option value="topico" <?php echo ($medicamento['via_administracion'] ?? '') == 'topico' ? 'selected' : ''; ?>>Tópico</option>
                                    <option value="oftalmico" <?php echo ($medicamento['via_administracion'] ?? '') == 'oftalmico' ? 'selected' : ''; ?>>Oftálmico</option>
                                    <option value="otico" <?php echo ($medicamento['via_administracion'] ?? '') == 'otico' ? 'selected' : ''; ?>>Ótico</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="fabricante">Fabricante/Laboratorio</label>
                                <input type="text" id="fabricante" name="fabricante" 
                                       value="<?php echo htmlspecialchars($medicamento['fabricante'] ?? ''); ?>"
                                       placeholder="Nombre del fabricante">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="descripcion">Descripción</label>
                            <textarea id="descripcion" name="descripcion" 
                                      placeholder="Descripción detallada del medicamento..."><?php echo htmlspecialchars($medicamento['descripcion'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="proveedor_id">Proveedor Principal</label>
                                <select id="proveedor_id" name="proveedor_id">
                                    <option value="">Seleccione un proveedor</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo $proveedor['id']; ?>" 
                                            <?php echo ($medicamento['proveedor_id'] == $proveedor['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="ubicacion">Ubicación Principal</label>
                                <input type="text" id="ubicacion" name="ubicacion" 
                                       value="<?php echo htmlspecialchars($medicamento['ubicacion'] ?? ''); ?>"
                                       placeholder="Ej: Estante A, Nivel 2">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="con_presentaciones" name="con_presentaciones" value="1" 
                                    <?php echo ($medicamento['es_medicamento_con_presentaciones'] == 1) ? 'checked' : ''; ?>
                                    onchange="togglePresentaciones()">
                                <label for="con_presentaciones">Este medicamento tiene diferentes presentaciones por peso</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="activo" name="activo" value="1" 
                                    <?php echo ($medicamento['activo'] == 1) ? 'checked' : ''; ?>>
                                <label for="activo">Medicamento Activo</label>
                            </div>
                        </div>
                    </fieldset>
                    
                    <!-- Sección para medicamento sin presentaciones -->
                    <div id="seccion_sin_presentaciones" class="<?php echo ($medicamento['es_medicamento_con_presentaciones'] == 1) ? 'hidden' : ''; ?>">
                        <fieldset class="fieldset">
                            <legend class="legend">Información de Stock y Precios</legend>
                            
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label for="stock_actual_simple">Stock Actual</label>
                                    <input type="number" id="stock_actual_simple" name="stock_actual_simple" 
                                           value="<?php echo $medicamento['stock_actual']; ?>" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="stock_minimo_simple">Stock Mínimo</label>
                                    <input type="number" id="stock_minimo_simple" name="stock_minimo_simple" 
                                           value="<?php echo $medicamento['stock_minimo']; ?>" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="stock_maximo_simple">Stock Máximo</label>
                                    <input type="number" id="stock_maximo_simple" name="stock_maximo_simple" 
                                           value="<?php echo $medicamento['stock_maximo']; ?>" min="0">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="precio_compra_simple">Precio de Compra ($)</label>
                                    <input type="number" id="precio_compra_simple" name="precio_compra_simple" 
                                           step="0.01" min="0" value="<?php echo $medicamento['precio_compra']; ?>">
                                </div>
                                <div class="form-group">
                                    <label for="precio_venta_simple">Precio de Venta ($)</label>
                                    <input type="number" id="precio_venta_simple" name="precio_venta_simple" 
                                           step="0.01" min="0" value="<?php echo $medicamento['precio_venta']; ?>">
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    
                    <!-- Sección para medicamento con presentaciones -->
                    <div id="seccion_con_presentaciones" class="<?php echo ($medicamento['es_medicamento_con_presentaciones'] == 0) ? 'hidden' : ''; ?>">
                        <fieldset class="fieldset">
                            <legend class="legend">
                                Presentaciones por Peso
                                <button type="button" class="btn btn-primary btn-sm" style="float: right; padding: 4px 12px;" onclick="agregarPresentacion()">
                                    <i class='bx bx-plus'></i> Agregar Presentación
                                </button>
                            </legend>
                            
                            <div id="presentaciones-container" class="presentaciones-container">
                                <!-- Presentaciones existentes -->
                                <?php if (!empty($presentaciones)): ?>
                                    <?php foreach ($presentaciones as $index => $presentacion): ?>
                                        <div class="presentacion-item" id="presentacion-<?php echo $presentacion['id']; ?>">
                                            <div class="presentacion-header">
                                                <div class="presentacion-title">Presentación <?php echo $index + 1; ?> (Existente)</div>
                                                <button type="button" class="btn-remove-presentacion" onclick="removerPresentacion(<?php echo $presentacion['id']; ?>)">
                                                    <i class='bx bx-trash'></i>
                                                </button>
                                            </div>
                                            
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Nombre de Presentación *</label>
                                                    <input type="text" name="presentaciones[<?php echo $presentacion['id']; ?>][nombre_presentacion]" required 
                                                           value="<?php echo htmlspecialchars($presentacion['nombre_presentacion']); ?>"
                                                           placeholder="Ej: Comprimidos 50mg, Suspensión 10ml">
                                                </div>
                                                <div class="form-group">
                                                    <label>Peso del Animal *</label>
                                                    <select name="presentaciones[<?php echo $presentacion['id']; ?>][peso_animal]" required>
                                                        <option value="">Seleccionar peso</option>
                                                        <option value="pequeño" <?php echo $presentacion['peso_animal'] == 'pequeño' ? 'selected' : ''; ?>>Pequeño (0-10 kg)</option>
                                                        <option value="mediano" <?php echo $presentacion['peso_animal'] == 'mediano' ? 'selected' : ''; ?>>Mediano (10-25 kg)</option>
                                                        <option value="grande" <?php echo $presentacion['peso_animal'] == 'grande' ? 'selected' : ''; ?>>Grande (25-40 kg)</option>
                                                        <option value="muy_grande" <?php echo $presentacion['peso_animal'] == 'muy_grande' ? 'selected' : ''; ?>>Muy Grande (+40 kg)</option>
                                                        <option value="cachorro" <?php echo $presentacion['peso_animal'] == 'cachorro' ? 'selected' : ''; ?>>Cachorro/Gatito</option>
                                                        <option value="adulto" <?php echo $presentacion['peso_animal'] == 'adulto' ? 'selected' : ''; ?>>Adulto</option>
                                                        <option value="senior" <?php echo $presentacion['peso_animal'] == 'senior' ? 'selected' : ''; ?>>Senior</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Rango Mínimo de Peso (kg)</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][rango_peso_min]" 
                                                           step="0.1" min="0" value="<?php echo $presentacion['rango_peso_min']; ?>" placeholder="0">
                                                </div>
                                                <div class="form-group">
                                                    <label>Rango Máximo de Peso (kg)</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][rango_peso_max]" 
                                                           step="0.1" min="0" value="<?php echo $presentacion['rango_peso_max']; ?>" placeholder="10">
                                                </div>
                                            </div>
                                            
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Dosis Recomendada</label>
                                                    <input type="text" name="presentaciones[<?php echo $presentacion['id']; ?>][dosis_recomendada]" 
                                                           value="<?php echo htmlspecialchars($presentacion['dosis_recomendada']); ?>"
                                                           placeholder="Ej: 1 comprimido cada 12h">
                                                </div>
                                                <div class="form-group">
                                                    <label>Concentración</label>
                                                    <input type="text" name="presentaciones[<?php echo $presentacion['id']; ?>][concentracion]" 
                                                           value="<?php echo htmlspecialchars($presentacion['concentracion']); ?>"
                                                           placeholder="Ej: 50mg/ml">
                                                </div>
                                            </div>
                                            
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Unidad de Medida</label>
                                                    <input type="text" name="presentaciones[<?php echo $presentacion['id']; ?>][unidad_medida]" 
                                                           value="<?php echo htmlspecialchars($presentacion['unidad_medida']); ?>"
                                                           placeholder="Ej: comprimidos, ml, mg">
                                                </div>
                                                <div class="form-group">
                                                    <label>Código de Barras</label>
                                                    <input type="text" name="presentaciones[<?php echo $presentacion['id']; ?>][codigo_barras]" 
                                                           value="<?php echo htmlspecialchars($presentacion['codigo_barras']); ?>"
                                                           placeholder="Código único de barras">
                                                </div>
                                            </div>
                                            
                                            <div class="form-row-3">
                                                <div class="form-group">
                                                    <label>Stock Actual</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][stock_actual]" 
                                                           value="<?php echo $presentacion['stock_actual']; ?>" min="0" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Stock Mínimo</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][stock_minimo]" 
                                                           value="<?php echo $presentacion['stock_minimo']; ?>" min="0" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Stock Máximo</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][stock_maximo]" 
                                                           value="<?php echo $presentacion['stock_maximo']; ?>" min="0" required>
                                                </div>
                                            </div>
                                            
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Precio de Compra ($)</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][precio_compra]" 
                                                           step="0.01" min="0" value="<?php echo $presentacion['precio_compra']; ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Precio de Venta ($)</label>
                                                    <input type="number" name="presentaciones[<?php echo $presentacion['id']; ?>][precio_venta]" 
                                                           step="0.01" min="0" value="<?php echo $presentacion['precio_venta']; ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <!-- Las nuevas presentaciones se agregarán aquí dinámicamente -->
                            </div>
                        </fieldset>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="button" class="btn btn-outline" onclick="window.location.href='medicamentos.php'">
                            Cancelar
                        </button>
                        <button type="submit" name="actualizar_medicamento" class="btn btn-primary">
                            <i class='bx bx-save'></i>
                            Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Contador para nuevas presentaciones
        let nuevaPresentacionCounter = 0;
        
        // Toggle entre presentaciones y medicamento simple
        function togglePresentaciones() {
            const conPresentaciones = document.getElementById('con_presentaciones').checked;
            const seccionSin = document.getElementById('seccion_sin_presentaciones');
            const seccionCon = document.getElementById('seccion_con_presentaciones');
            
            if (conPresentaciones) {
                seccionSin.classList.add('hidden');
                seccionCon.classList.remove('hidden');
            } else {
                seccionSin.classList.remove('hidden');
                seccionCon.classList.add('hidden');
            }
        }

        // Agregar nueva presentación
        function agregarPresentacion() {
            nuevaPresentacionCounter++;
            const container = document.getElementById('presentaciones-container');
            
            const presentacionHTML = `
                <div class="presentacion-item" id="nueva-presentacion-${nuevaPresentacionCounter}">
                    <div class="presentacion-header">
                        <div class="presentacion-title">Nueva Presentación</div>
                        <button type="button" class="btn-remove-presentacion" onclick="removerNuevaPresentacion(${nuevaPresentacionCounter})">
                            <i class='bx bx-trash'></i>
                        </button>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre de Presentación *</label>
                            <input type="text" name="presentaciones[new_${nuevaPresentacionCounter}][nombre_presentacion]" required 
                                   placeholder="Ej: Comprimidos 50mg, Suspensión 10ml">
                        </div>
                        <div class="form-group">
                            <label>Peso del Animal *</label>
                            <select name="presentaciones[new_${nuevaPresentacionCounter}][peso_animal]" required>
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
                            <label>Rango Mínimo de Peso (kg)</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][rango_peso_min]" 
                                   step="0.1" min="0" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label>Rango Máximo de Peso (kg)</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][rango_peso_max]" 
                                   step="0.1" min="0" placeholder="10">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Dosis Recomendada</label>
                            <input type="text" name="presentaciones[new_${nuevaPresentacionCounter}][dosis_recomendada]" 
                                   placeholder="Ej: 1 comprimido cada 12h">
                        </div>
                        <div class="form-group">
                            <label>Concentración</label>
                            <input type="text" name="presentaciones[new_${nuevaPresentacionCounter}][concentracion]" 
                                   placeholder="Ej: 50mg/ml">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Unidad de Medida</label>
                            <input type="text" name="presentaciones[new_${nuevaPresentacionCounter}][unidad_medida]" 
                                   placeholder="Ej: comprimidos, ml, mg">
                        </div>
                        <div class="form-group">
                            <label>Código de Barras</label>
                            <input type="text" name="presentaciones[new_${nuevaPresentacionCounter}][codigo_barras]" 
                                   placeholder="Código único de barras">
                        </div>
                    </div>
                    
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Stock Actual</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][stock_actual]" 
                                   value="0" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Stock Mínimo</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][stock_minimo]" 
                                   value="5" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Stock Máximo</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][stock_maximo]" 
                                   value="100" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Precio de Compra ($)</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][precio_compra]" 
                                   step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>Precio de Venta ($)</label>
                            <input type="number" name="presentaciones[new_${nuevaPresentacionCounter}][precio_venta]" 
                                   step="0.01" min="0" value="0.00" required>
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', presentacionHTML);
        }

        // Remover presentación existente
        function removerPresentacion(id) {
            if (confirm('¿Estás seguro de eliminar esta presentación? Esta acción no se puede deshacer.')) {
                const presentacion = document.getElementById(`presentacion-${id}`);
                if (presentacion) {
                    // Cambiar el nombre del campo para que sea eliminado en el backend
                    const nombreInput = presentacion.querySelector('input[name*="[nombre_presentacion]"]');
                    if (nombreInput) {
                        nombreInput.name = `presentaciones[${id}][eliminar]`;
                    }
                    presentacion.style.display = 'none';
                }
            }
        }

        // Remover nueva presentación
        function removerNuevaPresentacion(id) {
            const presentacion = document.getElementById(`nueva-presentacion-${id}`);
            if (presentacion) {
                presentacion.remove();
            }
        }

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar toggle de presentaciones
            togglePresentaciones();
        });
    </script>
</body>
</html>                                                                