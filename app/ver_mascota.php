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

$mascota = null;
$cliente = null;
$especie = null;
$mensaje = '';
$tipo_mensaje = '';

// Inicializar variable $db
$db = null;

try {
    $db = getDBConnection();
} catch (PDOException $e) {
    $mensaje = "Error de conexión: " . $e->getMessage();
    $tipo_mensaje = "error";
}

// Obtener ID de la mascota desde la URL
$mascota_id = $_GET['id'] ?? null;

if ($mascota_id && $db !== null) {
    try {
        // Obtener información completa de la mascota
        $mascota_sql = "
            SELECT m.*, 
                   e.nombre as especie_nombre,
                   c.nombre as cliente_nombre,
                   c.telefono as cliente_telefono,
                   c.email as cliente_email,
                   c.direccion as cliente_direccion,
                   c.documento_identidad as cliente_cedula
            FROM mascotas m
            LEFT JOIN especies e ON m.especie_id = e.id
            LEFT JOIN clientes c ON m.cliente_id = c.id
            WHERE m.id = ?
        ";
        $mascota_stmt = $db->prepare($mascota_sql);
        $mascota_stmt->execute([$mascota_id]);
        $mascota = $mascota_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mascota) {
            $mensaje = "Mascota no encontrada";
            $tipo_mensaje = "error";
        }
        
    } catch (PDOException $e) {
        $mensaje = "Error al cargar datos: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
} elseif (!$mascota_id) {
    $mensaje = "ID de mascota no especificado";
    $tipo_mensaje = "error";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Mascota - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
    <style>
        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s ease;
            background-color: #f8f9fa;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .page-title {
            font-size: 1.8rem;
            color: #2c3e50;
            margin: 0;
            font-weight: 600;
        }

        .page-subtitle {
            color: #7f8c8d;
            margin: 0.5rem 0 0 0;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #3498db;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border: 1px solid #3498db;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #3498db;
            color: white;
            text-decoration: none;
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
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1.5rem;
        }

        .info-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #ecf0f1;
        }

        .info-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: #e3f2fd;
            color: #3498db;
        }

        .info-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .info-content {
            display: grid;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .info-label {
            font-weight: 600;
            color: #7f8c8d;
            min-width: 120px;
        }

        .info-value {
            color: #2c3e50;
            font-weight: 500;
            text-align: right;
            flex: 1;
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
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
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            border-color: #27ae60;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border-color: #e74c3c;
            color: #721c24;
        }

        /* Notes Section */
        .notes-content {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #3498db;
            white-space: pre-wrap;
            font-family: inherit;
            min-height: 100px;
        }

        /* Pet Avatar */
        .pet-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 2rem;
            margin-right: 1rem;
        }

        .pet-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .pet-details h2 {
            margin: 0 0 0.5rem 0;
            color: #2c3e50;
        }

        .pet-details p {
            margin: 0;
            color: #7f8c8d;
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
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .pet-header {
                flex-direction: column;
                text-align: center;
            }
            
            .pet-avatar-large {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .info-value {
                text-align: left;
            }
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
                    <h1 class="page-title">Información de la Mascota</h1>
                    <p class="page-subtitle">Detalles completos de la mascota registrada</p>
                </div>
                <a href="listademascotas.php" class="back-btn">
                    <i class='bx bx-arrow-back'></i>
                    Volver a Lista
                </a>
            </div>

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <?php if ($mascota): ?>
            <!-- Encabezado de la Mascota -->
            <div class="pet-header">
                <div class="pet-avatar-large">
                    <?php echo strtoupper(substr($mascota['nombre'], 0, 1)); ?>
                </div>
                <div class="pet-details">
                    <h2><?php echo htmlspecialchars($mascota['nombre']); ?></h2>
                    <p><?php echo htmlspecialchars($mascota['especie_nombre']); ?> • 
                       <?php echo htmlspecialchars($mascota['cliente_nombre']); ?></p>
                </div>
                <div style="margin-left: auto; display: flex; gap: 1rem;">
                    <a href="editar_mascota.php?id=<?php echo $mascota['id']; ?>" class="btn btn-warning">
                        <i class='bx bx-edit'></i>
                        Editar Mascota
                    </a>
                    <a href="historial.php?id=<?php echo $mascota['id']; ?>" class="btn btn-primary">
                        <i class='bx bx-clipboard'></i>
                        Ver Historial
                    </a>
                </div>
            </div>

            <!-- Información de la Mascota -->
            <div class="info-grid">
                <!-- Información Básica -->
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class='bx bx-paw'></i>
                        </div>
                        <h3 class="info-title">Información Básica</h3>
                    </div>
                    <div class="info-content">
                        <div class="info-item">
                            <span class="info-label">Nombre:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['nombre']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Especie:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['especie_nombre']); ?></span>
                        </div>
                        <?php if (!empty($mascota['raza'])): ?>
                        <div class="info-item">
                            <span class="info-label">Raza:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['raza']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($mascota['fecha_nacimiento'])): ?>
                        <div class="info-item">
                            <span class="info-label">Fecha Nacimiento:</span>
                            <span class="info-value"><?php echo date('d/m/Y', strtotime($mascota['fecha_nacimiento'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Sexo:</span>
                            <span class="info-value">
                                <?php 
                                if (in_array(strtolower((string)$mascota['sexo']), ['macho','m'], true)) echo 'Macho';
                                elseif (in_array(strtolower((string)$mascota['sexo']), ['hembra','h'], true)) echo 'Hembra';
                                else echo 'No especificado';
                                ?>
                            </span>
                        </div>
                        <?php if (!empty($mascota['color'])): ?>
                        <div class="info-item">
                            <span class="info-label">Color:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['color']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($mascota['peso'])): ?>
                        <div class="info-item">
                            <span class="info-label">Peso:</span>
                            <span class="info-value"><?php echo $mascota['peso']; ?> kg</span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Fecha Registro:</span>
                            <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($mascota['fecha_registro'])); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Información del Propietario -->
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class='bx bx-user'></i>
                        </div>
                        <h3 class="info-title">Propietario</h3>
                    </div>
                    <div class="info-content">
                        <div class="info-item">
                            <span class="info-label">Nombre:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_nombre']); ?></span>
                        </div>
                        <?php if (!empty($mascota['cliente_cedula'])): ?>
                        <div class="info-item">
                            <span class="info-label">Cédula:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_cedula']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($mascota['cliente_telefono'])): ?>
                        <div class="info-item">
                            <span class="info-label">Teléfono:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_telefono']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($mascota['cliente_email'])): ?>
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_email']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($mascota['cliente_direccion'])): ?>
                        <div class="info-item">
                            <span class="info-label">Dirección:</span>
                            <span class="info-value"><?php echo htmlspecialchars($mascota['cliente_direccion']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Información Médica -->
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class='bx bx-heart'></i>
                        </div>
                        <h3 class="info-title">Información Médica</h3>
                    </div>
                    <div class="info-content">
                        <?php if (!empty($mascota['peso'])): ?>
                        <div class="info-item">
                            <span class="info-label">Peso Actual:</span>
                            <span class="info-value"><?php echo $mascota['peso']; ?> kg</span>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($mascota['estado_salud']) && !empty($mascota['estado_salud'])): ?>
                        <div class="info-item">
                            <span class="info-label">Estado Salud:</span>
                            <span class="info-value">
                                <span class="badge badge-<?php echo strtolower($mascota['estado_salud']); ?>">
                                    <?php echo ucfirst($mascota['estado_salud']); ?>
                                </span>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($mascota['esterilizado'])): ?>
                        <div class="info-item">
                            <span class="info-label">Esterilizado:</span>
                            <span class="info-value">
                                <span class="badge <?php echo $mascota['esterilizado'] ? 'badge-success' : 'badge-secondary'; ?>">
                                    <?php echo $mascota['esterilizado'] ? 'Sí' : 'No'; ?>
                                </span>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($mascota['estado']) && !empty($mascota['estado'])): ?>
                        <div class="info-item">
                            <span class="info-label">Estado:</span>
                            <span class="info-value">
                                <span class="badge <?php echo $mascota['estado'] === 'activo' ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo ucfirst($mascota['estado']); ?>
                                </span>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notas Adicionales -->
                <?php if (!empty($mascota['notas'])): ?>
                <div class="info-card" style="grid-column: 1 / -1;">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class='bx bx-note'></i>
                        </div>
                        <h3 class="info-title">Notas Adicionales</h3>
                    </div>
                    <div class="notes-content">
                        <?php echo nl2br(htmlspecialchars($mascota['notas'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($db !== null): ?>
            <!-- Estado cuando no hay mascota encontrada -->
            <div class="alert alert-error">
                <strong>Error:</strong> La mascota solicitada no existe o no se pudo cargar la información.
            </div>
            <div style="text-align: center; margin-top: 2rem;">
                <a href="listademascotas.php" class="btn btn-primary">
                    <i class='bx bx-list-ul'></i>
                    Volver a la Lista de Mascotas
                </a>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
</body>
</html>