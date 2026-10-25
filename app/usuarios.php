<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_admin();

require_once 'config/database.php';

// Obtener conexión a la base de datos usando la función del archivo database.php
$pdo = getDBConnection();

// Verificar si la conexión se estableció correctamente
if (!$pdo) {
    die("Error: No se pudo establecer conexión con la base de datos");
}

// Permitir la entrada a cualquier usuario logueado
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$error='';$success='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')try{
 $pdo->beginTransaction();$action=$_POST['accion']??'';$id=(int)($_POST['id']??0);
 $admins=$pdo->query("SELECT id FROM usuarios WHERE rol='admin' AND estado='active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
 $q=$pdo->prepare('SELECT * FROM usuarios WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();
 if($action!=='crear'&&!$old)throw new RuntimeException('Usuario no encontrado.');
 if(in_array($action,['crear','editar'],true)){
  $name=trim($_POST['nombre']??'');$email=trim($_POST['email']??'');$username=trim($_POST['username']??'');$role=$_POST['rol']??'';$state=$_POST['estado']??'';$phone=trim($_POST['telefono']??'');
  if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username))throw new RuntimeException('Nombre, correo o usuario no válido.');
  if(!in_array($role,['admin','veterinarian','assistant','reception','cliente'],true)||!in_array($state,['active','inactive','pending'],true))throw new RuntimeException('Rol o estado no válido.');
  if($id===auth_user_id()&&($role!=='admin'||$state!=='active'))throw new RuntimeException('No puede quitar permisos ni desactivar su propia cuenta.');
  if($old&&$old['rol']==='admin'&&$old['estado']==='active'&&count($admins)<=1&&($role!=='admin'||$state!=='active'))throw new RuntimeException('Debe quedar al menos un administrador activo.');
  $q=$pdo->prepare('SELECT id FROM usuarios WHERE (username=? OR email=?) AND id<>?');$q->execute([$username,$email,$id]);if($q->fetchColumn())throw new RuntimeException('El usuario o correo ya existe.');
  $client=$old['cliente_id']??null;
  if($role==='cliente'&&!$client){$pdo->prepare('INSERT INTO clientes(nombre,email,telefono) VALUES(?,?,?)')->execute([$name,$email,$phone]);$client=$pdo->lastInsertId();}
  if($action==='crear'){$pass=$_POST['password']??'';if(strlen($pass)<8)throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');$pdo->prepare('INSERT INTO usuarios(nombre,email,telefono,username,password_hash,rol,estado,cliente_id) VALUES(?,?,?,?,?,?,?,?)')->execute([$name,$email,$phone,$username,password_hash($pass,PASSWORD_DEFAULT),$role,$state,$client]);}
  else $pdo->prepare('UPDATE usuarios SET nombre=?,email=?,telefono=?,username=?,rol=?,estado=?,cliente_id=? WHERE id=?')->execute([$name,$email,$phone,$username,$role,$state,$client,$id]);
 }elseif($action==='cambiar_estado'){
  $state=$_POST['nuevo_estado']??'';if(!in_array($state,['active','inactive','pending'],true))throw new RuntimeException('Estado no válido.');
  if($id===auth_user_id()&&$state!=='active')throw new RuntimeException('No puede desactivar su propia cuenta.');
  if($old['rol']==='admin'&&$old['estado']==='active'&&count($admins)<=1&&$state!=='active')throw new RuntimeException('Debe quedar un administrador activo.');
  $pdo->prepare('UPDATE usuarios SET estado=? WHERE id=?')->execute([$state,$id]);
 }elseif($action==='cambiar_password'){
  $pass=$_POST['nueva_password']??'';if(strlen($pass)<8)throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');$pdo->prepare('UPDATE usuarios SET password_hash=? WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$id]);
 }else throw new RuntimeException('Acción no válida.');
 $pdo->commit();$success='Usuario actualizado correctamente.';
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[usuarios] '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo guardar el usuario.':$e->getMessage();}

