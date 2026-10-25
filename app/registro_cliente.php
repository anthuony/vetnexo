<?php
// registro_cliente.php - Versión mejorada con diseño moderno y profesional
require_once __DIR__ . '/config/bootstrap.php';
require_once 'config/database.php';

// Si ya está logueado, redirigir
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['rol'] == 'cliente') {
        header("Location: cliente/dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$database = new Database();
$conn = $database->conectar();

$error = '';
$success = '';
$show_form = true; // Controlar si mostramos el formulario

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Obtener datos del formulario
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validaciones básicas
    if (empty($nombre) || empty($email) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = "Por favor, complete todos los campos obligatorios.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "El correo electrónico no es válido.";
    } elseif ($password !== $confirm_password) {
        $error = "Las contraseñas no coinciden.";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } else {
        // Verificar si el username o email ya existen
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->rowCount() > 0) {
            $error = "El nombre de usuario o correo electrónico ya está registrado.";
        } else {
            // Iniciar transacción
            $conn->beginTransaction();
            try {
                // Insertar en clientes
                $stmt = $conn->prepare("INSERT INTO clientes (nombre, email, telefono, direccion, documento_identidad) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $email, $telefono, $direccion, $documento]);
                $cliente_id = $conn->lastInsertId();

                // Encriptar contraseña
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Insertar en usuarios con rol 'cliente' y el cliente_id
                $stmt = $conn->prepare("INSERT INTO usuarios (cliente_id, nombre, email, telefono, username, password_hash, rol, estado) VALUES (?, ?, ?, ?, ?, ?, 'cliente', 'active')");
                $stmt->execute([$cliente_id, $nombre, $email, $telefono, $username, $password_hash]);

                $conn->commit();
                
                // Mensaje de éxito y ocultar formulario
                $success = "¡Registro exitoso! Ya puedes iniciar sesión.";
                $show_form = false; // Ocultar formulario después del éxito
                
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error al registrar: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Cliente</title>
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
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
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --danger: #F44336;
            --warning: #FFC107;
            --success: #4CAF50;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 5px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
            --radius-sm: 8px;
            --radius: 16px;
            --radius-lg: 24px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--primary-soft) 0%, var(--background) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Partículas de fondo */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            background: rgba(46, 125, 50, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(180deg); }
        }

        .registro-container {
            max-width: 550px;
            width: 100%;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            z-index: 10;
            border: 1px solid rgba(46, 125, 50, 0.1);
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

        .registro-header {
            background: linear-gradient(135deg, #0F6C33 0%, #0a4d25 100%);
            color: var(--white);
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .registro-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none" opacity="0.1"><path d="M0,50 Q25,40 50,50 T100,50 L100,100 L0,100 Z" fill="white"/></svg>');
            background-size: cover;
        }

        /* Estilo del logo - igual al sidebar */
        .logo-container {
            position: relative;
            z-index: 2;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: center;
        }

        .sidebar-logo {
            width: 90px;
            height: 90px;
            border-radius: 20px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            border: 3px solid #FBEF16;
            margin: 0 auto;
        }

        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .registro-header h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
            color: white;
        }

        .registro-header p {
            opacity: 0.95;
            font-size: 1rem;
            position: relative;
            z-index: 2;
            color: #FBEF16;
        }

        .registro-body {
            padding: 2.5rem;
            background: var(--white);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-group label i {
            color: var(--accent);
            margin-right: 0.5rem;
            font-size: 1rem;
        }

        .form-group label .required {
            color: var(--danger);
            margin-left: 0.2rem;
        }

        .form-control {
            width: 100%;
            padding: 0.85rem 1.2rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius);
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
        }

        .form-control::placeholder {
            color: #aaa;
            font-size: 0.9rem;
        }

        .row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn-registro {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #F99B07, #F91302);
            color: var(--white);
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            margin-top: 1.5rem;
            box-shadow: 0 4px 15px rgba(249, 19, 2, 0.3);
        }

        .btn-registro:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(249, 19, 2, 0.4);
        }

        .btn-registro i {
            font-size: 1.3rem;
        }

        .btn-login-success {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #0F6C33, #0a4d25);
            color: var(--white);
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            text-decoration: none;
            margin-top: 1.5rem;
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        .btn-login-success:hover {
            background: linear-gradient(135deg, #0a4d25, #0F6C33);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(15, 108, 51, 0.4);
        }

        .alert {
            padding: 1.2rem;
            border-radius: var(--radius);
            margin-bottom: 1.8rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-left: 4px solid;
        }

        .alert i {
            font-size: 1.5rem;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-color: var(--danger);
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-color: var(--success);
        }

        .success-message {
            text-align: center;
            padding: 1rem;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .success-message i {
            font-size: 5rem;
            color: #0F6C33;
            margin-bottom: 1.5rem;
            background: var(--primary-soft);
            padding: 1rem;
            border-radius: 50%;
            display: inline-block;
        }

        .success-message h3 {
            font-size: 1.8rem;
            color: var(--text-dark);
            margin-bottom: 0.8rem;
            font-weight: 600;
        }

        .success-message p {
            color: var(--text-light);
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.6;
        }

        .login-link {
            text-align: center;
            margin-top: 1.8rem;
            font-size: 0.95rem;
            padding-top: 1.2rem;
            border-top: 1px solid var(--gray-light);
        }

        .login-link a {
            color: #0F6C33;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .login-link a:hover {
            color: #F91302;
            text-decoration: underline;
        }

        /* Info adicional */
        .info-box {
            margin-top: 1.5rem;
            padding: 1rem;
            background: var(--primary-soft);
            border-radius: var(--radius);
            font-size: 0.85rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid var(--primary-light);
        }

        .info-box i {
            color: #0F6C33;
            font-size: 1.2rem;
        }

        @media (max-width: 480px) {
            .row2 {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .registro-body {
                padding: 1.5rem;
            }
            
            .registro-header h2 {
                font-size: 1.6rem;
            }
            
            .sidebar-logo {
                width: 70px;
                height: 70px;
            }
        }

        /* Efecto de hover en campos */
        .form-group:hover .form-control {
            border-color: #F99B07;
        }

        /* Estilo para campos obligatorios */
        .required-field {
            color: var(--danger);
            font-size: 0.8rem;
            margin-top: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Partículas de fondo -->
    <div class="particles">
        <?php for ($i = 0; $i < 10; $i++): ?>
            <div class="particle" style="
                top: <?php echo rand(0, 100); ?>%;
                left: <?php echo rand(0, 100); ?>%;
                width: <?php echo rand(5, 15); ?>px;
                height: <?php echo rand(5, 15); ?>px;
                animation-delay: <?php echo rand(0, 5); ?>s;
                opacity: <?php echo rand(3, 8) / 10; ?>;
                background: <?php echo rand(0, 1) ? 'rgba(15, 108, 51, 0.05)' : 'rgba(249, 155, 7, 0.05)'; ?>;
            "></div>
        <?php endfor; ?>
    </div>

    <div class="registro-container">
        <div class="registro-header">
            <div class="logo-container">
                <div class="sidebar-logo">
                    <img src="includes/img/logo.svg" alt="Logo">
                </div>
            </div>
            <h2>Crear Cuenta</h2>
            <p>Únete a VetNexo C.A Online y accede a todos los servicios</p>
        </div>
        
        <div class="registro-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class='bx bx-error-circle'></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle'></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
                
                <div class="success-message">
                    <i class='bx bxs-check-shield'></i>
                    <h3>¡Bienvenido a VetNexo CA!</h3>
                    <p>Tu cuenta ha sido creada exitosamente.<br>Ahora puedes iniciar sesión y disfrutar de todos nuestros servicios veterinarios.</p>
                    <a href="login.php" class="btn-login-success">
                        <i class='bx bx-log-in-circle'></i>
                        Iniciar Sesión
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($show_form): ?>
                <form method="POST" action=""><?php echo av_csrf_field(); ?>
                    <div class="form-group">
                        <label>
                            <i class='bx bx-user'></i> 
                            Nombre completo 
                            <span class="required">*</span>
                        </label>
                        <input type="text" name="nombre" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" 
                               placeholder="Ej: Juan Pérez García" required>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class='bx bx-envelope'></i> 
                            Correo electrónico 
                            <span class="required">*</span>
                        </label>
                        <input type="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                               placeholder="ejemplo@correo.com" required>
                    </div>

                    <div class="row2">
                        <div class="form-group">
                            <label><i class='bx bx-phone'></i> Teléfono</label>
                            <input type="text" name="telefono" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>" 
                                   placeholder="[Clinic phone not configured]">
                        </div>
                        <div class="form-group">
                            <label><i class='bx bx-id-card'></i> Documento</label>
                            <input type="text" name="documento" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['documento'] ?? ''); ?>" 
                                   placeholder="12345678">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class='bx bx-map'></i> Dirección</label>
                        <input type="text" name="direccion" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['direccion'] ?? ''); ?>" 
                               placeholder="Dirección completa">
                    </div>

                    <div class="form-group">
                        <label>
                            <i class='bx bx-user-circle'></i> 
                            Nombre de usuario 
                            <span class="required">*</span>
                        </label>
                        <input type="text" name="username" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               placeholder="usuario123" required>
                    </div>

                    <div class="row2">
                        <div class="form-group">
                            <label>
                                <i class='bx bx-lock'></i> 
                                Contraseña 
                                <span class="required">*</span>
                            </label>
                            <input type="password" name="password" class="form-control" 
                                   placeholder="Mínimo 6 caracteres" required>
                        </div>
                        <div class="form-group">
                            <label>
                                <i class='bx bx-lock-alt'></i> 
                                Confirmar 
                                <span class="required">*</span>
                            </label>
                            <input type="password" name="confirm_password" class="form-control" 
                                   placeholder="Repite tu contraseña" required>
                        </div>
                    </div>

                    <div class="info-box">
                        <i class='bx bx-info-circle'></i>
                        <span>Los campos marcados con <span class="required" style="color: var(--danger);">*</span> son obligatorios</span>
                    </div>

                    <button type="submit" class="btn-registro">
                        <i class='bx bx-user-plus'></i> 
                        Crear mi cuenta
                    </button>

                    <div class="login-link">
                        ¿Ya tienes cuenta? 
                        <a href="login.php">
                            <i class='bx bx-log-in' style="vertical-align: middle;"></i>
                            Inicia sesión aquí
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Efectos visuales adicionales
        document.addEventListener('DOMContentLoaded', function() {
            // Crear más partículas dinámicamente
            const particlesContainer = document.querySelector('.particles');
            for (let i = 0; i < 5; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.cssText = `
                    top: ${Math.random() * 100}%;
                    left: ${Math.random() * 100}%;
                    width: ${Math.random() * 12 + 4}px;
                    height: ${Math.random() * 12 + 4}px;
                    animation-delay: ${Math.random() * 5}s;
                    opacity: ${Math.random() * 0.3 + 0.1};
                    background: ${Math.random() > 0.5 ? 'rgba(15, 108, 51, 0.05)' : 'rgba(249, 155, 7, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }

            // Validación de teléfono (solo números y guiones)
            const telefonoInput = document.querySelector('input[name="telefono"]');
            if (telefonoInput) {
                telefonoInput.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9-]/g, '');
                });
            }

            // Validación de documento (solo números)
            const documentoInput = document.querySelector('input[name="documento"]');
            if (documentoInput) {
                documentoInput.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }

            // Animación de entrada para el formulario
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                group.style.opacity = '0';
                group.style.transform = 'translateY(10px)';
                
                setTimeout(() => {
                    group.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    group.style.opacity = '1';
                    group.style.transform = 'translateY(0)';
                }, 100 + index * 50);
            });

            // Animación para el botón
            const btn = document.querySelector('.btn-registro, .btn-login-success');
            if (btn) {
                btn.addEventListener('mouseenter', () => {
                    btn.style.transform = 'translateY(-3px) scale(1.02)';
                });
                btn.addEventListener('mouseleave', () => {
                    btn.style.transform = 'translateY(0) scale(1)';
                });
            }

            // Verificar si el logo se cargó correctamente
            const logoImg = document.querySelector('.sidebar-logo img');
            if (logoImg) {
                logoImg.onerror = function() {
                    // Si hay error al cargar el logo, crear un fallback
                    this.style.display = 'none';
                    const logoContainer = document.querySelector('.sidebar-logo');
                    logoContainer.style.background = 'linear-gradient(135deg, #F99B07, #F91302)';
                    logoContainer.style.color = 'white';
                    logoContainer.style.fontSize = '2rem';
                    logoContainer.style.fontWeight = 'bold';
                    logoContainer.innerHTML = 'AVP';
                };
            }
        });
    </script>
</body>
</html>