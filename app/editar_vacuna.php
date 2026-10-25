<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Incluir configuración de base de datos
include 'config/database.php';

// Inicializar variables
$db = null;
$vacuna = null;
$mascotas = [];
$error = '';
$success = '';

// Obtener ID de la vacuna a editar
$vacuna_id = $_GET['id'] ?? '';

if (empty($vacuna_id)) {
    header('Location: controlvacunas.php');
    exit();
}

try {
    $db = getDBConnection();
    
    // Obtener datos de la vacuna (CORREGIDO: usando las columnas reales)
    $vacuna_sql = "
        SELECT v.*, 
               m.nombre as mascota_nombre,
               m.especie_id,
               e.nombre as especie_nombre,
               c.nombre as cliente_nombre,
               v.created_at as fecha_registro,
               v.updated_at as fecha_actualizacion
        FROM vacunas v
        LEFT JOIN mascotas m ON v.mascota_id = m.id
        LEFT JOIN especies e ON m.especie_id = e.id
        LEFT JOIN clientes c ON m.cliente_id = c.id
        WHERE v.id = ?
    ";
    
    $vacuna_stmt = $db->prepare($vacuna_sql);
    $vacuna_stmt->execute([$vacuna_id]);
    $vacuna = $vacuna_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vacuna) {
        $error = "Vacuna no encontrada";
    }
    
    // Obtener todas las mascotas para el select
    $mascotas_sql = "SELECT id, nombre FROM mascotas ORDER BY nombre";
    $mascotas_stmt = $db->query($mascotas_sql);
    $mascotas = $mascotas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar el formulario de actualización
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mascota_id = $_POST['mascota_id'] ?? '';
        $nombre_vacuna = $_POST['nombre_vacuna'] ?? '';
        $fecha_aplicacion = $_POST['fecha_aplicacion'] ?? '';
        $proxima_dosis = $_POST['proxima_dosis'] ?? '';
        $veterinario = $_POST['veterinario'] ?? '';
        $observaciones = $_POST['observaciones'] ?? '';
        
        // Validaciones básicas
        if (empty($mascota_id) || empty($nombre_vacuna) || empty($fecha_aplicacion)) {
            $error = "Por favor complete todos los campos obligatorios";
        } else {
            // Actualizar vacuna en la base de datos (CORREGIDO: eliminadas columnas lote y laboratorio)
            $update_sql = "
                UPDATE vacunas 
                SET mascota_id = ?, 
                    nombre_vacuna = ?, 
                    fecha_aplicacion = ?, 
                    proxima_dosis = ?, 
                    veterinario = ?, 
                    observaciones = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $update_stmt = $db->prepare($update_sql);
            $result = $update_stmt->execute([
                $mascota_id,
                $nombre_vacuna,
                $fecha_aplicacion,
                $proxima_dosis ?: null,
                $veterinario,
                $observaciones,
                $vacuna_id
            ]);
            
            if ($result) {
                $success = "Vacuna actualizada correctamente";
                // Recargar datos actualizados
                $vacuna_stmt->execute([$vacuna_id]);
                $vacuna = $vacuna_stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Error al actualizar la vacuna";
            }
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
    <title>Editar Vacuna - VetNexo</title>
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

        .btn-success {
            background: var(--primary);
            color: var(--white);
        }

        .btn-success:hover {
            background: var(--primary-dark);
        }

        /* Form Section */
        .form-section {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .form-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-title i {
            color: var(--primary);
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .form-label.required::after {
            content: '*';
            color: #f56565;
            margin-left: 0.25rem;
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235A5A5A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-very-light);
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

        .alert-error {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            border: 1px solid #f56565;
            color: #742a2a;
        }

        .alert-success {
            background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
            border: 1px solid var(--primary);
            color: #22543d;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-error .alert-icon {
            color: #f56565;
        }

        .alert-success .alert-icon {
            color: var(--primary);
        }

        /* Pet Info Card */
        .pet-info-card {
            background: var(--primary-very-light);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--primary-light);
        }

        .pet-info-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .pet-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
        }

        .pet-details h4 {
            margin: 0 0 0.25rem 0;
            color: var(--text-dark);
            font-weight: 600;
        }

        .pet-details p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
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
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
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

        /* Help text */
        .help-text {
            color: var(--text-light);
            font-size: 0.8rem;
            margin-top: 0.25rem;
            font-style: italic;
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
                    <h1 class="page-title">Editar Vacuna</h1>
                    <p class="page-subtitle">Actualizar información de la vacuna aplicada</p>
                </div>
                <a href="controlvacunas.php" class="btn btn-secondary">
                    <i class='bx bx-arrow-back'></i>
                    Volver al Control
                </a>
            </div>

            <!-- Mensajes de error -->
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

            <!-- Mensajes de éxito -->
            <?php if (isset($success) && !empty($success)): ?>
                <div class="alert alert-success">
                    <div class="alert-icon">
                        <i class='bx bx-check-circle'></i>
                    </div>
                    <div class="alert-content">
                        <strong>Éxito:</strong> <?php echo htmlspecialchars($success); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($vacuna): ?>
                <!-- Información de la mascota -->
                <div class="pet-info-card">
                    <div class="pet-info-header">
                        <div class="pet-avatar">
                            <?php echo strtoupper(substr($vacuna['mascota_nombre'], 0, 1)); ?>
                        </div>
                        <div class="pet-details">
                            <h4><?php echo htmlspecialchars($vacuna['mascota_nombre']); ?></h4>
                            <p><?php echo htmlspecialchars($vacuna['especie_nombre']); ?> - Dueño: <?php echo htmlspecialchars($vacuna['cliente_nombre']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Formulario de edición -->
                <div class="form-section">
                    <div class="form-header">
                        <h3 class="form-title">
                            <i class='bx bx-edit'></i>
                            Información de la Vacuna
                        </h3>
                    </div>

                    <form method="POST" action=""><?php echo av_csrf_field(); ?>
                        <div class="form-grid">
                            <!-- Mascota -->
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class='bx bx-paw'></i>
                                    Mascota
                                </label>
                                <select name="mascota_id" class="form-control select" required>
                                    <option value="">Seleccione una mascota</option>
                                    <?php foreach ($mascotas as $mascota): ?>
                                        <option value="<?php echo $mascota['id']; ?>" 
                                            <?php echo ($vacuna['mascota_id'] == $mascota['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($mascota['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Nombre de la vacuna -->
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class='bx bx-syringe'></i>
                                    Nombre de la Vacuna
                                </label>
                                <input type="text" 
                                       name="nombre_vacuna" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($vacuna['nombre_vacuna']); ?>" 
                                       required
                                       placeholder="Ej: Vacuna múltiple canina">
                            </div>

                            <!-- Fecha de aplicación -->
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class='bx bx-calendar'></i>
                                    Fecha de Aplicación
                                </label>
                                <input type="date" 
                                       name="fecha_aplicacion" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($vacuna['fecha_aplicacion']); ?>" 
                                       required>
                            </div>

                            <!-- Próxima dosis -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class='bx bx-calendar-event'></i>
                                    Próxima Dosis
                                </label>
                                <input type="date" 
                                       name="proxima_dosis" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($vacuna['proxima_dosis'] ?? ''); ?>">
                                <div class="help-text">Dejar vacío si no requiere próxima dosis</div>
                            </div>

                            <!-- Veterinario -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class='bx bx-user'></i>
                                    Veterinario
                                </label>
                                <input type="text" 
                                       name="veterinario" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($vacuna['veterinario'] ?? ''); ?>" 
                                       placeholder="Nombre del veterinario que aplicó la vacuna">
                            </div>

                            <!-- Observaciones -->
                            <div class="form-group full-width">
                                <label class="form-label">
                                    <i class='bx bx-note'></i>
                                    Observaciones
                                </label>
                                <textarea name="observaciones" 
                                          class="form-control" 
                                          placeholder="Observaciones adicionales sobre la vacuna aplicada"><?php echo htmlspecialchars($vacuna['observaciones'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="controlvacunas.php" class="btn btn-secondary">
                                <i class='bx bx-x'></i>
                                Cancelar
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class='bx bx-save'></i>
                                Guardar Cambios
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Información adicional -->
                <div class="form-section">
                    <div class="form-header">
                        <h3 class="form-title">
                            <i class='bx bx-info-circle'></i>
                            Información Adicional
                        </h3>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Fecha de Registro</label>
                            <input type="text" 
                                   class="form-control" 
                                   value="<?php echo !empty($vacuna['fecha_registro']) ? date('d/m/Y H:i', strtotime($vacuna['fecha_registro'])) : 'No disponible'; ?>" 
                                   readonly
                                   style="background-color: var(--gray-light);">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Última Actualización</label>
                            <input type="text" 
                                   class="form-control" 
                                   value="<?php echo !empty($vacuna['fecha_actualizacion']) ? date('d/m/Y H:i', strtotime($vacuna['fecha_actualizacion'])) : 'Sin actualizaciones'; ?>" 
                                   readonly
                                   style="background-color: var(--gray-light);">
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Mensaje si no se encuentra la vacuna -->
                <div class="form-section">
                    <div style="text-align: center; padding: 3rem 2rem; color: var(--text-light);">
                        <i class='bx bx-error-circle' style="font-size: 3rem; margin-bottom: 1rem; color: var(--primary-light);"></i>
                        <h3>Vacuna no encontrada</h3>
                        <p>La vacuna que intentas editar no existe o ha sido eliminada.</p>
                        <a href="controlvacunas.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class='bx bx-arrow-back'></i>
                            Volver al Control de Vacunas
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            <footer class="main-footer">
    <div class="footer-content">
        <div class="footer-simple">
            <center><span class="footer-text">© 2026 VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></span></center>
        </div>
    </div>
</footer>
        </main>
        
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Validación de fechas
            const fechaAplicacion = document.querySelector('input[name="fecha_aplicacion"]');
            const proximaDosis = document.querySelector('input[name="proxima_dosis"]');
            
            if (fechaAplicacion && proximaDosis) {
                fechaAplicacion.addEventListener('change', function() {
                    if (proximaDosis.value && new Date(proximaDosis.value) <= new Date(this.value)) {
                        alert('La próxima dosis debe ser posterior a la fecha de aplicación');
                        proximaDosis.value = '';
                    }
                });
                
                proximaDosis.addEventListener('change', function() {
                    if (fechaAplicacion.value && new Date(this.value) <= new Date(fechaAplicacion.value)) {
                        alert('La próxima dosis debe ser posterior a la fecha de aplicación');
                        this.value = '';
                    }
                });
            }
            
            // Mostrar alertas temporalmente
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>