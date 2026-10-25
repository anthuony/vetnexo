<?php
// login.php
require_once __DIR__ . '/config/bootstrap.php';
require_once 'config/database.php';

// Si ya está logueado, redirigir según su rol
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['rol'] == 'cliente') {
        $redirect=$_GET['redirect']??''; header('Location: '.(in_array($redirect,['tienda/checkout.php','tienda/pedidos.php','tienda/carrito.php'],true)?$redirect:'cliente/dashboard.php'));
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$database = new Database();
$conn = $database->conectar();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = "Por favor ingrese usuario y contraseña";
    } else {
        // Buscar usuario
        $stmt = $conn->prepare("SELECT * FROM usuarios WHERE username = ? AND estado = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Protección contra fijación de sesión y compatibilidad con módulos antiguos
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['usuario_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nombre'] = $user['nombre'];
            $_SESSION['usuario_nombre'] = $user['nombre'];
            $_SESSION['rol'] = $user['rol'];
            $_SESSION['usuario_rol'] = $user['rol'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['usuario_email'] = $user['email'];
            $_SESSION['ultimo_acceso'] = time();

            // Registrar último acceso sin romper login si la columna no existe
            try {
                $stmtAcceso = $conn->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
                $stmtAcceso->execute([(int)$user['id']]);
            } catch (Throwable $e) {
                error_log('No se pudo actualizar ultimo_acceso: ' . $e->getMessage());
            }
            
            // Si es cliente, guardar también el cliente_id
            if ($user['rol'] == 'cliente') {
                $_SESSION['cliente_id'] = $user['cliente_id'];
                $redirect=$_GET['redirect']??''; header('Location: '.(in_array($redirect,['tienda/checkout.php','tienda/pedidos.php','tienda/carrito.php'],true)?$redirect:'cliente/dashboard.php'));
            } else {
                // Admin, veterinario, asistente, recepcionista
                header("Location: dashboard.php");
            }
            exit();
        } else {
            $error = "Usuario o contraseña incorrectos";
        }
    }
}

// Función para obtener el color del rol (para el gradiente del header)
function getRolGradient($rol = null) {
    return "linear-gradient(135deg, #0F6C33, #0a4d25)";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - VetNexo</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --verde-bosque: #0F6C33;
            --verde-bosque-dark: #0a4d25;
            --amarillo-intenso: #FBEF16;
            --rojo-corazon: #F91302;
            --marron-chocolate: #5A4B37;
            --blanco-puro: #FFFFFF;
            --naranja-vibrante: #F99B07;
            --azul-real: #2261BD;
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --background: #F8FDF8;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow: 0 5px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
            --radius-sm: 8px;
            --radius: 16px;
            --radius-lg: 24px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0f7f0 0%, #e0f0e0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Elementos decorativos de fondo */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(249, 19, 2, 0.03) 0%, transparent 30%);
            animation: rotate 60s linear infinite;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(249, 155, 7, 0.03) 0%, transparent 30%);
            animation: rotate 60s linear infinite reverse;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .login-container {
            max-width: 420px;
            width: 100%;
            background: var(--blanco-puro);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: relative;
            z-index: 10;
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, var(--verde-bosque), var(--verde-bosque-dark));
            color: var(--blanco-puro);
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(251, 239, 22, 0.15) 0%, transparent 50%);
            animation: rotate 30s linear infinite;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 0;
            width: 100%;
            height: 40px;
            background: linear-gradient(135deg, transparent 50%, var(--rojo-corazon) 50%);
            opacity: 0.3;
        }

        .login-header img {
            height: 70px;
            width: auto;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
        }

        .login-header h2 {
            font-size: 2rem;
            font-weight: 600;
            position: relative;
            z-index: 2;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .login-header p {
            position: relative;
            z-index: 2;
            opacity: 0.9;
            font-size: 0.95rem;
            margin-top: 0.5rem;
        }

        .login-body {
            padding: 2.5rem 2rem;
            background: white;
        }

        .form-group {
            margin-bottom: 1.8rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .form-group label i {
            color: var(--naranja-vibrante);
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--verde-bosque);
            font-size: 1.2rem;
            opacity: 0.7;
        }

        .form-control {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 2.8rem;
            border: 2px solid var(--gray);
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--gray-light);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--verde-bosque);
            background: white;
            box-shadow: 0 0 0 4px rgba(15, 108, 51, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--naranja-vibrante), var(--rojo-corazon));
            color: var(--blanco-puro);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(249, 19, 2, 0.3);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-login:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(249, 19, 2, 0.4);
        }

        .btn-login i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .btn-login:hover i {
            transform: translateX(5px);
        }

        .alert {
            padding: 1rem 1.2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.95rem;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        .alert i {
            font-size: 1.3rem;
        }

        .register-link {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px dashed var(--gray);
        }

        .register-link a {
            color: var(--verde-bosque);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.5rem;
            background: var(--gray-light);
            border-radius: 50px;
        }

        .register-link a:hover {
            background: var(--verde-bosque);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .register-link a i {
            transition: transform 0.3s ease;
        }

        .register-link a:hover i {
            transform: translateX(5px);
        }

        .back-link {
            text-align: center;
            margin-top: 1.2rem;
        }

        .back-link a {
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .back-link a:hover {
            color: var(--azul-real);
        }

        .back-link a i {
            transition: transform 0.3s ease;
        }

        .back-link a:hover i {
            transform: translateX(-5px);
        }

        /* Efecto de partículas flotantes (opcional) */
        .floating-particle {
            position: absolute;
            background: rgba(249, 155, 7, 0.1);
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                margin: 1rem;
            }
            
            .login-header {
                padding: 2rem 1.5rem;
            }
            
            .login-header h2 {
                font-size: 1.8rem;
            }
            
            .login-body {
                padding: 2rem 1.5rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Partículas flotantes (decorativas) -->
    <div class="floating-particle" style="top: 10%; left: 10%; width: 100px; height: 100px; animation: float 20s infinite;"></div>
    <div class="floating-particle" style="top: 70%; right: 15%; width: 150px; height: 150px; animation: float 25s infinite reverse;"></div>
    <div class="floating-particle" style="bottom: 20%; left: 20%; width: 80px; height: 80px; animation: float 15s infinite;"></div>

    <style>
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
            100% { transform: translate(0, 0) rotate(360deg); }
        }
    </style>

    <div class="login-container">
        <div class="login-header">
            <img src="includes/img/logo.svg" alt="VetNexo">
            <h2>¡Bienvenido!</h2>
            <p>Ingresa a tu cuenta para continuar</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert">
                    <i class='bx bx-error-circle'></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action=""><?php echo av_csrf_field(); ?>
                <div class="form-group">
                    <label><i class='bx bx-user-circle'></i> Usuario</label>
                    <div class="input-wrapper">
                        <i class='bx bx-user'></i>
                        <input type="text" name="username" class="form-control" placeholder="Ingresa tu usuario" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label><i class='bx bx-lock-alt'></i> Contraseña</label>
                    <div class="input-wrapper">
                        <i class='bx bx-lock'></i>
                        <input type="password" name="password" class="form-control" placeholder="Ingresa tu contraseña" required>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class='bx bx-log-in-circle'></i>
                    Iniciar Sesión
                </button>

                <div class="register-link">
                    <a href="registro_cliente.php">
                        ¿No tienes cuenta? Regístrate
                        <i class='bx bx-chevron-right'></i>
                    </a>
                </div>

                <div class="back-link">
                    <a href="index.php">
                        <i class='bx bx-arrow-back'></i>
                        Volver al inicio
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>