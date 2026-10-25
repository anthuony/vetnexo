<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_admin();


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

// Obtener conexión a la base de datos
$db = getDBConnection();

// Verificar si la conexión se estableció correctamente
if (!$db) {
    die("Error: No se pudo establecer conexión con la base de datos");
}

// Obtener información del usuario actual para el avatar
$stmt = $db->prepare("SELECT nombre, email, rol FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_data) {
    $usuario_nombre = $user_data['nombre'];
    $usuario_email = $user_data['email'];
    $usuario_rol = $user_data['rol'];
}

// Verificar si el usuario es administrador
if ($usuario_rol !== 'admin') {
    $error_acceso = "Acceso denegado. Solo los administradores pueden acceder al sistema de backup.";
}

// Configuración de backup
$backup_dir = 'backups/';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Procesar acciones de backup (solo si es admin)
if (!isset($error_acceso) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion'])) {
        $accion = $_POST['accion'];
        
        switch ($accion) {
            case 'crear_backup':
                $backup_file = crearBackupSQL($db, $backup_dir);
                if ($backup_file) {
                    $_SESSION['success'] = "Backup SQL creado exitosamente: " . basename($backup_file);
                } else {
                    $_SESSION['error'] = "Error al crear el backup SQL";
                }
                header('Location: backup.php');
                exit();
                break;
                
            case 'crear_backup_excel':
                $backup_file = crearBackupExcel($db, $backup_dir);
                if ($backup_file) {
                    $_SESSION['success'] = "Backup Excel creado exitosamente: " . basename($backup_file);
                } else {
                    $_SESSION['error'] = "Error al crear el backup Excel";
                }
                header('Location: backup.php');
                exit();
                break;
                
            case 'restaurar_backup':
                if (isset($_FILES['archivo_backup']) && $_FILES['archivo_backup']['error'] === UPLOAD_ERR_OK) {
                    $archivo_tmp = $_FILES['archivo_backup']['tmp_name'];
                    $archivo_nombre = $_FILES['archivo_backup']['name'];
                    
                    if (pathinfo($archivo_nombre, PATHINFO_EXTENSION) === 'sql') {
                        if (restaurarBackupSQL($db, $archivo_tmp)) {
                            $_SESSION['success'] = "Backup restaurado exitosamente";
                        } else {
                            $_SESSION['error'] = "Error al restaurar el backup";
                        }
                    } else {
                        $_SESSION['error'] = "El archivo debe ser un backup SQL válido";
                    }
                } else {
                    $_SESSION['error'] = "Por favor seleccione un archivo de backup válido";
                }
                header('Location: backup.php');
                exit();
                break;
                
            case 'eliminar_backup':
                $archivo = basename($_POST['archivo']??'');if(!preg_match('/^backup_vetnexo_[A-Za-z0-9_.-]+\.(sql|csv)$/',$archivo))av_fail('Archivo no válido.');
                $ruta_archivo = $backup_dir . $archivo;
                
                if (file_exists($ruta_archivo) && is_file($ruta_archivo)) {
                    if (unlink($ruta_archivo)) {
                        $_SESSION['success'] = "Backup eliminado exitosamente";
                    } else {
                        $_SESSION['error'] = "Error al eliminar el backup";
                    }
                } else {
                    $_SESSION['error'] = "El archivo de backup no existe";
                }
                header('Location: backup.php');
                exit();
                break;
                
            case 'descargar_backup':
                $archivo = basename($_POST['archivo']??'');if(!preg_match('/^backup_vetnexo_[A-Za-z0-9_.-]+\.(sql|csv)$/',$archivo))av_fail('Archivo no válido.');
                $ruta_archivo = $backup_dir . $archivo;
                
                if (file_exists($ruta_archivo) && is_file($ruta_archivo)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($ruta_archivo) . '"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($ruta_archivo));
                    readfile($ruta_archivo);
                    exit;
                }
                break;
        }
    }
}

