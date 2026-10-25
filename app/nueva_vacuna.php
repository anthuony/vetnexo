<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Verificar que se haya proporcionado un ID de mascota
if (!isset($_GET['mascota_id']) || empty($_GET['mascota_id'])) {
    header('Location: listademascotas.php');
    exit();
}

$mascota_id = $_GET['mascota_id'];

// Incluir configuración de base de datos
include 'config/database.php';

// Inicializar variables
$db = null;
$mascota = null;
$veterinarios = [];
$veterinario_seleccionado = '';
$error = '';
$success = '';

try {
    $db = getDBConnection();
    
    // Obtener información de la mascota
    $mascota_sql = "
        SELECT m.*, 
               e.nombre as especie_nombre,
               c.nombre as cliente_nombre,
               c.telefono as cliente_telefono
        FROM mascotas m
        LEFT JOIN especies e ON m.especie_id = e.id
        LEFT JOIN clientes c ON m.cliente_id = c.id
        WHERE m.id = ?
    ";
    
    $mascota_stmt = $db->prepare($mascota_sql);
    $mascota_stmt->execute([$mascota_id]);
    $mascota = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mascota) {
        header('Location: listademascotas.php');
        exit();
    }

    // Obtener lista de veterinarios activos
    $veterinarios_sql = "
        SELECT id, nombre 
        FROM usuarios 
        WHERE rol = 'veterinarian' 
        AND estado = 'active'
        ORDER BY nombre
    ";
    $veterinarios_stmt = $db->query($veterinarios_sql);
    $veterinarios = $veterinarios_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si solo hay un veterinario, seleccionarlo automáticamente
    if (count($veterinarios) === 1) {
        $veterinario_seleccionado = $veterinarios[0]['nombre'];
    }

    // Procesar el formulario cuando se envía
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre_vacuna = trim($_POST['nombre_vacuna']);
        $fecha_aplicacion = $_POST['fecha_aplicacion'];
        $proxima_dosis = !empty($_POST['proxima_dosis']) ? $_POST['proxima_dosis'] : null;
        $veterinario = trim($_POST['veterinario']);
        $observaciones = trim($_POST['observaciones']);

        // Validaciones
        if (empty($nombre_vacuna)) {
            $error = "El nombre de la vacuna es obligatorio.";
        } elseif (empty($fecha_aplicacion)) {
            $error = "La fecha de aplicación es obligatoria.";
        } elseif (empty($veterinario)) {
            $error = "Debe seleccionar un veterinario.";
        } else {
            try {
                // Insertar la vacuna en la base de datos
                $sql = "INSERT INTO vacunas (
                    mascota_id, nombre_vacuna, fecha_aplicacion, 
                    proxima_dosis, veterinario, observaciones, 
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $mascota_id,
                    $nombre_vacuna,
                    $fecha_aplicacion,
                    $proxima_dosis,
                    $veterinario,
                    $observaciones
                ]);
                
                $success = "Vacuna registrada exitosamente.";
                
                // Limpiar el formulario después del éxito
                $_POST = array();
                
            } catch (PDOException $e) {
                $error = "Error al registrar la vacuna: " . $e->getMessage();
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
    <title>Registrar Vacuna - VetNexo</title>
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
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
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
            border-radius: 8px;
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
            background: var(--text-dark);
            transform: translateY(-2px);
        }

        /* Pet Info Header */
        .pet-header {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            border: 1px solid var(--border);
        }

        .pet-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.5rem;
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
        }

        .pet-info h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-dark);
            font-size: 1.3rem;
        }

        .pet-info p {
            margin: 0.25rem 0;
            color: var(--text-light);
        }

        /* Form Container */
        .form-container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 2rem;
            border: 1px solid var(--border);
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0 0 1.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .section-title i {
            color: var(--accent);
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-label.required::after {
            content: " *";
            color: #e53e3e;
        }

        .form-input, .form-select, .form-textarea {
            padding: 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            font-size: 0.95rem;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.1);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
            line-height: 1.5;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid;
        }

        .alert-error {
            background: #fed7d7;
            border-color: #e53e3e;
            color: #742a2a;
        }

        .alert-success {
            background: #c6f6d5;
            border-color: #38a169;
            color: #22543d;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--primary-very-light);
        }

        /* Common Vaccines Suggestions */
        .vaccine-suggestions {
            background: var(--primary-very-light);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .suggestions-title {
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .suggestions-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .suggestion-chip {
            background: var(--white);
            border: 1px solid var(--primary-light);
            border-radius: 20px;
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
            color: var(--primary-dark);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .suggestion-chip:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
        }

        /* Veterinario Info */
        .veterinario-info {
            background: var(--primary-very-light);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .veterinario-auto {
            font-size: 0.9rem;
            color: var(--primary-dark);
            font-weight: 600;
        }

        /* Responsive */
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
            
            .pet-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Loading State */
        .loading {
            opacity: 0.7;
            pointer-events: none;
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
                    <h1 class="page-title">Registrar Nueva Vacuna</h1>
                    <p class="page-subtitle">Complete la información de la vacuna aplicada</p>
                </div>
                <div>
                    <a href="anamnesis.php?id=<?php echo $mascota_id; ?>" class="btn btn-secondary">
                        <i class='bx bx-arrow-back'></i>
                        Volver al Historial
                    </a>
                </div>
            </div>

            <!-- Mensajes de alerta -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class='bx bx-error-circle'></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle'></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Información de la Mascota -->
            <div class="pet-header">
                <div class="pet-avatar">
                    <?php echo strtoupper(substr($mascota['nombre'], 0, 1)); ?>
                </div>
                <div class="pet-info">
                    <h3><?php echo htmlspecialchars($mascota['nombre']); ?></h3>
                    <p><strong>Especie:</strong> <?php echo htmlspecialchars($mascota['especie_nombre']); ?> 
                    | <strong>Raza:</strong> <?php echo !empty($mascota['raza']) ? htmlspecialchars($mascota['raza']) : 'No especificada'; ?></p>
                    <p><strong>Dueño:</strong> <?php echo htmlspecialchars($mascota['cliente_nombre']); ?> 
                    | <strong>Teléfono:</strong> <?php echo htmlspecialchars($mascota['cliente_telefono']); ?></p>
                </div>
            </div>

            <!-- Formulario de Vacuna -->
            <div class="form-container">
                <form method="POST" action=""><?php echo av_csrf_field(); ?>
                    <!-- Información de la Vacuna -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-syringe'></i>
                            Información de la Vacuna
                        </h3>

                        <!-- Sugerencias de vacunas comunes -->
                        <div class="vaccine-suggestions">
                            <div class="suggestions-title">Vacunas comunes (haga clic para seleccionar):</div>
                            <div class="suggestions-list">
    <div class="suggestion-chip" onclick="setVaccineName('Vacuna Antirrábica')">Antirrábica</div>
    <div class="suggestion-chip" onclick="setVaccineName('Vacuna Puppy')">Puppy</div>
    <div class="suggestion-chip" onclick="setVaccineName('Vacuna Séxtuple')">Séxtuple</div>
    <div class="suggestion-chip" onclick="setVaccineName('Vacuna KC')">KC</div>
    <div class="suggestion-chip" onclick="setVaccineName('Vacuna Triple Felina')">Triple Felina</div>
    <div class="suggestion-chip" onclick="setVaccineName('Vacuna Quintuple Felina')">Quintuple Felina</div>
</div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required" for="nombre_vacuna">Nombre de la Vacuna</label>
                                <input type="text" 
                                       class="form-input" 
                                       id="nombre_vacuna" 
                                       name="nombre_vacuna" 
                                       value="<?php echo isset($_POST['nombre_vacuna']) ? htmlspecialchars($_POST['nombre_vacuna']) : ''; ?>" 
                                       placeholder="Ej: Vacuna Antirrábica"
                                       required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required" for="fecha_aplicacion">Fecha de Aplicación</label>
                                <input type="date" 
                                       class="form-input" 
                                       id="fecha_aplicacion" 
                                       name="fecha_aplicacion" 
                                       value="<?php echo isset($_POST['fecha_aplicacion']) ? htmlspecialchars($_POST['fecha_aplicacion']) : date('Y-m-d'); ?>" 
                                       required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="proxima_dosis">Próxima Dosis</label>
                                <input type="date" 
                                       class="form-input" 
                                       id="proxima_dosis" 
                                       name="proxima_dosis" 
                                       value="<?php echo isset($_POST['proxima_dosis']) ? htmlspecialchars($_POST['proxima_dosis']) : ''; ?>" 
                                       placeholder="Opcional">
                                <small style="color: var(--text-light); font-size: 0.8rem;">
                                    Fecha recomendada para la próxima aplicación
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="form-label required" for="veterinario">Veterinario que Aplicó</label>
                                
                                <?php if (count($veterinarios) === 1): ?>
                                    <!-- Si solo hay un veterinario, mostrar campo readonly -->
                                    <div class="veterinario-info">
                                        <div class="veterinario-auto">
                                            <i class='bx bx-check-circle'></i>
                                            Veterinario seleccionado automáticamente
                                        </div>
                                        <input type="text" 
                                               class="form-input" 
                                               id="veterinario" 
                                               name="veterinario" 
                                               value="<?php echo htmlspecialchars($veterinario_seleccionado); ?>" 
                                               readonly
                                               style="background-color: var(--primary-very-light);">
                                    </div>
                                <?php else: ?>
                                    <!-- Si hay múltiples veterinarios, mostrar select -->
                                    <select class="form-select" id="veterinario" name="veterinario" required>
                                        <option value="">Seleccione un veterinario</option>
                                        <?php foreach ($veterinarios as $vet): ?>
                                            <option value="<?php echo htmlspecialchars($vet['nombre']); ?>"
                                                <?php echo (isset($_POST['veterinario']) && $_POST['veterinario'] === $vet['nombre']) || 
                                                         (!isset($_POST['veterinario']) && $vet['nombre'] === $veterinario_seleccionado) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($vet['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Observaciones -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class='bx bx-note'></i>
                            Observaciones
                        </h3>
                        <div class="form-group">
                            <label class="form-label" for="observaciones">Observaciones y Notas</label>
                            <textarea class="form-textarea" 
                                      id="observaciones" 
                                      name="observaciones" 
                                      placeholder="Observaciones sobre la aplicación, reacciones, lote de la vacuna, etc..."><?php echo isset($_POST['observaciones']) ? htmlspecialchars($_POST['observaciones']) : ''; ?></textarea>
                        </div>
                    </div>

                    <!-- Botones de Acción -->
                    <div class="action-buttons">
                        <a href="anamnesis.php?id=<?php echo $mascota_id; ?>" class="btn btn-secondary">
                            <i class='bx bx-x'></i>
                            Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class='bx bx-save'></i>
                            Registrar Vacuna
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Función para establecer el nombre de la vacuna desde las sugerencias
        function setVaccineName(vaccineName) {
            document.getElementById('nombre_vacuna').value = vaccineName;
            document.getElementById('nombre_vacuna').focus();
        }

        // Calcular automáticamente la próxima dosis (1 año después)
        document.getElementById('fecha_aplicacion').addEventListener('change', function() {
            const fechaAplicacion = new Date(this.value);
            if (!isNaN(fechaAplicacion.getTime())) {
                const proximaDosis = new Date(fechaAplicacion);
                proximaDosis.setFullYear(proximaDosis.getFullYear() + 1);
                
                const proximaDosisInput = document.getElementById('proxima_dosis');
                if (!proximaDosisInput.value) {
                    proximaDosisInput.value = proximaDosis.toISOString().split('T')[0];
                }
            }
        });

        // Validación del formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const nombreVacuna = document.getElementById('nombre_vacuna').value.trim();
            const fechaAplicacion = document.getElementById('fecha_aplicacion').value;
            const veterinario = document.getElementById('veterinario').value;
            
            if (!nombreVacuna) {
                e.preventDefault();
                alert('Por favor, ingrese el nombre de la vacuna.');
                document.getElementById('nombre_vacuna').focus();
                return;
            }
            
            if (!fechaAplicacion) {
                e.preventDefault();
                alert('Por favor, seleccione la fecha de aplicación.');
                document.getElementById('fecha_aplicacion').focus();
                return;
            }
            
            if (!veterinario) {
                e.preventDefault();
                alert('Por favor, seleccione un veterinario.');
                document.getElementById('veterinario').focus();
                return;
            }
            
            // Validar que la fecha de aplicación no sea futura
            const hoy = new Date();
            const fechaAplicacionDate = new Date(fechaAplicacion);
            if (fechaAplicacionDate > hoy) {
                if (!confirm('La fecha de aplicación es futura. ¿Está seguro de que desea continuar?')) {
                    e.preventDefault();
                    return;
                }
            }
            
            // Mostrar estado de carga
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Registrando...';
            submitBtn.classList.add('loading');
        });

        // Efecto de focus en los campos del formulario
        const formInputs = document.querySelectorAll('.form-input, .form-select, .form-textarea');
        formInputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>