<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Panel de Control</h3>
    </div>
    <ul class="sidebar-menu">
        <!-- Dashboard -->
        <li class="menu-item">
            <a href="dashboard.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                <i class='bx bxs-dashboard menu-icon'></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Gestión de Pacientes -->
        <li class="menu-item">
            <a href="#" class="menu-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['lista_mascotas.php', 'registrar_mascota.php', 'historial_clinico.php', 'anamnesis.php', 'control_vacunas.php'])) ? 'active' : ''; ?>" data-toggle="submenu-1">
                <i class='bx bxs-dog menu-icon'></i>
                <span>Gestión de Pacientes</span>
                <i class='bx bx-chevron-down menu-toggle'></i>
            </a>
            <ul class="submenu" id="submenu-1">
                <li><a href="listademascotas.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'lista_mascotas.php') ? 'active' : ''; ?>">Lista de Mascotas</a></li>
                <li><a href="registrarmascota.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'registrar_mascota.php') ? 'active' : ''; ?>">Registrar Nueva Mascota</a></li>
                <li><a href="historial.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'historial_clinico.php') ? 'active' : ''; ?>">Historial Clínico</a></li>
                <li><a href="anamnesis.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'anamnesis.php') ? 'active' : ''; ?>">Anamnesis</a></li>
                <li><a href="controlvacunas.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'control_vacunas.php') ? 'active' : ''; ?>">Control de Vacunación</a></li>
            </ul>
        </li>

        <!-- Gestión de Citas -->
        <li class="menu-item">
            <a href="#" class="menu-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['calendario.php', 'agendar_cita.php', 'citas_dia.php', 'historial_citas.php'])) ? 'active' : ''; ?>" data-toggle="submenu-2">
                <i class='bx bxs-calendar menu-icon'></i>
                <span>Gestión de Citas</span>
                <i class='bx bx-chevron-down menu-toggle'></i>
            </a>
            <ul class="submenu" id="submenu-2">
                <li><a href="calendario.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'calendario.php') ? 'active' : ''; ?>">Calendario</a></li>
                <li><a href="agendarcita.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'agendar_cita.php') ? 'active' : ''; ?>">Agendar Cita</a></li>
                <li><a href="citasdeldia.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'citas_dia.php') ? 'active' : ''; ?>">Citas del Día</a></li>
                <li><a href="historialcitas.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'historial_citas.php') ? 'active' : ''; ?>">Historial de Citas</a></li>
            </ul>
        </li>

        <!-- Facturación -->
        <li class="menu-item">
            <a href="#" class="menu-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['nueva_factura.php', 'facturas_pendientes.php', 'historial_facturas.php', 'reportes_ventas.php'])) ? 'active' : ''; ?>" data-toggle="submenu-3">
                <i class='bx bxs-receipt menu-icon'></i>
                <span>Facturación</span>
                <i class='bx bx-chevron-down menu-toggle'></i>
            </a>
            <ul class="submenu" id="submenu-3">
                <li><a href="nuevafactura.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'nueva_factura.php') ? 'active' : ''; ?>">Nueva Factura</a></li>
                <li><a href="facturaspendientes.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'facturas_pendientes.php') ? 'active' : ''; ?>">Facturas Pendientes</a></li>
                <li><a href="historialfacturas.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'historial_facturas.php') ? 'active' : ''; ?>">Historial de Facturas</a></li>
                <li><a href="reportesventas.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'reportes_ventas.php') ? 'active' : ''; ?>">Reportes de Ventas</a></li>
            </ul>
        </li>

        <!-- Inventario -->
        <li class="menu-item">
            <a href="#" class="menu-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['productos.php', 'medicamentos.php', 'stock_bajo.php', 'proveedores.php'])) ? 'active' : ''; ?>" data-toggle="submenu-4">
                <i class='bx bxs-package menu-icon'></i>
                <span>Gestión de Inventario</span>
                <i class='bx bx-chevron-down menu-toggle'></i>
            </a>
            <ul class="submenu" id="submenu-4">
                <li><a href="productos.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'productos.php') ? 'active' : ''; ?>">Productos</a></li>
                <li><a href="medicamentos.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'medicamentos.php') ? 'active' : ''; ?>">Medicamentos</a></li>
                <li><a href="stock.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'stock_bajo.php') ? 'active' : ''; ?>">Stock Bajo</a></li>
                <li><a href="proveedores.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'proveedores.php') ? 'active' : ''; ?>">Proveedores</a></li>
            </ul>
        </li>

        <!-- Análisis Financiero -->
        <li class="menu-item">
            <a href="#" class="menu-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['dashboard_financiero.php', 'estado_resultados.php', 'flujo_caja.php', 'metricas_clave.php'])) ? 'active' : ''; ?>" data-toggle="submenu-5">
                <i class='bx bxs-chart menu-icon'></i>
                <span>Análisis Financiero</span>
                <i class='bx bx-chevron-down menu-toggle'></i>
            </a>
            <ul class="submenu" id="submenu-5">
                <li><a href="dashboardfi.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard_financiero.php') ? 'active' : ''; ?>">Dashboard Financiero</a></li>
                <li><a href="estado.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'estado_resultados.php') ? 'active' : ''; ?>">Estado de Resultados</a></li>
                <li><a href="flujodecaja.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'flujo_caja.php') ? 'active' : ''; ?>">Flujo de Caja</a></li>
                <li><a href="metricas.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'metricas_clave.php') ? 'active' : ''; ?>">Métricas Clave</a></li>
            </ul>
        </li>

        <!-- Configuración -->
        <li class="menu-item">
            <a href="#" class="menu-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['usuarios.php', 'perfil_veterinaria.php', 'servicios.php', 'backup.php'])) ? 'active' : ''; ?>" data-toggle="submenu-6">
                <i class='bx bxs-cog menu-icon'></i>
                <span>Configuración</span>
                <i class='bx bx-chevron-down menu-toggle'></i>
            </a>
            <ul class="submenu" id="submenu-6">
                <li><a href="usuarios.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'usuarios.php') ? 'active' : ''; ?>">Usuarios</a></li>
                <li><a href="perfilve.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'perfil_veterinaria.php') ? 'active' : ''; ?>">Perfil de la Veterinaria</a></li>
                <li><a href="servicios.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'servicios.php') ? 'active' : ''; ?>">Servicios</a></li>
                <li><a href="backup.php" class="menu-link <?php echo (basename($_SERVER['PHP_SELF']) == 'backup.php') ? 'active' : ''; ?>">Sistema de Backup</a></li>
            </ul>
        </li>

        <!-- Cerrar Sesión -->
        <li class="menu-item">
            <a href="logout.php" class="menu-link" style="color: var(--error-color);">
                <i class='bx bx-log-out menu-icon'></i>
                <span>Cerrar Sesión</span>
            </a>
        </li>
    </ul>
</nav>