// Obtener lista de backups (solo si es admin)
if (!isset($error_acceso)) {
    $backups = [];
    if (is_dir($backup_dir)) {
        $archivos = scandir($backup_dir);
        foreach ($archivos as $archivo) {
            if ($archivo !== '.' && $archivo !== '..') {
                $extension = pathinfo($archivo, PATHINFO_EXTENSION);
                if (in_array($extension, ['sql', 'csv'])) {
                    $ruta_archivo = $backup_dir . $archivo;
                    $backups[] = [
                        'nombre' => $archivo,
                        'tamaño' => filesize($ruta_archivo),
                        'fecha' => filemtime($ruta_archivo),
                        'tipo' => $extension
                    ];
                }
            }
        }
        
        // Ordenar por fecha (más reciente primero)
        usort($backups, function($a, $b) {
            return $b['fecha'] - $a['fecha'];
        });
    }

    // Obtener estadísticas del sistema
    $estadisticas = [
        'total_backups' => count($backups),
        'tamaño_total' => array_sum(array_column($backups, 'tamaño')),
        'ultimo_backup' => !empty($backups) ? $backups[0]['fecha'] : null,
        'espacio_libre' => disk_free_space(__DIR__),
        'espacio_total' => disk_total_space(__DIR__)
    ];
}

// Función para crear backup SQL
function crearBackupSQL($db, $backup_dir) {
 try {require_once __DIR__.'/config/backup.php';$path=$backup_dir.'backup_vetnexo_'.date('Y-m-d_H-i-s').'_'.bin2hex(random_bytes(3)).'.sql';av_backup_sql($db,$path);return $path;}
 catch(Throwable $e){error_log($e->getMessage());return false;}
}

// Función para crear backup en Excel (CSV)
function crearBackupExcel($db, $backup_dir) {
    try {
        // Obtener todas las tablas
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $excel_content = "";
        
        foreach ($tables as $table) {
            // Encabezado de tabla
            $excel_content .= "=== TABLA: $table ===\n";
            $excel_content .= "Fecha de exportación: " . date('Y-m-d H:i:s') . "\n";
            $excel_content .= str_repeat("=", 50) . "\n\n";
            
            // Obtener nombres de columnas
            $stmt_columns = $db->query("DESCRIBE `$table`");
            $columns = $stmt_columns->fetchAll(PDO::FETCH_COLUMN, 0);
            
            // Encabezados de columnas
            $excel_content .= implode("\t", $columns) . "\n";
            
            // Línea separadora
            $excel_content .= str_repeat("-\t", count($columns)) . "\n";
            
            // Obtener datos de la tabla
            $stmt_data = $db->query("SELECT * FROM `$table`");
            $rows = $stmt_data->fetchAll(PDO::FETCH_ASSOC);
            
            // Agregar datos
            foreach ($rows as $row) {
                $formatted_row = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $formatted_row[] = "NULL";
                    } elseif (is_numeric($value)) {
                        $formatted_row[] = $value;
                    } else {
                        // Escapar comillas y tabuladores para CSV
                        $value = str_replace('"', '""', $value);
                        $value = str_replace("\t", "    ", $value);
                        $formatted_row[] = '"' . $value . '"';
                    }
                }
                $excel_content .= implode("\t", $formatted_row) . "\n";
            }
            
            $excel_content .= "\n\n";
        }
        
        // Guardar como CSV
        $backup_file = $backup_dir . 'backup_vetnexo_' . date('Y-m-d_H-i-s') . '.csv';
        if (file_put_contents($backup_file, $excel_content) !== false) {
            return $backup_file;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error creando backup Excel: " . $e->getMessage());
        return false;
    }
}

// Función para restaurar backup SQL
function restaurarBackupSQL($db, $archivo_backup) {
 try {require_once __DIR__.'/config/backup.php';av_backup_sql($db,__DIR__.'/_private/respaldo_previo_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.sql');$sql=file_get_contents($archivo_backup);if($sql===false)throw new RuntimeException('No se puede leer el SQL.');$db->exec('SET FOREIGN_KEY_CHECKS=0');av_execute_sql($db,$sql);av_execute_sql($db,file_get_contents(__DIR__.'/database/MIGRACION_DEPURACION_20260812.sql'));av_execute_sql($db,file_get_contents(__DIR__.'/database/MIGRACION_LOCAL.sql'));$db->exec('SET FOREIGN_KEY_CHECKS=1');return true;}
 catch(Throwable $e){error_log($e->getMessage());$db->exec('SET FOREIGN_KEY_CHECKS=1');return false;}
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

// Función para formatear bytes
function formatBytes($bytes, $decimals = 2) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}

// Función para obtener icono según tipo de archivo
function getFileIcon($tipo) {
    switch ($tipo) {
        case 'sql':
            return 'bx bx-data';
        case 'csv':
            return 'bx bx-spreadsheet';
        default:
            return 'bx bx-file';
    }
}