// Obtener lista de usuarios
$sql_usuarios = "SELECT * FROM usuarios ORDER BY fecha_creacion DESC";
$stmt_usuarios = $pdo->query($sql_usuarios);
$usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);

// Obtener usuario específico para edición
$usuario_editar = null;
if (isset($_GET['editar'])) {
    $sql_editar = "SELECT * FROM usuarios WHERE id = ?";
    $stmt_editar = $pdo->prepare($sql_editar);
    $stmt_editar->execute([$_GET['editar']]);
    $usuario_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// Calcular estadísticas
$total_usuarios = count($usuarios);
$usuarios_activos = array_filter($usuarios, function($user) {
    return $user['estado'] === 'active';
});
$total_activos = count($usuarios_activos);
$usuarios_admin = array_filter($usuarios, function($user) {
    return $user['rol'] === 'admin';
});
$total_admin = count($usuarios_admin);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - VetNexo</title>
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
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

        /* Header Mejorado */
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
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

        .btn-back {
            background: var(--primary-very-light);
            color: var(--primary-dark);
            border: 1px solid var(--primary);
        }

        .btn-back:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: #388E3C;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }

        /* Estadísticas Mejoradas */
        .stats-section {
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
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
            background: var(--primary-very-light);
            color: var(--primary);
        }

        .stat-icon.warning {
            color: var(--warning);
            background: rgba(255, 193, 7, 0.1);
        }

        .stat-icon.danger {
            color: var(--danger);
            background: rgba(244, 67, 54, 0.1);
        }

        .stat-icon.accent {
            color: var(--accent);
            background: rgba(255, 152, 0, 0.1);
        }

        .stat-icon.secondary {
            color: var(--secondary);
            background: rgba(33, 150, 243, 0.1);
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
            margin-bottom: 0.5rem;
        }

        /* Secciones de Contenido */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            height: fit-content;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
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

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            background: var(--white);
            cursor: pointer;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        /* Users Grid */
        .users-grid {
            display: grid;
            gap: 1.5rem;
        }

        .user-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .user-card.inactive {
            opacity: 0.7;
            background: var(--gray-light);
        }

        .user-card.pending {
            border-left: 4px solid var(--warning);
        }

        .user-card.inactive {
            border-left: 4px solid var(--danger);
        }

        .user-card.active {
            border-left: 4px solid var(--success);
        }

        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .user-info h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-dark);
            font-size: 1.2rem;
        }

        .user-meta {
            color: var(--text-light);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .user-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .user-content {
                grid-template-columns: 1fr;
            }
        }

        .user-item {
            margin-bottom: 1rem;
        }

        .user-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.9rem;
        }

        .user-value {
            color: var(--text-dark);
            line-height: 1.6;
            padding: 0.5rem 0;
        }

        /* Badges */
        .badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
            color: #22543d;
        }

        .badge-warning {
            background: linear-gradient(135deg, #fefcbf, #faf089);
            color: #744210;
        }

        .badge-danger {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            color: #742a2a;
        }

        .badge-info {
            background: linear-gradient(135deg, #bee3f8, #90cdf4);
            color: #2a4365;
        }

        .badge-secondary {
            background: linear-gradient(135deg, #e2e3e5, #c8c9cb);
            color: #383d41;
        }

        .badge-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: var(--white);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            border-radius: var(--radius-sm);
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            text-align: center;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 2rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: #c6f6d5;
            border-color: #38a169;
            color: #22543d;
        }

        .alert-error {
            background: #fed7d7;
            border-color: #e53e3e;
            color: #742a2a;
        }

        /* Footer Minimalista */
        .main-footer {
            background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e9 100%);
            color: var(--text-dark);
            padding: 1.5rem 0;
            margin-top: auto;
            border-top: 1px solid rgba(76, 175, 80, 0.2);
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .footer-content {
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .footer-simple {
            font-size: 0.9rem;
            color: var(--text-light);
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 25px;
            border: 1px solid rgba(76, 175, 80, 0.2);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-sm);
        }

        .footer-text {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .footer-text strong {
            color: var(--primary-dark);
            font-weight: 600;
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
                padding: 1.5rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .section-title {
                font-size: 1.1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .footer-content {
                padding: 0 1rem;
            }
            
            .footer-simple {
                font-size: 0.8rem;
                padding: 0.6rem 1rem;
            }
            
            .footer-text {
                flex-direction: column;
                gap: 0.25rem;
            }
        }

        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Animación de partículas */
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
            background: rgba(76, 175, 80, 0.05);
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

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius);
            padding: 2rem;
            width: 90%;
            max-width: 400px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-very-light);
        }

        .modal-header h3 {
            margin: 0;
            color: var(--primary-dark);
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
                    <h1><i class='bx bx-user'></i> Gestión de Usuarios</h1>
                    <p>Administra los usuarios del sistema - VetNexo</p>
                </div>
                
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-back">
                        <i class='bx bx-arrow-back'></i>
                        Volver al Dashboard
                    </a>
                </div>
            </header>

            <!-- Mensajes -->
            <?php if ($success!==''): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle'></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error!==''): ?>
                <div class="alert alert-error">
                    <i class='bx bx-error-circle'></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Estadísticas Principales -->
            <div class="stats-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon">
                                <i class='bx bx-group'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $total_usuarios; ?></div>
                        <div class="stat-label">Total de Usuarios</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon accent">
                                <i class='bx bx-user-check'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $total_activos; ?></div>
                        <div class="stat-label">Usuarios Activos</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon secondary">
                                <i class='bx bx-shield'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $total_admin; ?></div>
                        <div class="stat-label">Administradores</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class='bx bx-user-x'></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $total_usuarios - $total_activos; ?></div>
                        <div class="stat-label">Usuarios Inactivos</div>
                    </div>
                </div>
            </div>

            <!-- Formulario de Usuario -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class='bx <?php echo $usuario_editar ? 'bx-edit' : 'bx-user-plus'; ?>'></i>
                        <?php echo $usuario_editar ? 'Editar Usuario' : 'Nuevo Usuario'; ?>
                    </h3>
                    <?php if ($usuario_editar): ?>
                        <a href="usuarios.php" class="btn btn-back">
                            <i class='bx bx-plus'></i>
                            Nuevo Usuario
                        </a>
                    <?php endif; ?>
                </div>
                
                <form method="POST"><?php echo av_csrf_field(); ?>
                    <input type="hidden" name="accion" value="<?php echo $usuario_editar ? 'editar' : 'crear'; ?>">
                    <?php if ($usuario_editar): ?>
                        <input type="hidden" name="id" value="<?php echo $usuario_editar['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="nombre" class="form-label">Nombre Completo *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                   value="<?php echo $usuario_editar ? htmlspecialchars($usuario_editar['nombre']) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo $usuario_editar ? htmlspecialchars($usuario_editar['email']) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="telefono" name="telefono" 
                                   value="<?php echo $usuario_editar ? htmlspecialchars($usuario_editar['telefono']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="username" class="form-label">Nombre de Usuario *</label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?php echo $usuario_editar ? htmlspecialchars($usuario_editar['username']) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="form-label">
                                <?php echo $usuario_editar ? 'Nueva Contraseña (dejar en blanco para no cambiar)' : 'Contraseña *'; ?>
                            </label>
                            <input type="password" class="form-control" id="password" name="password" 
                                   <?php echo $usuario_editar ? '' : 'required'; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="rol" class="form-label">Rol *</label>
                            <select class="form-select" id="rol" name="rol" required>
                                <option value="">Seleccionar rol...</option>
                                <option value="admin" <?php echo ($usuario_editar && $usuario_editar['rol'] === 'admin') ? 'selected' : ''; ?>>Administrador</option>
                                <option value="veterinarian" <?php echo ($usuario_editar && $usuario_editar['rol'] === 'veterinarian') ? 'selected' : ''; ?>>Veterinario</option>
                                <option value="assistant" <?php echo ($usuario_editar && $usuario_editar['rol'] === 'assistant') ? 'selected' : ''; ?>>Asistente</option>
                                <option value="reception" <?php echo ($usuario_editar && $usuario_editar['rol'] === 'reception') ? 'selected' : ''; ?>>Recepción</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="estado" class="form-label">Estado *</label>
                            <select class="form-select" id="estado" name="estado" required>
                                <option value="active" <?php echo ($usuario_editar && $usuario_editar['estado'] === 'active') ? 'selected' : ''; ?>>Activo</option>
                                <option value="inactive" <?php echo ($usuario_editar && $usuario_editar['estado'] === 'inactive') ? 'selected' : ''; ?>>Inactivo</option>
                                <option value="pending" <?php echo ($usuario_editar && $usuario_editar['estado'] === 'pending') ? 'selected' : ''; ?>>Pendiente</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class='bx <?php echo $usuario_editar ? 'bx-save' : 'bx-user-plus'; ?>'></i>
                            <?php echo $usuario_editar ? 'Actualizar Usuario' : 'Crear Usuario'; ?>
                        </button>
                        <?php if ($usuario_editar): ?>
                            <a href="usuarios.php" class="btn btn-back">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Lista de Usuarios -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class='bx bx-list-ul'></i>
                        Lista de Usuarios (<?php echo $total_usuarios; ?>)
                    </h3>
                </div>
                
                <div class="users-grid">
                    <?php if (!empty($usuarios)): ?>
                        <?php foreach ($usuarios as $usuario): ?>
                            <div class="user-card <?php echo $usuario['estado']; ?>">
                                <div class="user-header">
                                    <div class="user-info">
                                        <h3><?php echo htmlspecialchars($usuario['nombre']); ?></h3>
                                        <div class="user-meta">
                                            <span><strong>Usuario:</strong> <?php echo htmlspecialchars($usuario['username']); ?></span>
                                            <span><strong>Email:</strong> <?php echo htmlspecialchars($usuario['email']); ?></span>
                                        </div>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="usuarios.php?editar=<?php echo $usuario['id']; ?>" 
                                           class="btn btn-primary btn-sm" title="Editar">
                                            <i class='bx bx-edit'></i>
                                        </a>
                                        
                                        <form method="POST" style="display: inline;"><?php echo av_csrf_field(); ?>
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                            <input type="hidden" name="nuevo_estado" 
                                                   value="<?php echo $usuario['estado'] === 'active' ? 'inactive' : 'active'; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" 
                                                    title="<?php echo $usuario['estado'] === 'active' ? 'Desactivar' : 'Activar'; ?>"
                                                    onclick="return confirm('¿<?php echo $usuario['estado'] === 'active' ? 'Desactivar' : 'Activar'; ?> este usuario?')">
                                                <i class='bx bx-<?php echo $usuario['estado'] === 'active' ? 'pause' : 'play'; ?>'></i>
                                            </button>
                                        </form>
                                        
                                        <button type="button" class="btn btn-success btn-sm" 
                                                onclick="abrirModalPassword(<?php echo $usuario['id']; ?>,'<?php echo htmlspecialchars($usuario['nombre']); ?>')"
                                                title="Cambiar Contraseña">
                                            <i class='bx bx-key'></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="user-content">
                                    <div>
                                        <div class="user-item">
                                            <span class="user-label">Rol</span>
                                            <div class="user-value">
                                                <?php 
                                                $roles = [
                                                    'admin' => 'Administrador',
                                                    'veterinarian' => 'Veterinario',
                                                    'assistant' => 'Asistente',
                                                    'reception' => 'Recepción'
                                                ];
                                                $role_color = [
                                                    'admin' => 'badge-danger',
                                                    'veterinarian' => 'badge-primary',
                                                    'assistant' => 'badge-info',
                                                    'reception' => 'badge-secondary'
                                                ];
                                                ?>
                                                <span class="badge <?php echo $role_color[$usuario['rol']] ?? 'badge-secondary'; ?>">
                                                    <?php echo $roles[$usuario['rol']] ?? $usuario['rol']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="user-item">
                                            <span class="user-label">Estado</span>
                                            <div class="user-value">
                                                <?php
                                                $estados = [
                                                    'active' => 'Activo',
                                                    'inactive' => 'Inactivo',
                                                    'pending' => 'Pendiente'
                                                ];
                                                $badge_class = [
                                                    'active' => 'badge-success',
                                                    'inactive' => 'badge-danger',
                                                    'pending' => 'badge-warning'
                                                ];
                                                ?>
                                                <span class="badge <?php echo $badge_class[$usuario['estado']]; ?>">
                                                    <?php echo $estados[$usuario['estado']]; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="user-item">
                                            <span class="user-label">Teléfono</span>
                                            <div class="user-value">
                                                <?php echo !empty($usuario['telefono']) ? htmlspecialchars($usuario['telefono']) : 'No especificado'; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="user-item">
                                            <span class="user-label">Último Acceso</span>
                                            <div class="user-value">
                                                <?php 
                                                echo $usuario['ultimo_acceso'] 
                                                    ? date('d/m/Y H:i', strtotime($usuario['ultimo_acceso'])) 
                                                    : 'Nunca';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class='bx bx-user-x'></i>
                            <h4>No hay usuarios registrados</h4>
                            <p>No se han registrado usuarios en el sistema.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer Minimalista -->
            <footer class="main-footer">
                <div class="footer-content">
                    <div class="footer-simple">
                        <span class="footer-text">© 2026 VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></span>
                    </div>
                </div>
            </footer>
        </main>
    </div>

    <!-- Modal para cambiar contraseña -->
    <div class="modal-overlay" id="modalPassword">
        <div class="modal">
            <div class="modal-header">
                <h3>Cambiar Contraseña</h3>
                <button class="modal-close" onclick="cerrarModalPassword()">&times;</button>
            </div>
            <form method="POST" id="formPassword"><?php echo av_csrf_field(); ?>
                <input type="hidden" name="accion" value="cambiar_password">
                <input type="hidden" name="id" id="userId">
                
                <div class="form-group">
                    <label for="nueva_password" class="form-label" id="passwordLabel">Nueva Contraseña para <span id="userName"></span></label>
                    <input type="password" class="form-control" id="nueva_password" name="nueva_password" required>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="button" class="btn btn-back" onclick="cerrarModalPassword()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cambiar Contraseña</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        function abrirModalPassword(userId, userName) {
            document.getElementById('userId').value = userId;
            document.getElementById('userName').textContent = userName;
            document.getElementById('modalPassword').style.display = 'flex';
        }

        function cerrarModalPassword() {
            document.getElementById('modalPassword').style.display = 'none';
            document.getElementById('formPassword').reset();
        }

        // Cerrar modal al hacer clic fuera
        document.getElementById('modalPassword').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalPassword();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Animación de contadores
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const text = stat.textContent;
                const number = parseInt(text.replace(/[^0-9]/g, ''));
                
                if (!isNaN(number) && number > 0) {
                    let current = 0;
                    const increment = number / 30;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= number) {
                            current = number;
                            clearInterval(timer);
                        }
                        stat.textContent = text.replace(number, Math.floor(current).toLocaleString());
                    }, 30);
                }
            });

            // Efecto de carga para las secciones
            const sections = document.querySelectorAll('.content-section');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100 + 300);
            });

            // Crear partículas dinámicas
            const particlesContainer = document.querySelector('.particles');
            for (let i = 0; i < 15; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 10 + 5}px;
                    height: ${Math.random() * 10 + 5}px;
                    animation-delay: ${Math.random() * 5}s;
                    opacity: ${Math.random() * 0.5 + 0.1};
                    background: ${Math.random() > 0.5 ? 'rgba(76, 175, 80, 0.05)' : 'rgba(255, 152, 0, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        });
    </script>
</body>
</html>