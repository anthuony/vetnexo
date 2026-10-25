<?php
// index.php - Versión con botones más pequeños
require_once __DIR__ . '/config/bootstrap.php';
require_once 'config/database.php';

// Crear instancia y obtener conexión
$database = new Database();
$conn = $database->conectar();

// Redirección si ya está logueado
if (isset($_SESSION['user_id']) && isset($_SESSION['rol'])) {
    if ($_SESSION['rol'] == 'cliente' && is_dir('cliente')) {
        header("Location: cliente/dashboard.php");
        exit();
    } elseif (in_array($_SESSION['rol'], ['admin', 'veterinarian', 'assistant', 'reception'])) {
        header("Location: dashboard.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VetNexo - Clínica Veterinaria Especializada en Your Clinic Location</title>
    
    <!-- Meta tags para SEO -->
    <meta name="description" content="Clínica veterinaria en Your Clinic Location especializada en consultas, peluquería canina, vacunación, ecografía y más. Cuidado profesional para tu mascota con más de 15 años de experiencia.">
    <meta name="keywords" content="veterinaria Your Clinic Location, peluquería canina Your Clinic Location, ecografía veterinaria, vacunas para perros Your Clinic Location, consulta veterinaria VetNexo, VetNexo">
    <meta name="author" content="VetNexo">
    <meta name="robots" content="index, follow">
    
    <!-- Favicon - Usando el logo real -->
    <link rel="icon" type="image/svg+xml" href="includes/img/logo.svg">
    <link rel="apple-touch-icon" href="includes/img/logo.svg">
    <link rel="shortcut icon" href="includes/img/logo.svg" type="image/x-icon">
    
    <!-- Fonts y estilos -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    
    <style>
        /* Reset y variables */
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
            --yellow: #FFC107;
            --yellow-light: #FFD54F;
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 5px 15px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.15);
            --shadow-hover: 0 15px 30px rgba(46, 125, 50, 0.15);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header mejorado */
        .header {
            padding: 0.8rem 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-sm);
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .logo-img {
            height: 45px; /* Reducido ligeramente */
            width: auto;
            object-fit: contain;
            transition: transform 0.3s ease;
        }

        .logo:hover .logo-img {
            transform: scale(1.1) rotate(5deg);
        }

        .logo span {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.3rem; /* Reducido */
        }

        .nav-links {
            display: flex;
            gap: 2rem; /* Reducido de 2.5rem */
            align-items: center;
        }

        .nav-links a {
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem; /* Reducido de 1rem */
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
        }

        .nav-links a:hover {
            color: var(--accent);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent);
            transition: width 0.3s ease;
            border-radius: 2px;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        /* Botones mejorados - VERSIÓN MÁS COMPACTA */
        .auth-buttons {
            display: flex;
            gap: 0.8rem; /* Reducido de 1rem */
            align-items: center;
        }

        .btn {
            padding: 0.5rem 1.2rem;  /* Reducido significativamente */
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;  /* Reducido */
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;  /* Reducido */
            position: relative;
            overflow: hidden;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

        .btn::before {
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

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn i {
            font-size: 1rem;  /* Reducido */
            transition: transform 0.3s ease;
        }

        .btn:hover i {
            transform: translateX(3px) scale(1.1);
        }

        .btn-whatsapp {
            background: var(--primary);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
        }

        .btn-whatsapp:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(46, 125, 50, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--accent);
            border: 2px solid var(--accent);
        }

        .btn-outline:hover {
            background: var(--accent);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(255, 152, 0, 0.3);
        }

        .btn-login {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-login:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(46, 125, 50, 0.2);
        }

        .btn-register {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
            box-shadow: 0 4px 12px rgba(255, 152, 0, 0.3);
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(255, 152, 0, 0.4);
        }

        /* Para móviles, mantener un tamaño más cómodo al tacto */
        @media (max-width: 768px) {
            .btn {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }
            
            .btn i {
                font-size: 1.1rem;
            }
            
            .auth-buttons {
                gap: 1rem;
            }
        }

        /* Hero Section mejorado */
        .hero {
            padding: 10rem 0 5rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,100 L100,100 L100,0 Z" fill="rgba(255,255,255,0.05)"/></svg>');
            background-size: cover;
            opacity: 0.1;
            animation: moveBackground 20s linear infinite;
        }

        @keyframes moveBackground {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-text {
            animation: slideInLeft 1s ease-out;
        }

        .hero-text h1 {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .hero-text h1 span {
            color: var(--accent-light);
            position: relative;
            display: inline-block;
        }

        .hero-text h1 span::after {
            content: '';
            position: absolute;
            bottom: 5px;
            left: 0;
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            z-index: -1;
        }

        .hero-text p {
            font-size: 1.2rem;
            margin-bottom: 2.5rem;
            opacity: 0.95;
            line-height: 1.8;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 2.5rem;
        }

        .hero-image {
            background: var(--white);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 350px;
            position: relative;
            overflow: hidden;
            animation: floatAnimation 3s ease-in-out infinite;
        }

        @keyframes floatAnimation {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .hero-image::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -30px;
            width: 150px;
            height: 150px;
            background: var(--accent);
            border-radius: 50%;
            opacity: 0.2;
            animation: pulseAnimation 3s ease-in-out infinite;
        }

        .hero-image::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 150px;
            height: 150px;
            background: var(--yellow);
            border-radius: 50%;
            opacity: 0.15;
            animation: pulseAnimation 3s ease-in-out infinite reverse;
        }

        @keyframes pulseAnimation {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .hero-image img {
            max-width: 100%;
            max-height: 100%;
            object-fit: cover;
            border-radius: 15px;
            position: relative;
            z-index: 2;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
        }

        .hero-image:hover img {
            transform: scale(1.05);
        }

        .contact-info {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .contact-info-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 0.75rem 1.25rem;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .contact-info-item:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        .contact-info-item i {
            font-size: 1.25rem;
            color: var(--accent-light);
        }

        /* Services Section mejorado */
        .services {
            padding: 5rem 0;
            background: var(--white);
            position: relative;
        }

        .section-title {
            text-align: center;
            font-size: 2.8rem;
            margin-bottom: 1rem;
            color: var(--text-dark);
            font-weight: 700;
            position: relative;
        }

        .section-title span {
            color: var(--accent);
            position: relative;
            display: inline-block;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.1rem;
            color: var(--text-light);
            margin-bottom: 4rem;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.8;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .service-card {
            background: var(--white);
            padding: 2.5rem 2rem;
            border-radius: 20px;
            text-align: center;
            transition: all 0.4s ease;
            border: 1px solid var(--gray);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .service-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transform: translateX(-100%);
            transition: transform 0.5s ease;
        }

        .service-card:hover::before {
            transform: translateX(0);
        }

        .service-card::after {
            content: '';
            position: absolute;
            top: 50%;
            right: -30px;
            width: 60px;
            height: 60px;
            background: var(--accent-soft);
            border-radius: 50%;
            opacity: 0.5;
            transition: all 0.4s ease;
        }

        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-light);
        }

        .service-card:hover::after {
            transform: scale(1.5);
            opacity: 0.2;
        }

        .service-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            color: var(--primary);
            position: relative;
            display: inline-block;
        }

        .service-icon::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 3px;
            background: var(--accent);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .service-card:hover .service-icon::after {
            width: 60px;
        }

        .service-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--text-dark);
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .service-card:hover h3 {
            color: var(--primary);
        }

        .service-card p {
            color: var(--text-light);
            line-height: 1.7;
            font-size: 0.95rem;
        }

        /* About Section mejorado */
        .about {
            padding: 5rem 0;
            background: var(--primary-soft);
            position: relative;
            overflow: hidden;
        }

        .about::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--accent), var(--yellow));
        }

        .about::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: var(--primary-light);
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(50%, 50%);
        }

        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .about-image {
            background: linear-gradient(135deg, var(--white), var(--primary-soft));
            border-radius: 30px;
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 400px;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(46, 125, 50, 0.1);
        }

        .about-image i {
            font-size: 12rem;
            color: var(--primary);
            opacity: 0.9;
            position: relative;
            z-index: 2;
            animation: pulseSoft 3s ease-in-out infinite;
        }

        @keyframes pulseSoft {
            0%, 100% { transform: scale(1); opacity: 0.9; }
            50% { transform: scale(1.05); opacity: 1; }
        }

        .about-text h2 {
            font-size: 2.8rem;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
            position: relative;
            line-height: 1.2;
        }

        .about-text h2 span {
            color: var(--primary);
        }

        .about-text h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 80px;
            height: 4px;
            background: var(--accent);
            border-radius: 2px;
        }

        .about-text p {
            margin-bottom: 1.5rem;
            color: var(--text-light);
            font-size: 1.05rem;
            line-height: 1.8;
        }

        .about-features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 2.5rem;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.25rem;
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--gray);
        }

        .feature-item:hover {
            transform: translateX(8px);
            border-color: var(--primary-light);
            box-shadow: var(--shadow-md);
        }

        .feature-item i {
            font-size: 1.5rem;
            color: var(--accent);
        }

        .feature-item span {
            font-weight: 500;
            color: var(--text-dark);
        }

        /* Location Section mejorado */
        .location {
            padding: 5rem 0;
            background: var(--white);
            position: relative;
        }

        .location-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .location-info h2 {
            font-size: 2.8rem;
            margin-bottom: 2rem;
            color: var(--text-dark);
            position: relative;
        }

        .location-info h2 span {
            color: var(--primary);
        }

        .location-info h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 80px;
            height: 4px;
            background: var(--accent);
            border-radius: 2px;
        }

        .location-details {
            margin-bottom: 2.5rem;
        }

        .location-detail {
            display: flex;
            align-items: flex-start;
            gap: 1.2rem;
            margin-bottom: 1.5rem;
            padding: 1.2rem;
            background: var(--gray-light);
            border-radius: 15px;
            transition: all 0.3s ease;
            border: 1px solid var(--gray);
        }

        .location-detail:hover {
            transform: translateX(8px);
            background: var(--white);
            border-color: var(--primary-light);
            box-shadow: var(--shadow-md);
        }

        .location-detail i {
            font-size: 1.8rem;
            color: var(--accent);
            min-width: 35px;
            text-align: center;
        }

        .location-detail strong {
            display: block;
            margin-bottom: 0.3rem;
            color: var(--text-dark);
            font-size: 1.1rem;
        }

        .location-detail span {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        /* Mapa mejorado */
        .map-container {
            background: var(--gray-light);
            border-radius: 30px;
            height: 450px;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .map-container iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 30px;
            transition: all 0.3s ease;
        }

        .map-container:hover iframe {
            transform: scale(1.02);
        }

        .map-overlay {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 1.5rem;
            text-align: center;
            border-radius: 50px;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.5);
        }

        .map-overlay a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .map-overlay a:hover {
            color: var(--accent);
            transform: scale(1.05);
        }

        .map-overlay i {
            font-size: 1.3rem;
        }

        /* CTA Section mejorado */
        .cta {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            padding: 6rem 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--accent), var(--yellow));
        }

        .cta::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotateGradient 20s linear infinite;
        }

        @keyframes rotateGradient {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .cta .container {
            position: relative;
            z-index: 2;
        }

        .cta h2 {
            font-size: 3.2rem;
            margin-bottom: 1.5rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .cta p {
            font-size: 1.3rem;
            margin-bottom: 3rem;
            opacity: 0.95;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.8;
        }

        /* Footer mejorado */
        .footer {
            background: linear-gradient(135deg, #1a1a1a, #2a2a2a);
            color: var(--white);
            padding: 4rem 0 1.5rem;
            position: relative;
        }

        .footer-content {
            display: grid;
            grid-template-columns: 2fr 1fr 1.5fr;
            gap: 3rem;
            margin-bottom: 3rem;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 1.2rem;
        }

        .footer-logo img {
            height: 35px;
            width: auto;
            filter: brightness(0) invert(1);
            transition: transform 0.3s ease;
        }

        .footer-logo:hover img {
            transform: rotate(5deg) scale(1.1);
        }

        .footer-about p {
            color: #B0B0B0;
            margin-bottom: 1.8rem;
            line-height: 1.8;
            font-size: 0.95rem;
        }

        .social-links {
            display: flex;
            gap: 1rem;
        }

        .social-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px; /* Reducido de 45px */
            height: 40px; /* Reducido de 45px */
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            color: var(--white);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1.2rem; /* Reducido de 1.3rem */
            border: 1px solid rgba(255,255,255,0.1);
        }

        .social-link:hover {
            background: var(--accent);
            transform: translateY(-5px) scale(1.1);
            border-color: transparent;
        }

        .footer-links h3, .footer-contact h3 {
            font-size: 1.2rem; /* Reducido de 1.3rem */
            margin-bottom: 1.5rem; /* Reducido de 1.8rem */
            color: var(--white);
            position: relative;
            font-weight: 600;
        }

        .footer-links h3::after, .footer-contact h3::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--accent);
            border-radius: 2px;
        }

        .footer-links ul, .footer-contact ul {
            list-style: none;
        }

        .footer-links li, .footer-contact li {
            margin-bottom: 0.8rem; /* Reducido de 1rem */
        }

        .footer-links a {
            color: #B0B0B0;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            font-size: 0.9rem; /* Reducido de 0.95rem */
        }

        .footer-links a:hover {
            color: var(--accent-light);
            transform: translateX(5px);
        }

        .footer-contact li {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem; /* Reducido de 1rem */
            color: #B0B0B0;
            font-size: 0.9rem; /* Reducido de 0.95rem */
        }

        .footer-contact i {
            color: var(--accent-light);
            font-size: 1.1rem; /* Reducido de 1.2rem */
            margin-top: 0.2rem;
        }

        .footer-bottom {
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #B0B0B0;
        }

        .developed-by {
            margin-top: 1rem;
            font-size: 0.9rem; /* Reducido de 0.95rem */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            background: rgba(255,255,255,0.03);
            padding: 0.6rem 1.5rem; /* Reducido de 0.8rem 2rem */
            border-radius: 50px;
            display: inline-flex;
            margin-left: auto;
            margin-right: auto;
        }

        .heart {
            color: #ff4d4d;
            animation: heartbeat 1.5s ease-in-out infinite;
            display: inline-block;
            font-size: 1rem; /* Reducido de 1.2rem */
        }

        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

        .developer-name {
            color: var(--accent-light);
            font-weight: 600;
        }

        /* Mobile Menu mejorado */
        .mobile-menu {
            display: none;
            font-size: 1.8rem; /* Reducido de 2rem */
            color: var(--primary);
            cursor: pointer;
            background: var(--primary-soft);
            width: 40px; /* Reducido de 45px */
            height: 40px; /* Reducido de 45px */
            border-radius: 10px; /* Reducido de 12px */
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .mobile-menu:hover {
            background: var(--primary);
            color: var(--white);
        }

        /* Animaciones */
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in {
            opacity: 0;
            animation: fadeInUp 1s ease-out forwards;
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

        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 45px; /* Reducido de 50px */
            height: 45px; /* Reducido de 50px */
            background: var(--accent);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.3rem; /* Reducido de 1.5rem */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 999;
            box-shadow: 0 4px 12px rgba(255, 152, 0, 0.4);
        }

        .back-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .back-to-top:hover {
            background: var(--accent-dark);
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(255, 152, 0, 0.5);
        }

        /* Responsive mejorado */
        @media (max-width: 1024px) {
            .hero-text h1 {
                font-size: 3rem;
            }
            
            .section-title, .about-text h2, .location-info h2, .cta h2 {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 968px) {
            .hero-content, .about-content, .location-content {
                grid-template-columns: 1fr;
                gap: 3rem;
            }
            
            .footer-content {
                grid-template-columns: 1fr 1fr;
                gap: 2rem;
            }
            
            .hero-text h1 {
                font-size: 2.8rem;
            }
            
            .hero-image {
                height: 300px;
                max-width: 500px;
                margin: 0 auto;
            }
            
            .map-container {
                height: 350px;
                order: -1;
            }
            
            .location-info {
                order: 2;
            }
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }
            
            .auth-buttons {
                display: none;
            }
            
            .mobile-menu {
                display: flex;
            }
            
            .hero {
                padding: 8rem 0 3rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .hero-buttons .btn {
                width: 100%;
            }
            
            .btn {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }
            
            .btn i {
                font-size: 1.1rem;
            }
            
            .about-features {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .services-grid {
                grid-template-columns: 1fr;
            }
            
            .contact-info {
                flex-direction: column;
                gap: 1rem;
            }
            
            .hero-image {
                height: 250px;
            }
            
            .map-container {
                height: 300px;
            }
            
            .developed-by {
                flex-direction: column;
                gap: 0.3rem;
            }
        }

        @media (max-width: 480px) {
            .hero-text h1 {
                font-size: 2.2rem;
            }
            
            .section-title, .about-text h2, .location-info h2, .cta h2 {
                font-size: 2rem;
            }
            
            .logo {
                font-size: 1.2rem;
            }
            
            .logo-img {
                height: 35px;
            }
            
            .logo span {
                font-size: 1.1rem;
            }
            
            .hero-image {
                height: 200px;
            }
            
            .location-detail {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .location-detail i {
                margin-bottom: 0.5rem;
            }
            
            .auth-buttons {
                gap: 0.5rem;
            }
            
            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <a href="#" class="logo">
                    <img src="includes/img/logo.svg" alt="VetNexo" class="logo-img">
                    <span>VetNexo</span>
                </a>
                
                <div class="nav-links">
                    <a href="#inicio">Inicio</a>
                    <a href="#servicios">Servicios</a>
                    <a href="#nosotros">Nosotros</a>
                    <a href="#ubicacion">Ubicación</a>
                </div>
                
                <div class="auth-buttons">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($_SESSION['rol'] == 'cliente'): ?>
                            <a href="cliente/dashboard.php" class="btn btn-login">
                                <i class='bx bx-user'></i>
                                Mi Portal
                            </a>
                        <?php else: ?>
                            <a href="dashboard.php" class="btn btn-login">
                                <i class='bx bx-dashboard'></i>
                                Dashboard
                            </a>
                        <?php endif; ?>
                        <a href="logout.php" class="btn btn-outline">
                            <i class='bx bx-log-out'></i>
                            Salir
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-login">
                            <i class='bx bx-log-in'></i>
                            Iniciar Sesión
                        </a>
                        <a href="registro_cliente.php" class="btn btn-register">
                            <i class='bx bx-user-plus'></i>
                            Registrarse
                        </a>
                    <?php endif; ?>
                    <a href="#" class="btn btn-whatsapp">
                        <i class='bx bxl-whatsapp'></i>
                        WhatsApp
                    </a>
                </div>
                
                <div class="mobile-menu">
                    <i class='bx bx-menu'></i>
                </div>
            </nav>
        </div>
    </header>

    <!-- Resto del contenido igual... -->

    <!-- Hero Section -->
    <section class="hero" id="inicio">
        <div class="container">
            <div class="hero-content">
                <div class="hero-text">
                    <h1>Cuidado veterinario <span>especializado</span> para tu mascota</h1>
                    <p>En VetNexo ofrecemos servicios de consulta veterinaria y peluquería canina con los más altos estándares de calidad y profesionalismo.</p>
                    <div class="hero-buttons">
                        <a href="#" class="btn btn-whatsapp">
                            <i class='bx bxl-whatsapp'></i>
                            Agendar Cita
                        </a>
                        <a href="#servicios" class="btn btn-outline">
                            <i class='bx bx-info-circle'></i>
                            Nuestros Servicios
                        </a>
                    </div>
                    <div class="contact-info">
                        <div class="contact-info-item">
                            <i class='bx bx-time'></i>
                            <span>Lun-Sáb: 8:00 AM - 5:00 PM</span>
                        </div>
                        <div class="contact-info-item">
                            <i class='bx bx-phone'></i>
                            <span>[Clinic phone not configured]</span>
                        </div>
                        <div class="contact-info-item">
                            <i class='bx bx-map'></i>
                            <span>VetNexo, Your Clinic Location</span>
                        </div>
                    </div>
                </div>
                <div class="hero-image">
                    <img src="includes/img/mascota.jpg" alt="Mascota feliz en VetNexo">
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="services" id="servicios">
        <div class="container">
            <h2 class="section-title">Nuestros <span>Servicios</span></h2>
            <p class="section-subtitle">Ofrecemos una amplia gama de servicios veterinarios para el cuidado integral de tu mascota</p>
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-icon">
                        <i class='bx bx-plus-medical'></i>
                    </div>
                    <h3>Consulta Veterinaria</h3>
                    <p>Revisiones completas, diagnósticos precisos y seguimiento personalizado para garantizar la salud de tu mascota.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class='bx bx-cut'></i>
                    </div>
                    <h3>Peluquería Canina</h3>
                    <p>Servicios profesionales de peluquería para mantener a tu mascota limpia, cómoda y con un aspecto saludable.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class='bx bx-shield-plus'></i>
                    </div>
                    <h3>Vacunación</h3>
                    <p>Programas de vacunación personalizados para proteger a tu mascota contra enfermedades comunes y graves.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class='bx bx-first-aid'></i>
                    </div>
                    <h3>Atención de Urgencias</h3>
                    <p>Atención inmediata para emergencias durante nuestro horario de atención.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class='bx bx-test-tube'></i>
                    </div>
                    <h3>Ecografía Veterinaria</h3>
                    <p>Diagnóstico por imagen con tecnología de ecografía para evaluaciones internas precisas y no invasivas.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class='bx bx-shower'></i>
                    </div>
                    <h3>Estética y Bienestar</h3>
                    <p>Baños terapéuticos, cortes de pelo y cuidados estéticos para el confort de tu mascota.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about" id="nosotros">
        <div class="container">
            <div class="about-content">
                <div class="about-image">
                    <i class='bx bx-clinic'></i>
                </div>
                <div class="about-text">
                    <h2>Más de <span>15 años</span> cuidando de tus mascotas</h2>
                    <p>En VetNexo nos dedicamos apasionadamente al cuidado de los animales. Nuestro equipo de profesionales altamente capacitados está comprometido con brindar la mejor atención veterinaria.</p>
                    <p>Contamos con tecnología de punta, incluyendo equipos de ecografía, para ofrecer diagnósticos precisos y tratamientos efectivos.</p>
                    <div class="about-features">
                        <div class="feature-item">
                            <i class='bx bx-check-circle'></i>
                            <span>Profesionales certificados</span>
                        </div>
                        <div class="feature-item">
                            <i class='bx bx-check-circle'></i>
                            <span>Tecnología de vanguardia</span>
                        </div>
                        <div class="feature-item">
                            <i class='bx bx-check-circle'></i>
                            <span>Atención personalizada</span>
                        </div>
                        <div class="feature-item">
                            <i class='bx bx-check-circle'></i>
                            <span>Ambiente seguro y limpio</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Location Section -->
    <section class="location" id="ubicacion">
        <div class="container">
            <div class="location-content">
                <div class="location-info">
                    <h2>Visítanos en <span>Your Clinic Location</span></h2>
                    <div class="location-details">
                        <div class="location-detail">
                            <i class='bx bx-map'></i>
                            <div>
                                <strong>Dirección:</strong>
                                <span>Clinic address not configured</span>
                            </div>
                        </div>
                        <div class="location-detail">
                            <i class='bx bx-time'></i>
                            <div>
                                <strong>Horario de atención:</strong>
                                <span>Lunes a Sábado: 8:00 AM - 5:00 PM</span>
                            </div>
                        </div>
                        <div class="location-detail">
                            <i class='bx bx-phone'></i>
                            <div>
                                <strong>Teléfono:</strong>
                                <span>[Clinic phone not configured]</span>
                            </div>
                        </div>
                        <div class="location-detail">
                            <i class='bx bx-envelope'></i>
                            <div>
                                <strong>Email:</strong>
                                <span>info@vetnexo.example</span>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="btn btn-whatsapp">
                        <i class='bx bxl-whatsapp'></i>
                        Contactar por WhatsApp
                    </a>
                </div>
                
                <!-- Mapa mejorado -->
                <div class="map-container">
                    <iframe 
                        src="about:blank" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Ubicación de VetNexo en Your Clinic Location">
                    </iframe>
                    <div class="map-overlay">
                        <a href="#" target="_blank">
                            <i class='bx bx-map'></i>
                            Abrir en Google Maps
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta">
        <div class="container">
            <h2>¿Necesitas atención para tu mascota?</h2>
            <p>No esperes más, agenda una cita hoy mismo y bríndale a tu compañero la atención que merece.</p>
            <div class="hero-buttons">
                <a href="#" class="btn" style="background: var(--white); color: var(--primary);">
                    <i class='bx bxl-whatsapp'></i>
                    Contactar por WhatsApp
                </a>
                <a href="tel:04249602906" class="btn btn-outline" style="border-color: var(--white); color: var(--white);">
                    <i class='bx bx-phone'></i>
                    Llamar Ahora
                </a>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="registro_cliente.php" class="btn btn-outline" style="border-color: var(--white); color: var(--white);">
                    <i class='bx bx-user-plus'></i>
                    Registrarme
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-about">
                    <div class="footer-logo">
                        <img src="includes/img/logo.svg" alt="VetNexo">
                        VetNexo
                    </div>
                    <p>Clínica veterinaria especializada en Your Clinic Location, ofreciendo servicios de consulta, peluquería canina y diagnóstico por imagen con ecografía. Comprometidos con la salud y bienestar de tus mascotas.</p>
                    <div class="social-links">
                        <a href="#" class="social-link" target="_blank">
                            <i class='bx bxl-instagram'></i>
                        </a>
                        <a href="#" class="social-link" target="_blank">
                            <i class='bx bxl-tiktok'></i>
                        </a>
                        <a href="#" class="social-link" target="_blank">
                            <i class='bx bxl-facebook'></i>
                        </a>
                    </div>
                </div>
                <div class="footer-links">
                    <h3>Enlaces Rápidos</h3>
                    <ul>
                        <li><a href="#inicio">Inicio</a></li>
                        <li><a href="#servicios">Servicios</a></li>
                        <li><a href="#nosotros">Nosotros</a></li>
                        <li><a href="#ubicacion">Ubicación</a></li>
                        <?php if (!isset($_SESSION['user_id'])): ?>
                        <li><a href="login.php">Iniciar Sesión</a></li>
                        <li><a href="registro_cliente.php">Registrarse</a></li>
                        <?php else: ?>
                        <li><a href="cliente/dashboard.php">Mi Portal</a></li>
                        <li><a href="logout.php">Cerrar Sesión</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="footer-contact">
                    <h3>Contacto</h3>
                    <ul>
                        <li>
                            <i class='bx bx-map'></i>
                            <span>Clinic address not configured</span>
                        </li>
                        <li>
                            <i class='bx bx-time'></i>
                            <span>Lun-Sáb: 8:00 AM - 5:00 PM</span>
                        </li>
                        <li>
                            <i class='bx bx-phone'></i>
                            <span>[Clinic phone not configured]</span>
                        </li>
                        <li>
                            <i class='bx bx-envelope'></i>
                            <span>info@vetnexo.example</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
                <div class="developed-by">
                    <span>Desarrollado con</span>
                    <span class="heart">❤️</span>
                    <span>por <span class="developer-name">VetNexo Product Team</span></span>
                    <span>para VetNexo</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <a href="#inicio" class="back-to-top" id="backToTop">
        <i class='bx bx-chevron-up'></i>
    </a>

    <script>
        // Mobile Menu Toggle
        const mobileMenu = document.querySelector('.mobile-menu');
        const navLinks = document.querySelector('.nav-links');
        const authButtons = document.querySelector('.auth-buttons');

        mobileMenu.addEventListener('click', () => {
            if (navLinks.style.display === 'flex') {
                navLinks.style.display = 'none';
                authButtons.style.display = 'none';
            } else {
                navLinks.style.display = 'flex';
                authButtons.style.display = 'flex';
                
                // Estilos para móvil
                navLinks.style.flexDirection = 'column';
                navLinks.style.position = 'absolute';
                navLinks.style.top = '100%';
                navLinks.style.left = '0';
                navLinks.style.right = '0';
                navLinks.style.background = 'white';
                navLinks.style.padding = '2rem';
                navLinks.style.boxShadow = '0 10px 30px rgba(0,0,0,0.1)';
                navLinks.style.gap = '1.5rem';
                navLinks.style.zIndex = '999';
                
                authButtons.style.flexDirection = 'column';
                authButtons.style.position = 'absolute';
                authButtons.style.top = 'calc(100% + 220px)';
                authButtons.style.left = '20px';
                authButtons.style.right = '20px';
                authButtons.style.background = 'white';
                authButtons.style.padding = '2rem';
                authButtons.style.boxShadow = '0 10px 30px rgba(0,0,0,0.1)';
                authButtons.style.gap = '1rem';
                authButtons.style.zIndex = '999';
                authButtons.style.borderRadius = '12px';
            }
        });

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Back to Top Button
        const backToTop = document.getElementById('backToTop');
        
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        });

        // Animación de scroll con Intersection Observer
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.service-card, .about-content, .location-content').forEach(el => {
            observer.observe(el);
        });

        // Cerrar menú móvil al hacer clic en un enlace
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    navLinks.style.display = 'none';
                    authButtons.style.display = 'none';
                }
            });
        });

        // Ajustar al redimensionar la ventana
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                navLinks.style.display = 'flex';
                authButtons.style.display = 'flex';
                navLinks.style.flexDirection = 'row';
                navLinks.style.position = 'static';
                navLinks.style.padding = '0';
                navLinks.style.boxShadow = 'none';
                authButtons.style.flexDirection = 'row';
                authButtons.style.position = 'static';
                authButtons.style.padding = '0';
                authButtons.style.boxShadow = 'none';
            } else {
                navLinks.style.display = 'none';
                authButtons.style.display = 'none';
            }
        });
    </script>
</body>
</html>