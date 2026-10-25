<?php
// VetNexo - navegación lateral compartida (componente parcial, sin <html>/<head>/<body>)
$usuario_nombre = $_SESSION['usuario_nombre'] ?? $_SESSION['nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario';

$current_file = basename($_SERVER['PHP_SELF'] ?? '');
$current_path = $_SERVER['PHP_SELF'] ?? '';
$en_fumigacion = strpos($current_path, '/fumigacion/') !== false;
$en_historial_clinico = $current_file === 'historial.php' && !$en_fumigacion;
$en_historial_fumigacion = $current_file === 'historial.php' && $en_fumigacion;
$root_prefix = $en_fumigacion ? '../' : '';
$prefix = $root_prefix;

if (!function_exists('getRolColor')) {
    @include_once __DIR__ . '/config/funciones.php';
}

$rol_color = function_exists('getRolColor') ? getRolColor($usuario_rol) : '#2563eb';
$rol_texto = function_exists('getRolTexto') ? getRolTexto($usuario_rol) : ucfirst((string)$usuario_rol);
$inicial = function_exists('mb_substr') ? mb_substr($usuario_nombre, 0, 1, 'UTF-8') : substr($usuario_nombre, 0, 1);
?>
<link rel="stylesheet" href="<?php echo $root_prefix; ?>css/app-optimized.css?v=20260812c">

<style id="av-sidebar-styles">
:root{
    --av-sidebar-w:280px;
    --av-green:#0f6c33;
    --av-green-dark:#084a22;
    --av-yellow:#fbef16;
    --av-orange:#f99b07;
    --av-red:#f91302;
    --av-blue:#2261bd;
    --av-nav-shadow:0 18px 55px rgba(2,23,12,.24);
}
.av-mobile-nav{display:none}
.av-sidebar-overlay{display:none}
.av-sidebar{
    position:fixed;inset:0 auto 0 0;width:var(--av-sidebar-w);height:100dvh;
    background:linear-gradient(180deg,var(--av-green) 0%,var(--av-green-dark) 100%);
    color:#fff;z-index:1100;overflow-y:auto;overflow-x:hidden;
    box-shadow:4px 0 20px rgba(0,0,0,.15);transition:transform .25s ease;
    font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
    overscroll-behavior:contain;
}
.av-sidebar *{box-sizing:border-box}
.av-sidebar::-webkit-scrollbar{width:5px}.av-sidebar::-webkit-scrollbar-track{background:rgba(255,255,255,.08)}
.av-sidebar::-webkit-scrollbar-thumb{background:rgba(251,239,22,.55);border-radius:999px}
.av-sidebar__head{padding:18px 16px;border-bottom:1px solid rgba(251,239,22,.28);display:flex;align-items:center;gap:11px;background:rgba(0,0,0,.08);position:relative}
.av-sidebar__logo{width:48px;height:48px;border-radius:12px;background:#fff;display:grid;place-items:center;overflow:hidden;border:2px solid var(--av-yellow);flex:0 0 48px}
.av-sidebar__logo img{width:100%;height:100%;object-fit:contain}.av-sidebar__title{margin:0;color:#fff;font-size:17px;line-height:1.15;font-weight:750;min-width:0}
.av-sidebar__title span{display:block;color:var(--av-yellow);font-size:12px;font-weight:600;margin-top:3px}
.av-sidebar__close{display:none;margin-left:auto;width:40px;height:40px;border:0;border-radius:10px;background:rgba(255,255,255,.12);color:#fff;font-size:24px;cursor:pointer;align-items:center;justify-content:center}
.av-sidebar__profile{padding:14px 16px;display:flex;gap:11px;align-items:center;background:rgba(0,0,0,.12);border-bottom:1px solid rgba(255,255,255,.09)}
.av-sidebar__avatar{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--av-red),var(--av-orange));display:grid;place-items:center;font-weight:800;font-size:19px;border:2px solid var(--av-yellow);flex:0 0 44px}
.av-sidebar__person{min-width:0}.av-sidebar__name{font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:185px}.av-sidebar__role{display:inline-block;margin-top:4px;padding:3px 9px;border-radius:999px;background:<?php echo htmlspecialchars($rol_color, ENT_QUOTES, 'UTF-8'); ?>;font-size:10px;font-weight:700;border:1px solid rgba(255,255,255,.3)}
.av-menu{list-style:none;margin:0;padding:10px 8px 18px}.av-menu__item{margin:3px 0;border-radius:11px;overflow:hidden}.av-menu__item.is-active{background:rgba(255,255,255,.09)}
.av-menu__link{display:flex;align-items:center;gap:11px;width:100%;min-height:44px;padding:10px 12px;border-radius:10px;color:rgba(255,255,255,.93);text-decoration:none;font-size:14px;font-weight:650;transition:background .18s ease,color .18s ease,transform .18s ease;border:0;background:transparent;text-align:left;cursor:pointer}
.av-menu__link:hover,.av-menu__link:focus-visible{background:rgba(34,97,189,.32);color:#fff;outline:none}.av-menu__item.is-active>.av-menu__link{color:var(--av-yellow);background:rgba(249,19,2,.12)}
.av-menu__icon{font-size:20px;color:var(--av-orange);width:22px;text-align:center;flex:0 0 22px}.av-menu__label{flex:1;min-width:0}.av-menu__chev{font-size:18px;color:var(--av-orange);transition:transform .2s ease}
.av-menu__item.is-open>.av-menu__link .av-menu__chev{transform:rotate(180deg);color:var(--av-yellow)}
.av-submenu{list-style:none;margin:0;padding:0 7px 7px 43px;display:none}.av-menu__item.is-open>.av-submenu{display:block}.av-submenu a{display:block;padding:8px 10px;border-radius:8px;color:rgba(255,255,255,.78);text-decoration:none;font-size:12.5px;line-height:1.3}.av-submenu a:hover,.av-submenu a.active{background:rgba(255,255,255,.11);color:#fff}.av-submenu a.active{color:var(--av-yellow);font-weight:700}
.av-menu__logout{margin-top:12px;border-top:1px solid rgba(255,255,255,.1);padding-top:10px}.av-menu__logout .av-menu__link{color:#ffd1cc}.av-menu__logout .av-menu__icon{color:#ff746a}

/* Escritorio: reserva el espacio del menú sin tocar el diseño interno de cada módulo. */
@media (min-width:901px){
    body .main-content,body .content-wrapper,body .page-content.av-sidebar-aware{margin-left:var(--av-sidebar-w)}
}

/* Tablet y móvil: drawer real con barra superior y overlay. */
@media (max-width:900px){
    body{padding-top:58px!important}
    body.av-nav-open{overflow:hidden!important;touch-action:none}
    .av-mobile-nav{display:flex;position:fixed;inset:0 0 auto 0;height:58px;z-index:1080;align-items:center;gap:10px;padding:8px 12px;background:rgba(255,255,255,.96);border-bottom:1px solid #e5e7eb;box-shadow:0 4px 18px rgba(15,23,42,.08);backdrop-filter:blur(10px)}
    .av-mobile-nav__button{height:42px;min-width:94px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:11px;background:var(--av-green);color:#fff;font-size:14px;font-weight:750;cursor:pointer;box-shadow:0 5px 15px rgba(15,108,51,.2)}
    .av-mobile-nav__button i{font-size:22px}.av-mobile-nav__brand{display:flex;align-items:center;gap:8px;min-width:0;color:#0f172a;font-weight:800;font-size:14px}.av-mobile-nav__brand img{width:34px;height:34px;border-radius:9px;object-fit:contain;background:#fff}.av-mobile-nav__brand span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .av-sidebar{transform:translateX(-105%);width:min(86vw,320px);box-shadow:none}
    .av-sidebar.is-open{transform:translateX(0);box-shadow:var(--av-nav-shadow)}
    .av-sidebar__close{display:inline-flex}
    .av-sidebar-overlay{display:block;position:fixed;inset:0;background:rgba(2,8,23,.52);z-index:1090;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease,visibility .2s ease;backdrop-filter:blur(2px)}
    .av-sidebar-overlay.is-visible{opacity:1;visibility:visible;pointer-events:auto}
    body .main-content,body .content-wrapper,body .page-content{margin-left:0!important;max-width:100%!important;width:100%!important;min-width:0!important}
    .av-menu__link{min-height:48px}
}
@media (max-width:480px){
    .av-mobile-nav{padding:8px 10px}.av-mobile-nav__button{min-width:88px;padding-inline:11px}.av-mobile-nav__brand span{max-width:160px}
    .av-sidebar{width:min(91vw,330px)}
}
@media (prefers-reduced-motion:reduce){.av-sidebar,.av-sidebar-overlay,.av-menu__chev{transition:none!important}}
</style>

<div class="av-mobile-nav" aria-label="Navegación móvil">
    <button type="button" class="av-mobile-nav__button" id="avMenuOpen" aria-controls="avSidebar" aria-expanded="false">
        <i class='bx bx-menu' aria-hidden="true"></i><span>Menú</span>
    </button>
    <div class="av-mobile-nav__brand">
        <img src="<?php echo $root_prefix; ?>includes/img/logo.svg" alt="">
        <span>VetNexo</span>
    </div>
</div>
<div class="av-sidebar-overlay" id="avSidebarOverlay" aria-hidden="true"></div>

<aside class="av-sidebar" id="avSidebar" aria-label="Menú principal">
    <div class="av-sidebar__head">
        <div class="av-sidebar__logo"><img src="<?php echo $root_prefix; ?>includes/img/logo.svg" alt="VetNexo"></div>
        <h2 class="av-sidebar__title">VetNexo<span>VetNexo</span></h2>
        <button type="button" class="av-sidebar__close" id="avMenuClose" aria-label="Cerrar menú"><i class='bx bx-x'></i></button>
    </div>

    <div class="av-sidebar__profile">
        <div class="av-sidebar__avatar"><?php echo htmlspecialchars(strtoupper($inicial), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="av-sidebar__person">
            <div class="av-sidebar__name"><?php echo htmlspecialchars($usuario_nombre, ENT_QUOTES, 'UTF-8'); ?></div>
            <span class="av-sidebar__role"><?php echo htmlspecialchars($rol_texto, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <ul class="av-menu">
        <li class="av-menu__item <?php echo ($current_file === 'dashboard.php' && !$en_fumigacion) ? 'is-active' : ''; ?>">
            <a href="<?php echo $root_prefix; ?>dashboard.php" class="av-menu__link"><i class='bx bxs-dashboard av-menu__icon'></i><span class="av-menu__label">Inicio</span></a>
        </li>

        <li class="av-menu__item <?php echo (in_array($current_file, ['listademascotas.php','registrarmascota.php','anamnesis.php','controlvacunas.php','nueva_consulta.php','nueva_vacuna.php','ver_mascota.php','editar_mascota.php'], true) || $en_historial_clinico) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-dog av-menu__icon'></i><span class="av-menu__label">Pacientes</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>listademascotas.php" class="<?php echo $current_file === 'listademascotas.php' ? 'active' : ''; ?>">Lista de Mascotas</a></li><li><a href="<?php echo $root_prefix; ?>registrarmascota.php" class="<?php echo $current_file === 'registrarmascota.php' ? 'active' : ''; ?>">Registrar Mascota</a></li><li><a href="<?php echo $root_prefix; ?>controlvacunas.php" class="<?php echo $current_file === 'controlvacunas.php' ? 'active' : ''; ?>">Vacunación</a></li><li><a href="<?php echo $root_prefix; ?>nueva_consulta.php" class="<?php echo $current_file === 'nueva_consulta.php' ? 'active' : ''; ?>">Nueva Consulta</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['calendario.php','agendarcita.php','citasdeldia.php','historialcitas.php','editar_cita.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-calendar av-menu__icon'></i><span class="av-menu__label">Citas</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>calendario.php" class="<?php echo $current_file === 'calendario.php' ? 'active' : ''; ?>">Calendario</a></li><li><a href="<?php echo $root_prefix; ?>agendarcita.php" class="<?php echo $current_file === 'agendarcita.php' ? 'active' : ''; ?>">Agendar Cita</a></li><li><a href="<?php echo $root_prefix; ?>citasdeldia.php" class="<?php echo $current_file === 'citasdeldia.php' ? 'active' : ''; ?>">Citas del Día</a></li><li><a href="<?php echo $root_prefix; ?>historialcitas.php" class="<?php echo $current_file === 'historialcitas.php' ? 'active' : ''; ?>">Historial</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['citaspeluqueria.php','nuevocorte.php','gananciaspeluqueria.php','obtener_peluqueria.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bx-cut av-menu__icon'></i><span class="av-menu__label">Peluquería</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>citaspeluqueria.php" class="<?php echo $current_file === 'citaspeluqueria.php' ? 'active' : ''; ?>">Citas Pendientes</a></li><li><a href="<?php echo $root_prefix; ?>nuevocorte.php" class="<?php echo $current_file === 'nuevocorte.php' ? 'active' : ''; ?>">Registrar Corte</a></li><li><a href="<?php echo $root_prefix; ?>gananciaspeluqueria.php" class="<?php echo $current_file === 'gananciaspeluqueria.php' ? 'active' : ''; ?>">Ganancias</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo $en_fumigacion ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bx-spray-can av-menu__icon'></i><span class="av-menu__label">Fumigación</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $prefix; ?>fumigacion/index.php" class="<?php echo ($current_file === 'index.php' && $en_fumigacion) ? 'active' : ''; ?>">Dashboard</a></li><li><a href="<?php echo $prefix; ?>fumigacion/nueva_inspeccion.php" class="<?php echo $current_file === 'nueva_inspeccion.php' ? 'active' : ''; ?>">Nueva Inspección</a></li><li><a href="<?php echo $prefix; ?>fumigacion/inspecciones_pendientes.php" class="<?php echo $current_file === 'inspecciones_pendientes.php' ? 'active' : ''; ?>">Inspecciones</a></li><li><a href="<?php echo $prefix; ?>fumigacion/fumigaciones_activas.php" class="<?php echo $current_file === 'fumigaciones_activas.php' ? 'active' : ''; ?>">Activas</a></li><li><a href="<?php echo $prefix; ?>fumigacion/historial.php" class="<?php echo $en_historial_fumigacion ? 'active' : ''; ?>">Historial</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['nuevafactura.php','facturas.php','facturaspendientes.php','historialfacturas.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-receipt av-menu__icon'></i><span class="av-menu__label">Facturación</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>nuevafactura.php" class="<?php echo $current_file === 'nuevafactura.php' ? 'active' : ''; ?>">Nueva Factura</a></li><li><a href="<?php echo $root_prefix; ?>facturas.php" class="<?php echo $current_file === 'facturas.php' ? 'active' : ''; ?>">Facturas</a></li><li><a href="<?php echo $root_prefix; ?>facturaspendientes.php" class="<?php echo $current_file === 'facturaspendientes.php' ? 'active' : ''; ?>">Pendientes</a></li><li><a href="<?php echo $root_prefix; ?>historialfacturas.php" class="<?php echo $current_file === 'historialfacturas.php' ? 'active' : ''; ?>">Historial</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['productos.php','medicamentos.php','stock.php','proveedores.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-package av-menu__icon'></i><span class="av-menu__label">Inventario</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>productos.php" class="<?php echo $current_file === 'productos.php' ? 'active' : ''; ?>">Productos</a></li><li><a href="<?php echo $root_prefix; ?>medicamentos.php" class="<?php echo $current_file === 'medicamentos.php' ? 'active' : ''; ?>">Medicamentos</a></li><li><a href="<?php echo $root_prefix; ?>stock.php" class="<?php echo $current_file === 'stock.php' ? 'active' : ''; ?>">Stock Bajo</a></li><li><a href="<?php echo $root_prefix; ?>proveedores.php" class="<?php echo $current_file === 'proveedores.php' ? 'active' : ''; ?>">Proveedores</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['listadeclientes.php','registrarcliente.php','registro.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-user av-menu__icon'></i><span class="av-menu__label">Clientes</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>listadeclientes.php" class="<?php echo $current_file === 'listadeclientes.php' ? 'active' : ''; ?>">Lista de Clientes</a></li><li><a href="<?php echo $root_prefix; ?>registrarcliente.php" class="<?php echo $current_file === 'registrarcliente.php' ? 'active' : ''; ?>">Registrar Cliente</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['dashboardfi.php','ingresos.php','egresos.php','flujodecaja.php','metricas.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-chart av-menu__icon'></i><span class="av-menu__label">Financiero</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>dashboardfi.php" class="<?php echo $current_file === 'dashboardfi.php' ? 'active' : ''; ?>">Dashboard</a></li><li><a href="<?php echo $root_prefix; ?>ingresos.php" class="<?php echo $current_file === 'ingresos.php' ? 'active' : ''; ?>">Ingresos</a></li><li><a href="<?php echo $root_prefix; ?>egresos.php" class="<?php echo $current_file === 'egresos.php' ? 'active' : ''; ?>">Egresos</a></li></ul>
        </li>

        <li class="av-menu__item <?php echo in_array($current_file, ['usuarios.php','perfilve.php','backup.php','estado.php'], true) ? 'is-active is-open' : ''; ?>">
            <button type="button" class="av-menu__link av-submenu-toggle" aria-expanded="false"><i class='bx bxs-cog av-menu__icon'></i><span class="av-menu__label">Configuración</span><i class='bx bx-chevron-down av-menu__chev'></i></button>
            <ul class="av-submenu"><li><a href="<?php echo $root_prefix; ?>perfilve.php" class="<?php echo $current_file === 'perfilve.php' ? 'active' : ''; ?>">Perfil</a></li><li><a href="<?php echo $root_prefix; ?>backup.php" class="<?php echo $current_file === 'backup.php' ? 'active' : ''; ?>">Backup</a></li><li><a href="<?php echo $root_prefix; ?>estado.php" class="<?php echo $current_file === 'estado.php' ? 'active' : ''; ?>">Estado</a></li></ul>
        </li>

<?php if(auth_current_role()==='admin'):?><li class="av-menu__item"><a class="av-menu__link" href="<?php echo $root_prefix; ?>usuarios.php"><i class="bx bx-user av-menu__icon"></i><span class="av-menu__label">Usuarios</span></a></li><?php endif;?>
<li class="av-menu__item"><a class="av-menu__link" href="<?php echo $root_prefix; ?>pedidos_admin.php"><i class="bx bx-shopping-bag av-menu__icon"></i><span class="av-menu__label">Pedidos</span></a></li>
<li class="av-menu__item"><a class="av-menu__link" href="<?php echo $root_prefix; ?>movimientos.php"><i class="bx bx-transfer av-menu__icon"></i><span class="av-menu__label">Movimientos manuales</span></a></li>
<li class="av-menu__item"><a class="av-menu__link" href="<?php echo $root_prefix; ?>listaservicios.php"><i class="bx bx-list-ul av-menu__icon"></i><span class="av-menu__label">Servicios</span></a></li>
<li class="av-menu__item"><a class="av-menu__link" href="<?php echo $root_prefix; ?>tiposservicios.php"><i class="bx bx-category av-menu__icon"></i><span class="av-menu__label">Catálogos</span></a></li>
        <li class="av-menu__item av-menu__logout"><a href="<?php echo $root_prefix; ?>logout.php" class="av-menu__link"><i class='bx bx-log-out av-menu__icon'></i><span class="av-menu__label">Cerrar sesión</span></a></li>
    </ul>
</aside>

<script id="av-sidebar-script">
(function(){
    function initAvSidebar(){
        var sidebar=document.getElementById('avSidebar');
        var overlay=document.getElementById('avSidebarOverlay');
        var openBtn=document.getElementById('avMenuOpen');
        var closeBtn=document.getElementById('avMenuClose');
        if(!sidebar||!overlay||!openBtn) return;

        function openMenu(){sidebar.classList.add('is-open');overlay.classList.add('is-visible');document.body.classList.add('av-nav-open');openBtn.setAttribute('aria-expanded','true');overlay.setAttribute('aria-hidden','false');if(closeBtn) closeBtn.focus({preventScroll:true});}
        function closeMenu(returnFocus){sidebar.classList.remove('is-open');overlay.classList.remove('is-visible');document.body.classList.remove('av-nav-open');openBtn.setAttribute('aria-expanded','false');overlay.setAttribute('aria-hidden','true');if(returnFocus) openBtn.focus({preventScroll:true});}

        openBtn.addEventListener('click',openMenu);
        if(closeBtn) closeBtn.addEventListener('click',function(){closeMenu(true);});
        overlay.addEventListener('click',function(){closeMenu(true);});
        document.addEventListener('keydown',function(e){if(e.key==='Escape'&&sidebar.classList.contains('is-open')) closeMenu(true);});
        window.addEventListener('resize',function(){if(window.innerWidth>900) closeMenu(false);},{passive:true});

        sidebar.querySelectorAll('.av-submenu-toggle').forEach(function(btn){
            var item=btn.closest('.av-menu__item');
            if(item&&item.classList.contains('is-open')) btn.setAttribute('aria-expanded','true');
            btn.addEventListener('click',function(){
                var isOpen=item.classList.toggle('is-open');
                btn.setAttribute('aria-expanded',isOpen?'true':'false');
            });
        });
        sidebar.querySelectorAll('a[href]:not([href="#"])').forEach(function(link){link.addEventListener('click',function(){if(window.innerWidth<=900) closeMenu(false);});});
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initAvSidebar,{once:true}); else initAvSidebar();
})();
</script>