// Función para obtener clase de badge según tipo de archivo
function getFileBadgeClass($tipo) {
    switch ($tipo) {
        case 'sql':
            return 'badge-primary';
        case 'csv':
            return 'badge-success';
        default:
            return 'badge-secondary';
    }
}

// Función para obtener texto del tipo de archivo
function getFileTypeText($tipo) {
    switch ($tipo) {
        case 'sql':
            return 'SQL';
        case 'csv':
            return 'Excel/CSV';
        default:
            return strtoupper($tipo);
    }
}

$page_title = "Sistema de Backup - VetNexo";
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

        .btn-primary, .btn-secondary, .btn-success, .btn-danger, .btn-warning {
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #D32F2F);
            color: var(--white);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 67, 54, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--accent-dark));
            color: var(--white);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.4);
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

        .alert-warning {
            background: var(--accent-soft);
            color: var(--accent-dark);
            border: 1px solid var(--accent-light);
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

        /* Acceso Denegado */
        .access-denied {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            padding: 3rem;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .access-denied i {
            font-size: 4rem;
            color: var(--danger);
            margin-bottom: 1rem;
        }

        .access-denied h2 {
            color: var(--danger);
            margin-bottom: 1rem;
        }

        .access-denied p {
            color: var(--text-light);
            margin-bottom: 2rem;
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

        .stat-subtext {
            font-size: 0.8rem;
            color: var(--text-lighter);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.5rem;
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--gray-light);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Secciones */
        .content-section {
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
            margin: 0;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Grid de Acciones */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .action-card {
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .action-card.warning:hover {
            border-color: var(--warning);
        }

        .action-card.info:hover {
            border-color: var(--info);
        }

        .action-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .action-card.warning .action-icon {
            color: var(--warning);
        }

        .action-card.info .action-icon {
            color: var(--info);
        }

        .action-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .action-desc {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        /* Formulario de Restauración */
        .restore-form {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--primary-soft);
            border-radius: var(--radius);
            border: 2px solid var(--warning);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        /* Lista de Backups */
        .backup-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .backup-item {
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .backup-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .backup-info {
            flex: 1;
            min-width: 300px;
        }

        .backup-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .backup-title i {
            font-size: 1.3rem;
            color: var(--primary);
        }

        .backup-title strong {
            font-size: 1rem;
            color: var(--text-dark);
        }

        .backup-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .backup-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-secondary {
            background: var(--gray);
            color: var(--text-light);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
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

        .empty-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        /* Info Items */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            padding: 0.5rem;
        }

        .info-item strong {
            color: var(--text-dark);
            font-weight: 600;
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

        /* Partículas */
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
            background: rgba(46, 125, 50, 0.05);
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
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .backup-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .backup-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .empty-actions {
                flex-direction: column;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
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
                    <h1>
                        <i class='bx bx-cloud-upload'></i>
                        Sistema de Backup
                    </h1>
                    <p>
                        <i class='bx bx-shield-quarter'></i>
                        Gestiona los respaldos de la base de datos
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
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class='bx bx-arrow-back'></i>
                    Volver al Dashboard
                </a>
            </div>

            <!-- Alertas de sesión -->
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

            <?php if (isset($error_acceso)): ?>
                <!-- Acceso Denegado -->
                <div class="access-denied fade-in">
                    <i class='bx bx-shield-x'></i>
                    <h2>Acceso Denegado</h2>
                    <p><?php echo $error_acceso; ?></p>
                    <a href="dashboard.php" class="btn-primary btn-sm">
                        <i class='bx bx-arrow-back'></i>
                        Volver al Dashboard
                    </a>
                </div>
            <?php else: ?>

            <!-- Estadísticas del Sistema -->
            <div class="stats-section fade-in">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-archive'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $estadisticas['total_backups']; ?></div>
                        <div class="stat-label">Total de Backups</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-hdd'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo formatBytes($estadisticas['tamaño_total']); ?></div>
                        <div class="stat-label">Espacio Usado</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-calendar'></i>
                            </div>
                        </div>
                        <div class="stat-number">
                            <?php echo $estadisticas['ultimo_backup'] ? date('d/m/Y', $estadisticas['ultimo_backup']) : 'Nunca'; ?>
                        </div>
                        <div class="stat-label">Último Backup</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-data'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo formatBytes($estadisticas['espacio_libre']); ?></div>
                        <div class="stat-label">Espacio Libre</div>
                        <?php 
                        $porcentaje_usado = (($estadisticas['espacio_total'] - $estadisticas['espacio_libre']) / $estadisticas['espacio_total']) * 100;
                        ?>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $porcentaje_usado; ?>%"></div>
                        </div>
                        <div class="stat-subtext">
                            <?php echo round($porcentaje_usado, 1); ?>% utilizado
                        </div>
                    </div>
                </div>
            </div>

            <!-- Acciones Rápidas -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-plus-circle'></i>
                        Acciones Rápidas
                    </h3>
                </div>
                <div class="section-body">
                    <div class="actions-grid">
                        <!-- Backup SQL -->
                        <form method="POST" id="formBackupSQL" style="display: contents;"><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="accion" value="crear_backup">
                            <div class="action-card" onclick="document.getElementById('formBackupSQL').submit()">
                                <div class="action-icon">
                                    <i class='bx bx-data'></i>
                                </div>
                                <div class="action-title">Backup SQL</div>
                                <div class="action-desc">Respaldo completo para restauración</div>
                            </div>
                        </form>
                        
                        <!-- Backup Excel -->
                        <form method="POST" id="formBackupExcel" style="display: contents;"><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="accion" value="crear_backup_excel">
                            <div class="action-card warning" onclick="document.getElementById('formBackupExcel').submit()">
                                <div class="action-icon">
                                    <i class='bx bx-spreadsheet'></i>
                                </div>
                                <div class="action-title">Backup Excel</div>
                                <div class="action-desc">Exportación para análisis de datos</div>
                            </div>
                        </form>
                        
                        <!-- Restaurar Backup -->
                        <div class="action-card info" onclick="toggleRestoreForm()">
                            <div class="action-icon">
                                <i class='bx bx-upload'></i>
                            </div>
                            <div class="action-title">Restaurar Backup</div>
                            <div class="action-desc">Recuperar datos desde un respaldo</div>
                        </div>
                    </div>

                    <!-- Formulario de Restauración -->
                    <div id="restoreForm" class="restore-form" style="display: none;">
                        <div class="alert alert-warning">
                            <i class='bx bx-error'></i>
                            <strong>¡ADVERTENCIA!</strong> Restaurar un backup reemplazará TODOS los datos actuales. Esta acción NO se puede deshacer.
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="restoreBackupForm"><?php echo av_csrf_field(); ?>
                            <input type="hidden" name="accion" value="restaurar_backup">
                            
                            <div class="form-group">
                                <label class="form-label">Seleccionar archivo de backup (.sql)</label>
                                <input type="file" class="form-control" name="archivo_backup" accept=".sql" required>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn-danger btn-sm" onclick="return confirmarRestauracion(event)">
                                    <i class='bx bx-upload'></i>
                                    Restaurar Backup
                                </button>
                                <button type="button" class="btn-secondary btn-sm" onclick="toggleRestoreForm()">
                                    <i class='bx bx-x'></i>
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Lista de Backups -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-archive'></i>
                        Backups Existentes (<?php echo count($backups); ?>)
                    </h3>
                </div>
                <div class="section-body">
                    <?php if (!empty($backups)): ?>
                        <div class="backup-list">
                            <?php foreach ($backups as $backup): ?>
                                <div class="backup-item">
                                    <div class="backup-info">
                                        <div class="backup-title">
                                            <i class='<?php echo getFileIcon($backup['tipo']); ?>'></i>
                                            <strong><?php echo htmlspecialchars($backup['nombre']); ?></strong>
                                            <span class="badge <?php echo getFileBadgeClass($backup['tipo']); ?>">
                                                <?php echo getFileTypeText($backup['tipo']); ?>
                                            </span>
                                        </div>
                                        <div class="backup-meta">
                                            <span><i class='bx bx-calendar'></i> <?php echo date('d/m/Y H:i', $backup['fecha']); ?></span>
                                            <span><i class='bx bx-data'></i> <?php echo formatBytes($backup['tamaño']); ?></span>
                                        </div>
                                    </div>
                                    <div class="backup-actions">
                                        <form method="POST" style="display: inline;"><?php echo av_csrf_field(); ?>
                                            <input type="hidden" name="accion" value="descargar_backup">
                                            <input type="hidden" name="archivo" value="<?php echo $backup['nombre']; ?>">
                                            <button type="submit" class="btn-primary btn-sm" title="Descargar">
                                                <i class='bx bx-download'></i>
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;"><?php echo av_csrf_field(); ?>
                                            <input type="hidden" name="accion" value="eliminar_backup">
                                            <input type="hidden" name="archivo" value="<?php echo $backup['nombre']; ?>">
                                            <button type="submit" class="btn-danger btn-sm" title="Eliminar" onclick="return confirmarEliminacion(event)">
                                                <i class='bx bx-trash'></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-archive-out'></i>
                            <h3>No hay backups disponibles</h3>
                            <p>No se han creado backups del sistema aún.</p>
                            <div class="empty-actions">
                                <form method="POST" style="display: inline;"><?php echo av_csrf_field(); ?>
                                    <input type="hidden" name="accion" value="crear_backup">
                                    <button type="submit" class="btn-primary btn-sm">
                                        <i class='bx bx-data'></i>
                                        Crear Backup SQL
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;"><?php echo av_csrf_field(); ?>
                                    <input type="hidden" name="accion" value="crear_backup_excel">
                                    <button type="submit" class="btn-success btn-sm">
                                        <i class='bx bx-spreadsheet'></i>
                                        Crear Backup Excel
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Información del Sistema -->
            <div class="content-section fade-in">
                <div class="section-header">
                    <h3>
                        <i class='bx bx-info-circle'></i>
                        Información del Sistema
                    </h3>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <strong>PHP Version:</strong> <?php echo phpversion(); ?>
                        </div>
                        <div class="info-item">
                            <strong>MySQL Version:</strong> <?php echo $db->getAttribute(PDO::ATTR_SERVER_VERSION); ?>
                        </div>
                        <div class="info-item">
                            <strong>Directorio de Backups:</strong> <?php echo realpath($backup_dir); ?>
                        </div>
                        <div class="info-item">
                            <strong>Última Ejecución:</strong> <?php echo date('d/m/Y H:i:s'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-content">
                    <p>© <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
                </div>
            </footer>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        // Variables para formularios
        let restoreFormVisible = false;

        // Funciones para el formulario de restauración
        function toggleRestoreForm() {
            const form = document.getElementById('restoreForm');
            restoreFormVisible = !restoreFormVisible;
            form.style.display = restoreFormVisible ? 'block' : 'none';
            
            if (restoreFormVisible) {
                form.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Confirmación para eliminar backup
        function confirmarEliminacion(event) {
            if (!confirm('¿Está seguro de eliminar este backup?\nEsta acción no se puede deshacer.')) {
                event.preventDefault();
                return false;
            }
            return true;
        }

        // Confirmación para restaurar backup
        function confirmarRestauracion(event) {
            event.preventDefault();
            
            const fileInput = document.querySelector('input[name="archivo_backup"]');
            if (!fileInput.files.length) {
                alert('Por favor seleccione un archivo de backup.');
                return false;
            }
            
            const fileName = fileInput.files[0].name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            if (fileExt !== 'sql') {
                alert('Solo se pueden restaurar archivos .sql');
                return false;
            }
            
            const confirmation = confirm(
                '⚠️ ¡ADVERTENCIA CRÍTICA! ⚠️\n\n' +
                '¿ESTÁ ABSOLUTAMENTE SEGURO DE RESTAURAR ESTE BACKUP?\n\n' +
                'Esta acción:\n' +
                '• Reemplazará TODOS los datos actuales\n' +
                '• NO se puede deshacer\n' +
                '• Podría causar pérdida de datos\n\n' +
                'Escriba "CONFIRMAR" para proceder:'
            );
            
            if (confirmation) {
                const userInput = prompt('Escriba "CONFIRMAR" para proceder con la restauración:');
                if (userInput === 'CONFIRMAR') {
                    document.getElementById('restoreBackupForm').submit();
                } else {
                    alert('Restauración cancelada. El texto no coincide.');
                }
            }
            
            return false;
        }

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de estadísticas
            animateStats();
            
            // Crear partículas
            createParticles();
        });

        function animateStats() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseFloat(text.replace(/[^0-9.-]+/g, ''));
                
                if (!isNaN(number) && number > 0 && !text.includes('/')) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = text.replace(number.toString(), Math.floor(current).toLocaleString());
                    }, 30);
                }
            });
        }

        function createParticles() {
            const particlesContainer = document.querySelector('.particles');
            for (let i = 0; i < 10; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    animation-delay: ${Math.random() * 5}s;
                    opacity: ${Math.random() * 0.5 + 0.1};
                    background: ${Math.random() > 0.5 ? 'rgba(46, 125, 50, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        }
    </script>
</body>
</html>