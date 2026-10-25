<?php
require_once __DIR__ . '/config/auth.php';
require_staff();

require_once 'config/database.php';
// Inicializar variables
$cita = [];
$error = '';
$success = '';
$mascotas = [];
$clientes = [];
$servicios = [];

// Verificar si se proporcionó un ID de cita
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: calendario.php');
    exit;
}

$cita_id = $_GET['id'];

try {
    // Crear conexión a la base de datos
    $pdo = getDBConnection();

    // Obtener datos de la cita
    $sql_cita = "SELECT c.*, 
                        m.nombre as mascota_nombre, 
                        m.raza as mascota_raza,
                        m.id as mascota_id,
                        cl.nombre as cliente_nombre, 
                        cl.id as cliente_id,
                        s.nombre as servicio_nombre, 
                        s.id as servicio_id,
                        s.categoria as tipo_servicio
                 FROM citas c 
                 LEFT JOIN mascotas m ON c.mascota_id = m.id 
                 LEFT JOIN clientes cl ON c.cliente_id = cl.id 
                 LEFT JOIN servicios s ON c.servicio_id = s.id 
                 WHERE c.id = ?";
    
    $stmt_cita = $pdo->prepare($sql_cita);
    $stmt_cita->execute([$cita_id]);
    $cita = $stmt_cita->fetch();

    if (!$cita) {
        $error = "La cita no existe o ha sido eliminada.";
    }

    // Obtener mascotas para el dropdown
    $sql_mascotas = "SELECT id, nombre, raza FROM mascotas ORDER BY nombre";
    $mascotas = $pdo->query($sql_mascotas)->fetchAll();

    // Obtener clientes para el dropdown
    $sql_clientes = "SELECT id, nombre, telefono FROM clientes ORDER BY nombre";
    $clientes = $pdo->query($sql_clientes)->fetchAll();

    // Obtener servicios para el dropdown
    $sql_servicios = "SELECT id, nombre, categoria, duracion, precio FROM servicios ORDER BY nombre";
    $servicios = $pdo->query($sql_servicios)->fetchAll();

    // Procesar el formulario de actualización
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fecha_cita = $_POST['fecha_cita'] ?? '';
        $hora_cita = $_POST['hora_cita'] ?? '';
        $servicio_id = $_POST['servicio_id'] ?? '';
        $mascota_id = $_POST['mascota_id'] ?? '';
        $cliente_id = $_POST['cliente_id'] ?? '';
        $estado = $_POST['estado'] ?? '';
        $notas = $_POST['notas'] ?? '';

        // Validaciones básicas
        if (empty($fecha_cita) || empty($hora_cita) || empty($servicio_id) || empty($mascota_id) || empty($cliente_id)) {
            $error = "Todos los campos obligatorios deben ser completados.";
        } else {
            if(!in_array($estado,['scheduled','confirmed','completed','cancelled'],true))av_fail('Estado de cita no válido.');
            $v=$pdo->prepare('SELECT COUNT(*) FROM mascotas WHERE id=? AND cliente_id=?');$v->execute([$mascota_id,$cliente_id]);if(!$v->fetchColumn())av_fail('La mascota no pertenece al cliente seleccionado.');
            $v=$pdo->prepare('SELECT COUNT(*) FROM servicios WHERE id=? AND activo=1');$v->execute([$servicio_id]);if(!$v->fetchColumn())av_fail('Servicio no disponible.');
            // Actualizar la cita en la base de datos
            $sql_update = "UPDATE citas SET 
                          fecha_cita = ?, 
                          hora_cita = ?, 
                          servicio_id = ?, 
                          mascota_id = ?, 
                          cliente_id = ?, 
                          estado = ?, 
                          notas = ?,
                          updated_at = NOW()
                          WHERE id = ?";
            
            $stmt_update = $pdo->prepare($sql_update);
            $result = $stmt_update->execute([
                $fecha_cita,
                $hora_cita,
                $servicio_id,
                $mascota_id,
                $cliente_id,
                $estado,
                $notas,
                $cita_id
            ]);

            if ($result) {
                $success = "Cita actualizada correctamente.";
                // Recargar los datos de la cita
                $stmt_cita->execute([$cita_id]);
                $cita = $stmt_cita->fetch();
            } else {
                $error = "Error al actualizar la cita. Por favor, intenta nuevamente.";
            }
        }
    }

} catch (PDOException $e) {
    $error = "Error de conexión: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Cita - VetNexo</title>
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
            margin: 0;
            padding: 0;
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
            background: var(--white);
            padding: 1.5rem 2rem;
            border-radius: 16px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }

        .page-title {
            font-size: 1.8rem;
            color: var(--primary-dark);
            margin: 0;
            font-weight: 600;
        }

        .page-subtitle {
            color: var(--text-light);
            margin: 0.5rem 0 0 0;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-secondary {
            background: var(--text-light);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: #4a5568;
        }

        .btn-warning {
            background: var(--accent);
            color: var(--white);
        }

        .btn-warning:hover {
            background: var(--accent-dark);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #dc3545;
            color: var(--white);
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        /* Form Container */
        .form-container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .form-header {
            border-bottom: 2px solid var(--primary-very-light);
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.5rem;
            color: var(--primary-dark);
            margin: 0;
            font-weight: 600;
        }

        .form-subtitle {
            color: var(--text-light);
            margin: 0.5rem 0 0 0;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-label.required::after {
            content: " *";
            color: #dc3545;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-control:disabled {
            background: var(--gray-light);
            color: var(--text-light);
            cursor: not-allowed;
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-top: 0.25rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 1px solid var(--primary-light);
            color: #155724;
        }

        .alert-error {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            border: 1px solid #f56565;
            color: #742a2a;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-success .alert-icon {
            color: var(--primary);
        }

        .alert-error .alert-icon {
            color: #f56565;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid var(--border);
        }

        /* Cita Info */
        .cita-info {
            background: var(--primary-very-light);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .cita-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 0.5rem;
        }

        .info-label {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-confirmed {
            background: #d4edda;
            color: #155724;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--primary-very-light);
            border: 2px dashed var(--primary-light);
            border-radius: 12px;
            color: var(--primary-dark);
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--accent);
            color: var(--accent-dark);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.15);
            background: linear-gradient(135deg, var(--primary-very-light), #f0f9f0);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 0.8rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .action-btn:hover .action-icon {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .action-label {
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .form-container {
                padding: 1.5rem;
            }
            
            .cita-info-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Custom select styling */
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'><path fill='%23666' d='M2 0L0 2h4zm0 5L0 3h4z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 0.65rem;
            padding-right: 2.5rem;
        }

        /* Loading state */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--primary-very-light);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
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
            <!-- Header -->
            <div class="header">
                <div>
                    <h1 class="page-title">Editar Cita</h1>
                    <p class="page-subtitle">Modifica los detalles de la cita programada</p>
                </div>
                <a href="calendario.php" class="btn btn-secondary">
                    <i class='bx bx-arrow-back'></i>
                    Volver al Calendario
                </a>
            </div>

            <!-- Mensajes de éxito/error -->
            <?php if (isset($success) && !empty($success)): ?>
                <div class="alert alert-success">
                    <div class="alert-icon">
                        <i class='bx bx-check-circle'></i>
                    </div>
                    <div class="alert-content">
                        <strong>¡Éxito!</strong> <?php echo htmlspecialchars($success); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error) && !empty($error)): ?>
                <div class="alert alert-error">
                    <div class="alert-icon">
                        <i class='bx bx-error-circle'></i>
                    </div>
                    <div class="alert-content">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($cita) && !empty($error)): ?>
                <!-- Mostrar mensaje de error y opciones -->
                <div class="form-container">
                    <div class="empty-state" style="text-align: center; padding: 3rem 2rem;">
                        <i class='bx bx-error-circle' style="font-size: 4rem; color: #dc3545; margin-bottom: 1rem;"></i>
                        <h3 style="color: var(--text-dark); margin-bottom: 1rem;">No se pudo cargar la cita</h3>
                        <p style="color: var(--text-light); margin-bottom: 2rem;"><?php echo htmlspecialchars($error); ?></p>
                        
                        <div class="quick-actions">
                            <a href="calendario.php" class="action-btn">
                                <i class='bx bx-calendar action-icon'></i>
                                <span class="action-label">Volver al Calendario</span>
                            </a>
                            <a href="agendarcita.php" class="action-btn">
                                <i class='bx bx-calendar-plus action-icon'></i>
                                <span class="action-label">Crear Nueva Cita</span>
                            </a>
                            <a href="dashboard.php" class="action-btn">
                                <i class='bx bx-home action-icon'></i>
                                <span class="action-label">Ir al Dashboard</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif (!empty($cita)): ?>
                <!-- Información actual de la cita -->
                <div class="cita-info">
                    <h3 style="margin: 0 0 1rem 0; color: var(--primary-dark);">Información Actual de la Cita</h3>
                    <div class="cita-info-grid">
                        <div class="info-item">
                            <div class="info-label">Fecha y Hora</div>
                            <div class="info-value">
                                <?php 
                                $fecha = new DateTime($cita['fecha_cita']);
                                $hora = new DateTime($cita['hora_cita']);
                                echo $fecha->format('d/m/Y') . ' a las ' . $hora->format('H:i');
                                ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Servicio</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['servicio_nombre'] ?? 'No especificado'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Mascota</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['mascota_nombre'] ?? 'No especificada'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Cliente</div>
                            <div class="info-value"><?php echo htmlspecialchars($cita['cliente_nombre'] ?? 'No especificado'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Estado Actual</div>
                            <div class="info-value">
                                <span class="status-badge status-<?php echo $cita['estado'] ?? 'scheduled'; ?>">
                                    <?php 
                                    $estados = [
                                        'scheduled' => 'Programada',
                                        'confirmed' => 'Confirmada',
                                        'completed' => 'Completada',
                                        'cancelled' => 'Cancelada'
                                    ];
                                    echo $estados[$cita['estado']] ?? 'Programada';
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulario de edición -->
                <div class="form-container">
                    <div class="form-header">
                        <h2 class="form-title">Modificar Cita</h2>
                        <p class="form-subtitle">Actualiza los detalles de la cita según sea necesario</p>
                    </div>

                    <form method="POST" id="editarCitaForm"><?php echo av_csrf_field(); ?>
                        <div class="form-grid">
                            <!-- Fecha y Hora -->
                            <div class="form-group">
                                <label for="fecha_cita" class="form-label required">Fecha de la Cita</label>
                                <input type="date" 
                                       id="fecha_cita" 
                                       name="fecha_cita" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($cita['fecha_cita'] ?? ''); ?>"
                                       required
                                       min="<?php echo date('Y-m-d'); ?>">
                                <div class="form-text">Selecciona la fecha para la cita</div>
                            </div>

                            <div class="form-group">
                                <label for="hora_cita" class="form-label required">Hora de la Cita</label>
                                <input type="time" 
                                       id="hora_cita" 
                                       name="hora_cita" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars(substr($cita['hora_cita'] ?? '', 0, 5)); ?>"
                                       required>
                                <div class="form-text">Selecciona la hora para la cita</div>
                            </div>

                            <!-- Servicio -->
                            <div class="form-group">
                                <label for="servicio_id" class="form-label required">Servicio</label>
                                <select id="servicio_id" name="servicio_id" class="form-control" required>
                                    <option value="">Seleccionar servicio...</option>
                                    <?php foreach ($servicios as $servicio): ?>
                                        <option value="<?php echo $servicio['id']; ?>" 
                                                <?php echo ($servicio['id'] == $cita['servicio_id']) ? 'selected' : ''; ?>
                                                data-categoria="<?php echo htmlspecialchars($servicio['categoria'] ?? ''); ?>"
                                                data-duracion="<?php echo htmlspecialchars($servicio['duracion'] ?? ''); ?>"
                                                data-precio="<?php echo htmlspecialchars($servicio['precio'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($servicio['nombre']); ?>
                                            <?php if (!empty($servicio['precio'])): ?>
                                                - $<?php echo number_format($servicio['precio'], 2); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="servicio-info"></div>
                            </div>

                            <!-- Estado -->
                            <div class="form-group">
                                <label for="estado" class="form-label required">Estado</label>
                                <select id="estado" name="estado" class="form-control" required>
                                    <option value="scheduled" <?php echo ($cita['estado'] == 'scheduled') ? 'selected' : ''; ?>>Programada</option>
                                    <option value="confirmed" <?php echo ($cita['estado'] == 'confirmed') ? 'selected' : ''; ?>>Confirmada</option>
                                    <option value="completed" <?php echo ($cita['estado'] == 'completed') ? 'selected' : ''; ?>>Completada</option>
                                    <option value="cancelled" <?php echo ($cita['estado'] == 'cancelled') ? 'selected' : ''; ?>>Cancelada</option>
                                </select>
                                <div class="form-text">Estado actual de la cita</div>
                            </div>

                            <!-- Cliente -->
                            <div class="form-group">
                                <label for="cliente_id" class="form-label required">Cliente</label>
                                <select id="cliente_id" name="cliente_id" class="form-control" required>
                                    <option value="">Seleccionar cliente...</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>" 
                                                <?php echo ($cliente['id'] == $cita['cliente_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                            <?php if (!empty($cliente['telefono'])): ?>
                                                - <?php echo htmlspecialchars($cliente['telefono']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Selecciona el cliente dueño de la mascota</div>
                            </div>

                            <!-- Mascota -->
                            <div class="form-group">
                                <label for="mascota_id" class="form-label required">Mascota</label>
                                <select id="mascota_id" name="mascota_id" class="form-control" required>
                                    <option value="">Seleccionar mascota...</option>
                                    <?php foreach ($mascotas as $mascota): ?>
                                        <option value="<?php echo $mascota['id']; ?>" 
                                                <?php echo ($mascota['id'] == $cita['mascota_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($mascota['nombre']); ?>
                                            <?php if (!empty($mascota['raza'])): ?>
                                                - <?php echo htmlspecialchars($mascota['raza']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Selecciona la mascota para la cita</div>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div class="form-group">
                            <label for="notas" class="form-label">Notas Adicionales</label>
                            <textarea id="notas" 
                                      name="notas" 
                                      class="form-control" 
                                      rows="4" 
                                      placeholder="Agrega cualquier nota adicional sobre la cita..."><?php echo htmlspecialchars($cita['notas'] ?? ''); ?></textarea>
                            <div class="form-text">Información adicional sobre la cita (opcional)</div>
                        </div>

                        <!-- Acciones del Formulario -->
                        <div class="form-actions">
                            <a href="calendario.php" class="btn btn-secondary">
                                <i class='bx bx-x'></i>
                                Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-save'></i>
                                Guardar Cambios
                            </button>
                            <?php if ($cita['estado'] != 'cancelled'): ?>
                                <button type="button" class="btn btn-danger" onclick="cancelarCita()">
                                    <i class='bx bx-trash'></i>
                                    Cancelar Cita
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Información de la cita -->
                <div class="form-container">
                    <h3 style="margin-bottom: 1.5rem; color: var(--primary-dark);">Información del Sistema</h3>
                    <div class="cita-info-grid">
                        <div class="info-item">
                            <div class="info-label">ID de Cita</div>
                            <div class="info-value">#<?php echo htmlspecialchars($cita['id']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Creado</div>
                            <div class="info-value">
                                <?php 
                                $creado = new DateTime($cita['created_at'] ?? date('Y-m-d H:i:s'));
                                echo $creado->format('d/m/Y H:i');
                                ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Última Actualización</div>
                            <div class="info-value">
                                <?php 
                                $actualizado = new DateTime($cita['updated_at'] ?? $cita['created_at'] ?? date('Y-m-d H:i:s'));
                                echo $actualizado->format('d/m/Y H:i');
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Actualizar información del servicio
        document.getElementById('servicio_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const categoria = selectedOption.getAttribute('data-categoria');
            const duracion = selectedOption.getAttribute('data-duracion');
            const precio = selectedOption.getAttribute('data-precio');
            
            let info = '';
            if (categoria) {
                info += `Categoría: ${categoria} `;
            }
            if (duracion) {
                info += `| Duración: ${duracion} min `;
            }
            if (precio) {
                info += `| Precio: $${parseFloat(precio).toFixed(2)}`;
            }
            
            document.getElementById('servicio-info').textContent = info;
        });

        // Trigger change event on page load to show initial service info
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('servicio_id').dispatchEvent(new Event('change'));
        });

        // Validación de fecha y hora
        document.getElementById('editarCitaForm').addEventListener('submit', function(e) {
            const fecha = document.getElementById('fecha_cita').value;
            const hora = document.getElementById('hora_cita').value;
            const hoy = new Date().toISOString().split('T')[0];
            
            if (fecha < hoy) {
                e.preventDefault();
                alert('No puedes programar citas en fechas pasadas.');
                return;
            }
            
            if (fecha === hoy) {
                const ahora = new Date();
                const horaActual = ahora.getHours().toString().padStart(2, '0') + ':' + ahora.getMinutes().toString().padStart(2, '0');
                
                if (hora < horaActual) {
                    e.preventDefault();
                    alert('No puedes programar citas en horas pasadas para el día de hoy.');
                    return;
                }
            }
        });

        // Función para cancelar cita
        function cancelarCita() {
            if (confirm('¿Estás seguro de que deseas cancelar esta cita? Esta acción no se puede deshacer.')) {
                document.getElementById('estado').value = 'cancelled';
                document.getElementById('editarCitaForm').submit();
            }
        }

        // Mejorar la experiencia del formulario
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editarCitaForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            
            form.addEventListener('submit', function() {
                submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Guardando...';
                submitBtn.disabled = true;
                form.classList.add('loading');
            });

            // Efectos visuales para los campos
            const inputs = form.querySelectorAll('.form-control');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });
        });

        // Filtrar mascotas por cliente
        document.getElementById('cliente_id').addEventListener('change', function() {
            // En una implementación real, aquí harías una petición AJAX
            // para cargar las mascotas del cliente seleccionado
            console.log('Cliente cambiado:', this.value);
            // Por ahora, mostramos un mensaje
            if (this.value) {
                console.log('Cargando mascotas del cliente...');
            }
        });
    </script>
</body>
</html>