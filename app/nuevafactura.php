<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();


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

// Configurar zona horaria de Your Region
date_default_timezone_set('America/Caracas');

// Incluir configuración de base de datos
include 'config/database.php';

// Obtener datos del usuario de la sesión para el avatar
$usuario_nombre = $_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Usuario';
$usuario_rol = $_SESSION['rol'] ?? $_SESSION['usuario_rol'] ?? 'usuario';

$db=getDBConnection();require_once __DIR__.'/config/ventas.php';
$av_invoice_id=$av_invoice_id??null;$error='';$numero_factura='Se asigna al guardar';$av_edit=null;$av_items=[];
if($av_invoice_id){
 $q=$db->prepare("SELECT f.*,c.nombre AS cliente_nombre,c.documento_identidad,c.telefono,c.direccion FROM facturas f LEFT JOIN clientes c ON c.id=f.cliente_id WHERE f.id=? AND f.estado='pending' AND f.pagado=0");$q->execute([$av_invoice_id]);$av_edit=$q->fetch();
 if(!$av_edit)av_fail('Factura no encontrada o ya no editable.');$numero_factura=$av_edit['numero_factura'];
 $q=$db->prepare('SELECT * FROM factura_detalles WHERE factura_id=?');$q->execute([$av_invoice_id]);$av_items=$q->fetchAll();
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_POST['generar_factura'])){
 try{$id=av_save_invoice($db,$_POST,(int)$user_id,$av_invoice_id);$_SESSION['mensaje']=$av_invoice_id?'Factura actualizada correctamente':'Factura creada correctamente';$_SESSION['tipo_mensaje']='success';header('Location: ver_factura.php?id='.$id);exit;}
 catch(Throwable $e){error_log('[factura] '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo guardar la factura. Revise los datos.':$e->getMessage();}
}

// Función helper para obtener color según rol
function getRolColor($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return '#F99B07';
        case 'veterinario':
        case 'veterinarian':
            return '#0F6C33';
        case 'asistente':
            return '#2261BD';
        default:
            return '#5A4B37';
    }
}

// Función helper para texto del rol
function getRolTexto($rol) {
    switch(strtolower($rol)) {
        case 'admin':
            return 'Administrador';
        case 'veterinario':
        case 'veterinarian':
            return 'Veterinario';
        case 'asistente':
            return 'Asistente';
        default:
            return ucfirst($rol);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php if(function_exists('av_head')) av_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $av_invoice_id?'Editar factura':'Nueva factura'; ?> - VetNexo</title>
    <link rel="icon" type="image/png" href="includes/img/logo.svg">
    
    <link href='assets/vendor/boxicons/css/boxicons.min.css' rel='stylesheet'>
    <link href="css/sidebar.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0F6C33;        /* Verde Bosque */
            --primary-dark: #0a4f25;    /* Verde Bosque Oscuro */
            --primary-light: #2e8b57;   /* Verde más claro */
            --primary-soft: #e8f5e9;    /* Verde muy suave */
            
            --accent: #F99B07;           /* Naranja Vibrante */
            --accent-light: #ffb84d;     /* Naranja claro */
            --accent-soft: #fff3e0;      /* Naranja suave */
            --accent-dark: #d88206;       /* Naranja oscuro */
            
            --emergency: #F91302;         /* Rojo Corazón */
            --emergency-dark: #c00f02;    /* Rojo oscuro */
            --emergency-soft: #ffebee;    /* Rojo suave */
            
            --amarillo: #FBEF16;          /* Amarillo Intenso */
            --amarillo-dark: #e6d814;     /* Amarillo oscuro */
            --amarillo-soft: #fff9c4;     /* Amarillo suave */
            
            --marron: #5A4B37;            /* Marrón Chocolate */
            --marron-light: #7a6b57;      /* Marrón claro */
            --marron-soft: #f5f0e8;       /* Marrón suave */
            
            --azul: #2261BD;              /* Azul Real */
            --azul-light: #4d7fd8;        /* Azul claro */
            --azul-soft: #e3f2fd;         /* Azul suave */
            
            --blanco: #FFFFFF;             /* Blanco Puro */
            
            --text-dark: #2E2E2E;
            --text-light: #5A5A5A;
            --text-lighter: #888888;
            --background: #F8FDF8;
            --white: #FFFFFF;
            --gray-light: #F5F5F5;
            --gray: #E0E0E0;
            --danger: #F91302;
            --warning: #FBEF16;
            --success: #0F6C33;
            --info: #2261BD;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow: 0 5px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.12);
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
            font-family: 'Poppins', sans-serif;
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

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Botón Atrás */
        .back-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            background: var(--primary-soft);
            border: 1px solid var(--gray);
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateX(-3px);
        }

        /* Botones */
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
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(15, 108, 51, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(15, 108, 51, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
            color: var(--white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--primary-dark), #063619);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--white);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--accent-dark), #d88206);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
            color: var(--white);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, var(--azul-light), #3a6ab8);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-icon-danger {
            background: var(--emergency-soft);
            color: var(--emergency);
        }

        .btn-icon-danger:hover {
            background: var(--emergency);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-icon-success {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .btn-icon-success:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Secciones de Contenido */
        .content-section {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-soft), var(--white));
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
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

        /* Form Container */
        .form-container {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            overflow: hidden;
        }

        .form-section {
            padding: 1.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .required::after {
            content: " *";
            color: var(--emergency);
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--white);
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 108, 51, 0.1);
        }

        .form-control:read-only {
            background-color: var(--primary-soft);
            color: var(--text-light);
            cursor: not-allowed;
        }

        .form-control.select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-control.error {
            border-color: var(--emergency);
        }

        .error-message {
            color: var(--emergency);
            font-size: 0.8rem;
            margin-top: 0.25rem;
            display: none;
        }

        /* Services Table */
        .services-section {
            background: var(--primary-soft);
        }

        .table-container {
            background: var(--white);
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray);
            overflow: visible;
            box-shadow: var(--shadow-sm);
            position: relative;
        }

        .services-table {
            width: 100%;
            border-collapse: collapse;
        }

        .services-table th {
            background: var(--primary-soft);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .services-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
            background: var(--white);
            position: relative;
        }

        .services-table tr:last-child td {
            border-bottom: none;
        }

        .services-table tr:hover td {
            background: var(--primary-soft);
        }

        .service-input {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .service-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(15, 108, 51, 0.1);
        }

        .row-total {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 0.95rem;
        }

        /* Search Dropdown */
        .search-container {
            position: relative;
            width: 100%;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            max-height: 300px;
            overflow-y: auto;
            z-index: 10000;
            display: none;
            margin-top: 4px;
        }

        .search-result-item {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--primary-soft);
            transition: all 0.2s ease;
            display: block;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .search-result-item:hover {
            background-color: var(--primary-soft);
            transform: translateX(2px);
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .item-type {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
        }

        .item-type.servicio {
            background-color: var(--accent-soft);
            color: var(--accent-dark);
        }

        .item-type.producto {
            background-color: var(--primary-soft);
            color: var(--primary-dark);
        }

        .item-price {
            font-weight: 600;
            color: var(--primary-dark);
        }

        /* Badges */
        .item-type-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .item-type-badge.servicio {
            background-color: var(--accent-soft);
            color: var(--accent-dark);
        }

        .item-type-badge.producto {
            background-color: var(--primary-soft);
            color: var(--primary-dark);
        }

        .iva-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .iva-badge.yes {
            background-color: var(--accent-soft);
            color: var(--accent-dark);
        }

        .iva-badge.no {
            background-color: var(--primary-soft);
            color: var(--primary-dark);
        }

        /* Summary Section */
        .summary-section {
            background: var(--primary-soft);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .invoice-summary {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            box-shadow: var(--shadow);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--primary-light);
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-dark);
        }

        .summary-row.taxable {
            background-color: var(--accent-soft);
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            margin: 0.5rem 0;
        }

        .summary-row.exempt {
            background-color: var(--primary-soft);
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            margin: 0.5rem 0;
        }

        .summary-label {
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .summary-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .summary-row.total .summary-value {
            color: var(--primary-dark);
            font-size: 1.3rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: var(--success);
            color: #1e4620;
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-color: var(--emergency);
            color: #601e1e;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-success .alert-icon {
            color: var(--success);
        }

        .alert-error .alert-icon {
            color: var(--emergency);
        }

        /* Notification */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .notification {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--white);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 400px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: linear-gradient(135deg, var(--success), var(--primary-dark));
        }

        .notification.error {
            background: linear-gradient(135deg, var(--emergency), var(--emergency-dark));
        }

        .notification.warning {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
        }

        .notification.info {
            background: linear-gradient(135deg, var(--azul), var(--azul-light));
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

        /* Loading State */
        .loading {
            position: relative;
            opacity: 0.7;
            pointer-events: none;
        }

        .bx-loader-alt {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Partículas de fondo */
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
            background: rgba(15, 108, 51, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(180deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
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
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .back-button {
                width: 100%;
                justify-content: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .services-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Partículas de fondo -->
    <div class="particles">
        <div class="particle" style="top: 20%; left: 10%; width: 8px; height: 8px; animation-delay: 0s;"></div>
        <div class="particle" style="top: 60%; left: 80%; width: 12px; height: 12px; animation-delay: 1s;"></div>
        <div class="particle" style="top: 40%; left: 90%; width: 6px; height: 6px; animation-delay: 2s;"></div>
        <div class="particle" style="top: 80%; left: 20%; width: 10px; height: 10px; animation-delay: 3s;"></div>
    </div>

    <div class="notification-container" id="notificationContainer"></div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header con perfil de usuario -->
            <header class="dashboard-header fade-in">
                <div class="welcome-section">
                    <h1>
                        <i class='bx bx-receipt'></i>
                        <?php echo $av_invoice_id?'Editar factura':'Nueva factura'; ?>
                    </h1>
                    <p>
                        <i class='bx bx-calendar'></i>
                        <?php echo date('d \d\e F \d\e Y'); ?> • Complete la información para generar una nueva factura
                    </p>
                </div>
                
                <div class="header-actions">
                    <a href="facturas.php" class="back-button">
                        <i class='bx bx-arrow-back'></i> Volver a Facturas
                    </a>
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
                </div>
            </header>

            <?php if ($error!==''): ?>
                <div class="content-section">
                    <div class="section-body">
                        <div class="alert alert-error">
                            <div class="alert-icon">
                                <i class='bx bx-error-circle'></i>
                            </div>
                            <div class="alert-content">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Formulario de Factura -->
            <form id="invoiceForm" method="POST" action=""><?php echo av_csrf_field(); ?>
                <input type="hidden" name="generar_factura" value="1">
                <input type="hidden" name="numero_factura" id="hiddenInvoiceNumber" value="<?php echo $numero_factura; ?>">
                <input type="hidden" name="subtotal" id="hiddenSubtotal" value="0">
                <input type="hidden" name="descuento_total" id="hiddenTotalDiscount" value="0">
                <input type="hidden" name="taxable_base" id="hiddenTaxableBase" value="0">
                <input type="hidden" name="tax_exempt_base" id="hiddenTaxExemptBase" value="0">
                <input type="hidden" name="iva" id="hiddenTaxAmount" value="0">
                <input type="hidden" name="total" id="hiddenTotalAmount" value="0">
                
                <div class="form-container fade-in">
                    <!-- Información de la Factura -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3>
                                <i class='bx bx-info-circle'></i>
                                Información de la Factura
                            </h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Número de Factura</label>
                                <input type="text" class="form-control" id="invoiceNumber" value="<?php echo $numero_factura; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Fecha de Emisión</label>
                                <input type="date" class="form-control" id="invoiceDate" name="fecha_emision" value="<?php echo date('Y-m-d'); ?>" required>
                                <div class="error-message" id="dateError">La fecha es requerida</div>
                            </div>
                        </div>
                    </div>

                    <!-- Información del Cliente -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3>
                                <i class='bx bx-user'></i>
                                Información del Cliente
                            </h3>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="searchClientModal()">
                                <i class='bx bx-search'></i>
                                Buscar Cliente
                            </button>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Nombre o Razón Social</label>
                                <input type="text" class="form-control" id="clientName" name="cliente_nombre" placeholder="Ingrese nombre completo" required>
                                <div class="error-message" id="clientNameError">El nombre del cliente es requerido</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">RIF/Cédula</label>
                                <input type="text" class="form-control" id="clientId" name="cliente_identificacion" placeholder="Ej: [Tax ID not configured]" required>
                                <div class="error-message" id="clientIdError">La identificación es requerida</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Teléfono</label>
                                <input type="tel" class="form-control" id="clientPhone" name="cliente_telefono" placeholder="Número de contacto">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Domicilio Fiscal</label>
                                <input type="text" class="form-control" id="clientAddress" name="cliente_direccion" placeholder="Dirección completa del cliente">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Forma de Pago</label>
                                <select class="form-control select" id="paymentMethod" name="forma_pago" required>
                                    <option value="CONTADO">CONTADO</option>
                                    <option value="DIVISAS">DIVISAS</option>
                                    <option value="PAGO_MOVIL">PAGO MÓVIL</option>
                                    <option value="TARJETA_DEBITO">TARJETA DE DÉBITO</option>
                                    <option value="TARJETA_CREDITO">TARJETA DE CRÉDITO</option>
                                    <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                                </select>
                                <div class="error-message" id="paymentMethodError">La forma de pago es requerida</div>
                            </div>
                        </div>
                    </div>

                    <!-- Servicios y Productos -->
                    <div class="form-section services-section">
                        <div class="section-header">
                            <h3>
                                <i class='bx bx-package'></i>
                                Servicios y Productos
                            </h3>
                            <button type="button" class="btn btn-success btn-sm" onclick="addServiceRow()">
                                <i class='bx bx-plus'></i>
                                Agregar Item
                            </button>
                        </div>
                        <div class="table-container">
                            <table class="services-table">
                                <thead>
                                    <tr>
                                        <th width="35%">DESCRIPCIÓN</th>
                                        <th width="10%">TIPO</th>
                                        <th width="8%">CANT.</th>
                                        <th width="12%">P.U. (USD)</th>
                                        <th width="12%">DESCUENTO</th>
                                        <th width="12%">TOTAL</th>
                                        <th width="6%">IVA</th>
                                        <th width="5%"></th>
                                    </tr>
                                </thead>
                                <tbody id="servicesTableBody">
                                    <tr>
                                        <td>
                                            <div class="search-container">
                                                <input type="text" class="service-input search-input" 
                                                       placeholder="Buscar servicio o producto..." 
                                                       oninput="searchItems(this)"
                                                       data-row-index="0">
                                                <div class="search-results" id="searchResults0"></div>
                                            </div>
                                            <input type="hidden" name="items[0][item_id]" value=""><input type="hidden" name="items[0][presentacion_id]" value="">
                                            <input type="hidden" name="items[0][tipo_item]" value="">
                                            <input type="hidden" name="items[0][aplica_iva]" value="0">
                                            <input type="hidden" name="items[0][porcentaje_iva]" value="0">
                                            <input type="hidden" name="items[0][descripcion]" id="itemDescripcion0" value="">
                                        </td>
                                        <td>
                                            <span class="item-type-badge" id="itemType0">-</span>
                                        </td>
                                        <td>
                                            <input type="number" class="service-input" name="items[0][cantidad]" value="1" min="1" onchange="calculateRowTotal(this)">
                                        </td>
                                        <td>
                                            <input type="number" class="service-input" name="items[0][precio_unitario]" value="0" min="0" step="0.01" onchange="calculateRowTotal(this)">
                                        </td>
                                        <td>
                                            <input type="number" class="service-input" name="items[0][descuento]" value="0" min="0" step="0.01" onchange="calculateRowTotal(this)">
                                        </td>
                                        <td>
                                            <span class="row-total">0,00</span>
                                            <input type="hidden" name="items[0][total]" value="0">
                                        </td>
                                        <td>
                                            <span id="ivaStatus0" class="iva-badge no">Exento</span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn-icon btn-icon-danger" onclick="removeServiceRow(this)">
                                                <i class='bx bx-trash'></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Resumen y Observaciones -->
                    <div class="form-section summary-section">
                        <div class="summary-grid">
                            <div class="form-group">
                                <label class="form-label">Observaciones</label>
                                <textarea class="form-control" id="observations" name="observaciones" placeholder="Observaciones adicionales, condiciones especiales, etc..."></textarea>
                            </div>
                            <div class="invoice-summary">
                                <div class="summary-row">
                                    <span class="summary-label">Subtotal:</span>
                                    <span class="summary-value" id="subtotal">0,00</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Descuento Total:</span>
                                    <span class="summary-value" id="totalDiscount">0,00</span>
                                </div>
                                <div class="summary-row taxable">
                                    <span class="summary-label">Base Imponible (Gravada):</span>
                                    <span class="summary-value" id="taxableBase">0,00</span>
                                </div>
                                <div class="summary-row exempt">
                                    <span class="summary-label">Base Exenta:</span>
                                    <span class="summary-value" id="taxExemptBase">0,00</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">IVA:</span>
                                    <span class="summary-value" id="taxAmount">0,00</span>
                                </div>
                                <div class="summary-row total">
                                    <span class="summary-label">TOTAL USD.:</span>
                                    <span class="summary-value" id="totalAmount">0,00</span>
                                </div>
                                
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-secondary" onclick="previewInvoice()">
                                        <i class='bx bx-show'></i>
                                        Vista Previa
                                    </button>
                                    <button type="submit" class="btn btn-primary" onclick="return validateAndSubmit()">
                                        <i class='bx bx-save'></i>
                                        Generar Factura
                                    </button>
                                    <button type="button" class="btn btn-warning" onclick="clearForm()">
                                        <i class='bx bx-reset'></i>
                                        Limpiar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

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
    // Variables globales
    let rowCount = 1;

    // Inicialización
    document.addEventListener('DOMContentLoaded', function() {
        // Calcular totales iniciales
        calculateTotals();
        
        // Configurar validaciones
        setupValidations();
        
        // Crear partículas dinámicas
        const particlesContainer = document.querySelector('.particles');
        if (particlesContainer) {
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
                    background: ${Math.random() > 0.5 ? 'rgba(15, 108, 51, 0.05)' : 'rgba(249, 155, 7, 0.05)'};
                `;
                particlesContainer.appendChild(particle);
            }
        }
    });

    // Funciones de utilidad
    function formatCurrency(amount) {
        if (isNaN(amount)) amount = 0;
        return new Intl.NumberFormat('es-VE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    }

    function showNotification(message, type = 'info', duration = 5000) {
        const container = document.getElementById('notificationContainer');
        if (!container) return;
        
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error-circle' : type === 'warning' ? 'bx-error' : 'bx-info-circle'}'></i>
            <span>${message}</span>
        `;
        
        container.appendChild(notification);
        
        setTimeout(() => notification.classList.add('show'), 10);
        
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, duration);
    }

    function showError(fieldId, message) {
        const field = document.getElementById(fieldId);
        const errorElement = document.getElementById(fieldId + 'Error');
        
        if (field && errorElement) {
            field.classList.add('error');
            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }
    }

    function hideError(fieldId) {
        const field = document.getElementById(fieldId);
        const errorElement = document.getElementById(fieldId + 'Error');
        
        if (field && errorElement) {
            field.classList.remove('error');
            errorElement.style.display = 'none';
        }
    }

    function setupValidations() {
        const requiredFields = ['clientName', 'clientId', 'invoiceDate', 'paymentMethod'];
        
        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        showError(fieldId, 'Este campo es requerido');
                    } else {
                        hideError(fieldId);
                    }
                });
                
                field.addEventListener('input', function() {
                    if (this.value.trim()) {
                        hideError(fieldId);
                    }
                });
            }
        });
    }

    // Búsqueda de servicios y productos
    async function searchItems(input) {
        const searchTerm = input.value.trim();
        const rowIndex = input.getAttribute('data-row-index');
        const resultsContainer = document.getElementById(`searchResults${rowIndex}`);
        
        if (searchTerm.length < 2) {
            resultsContainer.style.display = 'none';
            return;
        }
        
        try {
            const response = await fetch(`buscar_items.php?q=${encodeURIComponent(searchTerm)}`);
            const items = await response.json();
            
            resultsContainer.innerHTML = '';
            
            if (items.length === 0) {
                resultsContainer.innerHTML = '<div class="search-result-item">No se encontraron resultados</div>';
            } else {
                items.forEach(item => {
                    const resultItem = document.createElement('button');
                    resultItem.className = 'search-result-item';
                    resultItem.type = 'button';
                    
                    let ivaInfo = '';
                    if (item.tipo === 'producto') {
                        ivaInfo = item.aplica_iva == 1 ? `(IVA ${item.porcentaje_iva || 16}%)` : '(Exento)';
                    } else {
                        ivaInfo = '(Exento)';
                    }
                    
                    resultItem.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-weight: 600;">${item.nombre}</span>
                            <span class="item-price">USD ${formatCurrency(parseFloat(item.precio))}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span class="item-type ${item.tipo}">${item.tipo.toUpperCase()}</span>
                            <small>${ivaInfo}</small>
                        </div>
                    `;
                    
                    resultItem.addEventListener('click', () => {
                        selectItem(rowIndex, item);
                        resultsContainer.style.display = 'none';
                        input.value = item.nombre;
                    });
                    
                    resultsContainer.appendChild(resultItem);
                });
            }
            
            resultsContainer.style.display = 'block';
            
        } catch (error) {
            console.error('Error en la búsqueda:', error);
        }
    }

    function selectItem(rowIndex, item) {
        const row = document.querySelector(`input[data-row-index="${rowIndex}"]`).closest('tr');
        
        // Actualizar campos ocultos
        row.querySelector(`input[name="items[${rowIndex}][item_id]"]`).value = item.id;
        row.querySelector(`input[name="items[${rowIndex}][presentacion_id]"]`).value = item.presentacion_id || "";
        row.querySelector(`input[name="items[${rowIndex}][tipo_item]"]`).value = item.tipo;
        row.querySelector(`input[name="items[${rowIndex}][precio_unitario]"]`).value = item.precio;
        row.querySelector(`input[name="items[${rowIndex}][descripcion]"]`).value = item.nombre;
        
        // Configurar IVA
        let aplicaIva = 0;
        let porcentajeIva = 0;
        let ivaStatus = 'Exento';
        
        if (item.tipo === 'producto' && item.aplica_iva == 1) {
            aplicaIva = 1;
            porcentajeIva = item.porcentaje_iva || 16.00;
            ivaStatus = `${porcentajeIva}%`;
        }
        
        row.querySelector(`input[name="items[${rowIndex}][aplica_iva]"]`).value = aplicaIva;
        row.querySelector(`input[name="items[${rowIndex}][porcentaje_iva]"]`).value = porcentajeIva;
        
        // Actualizar badge de tipo y estado IVA
        const typeBadge = document.getElementById(`itemType${rowIndex}`);
        const ivaBadge = document.getElementById(`ivaStatus${rowIndex}`);
        
        typeBadge.textContent = item.tipo === 'servicio' ? 'Servicio' : 'Producto';
        typeBadge.className = `item-type-badge ${item.tipo}`;
        
        ivaBadge.textContent = ivaStatus;
        ivaBadge.className = `iva-badge ${aplicaIva === 1 ? 'yes' : 'no'}`;
        
        // Calcular total de la fila
        calculateRowTotal(row.querySelector(`input[name="items[${rowIndex}][cantidad]"]`));
        
        showNotification(`"${item.nombre}" agregado`, 'success');
    }

    // Funciones de servicios
    function addServiceRow() {
        const tableBody = document.getElementById('servicesTableBody');
        
        const newRow = document.createElement('tr');
        newRow.innerHTML = `
            <td>
                <div class="search-container">
                    <input type="text" class="service-input search-input" 
                           placeholder="Buscar servicio o producto..." 
                           oninput="searchItems(this)"
                           data-row-index="${rowCount}">
                    <div class="search-results" id="searchResults${rowCount}"></div>
                </div>
                <input type="hidden" name="items[${rowCount}][item_id]" value=""><input type="hidden" name="items[${rowCount}][presentacion_id]" value="">
                <input type="hidden" name="items[${rowCount}][tipo_item]" value="">
                <input type="hidden" name="items[${rowCount}][aplica_iva]" value="0">
                <input type="hidden" name="items[${rowCount}][porcentaje_iva]" value="0">
                <input type="hidden" name="items[${rowCount}][descripcion]" id="itemDescripcion${rowCount}" value="">
            </td>
            <td>
                <span class="item-type-badge" id="itemType${rowCount}">-</span>
            </td>
            <td>
                <input type="number" class="service-input" name="items[${rowCount}][cantidad]" value="1" min="1" onchange="calculateRowTotal(this)">
            </td>
            <td>
                <input type="number" class="service-input" name="items[${rowCount}][precio_unitario]" value="0" min="0" step="0.01" onchange="calculateRowTotal(this)">
            </td>
            <td>
                <input type="number" class="service-input" name="items[${rowCount}][descuento]" value="0" min="0" step="0.01" onchange="calculateRowTotal(this)">
            </td>
            <td>
                <span class="row-total">0,00</span>
                <input type="hidden" name="items[${rowCount}][total]" value="0">
            </td>
            <td>
                <span id="ivaStatus${rowCount}" class="iva-badge no">Exento</span>
            </td>
            <td>
                <button type="button" class="btn-icon btn-icon-danger" onclick="removeServiceRow(this)">
                    <i class='bx bx-trash'></i>
                </button>
            </td>
        `;
        
        tableBody.appendChild(newRow);
        rowCount++;
        
        showNotification('Nuevo item agregado', 'success');
    }

    function removeServiceRow(button) {
        const row = button.closest('tr');
        const tableBody = document.getElementById('servicesTableBody');
        
        if (tableBody.children.length > 1) {
            row.remove();
            calculateTotals();
            showNotification('Item eliminado', 'info');
        } else {
            showNotification('Debe haber al menos un item', 'warning');
        }
    }

    // Funciones de cálculo
    function calculateRowTotal(input) {
        const row = input.closest('tr');
        const rowIndex = row.querySelector('.search-input').getAttribute('data-row-index');
        
        const quantity = parseFloat(row.querySelector(`input[name="items[${rowIndex}][cantidad]"]`).value) || 0;
        const price = parseFloat(row.querySelector(`input[name="items[${rowIndex}][precio_unitario]"]`).value) || 0;
        const discount = parseFloat(row.querySelector(`input[name="items[${rowIndex}][descuento]"]`).value) || 0;
        
        const subtotal = quantity * price;
        const total = Math.max(0, subtotal - discount);
        
        const totalSpan = row.querySelector('.row-total');
        const totalInput = row.querySelector('input[type="hidden"][name*="total"]');
        
        totalSpan.textContent = formatCurrency(total);
        totalInput.value = total;
        
        calculateTotals();
    }

    function calculateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        let taxableBase = 0;
        let taxExemptBase = 0;
        let taxAmount = 0;
        
        const rows = document.querySelectorAll('#servicesTableBody tr');
        rows.forEach(row => {
            const rowIndex = row.querySelector('.search-input').getAttribute('data-row-index');
            const quantity = parseFloat(row.querySelector(`input[name="items[${rowIndex}][cantidad]"]`).value) || 0;
            const price = parseFloat(row.querySelector(`input[name="items[${rowIndex}][precio_unitario]"]`).value) || 0;
            const discount = parseFloat(row.querySelector(`input[name="items[${rowIndex}][descuento]"]`).value) || 0;
            const aplicaIva = parseInt(row.querySelector(`input[name="items[${rowIndex}][aplica_iva]"]`).value) || 0;
            const porcentajeIva = parseFloat(row.querySelector(`input[name="items[${rowIndex}][porcentaje_iva]"]`).value) || 16.00;
            
            const rowSubtotal = quantity * price;
            const rowTotal = Math.max(0, rowSubtotal - discount);
            
            subtotal += rowSubtotal;
            totalDiscount += discount;
            
            if (aplicaIva === 1) {
                taxableBase += rowTotal;
                taxAmount += (rowTotal * porcentajeIva) / 100;
            } else {
                taxExemptBase += rowTotal;
            }
        });
        
        const totalAmount = subtotal - totalDiscount + taxAmount;
        
        // Actualizar display
        document.getElementById('subtotal').textContent = formatCurrency(subtotal);
        document.getElementById('totalDiscount').textContent = formatCurrency(totalDiscount);
        document.getElementById('taxableBase').textContent = formatCurrency(taxableBase);
        document.getElementById('taxExemptBase').textContent = formatCurrency(taxExemptBase);
        document.getElementById('taxAmount').textContent = formatCurrency(taxAmount);
        document.getElementById('totalAmount').textContent = formatCurrency(totalAmount);
        
        // Actualizar campos ocultos
        document.getElementById('hiddenSubtotal').value = subtotal;
        document.getElementById('hiddenTotalDiscount').value = totalDiscount;
        document.getElementById('hiddenTaxableBase').value = taxableBase;
        document.getElementById('hiddenTaxExemptBase').value = taxExemptBase;
        document.getElementById('hiddenTaxAmount').value = taxAmount;
        document.getElementById('hiddenTotalAmount').value = totalAmount;
    }

    // Validación y envío - CORREGIDO: El problema de "Generando..."
    function validateAndSubmit() {
        let isValid = true;
        
        // Validar campos requeridos
        const requiredFields = [
            { id: 'clientName', message: 'El nombre del cliente es obligatorio' },
            { id: 'clientId', message: 'La identificación del cliente es obligatoria' },
            { id: 'invoiceDate', message: 'La fecha de emisión es obligatoria' },
            { id: 'paymentMethod', message: 'La forma de pago es obligatoria' }
        ];
        
        requiredFields.forEach(field => {
            const value = document.getElementById(field.id).value.trim();
            if (!value) {
                showError(field.id, field.message);
                isValid = false;
            } else {
                hideError(field.id);
            }
        });
        
        // Validar que al menos un item tenga descripción
        const hasItems = Array.from(document.querySelectorAll('#servicesTableBody tr'))
            .some(row => row.querySelector('.search-input').value.trim());
        
        if (!hasItems) {
            showNotification('Debe agregar al menos un servicio o producto', 'error');
            isValid = false;
        }
        
        const totalAmount = parseFloat(document.getElementById('hiddenTotalAmount').value);
        if (totalAmount <= 0) {
            showNotification('El total debe ser mayor a 0', 'warning');
            isValid = false;
        }
        
        return isValid; // SOLO retorna el resultado, NO envía el formulario automáticamente
    }

    // Modificar el evento de envío del formulario
    document.getElementById('invoiceForm').addEventListener('submit', function(e) {
        e.preventDefault(); // Prevenir envío automático
        
        if (validateAndSubmit()) {
            if (confirm('¿Está seguro de generar esta factura?')) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Generando...';
                submitBtn.disabled = true;
                
                // Enviar el formulario después de un pequeño retraso
                setTimeout(() => {
                    this.submit();
                }, 100);
            }
        }
    });

    function clearForm() {
        if (confirm('¿Limpiar todos los campos?')) {
            document.getElementById('clientName').value = '';
            document.getElementById('clientId').value = '';
            document.getElementById('clientPhone').value = '';
            document.getElementById('clientAddress').value = '';
            document.getElementById('observations').value = '';
            document.getElementById('paymentMethod').value = 'CONTADO';
            
            // Resetear tabla
            const tableBody = document.getElementById('servicesTableBody');
            tableBody.innerHTML = `
                <tr>
                    <td>
                        <div class="search-container">
                            <input type="text" class="service-input search-input" 
                                   placeholder="Buscar servicio o producto..." 
                                   oninput="searchItems(this)"
                                   data-row-index="0">
                            <div class="search-results" id="searchResults0"></div>
                        </div>
                        <input type="hidden" name="items[0][item_id]" value=""><input type="hidden" name="items[0][presentacion_id]" value="">
                        <input type="hidden" name="items[0][tipo_item]" value="">
                        <input type="hidden" name="items[0][aplica_iva]" value="0">
                        <input type="hidden" name="items[0][porcentaje_iva]" value="0">
                        <input type="hidden" name="items[0][descripcion]" id="itemDescripcion0" value="">
                    </td>
                    <td>
                        <span class="item-type-badge" id="itemType0">-</span>
                    </td>
                    <td>
                        <input type="number" class="service-input" name="items[0][cantidad]" value="1" min="1" onchange="calculateRowTotal(this)">
                    </td>
                    <td>
                        <input type="number" class="service-input" name="items[0][precio_unitario]" value="0" min="0" step="0.01" onchange="calculateRowTotal(this)">
                    </td>
                    <td>
                        <input type="number" class="service-input" name="items[0][descuento]" value="0" min="0" step="0.01" onchange="calculateRowTotal(this)">
                    </td>
                    <td>
                        <span class="row-total">0,00</span>
                        <input type="hidden" name="items[0][total]" value="0">
                    </td>
                    <td>
                        <span id="ivaStatus0" class="iva-badge no">Exento</span>
                    </td>
                    <td>
                        <button type="button" class="btn-icon btn-icon-danger" onclick="removeServiceRow(this)">
                            <i class='bx bx-trash'></i>
                        </button>
                    </td>
                </tr>
            `;
            rowCount = 1;
            
            calculateTotals();
            showNotification('Formulario limpiado', 'success');
        }
    }

    // ============================================
    // VISTA PREVIA DE FACTURA HERMOSA
    // ============================================
    function previewInvoice() {
        // Validar antes de mostrar vista previa
        if (!validateAndSubmit()) {
            return;
        }
        
        const data = {
            invoiceNumber: document.getElementById('invoiceNumber').value,
            date: document.getElementById('invoiceDate').value,
            client: {
                name: document.getElementById('clientName').value,
                id: document.getElementById('clientId').value,
                phone: document.getElementById('clientPhone').value,
                address: document.getElementById('clientAddress').value
            },
            paymentMethod: document.getElementById('paymentMethod').value,
            subtotal: parseFloat(document.getElementById('hiddenSubtotal').value),
            totalDiscount: parseFloat(document.getElementById('hiddenTotalDiscount').value),
            taxableBase: parseFloat(document.getElementById('hiddenTaxableBase').value),
            taxExemptBase: parseFloat(document.getElementById('hiddenTaxExemptBase').value),
            taxAmount: parseFloat(document.getElementById('hiddenTaxAmount').value),
            totalAmount: parseFloat(document.getElementById('hiddenTotalAmount').value),
            observations: document.getElementById('observations').value,
            items: []
        };
        
        const rows = document.querySelectorAll('#servicesTableBody tr');
        rows.forEach(row => {
            const rowIndex = row.querySelector('.search-input').getAttribute('data-row-index');
            const description = row.querySelector(`input[name="items[${rowIndex}][descripcion]"]`).value;
            const itemType = row.querySelector(`input[name="items[${rowIndex}][tipo_item]"]`).value;
            const quantity = parseFloat(row.querySelector(`input[name="items[${rowIndex}][cantidad]"]`).value);
            const price = parseFloat(row.querySelector(`input[name="items[${rowIndex}][precio_unitario]"]`).value);
            const discount = parseFloat(row.querySelector(`input[name="items[${rowIndex}][descuento]"]`).value);
            const total = parseFloat(row.querySelector(`input[name="items[${rowIndex}][total]"]`).value);
            const aplicaIva = row.querySelector(`input[name="items[${rowIndex}][aplica_iva]"]`).value;
            const porcentajeIva = row.querySelector(`input[name="items[${rowIndex}][porcentaje_iva]"]`).value;
            
            if (description) {
                data.items.push({ 
                    description, 
                    itemType: itemType === 'servicio' ? 'Servicio' : 'Producto', 
                    quantity, 
                    price, 
                    discount, 
                    total,
                    aplicaIva,
                    porcentajeIva
                });
            }
        });
        
        // Abrir ventana de vista previa
        const previewWindow = window.open('', '_blank');
        previewWindow.document.write(generateBeautifulInvoiceHTML(data));
        previewWindow.document.close();
    }

    function generateBeautifulInvoiceHTML(data) {
        const fecha = new Date(data.date);
        const fechaFormateada = fecha.toLocaleDateString('es-VE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
        
        const formaPago = data.paymentMethod || 'CONTADO';
        
        // Calcular subtotal con descuento
        const subtotalConDescuento = data.subtotal - data.totalDiscount;
        
        return `
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Factura ${data.invoiceNumber} - VetNexo</title>
            
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                :root {
                    --primary: #0F6C33;
                    --primary-dark: #0a4f25;
                    --primary-light: #2e8b57;
                    --primary-soft: #e8f5e9;
                    
                    --accent: #F99B07;
                    --accent-light: #ffb84d;
                    --accent-soft: #fff3e0;
                    --accent-dark: #d88206;
                    
                    --emergency: #F91302;
                    --emergency-soft: #ffebee;
                    
                    --amarillo: #FBEF16;
                    --amarillo-soft: #fff9c4;
                    
                    --marron: #5A4B37;
                    --marron-light: #7a6b57;
                    --marron-soft: #f5f0e8;
                    
                    --azul: #2261BD;
                    --azul-light: #4d7fd8;
                    --azul-soft: #e3f2fd;
                    
                    --text-dark: #2E2E2E;
                    --text-light: #5A5A5A;
                    --text-lighter: #888888;
                    --white: #FFFFFF;
                    --gray-light: #F5F5F5;
                    --gray: #E0E0E0;
                    
                    --shadow: 0 10px 30px rgba(0,0,0,0.1);
                    --shadow-sm: 0 5px 15px rgba(0,0,0,0.05);
                }
                
                body {
                    font-family: 'Poppins', sans-serif;
                    background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e9 100%);
                    color: var(--text-dark);
                    padding: 30px 20px;
                    min-height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                }
                
                .invoice {
                    max-width: 900px;
                    width: 100%;
                    background: var(--white);
                    border-radius: 24px;
                    box-shadow: var(--shadow);
                    overflow: hidden;
                    position: relative;
                }
                
                .invoice::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 8px;
                    background: linear-gradient(90deg, var(--primary), var(--accent), var(--azul));
                }
                
                .invoice-header {
                    background: linear-gradient(135deg, var(--primary-soft), var(--white));
                    padding: 30px 30px 20px;
                    border-bottom: 2px solid var(--primary-light);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 20px;
                }
                
                .company-info {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                }
                
                .company-logo {
                    width: 80px;
                    height: 80px;
                    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: white;
                    font-size: 2.5rem;
                    font-weight: 700;
                    box-shadow: var(--shadow-sm);
                }
                
                .company-details h1 {
                    color: var(--primary-dark);
                    font-size: 1.8rem;
                    font-weight: 700;
                    margin-bottom: 5px;
                }
                
                .company-details p {
                    color: var(--text-light);
                    font-size: 0.85rem;
                    margin: 2px 0;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                
                .company-details i {
                    color: var(--primary);
                    width: 20px;
                }
                
                .invoice-title {
                    text-align: right;
                }
                
                .invoice-title h2 {
                    color: var(--primary-dark);
                    font-size: 2rem;
                    font-weight: 700;
                    margin-bottom: 5px;
                    letter-spacing: 2px;
                }
                
                .invoice-title .badge {
                    display: inline-block;
                    padding: 8px 20px;
                    border-radius: 50px;
                    font-weight: 600;
                    font-size: 1rem;
                    margin-top: 5px;
                }
                
                .badge-paid {
                    background: linear-gradient(135deg, var(--primary-soft), #c8e6c9);
                    color: var(--primary-dark);
                }
                
                .badge-pending {
                    background: linear-gradient(135deg, var(--accent-soft), #ffe0b2);
                    color: var(--accent-dark);
                }
                
                .invoice-info {
                    padding: 20px 30px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    background: var(--marron-soft);
                    border-bottom: 1px dashed var(--marron-light);
                    flex-wrap: wrap;
                    gap: 15px;
                }
                
                .info-item {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                
                .info-item i {
                    width: 35px;
                    height: 35px;
                    background: var(--white);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: var(--primary);
                    font-size: 1.2rem;
                    box-shadow: var(--shadow-sm);
                }
                
                .info-item .label {
                    font-size: 0.85rem;
                    color: var(--text-light);
                }
                
                .info-item .value {
                    font-weight: 600;
                    color: var(--text-dark);
                    font-size: 1rem;
                }
                
                .client-section {
                    padding: 25px 30px;
                    background: linear-gradient(135deg, var(--azul-soft), var(--white));
                    border-bottom: 2px solid var(--azul-light);
                }
                
                .client-section h3 {
                    color: var(--azul);
                    font-size: 1.2rem;
                    margin-bottom: 15px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                
                .client-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                }
                
                .client-item {
                    display: flex;
                    flex-direction: column;
                }
                
                .client-item .label {
                    font-size: 0.8rem;
                    color: var(--text-light);
                    margin-bottom: 3px;
                }
                
                .client-item .value {
                    font-weight: 600;
                    color: var(--text-dark);
                    font-size: 1rem;
                }
                
                .items-section {
                    padding: 25px 30px;
                }
                
                .items-section h3 {
                    color: var(--primary-dark);
                    font-size: 1.2rem;
                    margin-bottom: 15px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    border-radius: 16px;
                    overflow: hidden;
                    box-shadow: var(--shadow-sm);
                }
                
                .items-table th {
                    background: var(--primary);
                    color: var(--white);
                    padding: 15px 10px;
                    font-weight: 600;
                    font-size: 0.9rem;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }
                
                .items-table td {
                    padding: 15px 10px;
                    border-bottom: 1px solid var(--gray-light);
                    color: var(--text-dark);
                }
                
                .items-table tr:last-child td {
                    border-bottom: none;
                }
                
                .items-table tbody tr:hover {
                    background: var(--primary-soft);
                }
                
                .iva-badge {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 20px;
                    font-size: 0.7rem;
                    font-weight: 600;
                }
                
                .iva-badge.yes {
                    background: var(--accent-soft);
                    color: var(--accent-dark);
                }
                
                .iva-badge.no {
                    background: var(--primary-soft);
                    color: var(--primary-dark);
                }
                
                .text-right {
                    text-align: right;
                }
                
                .text-center {
                    text-align: center;
                }
                
                .summary-section {
                    padding: 20px 30px 30px;
                    background: var(--primary-soft);
                    border-top: 2px solid var(--primary-light);
                }
                
                .summary-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 30px;
                }
                
                .totales {
                    background: var(--white);
                    padding: 20px;
                    border-radius: 16px;
                    box-shadow: var(--shadow-sm);
                }
                
                .total-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 12px 0;
                    border-bottom: 1px dashed var(--gray-light);
                }
                
                .total-row:last-child {
                    border-bottom: none;
                }
                
                .total-label {
                    color: var(--text-light);
                    font-weight: 500;
                }
                
                .total-value {
                    font-weight: 600;
                    color: var(--text-dark);
                }
                
                .iva-row {
                    background: var(--accent-soft);
                    padding: 12px 15px;
                    border-radius: 12px;
                    margin: 10px 0;
                }
                
                .grand-total {
                    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                    color: var(--white);
                    padding: 15px 20px;
                    border-radius: 12px;
                    margin-top: 15px;
                }
                
                .grand-total .total-label,
                .grand-total .total-value {
                    color: var(--white);
                    font-weight: 700;
                }
                
                .grand-total .total-value {
                    font-size: 1.3rem;
                }
                
                .observations {
                    background: var(--marron-soft);
                    padding: 15px 20px;
                    border-radius: 12px;
                    border-left: 4px solid var(--marron);
                }
                
                .observations strong {
                    color: var(--marron);
                }
                
                .footer {
                    padding: 20px 30px;
                    text-align: center;
                    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
                    color: var(--white);
                    border-top: 2px solid var(--white);
                }
                
                .footer p {
                    opacity: 0.9;
                    font-size: 0.9rem;
                    margin: 5px 0;
                }
                
                .footer .control-num {
                    font-size: 1.1rem;
                    font-weight: 700;
                    letter-spacing: 2px;
                    margin-top: 10px;
                }
                
                .watermark {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    opacity: 0.03;
                    font-size: 8rem;
                    font-weight: 700;
                    color: var(--primary);
                    pointer-events: none;
                    z-index: 0;
                }
                
                @media print {
                    body {
                        background: white;
                        padding: 0;
                    }
                    .invoice {
                        box-shadow: none;
                    }
                }
                
                @media (max-width: 768px) {
                    .invoice-header {
                        flex-direction: column;
                        text-align: center;
                    }
                    .company-info {
                        flex-direction: column;
                    }
                    .invoice-title {
                        text-align: center;
                    }
                    .summary-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
            <link rel="stylesheet" href="css/app-optimized.css?v=20260812c">
</head>
        <body>
            <div class="invoice">
                <div class="watermark">VETNEXO</div>
                
                <div class="invoice-header">
                    <div class="company-info">
                        <div class="company-logo">
                            <span>AHT</span>
                        </div>
                        <div class="company-details">
                            <h1>VetNexo</h1>
                            <p><i class='bx bx-id-card'></i> RIF: J-29763608-0</p>
                            <p><i class='bx bx-phone'></i> (0424) 960 29 06 - (0426) 462 33 56</p>
                            <p><i class='bx bx-map'></i> Demo Veterinary Clinic</p>
                        </div>
                    </div>
                    <div class="invoice-title">
                        <h2>FACTURA</h2>
                        <div class="badge badge-pending">
                            <i class='bx bx-time-five'></i> PENDIENTE
                        </div>
                    </div>
                </div>
                
                <div class="invoice-info">
                    <div class="info-item">
                        <i class='bx bx-receipt'></i>
                        <div>
                            <div class="label">N° Factura</div>
                            <div class="value">${data.invoiceNumber}</div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class='bx bx-calendar'></i>
                        <div>
                            <div class="label">Fecha Emisión</div>
                            <div class="value">${fechaFormateada}</div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class='bx bx-credit-card'></i>
                        <div>
                            <div class="label">Forma de Pago</div>
                            <div class="value">${formaPago}</div>
                        </div>
                    </div>
                </div>
                
                <div class="client-section">
                    <h3><i class='bx bx-user-circle'></i> Datos del Cliente</h3>
                    <div class="client-grid">
                        <div class="client-item">
                            <span class="label">Nombre/Razón Social</span>
                            <span class="value">${data.client.name}</span>
                        </div>
                        <div class="client-item">
                            <span class="label">RIF/Cédula</span>
                            <span class="value">${data.client.id}</span>
                        </div>
                        <div class="client-item">
                            <span class="label">Teléfono</span>
                            <span class="value">${data.client.phone || 'No especificado'}</span>
                        </div>
                        <div class="client-item">
                            <span class="label">Dirección</span>
                            <span class="value">${data.client.address || 'No especificada'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="items-section">
                    <h3><i class='bx bx-package'></i> Detalle de la Factura</h3>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Descripción</th>
                                <th>Tipo</th>
                                <th class="text-center">Cant.</th>
                                <th class="text-right">P.Unitario</th>
                                <th class="text-right">Descuento</th>
                                <th class="text-center">IVA</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.items.map(item => `
                                <tr>
                                    <td>${item.description}</td>
                                    <td>${item.itemType}</td>
                                    <td class="text-center">${item.quantity}</td>
                                    <td class="text-right">USD ${formatCurrency(item.price)}</td>
                                    <td class="text-right">${item.discount > 0 ? 'USD ' + formatCurrency(item.discount) : '-'}</td>
                                    <td class="text-center">
                                        <span class="iva-badge ${item.aplicaIva == 1 ? 'yes' : 'no'}">
                                            ${item.aplicaIva == 1 ? (item.porcentajeIva || '16') + '%' : 'Exento'}
                                        </span>
                                    </td>
                                    <td class="text-right"><strong>USD ${formatCurrency(item.total)}</strong></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                
                <div class="summary-section">
                    <div class="summary-grid">
                        <div>
                            <div class="observations">
                                <strong><i class='bx bx-note'></i> Observaciones:</strong><br>
                                ${data.observations ? data.observations : 'Sin observaciones adicionales'}
                            </div>
                        </div>
                        <div class="totales">
                            <div class="total-row">
                                <span class="total-label">Subtotal:</span>
                                <span class="total-value">USD ${formatCurrency(data.subtotal)}</span>
                            </div>
                            ${data.totalDiscount > 0 ? `
                            <div class="total-row">
                                <span class="total-label">Descuento Total:</span>
                                <span class="total-value" style="color: var(--emergency);">- USD ${formatCurrency(data.totalDiscount)}</span>
                            </div>
                            ` : ''}
                            <div class="total-row iva-row">
                                <span class="total-label">Base Imponible (Gravada):</span>
                                <span class="total-value">USD ${formatCurrency(data.taxableBase)}</span>
                            </div>
                            <div class="total-row">
                                <span class="total-label">Base Exenta:</span>
                                <span class="total-value">USD ${formatCurrency(data.taxExemptBase)}</span>
                            </div>
                            <div class="total-row">
                                <span class="total-label">IVA:</span>
                                <span class="total-value">USD ${formatCurrency(data.taxAmount)}</span>
                            </div>
                            <div class="grand-total">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span class="total-label">TOTAL USD.:</span>
                                    <span class="total-value">USD ${formatCurrency(data.totalAmount)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="footer">
                    <p><strong>¡Gracias por su compra!</strong></p>
                    <p>Esta factura es válida como comprobante de pago</p>
                    <p>VetNexo - Comprometidos con la salud de tus mascotas</p>
                    <div class="control-num">
                        Nº CONTROL: 00-${data.invoiceNumber}
                    </div>
                </div>
            </div>
        
</body>
        </html>
        `;
    }

    // ============================================
    // BÚSQUEDA DE CLIENTES
    // ============================================
    async function searchClientModal() {
        try {
            // Crear el modal
            const modal = document.createElement('div');
            modal.id = 'clientSearchModal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 99999;
                backdrop-filter: blur(5px);
            `;
            
            // Contenido del modal
            modal.innerHTML = `
                <div style="background: white; border-radius: 24px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                    <div style="padding: 20px; border-bottom: 3px solid #0F6C33; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #e8f5e9, white);">
                        <h3 style="margin: 0; color: #0F6C33; font-size: 1.4rem; display: flex; align-items: center; gap: 10px;">
                            <i class='bx bx-search-alt' style="font-size: 1.6rem;"></i>
                            Buscar Cliente
                        </h3>
                        <button id="closeModalBtn" style="background: none; border: none; font-size: 32px; cursor: pointer; color: #666; line-height: 1; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; hover:background: #f0f0f0;">×</button>
                    </div>
                    <div style="padding: 20px;">
                        <input type="text" id="clientSearchField" placeholder="Buscar por nombre, RIF o teléfono..." 
                               style="width: 100%; padding: 12px 16px; border: 2px solid #E0E0E0; border-radius: 12px; font-size: 1rem; margin-bottom: 20px; box-sizing: border-box; transition: all 0.3s;">
                        <div id="clientResults" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Estilo para el input cuando está en focus
            const style = document.createElement('style');
            style.innerHTML = `
                #clientSearchField:focus {
                    outline: none;
                    border-color: #0F6C33 !important;
                    box-shadow: 0 0 0 3px rgba(15,108,51,0.1) !important;
                }
                .cliente-item:hover {
                    background: #e8f5e9 !important;
                    transform: translateX(5px);
                }
            `;
            document.head.appendChild(style);
            
            // Obtener referencias
            const searchField = document.getElementById('clientSearchField');
            const resultsDiv = document.getElementById('clientResults');
            const closeBtn = document.getElementById('closeModalBtn');
            
            // Enfocar el campo de búsqueda
            searchField.focus();
            
            // Función para cerrar el modal
            const closeModal = function() {
                if (modal && modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
                // Remover el estilo temporal
                if (style && style.parentNode) {
                    style.parentNode.removeChild(style);
                }
            };
            
            // Evento para cerrar con el botón X
            closeBtn.addEventListener('click', closeModal);
            
            // Evento para cerrar haciendo clic fuera del modal
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // Evento para cerrar con Escape
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            });
            
            // Variable para el timeout de búsqueda
            let searchTimeout;
            
            // Evento de búsqueda en tiempo real
            searchField.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const term = this.value.trim();
                
                if (term.length < 2) {
                    resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">Ingrese al menos 2 caracteres</p>';
                    return;
                }
                
                searchTimeout = setTimeout(async () => {
                    try {
                        resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">Buscando...</p>';
                        
                        const response = await fetch(`buscar_clientes.php?q=${encodeURIComponent(term)}`);
                        if (!response.ok) throw new Error('Error en la respuesta del servidor');
                        
                        const clientes = await response.json();
                        
                        if (!clientes || clientes.length === 0) {
                            resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No se encontraron clientes</p>';
                            return;
                        }
                        
                        let html = '';
                        clientes.forEach(cliente => {
                            html += `
                                <div class="cliente-item" style="padding: 16px; border: 1px solid #E0E0E0; border-radius: 12px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s; background: white;"
                                     onclick="selectClientFromSearch(${JSON.stringify(cliente).replace(/'/g, "\\'")})">
                                    <div style="font-weight: 600; color: #0F6C33; font-size: 1.1rem; margin-bottom: 8px;">${cliente.nombre}</div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.9rem;">
                                        <div style="color: #666;"><i class='bx bx-id-card' style="margin-right: 5px; color: #0F6C33;"></i> ${cliente.documento_identidad}</div>
                                        ${cliente.telefono ? `<div style="color: #666;"><i class='bx bx-phone' style="margin-right: 5px; color: #F99B07;"></i> ${cliente.telefono}</div>` : ''}
                                        ${cliente.direccion ? `<div style="color: #666; grid-column: span 2;"><i class='bx bx-map' style="margin-right: 5px; color: #2261BD;"></i> ${cliente.direccion}</div>` : ''}
                                    </div>
                                </div>
                            `;
                        });
                        
                        resultsDiv.innerHTML = html;
                        
                    } catch (error) {
                        console.error('Error:', error);
                        resultsDiv.innerHTML = '<p style="text-align: center; color: #f56565; padding: 20px;">Error al buscar clientes</p>';
                    }
                }, 300);
            });
            
        } catch (error) {
            console.error('Error al crear modal:', error);
            alert('Error al abrir el buscador de clientes');
        }
    }

    // Función para seleccionar cliente desde la búsqueda
    window.selectClientFromSearch = function(cliente) {
        document.getElementById('clientName').value = cliente.nombre || '';
        document.getElementById('clientId').value = cliente.documento_identidad || '';
        document.getElementById('clientPhone').value = cliente.telefono || '';
        document.getElementById('clientAddress').value = cliente.direccion || '';
        
        // Mostrar notificación
        showNotification(`Cliente "${cliente.nombre}" seleccionado`, 'success');
        
        // Cerrar modal
        const modal = document.getElementById('clientSearchModal');
        if (modal) {
            modal.remove();
        }
    };

    // Función de compatibilidad
    function selectClient(cliente) {
        window.selectClientFromSearch(cliente);
    }

    // Cerrar dropdowns al hacer click fuera
    document.addEventListener('click', function(e) {
        if (!e.target.classList.contains('search-input')) {
            document.querySelectorAll('.search-results').forEach(dropdown => {
                dropdown.style.display = 'none';
            });
        }
    });
</script>
<?php if($av_edit):?><script>
document.addEventListener('DOMContentLoaded',()=>{
 const f=<?= json_encode($av_edit,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
 const items=<?= json_encode($av_items,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
 const fields={clientName:f.cliente_nombre,clientId:f.documento_identidad,clientPhone:f.telefono,clientAddress:f.direccion,invoiceDate:f.fecha_emision.slice(0,10),observations:f.notas,paymentMethod:({cash:'CONTADO',card:'TARJETA_DEBITO',transfer:'TRANSFERENCIA'})[f.metodo_pago]};
 Object.entries(fields).forEach(([id,v])=>{const e=document.getElementById(id);if(e)e.value=v||''});
 items.forEach((it,i)=>{if(i)addServiceRow();const row=document.querySelectorAll('#servicesTableBody tr')[i];if(!row)return;const vals={item_id:it.producto_id||it.servicio_id,tipo_item:it.tipo_item,presentacion_id:it.presentacion_id,descripcion:it.descripcion,cantidad:it.cantidad,precio_unitario:it.precio_unitario,descuento:it.descuento,aplica_iva:it.aplica_iva,porcentaje_iva:it.porcentaje_iva};Object.entries(vals).forEach(([k,v])=>{const el=row.querySelector('[name$="['+k+']"]');if(el)el.value=v??''});const search=row.querySelector('.search-input');if(search)search.value=it.descripcion;calculateRowTotal(row.querySelector('[name$="[cantidad]"]'))});
});</script><?php endif;?>
</body>
</html>