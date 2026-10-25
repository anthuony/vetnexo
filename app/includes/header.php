<?php
// Iniciar sesión y verificar autenticación
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/../config/bootstrap.php';
}

// Las pantallas internas no deben quedar públicas. Durante desarrollo esta
// verificación estaba comentada, dejando accesibles módulos administrativos.
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Configuración de la página
$page_title = $page_title ?? 'VetNexo';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <style>
        /* Estilos específicos para el dashboard mejorado */
        :root {
            --sidebar-width: 280px;
            --header-height: 70px;
            --primary-dark: #2c5aa0;
            --primary-light: #f0f7ff;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --bg-light: #f8fafc;
            --border-light: #e2e8f0;
            --text-muted: #64748b;
            --transition: all 0.3s ease;
            --white: #ffffff;
            --primary-color: #3b82f6;
            --dark-color: #1f2937;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            padding-top: var(--header-height);
            background-color: var(--bg-light);
        }

        /* Sidebar Mejorado */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            position: fixed;
            left: 0;
            top: var(--header-height);
            height: calc(100vh - var(--header-height));
            overflow-y: auto;
            transition: var(--transition);
            z-index: 999;
            border-right: 1px solid var(--border-light);
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
        }

        .sidebar-header {
            padding: 2rem 1.5rem 1rem;
            border-bottom: 1px solid var(--border-light);
        }

        .sidebar-header h3 {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.1rem;
        }

        .sidebar-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .menu-item {
            position: relative;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 0.9rem 1.5rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .menu-link:hover,
        .menu-link.active {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }

        .menu-icon {
            margin-right: 1rem;
            font-size: 1.3rem;
            width: 24px;
            text-align: center;
        }

        .submenu {
            list-style: none;
            background-color: var(--bg-light);
            display: none;
        }

        .submenu.show {
            display: block;
        }

        .submenu .menu-link {
            padding-left: 3.5rem;
            font-size: 0.9rem;
            font-weight: 400;
            border-left: none;
        }

        .menu-toggle {
            position: absolute;
            right: 1.5rem;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .menu-toggle.rotated {
            transform: rotate(180deg);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 2rem;
            transition: var(--transition);
        }

        .main-content.expanded {
            margin-left: 0;
        }

        /* Header Mejorado */
        .dashboard-header {
            background: var(--white);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            border-bottom: 1px solid var(--border-light);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-toggle {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--primary-color);
            padding: 0.5rem;
            border-radius: 6px;
            transition: var(--transition);
        }

        .sidebar-toggle:hover {
            background: var(--primary-light);
        }

        .sidebar-toggle-label { display: none; font-size: .85rem; font-weight: 700; }
        .legacy-sidebar-overlay { display: none; }
        @media (max-width: 900px) {
            .sidebar-toggle-label { display: inline; }
            .legacy-sidebar-overlay { display: block; }
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--primary-light);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1rem;
        }

        /* Stats Grid Mejorado */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.primary {
            background: var(--primary-light);
            color: var(--primary-color);
        }

        .stat-icon.success {
            background: #ecfdf5;
            color: var(--success-color);
        }

        .stat-icon.warning {
            background: #fffbeb;
            color: var(--warning-color);
        }

        .stat-icon.danger {
            background: #fef2f2;
            color: var(--error-color);
        }

        .stat-info h3 {
            font-size: 1.8rem;
            margin-bottom: 0.2rem;
            color: var(--dark-color);
            font-weight: 700;
        }

        .stat-info p {
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Modules Grid Mejorado */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .module-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid var(--border-light);
            overflow: hidden;
            transition: var(--transition);
            cursor: pointer;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .module-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .module-icon {
            font-size: 1.8rem;
        }

        .module-body {
            padding: 1.5rem;
        }

        .module-body p {
            color: var(--text-muted);
            margin-bottom: 1.2rem;
            line-height: 1.5;
        }

        .module-features {
            list-style: none;
        }

        .module-features li {
            padding: 0.4rem 0;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .module-features li::before {
            content: "✓";
            color: var(--success-color);
            font-weight: bold;
        }

        /* Quick Actions Mejorado */
        .quick-actions {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid var(--border-light);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-actions h3 {
            margin-bottom: 1.2rem;
            color: var(--dark-color);
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.8rem 1.5rem;
            background: var(--primary-light);
            color: var(--primary-color);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .action-btn:hover {
            background: var(--primary-color);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Recent Activity Mejorado */
        .recent-activity {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid var(--border-light);
            padding: 1.5rem;
        }

        .recent-activity h3 {
            margin-bottom: 1.2rem;
            color: var(--dark-color);
            font-weight: 600;
        }

        .activity-list {
            list-style: none;
        }

        .activity-item {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-content p {
            margin-bottom: 0.2rem;
            font-weight: 500;
        }

        .activity-time {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        /* Badge para notificaciones */
        .badge {
            background: var(--error-color);
            color: white;
            border-radius: 10px;
            padding: 0.2rem 0.6rem;
            font-size: 0.7rem;
            font-weight: 600;
            position: absolute;
            top: -5px;
            right: -5px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .modules-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .dashboard-header {
                padding: 0 0.75rem;
            }

            .sidebar-toggle {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                min-width: 92px;
                justify-content: center;
                background: var(--primary-color);
                color: #fff;
                box-shadow: 0 5px 15px rgba(59,130,246,.2);
            }

            .legacy-sidebar-overlay {
                position: fixed;
                inset: var(--header-height) 0 0 0;
                background: rgba(2,8,23,.52);
                z-index: 998;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity .2s ease, visibility .2s ease;
            }

            .legacy-sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }
        }
    </style>
    <?php if (isset($page_styles)) { echo $page_styles; } ?>
    <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
<body>
    <!-- Dashboard Header -->
    <header class="dashboard-header">
        <div class="header-left">
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú lateral">
                <i class='bx bx-menu'></i><span class="sidebar-toggle-label">Menú</span>
            </button>
            <h2 style="color: var(--primary-color); font-weight: 600;">VetNexo</h2>
        </div>
        <div class="header-right">
            <div class="notifications" style="position: relative;">
                <i class='bx bx-bell' style="font-size: 1.3rem; color: var(--text-muted);"></i>
                <span class="badge">3</span>
            </div>
            <div class="user-menu" id="userMenu">
                <div class="user-avatar">
                    <?php 
                    // Mostrar inicial del usuario
                    echo substr($_SESSION['usuario_nombre'] ?? 'A', 0, 1); 
                    ?>
                </div>
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem;">
                        <?php echo $_SESSION['usuario_nombre'] ?? 'Administrador'; ?>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        <?php echo $_SESSION['veterinaria_nombre'] ?? 'Veterinaria Destino'; ?>
                    </div>
                </div>
                <i class='bx bx-chevron-down' style="font-size: 0.9rem; color: var(--text-muted);"></i>
            </div>
        </div>
    </header>
    <div class="legacy-sidebar-overlay" id="legacySidebarOverlay" aria-hidden="true"></div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>