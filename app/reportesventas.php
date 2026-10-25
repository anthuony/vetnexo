<?php
$page_title = "Reportes Financieros - VetNexo";

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_staff();

}
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';
$db = getDBConnection();
date_default_timezone_set('America/Caracas');

function fechaValidaReporte(string $fecha): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

$hoy = new DateTimeImmutable('today');
$periodo = $_GET['period'] ?? 'month';
$comparar = $_GET['compare'] ?? 'previous-period';
$permitidos = ['month', 'last-month', 'quarter', 'year', 'custom'];
if (!in_array($periodo, $permitidos, true)) {
    $periodo = 'month';
}

switch ($periodo) {
    case 'last-month':
        $inicio = $hoy->modify('first day of last month');
        $fin = $hoy->modify('last day of last month');
        break;
    case 'quarter':
        $mesInicio = ((int) floor(((int)$hoy->format('n') - 1) / 3) * 3) + 1;
        $inicio = new DateTimeImmutable($hoy->format('Y') . '-' . str_pad((string)$mesInicio, 2, '0', STR_PAD_LEFT) . '-01');
        $fin = $hoy;
        break;
    case 'year':
        $inicio = new DateTimeImmutable($hoy->format('Y') . '-01-01');
        $fin = $hoy;
        break;
    case 'custom':
        $inicioTexto = $_GET['start'] ?? '';
        $finTexto = $_GET['end'] ?? '';
        if (fechaValidaReporte($inicioTexto) && fechaValidaReporte($finTexto) && $inicioTexto <= $finTexto) {
            $inicio = new DateTimeImmutable($inicioTexto);
            $fin = new DateTimeImmutable($finTexto);
        } else {
            $inicio = $hoy->modify('first day of this month');
            $fin = $hoy;
            $periodo = 'month';
        }
        break;
    case 'month':
    default:
        $inicio = $hoy->modify('first day of this month');
        $fin = $hoy;
        break;
}

$start = $inicio->format('Y-m-d');
$end = $fin->format('Y-m-d');
$startTs = $start . ' 00:00:00';
$endTs = $end . ' 23:59:59';

// Resumen real de facturación del período.
$stmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN estado = 'paid' THEN total ELSE 0 END), 0) AS ingresos,
        SUM(CASE WHEN estado = 'paid' THEN 1 ELSE 0 END) AS pagadas,
        SUM(CASE WHEN estado IN ('pending','partial') THEN 1 ELSE 0 END) AS pendientes,
        COALESCE(SUM(CASE WHEN estado IN ('pending','partial') THEN GREATEST(total - COALESCE(pagado,0), 0) ELSE 0 END), 0) AS saldo_pendiente,
        COALESCE(AVG(CASE WHEN estado = 'paid' THEN total END), 0) AS ticket_promedio,
        COUNT(DISTINCT CASE WHEN estado = 'paid' THEN cliente_id END) AS clientes_activos
    FROM facturas
    WHERE fecha_emision BETWEEN ? AND ?");
$stmt->execute([$startTs, $endTs]);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN fd.tipo_item='servicio' THEN fd.subtotal + COALESCE(fd.iva_item,0) ELSE 0 END),0) AS servicios,
        COALESCE(SUM(CASE WHEN fd.tipo_item='producto' THEN fd.subtotal + COALESCE(fd.iva_item,0) ELSE 0 END),0) AS productos
    FROM factura_detalles fd
    JOIN facturas f ON f.id = fd.factura_id
    WHERE f.estado='paid' AND f.fecha_emision BETWEEN ? AND ?");
$stmt->execute([$startTs, $endTs]);
$porTipo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['servicios' => 0, 'productos' => 0];

// Comparación real con período anterior o mismo rango del año anterior.
$ingresoAnterior = null;
$variacion = null;
if ($comparar !== 'none') {
    if ($comparar === 'last-year') {
        $inicioAnterior = $inicio->modify('-1 year');
        $finAnterior = $fin->modify('-1 year');
    } else {
        $dias = (int)$inicio->diff($fin)->days + 1;
        $finAnterior = $inicio->modify('-1 day');
        $inicioAnterior = $finAnterior->modify('-' . ($dias - 1) . ' days');
    }
    $q = $db->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='paid' AND fecha_emision BETWEEN ? AND ?");
    $q->execute([$inicioAnterior->format('Y-m-d') . ' 00:00:00', $finAnterior->format('Y-m-d') . ' 23:59:59']);
    $ingresoAnterior = (float)$q->fetchColumn();
    $actual = (float)($resumen['ingresos'] ?? 0);
    if ($ingresoAnterior > 0) {
        $variacion = (($actual - $ingresoAnterior) / $ingresoAnterior) * 100;
    } elseif ($actual > 0) {
        $variacion = 100.0;
    } else {
        $variacion = 0.0;
    }
}

// Datos mensuales reales para el año que contiene el fin del período.
$anioGrafico = (int)$fin->format('Y');
$q = $db->prepare("SELECT MONTH(fecha_emision) AS mes, COALESCE(SUM(total),0) AS total
                   FROM facturas
                   WHERE estado='paid' AND YEAR(fecha_emision)=?
                   GROUP BY MONTH(fecha_emision)");
$q->execute([$anioGrafico]);
$ingresosMes = array_fill(1, 12, 0.0);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ingresosMes[(int)$r['mes']] = (float)$r['total'];
}
$crecimientoMes = array_fill(1, 12, 0.0);
for ($m = 2; $m <= 12; $m++) {
    $prev = $ingresosMes[$m - 1];
    $curr = $ingresosMes[$m];
    $crecimientoMes[$m] = $prev > 0 ? (($curr - $prev) / $prev) * 100 : ($curr > 0 ? 100.0 : 0.0);
}

// Servicios reales del período.
$q = $db->prepare("SELECT COALESCE(s.nombre, fd.descripcion, 'Servicio') AS servicio,
                          COUNT(*) AS operaciones,
                          COALESCE(SUM(fd.subtotal + COALESCE(fd.iva_item,0)),0) AS ingresos
                   FROM factura_detalles fd
                   JOIN facturas f ON f.id=fd.factura_id
                   LEFT JOIN servicios s ON s.id=fd.servicio_id
                   WHERE fd.tipo_item='servicio' AND f.estado='paid' AND f.fecha_emision BETWEEN ? AND ?
                   GROUP BY COALESCE(s.nombre, fd.descripcion, 'Servicio')
                   ORDER BY ingresos DESC");
$q->execute([$startTs, $endTs]);
$servicios = $q->fetchAll(PDO::FETCH_ASSOC);

// Clientes reales del período.
$q = $db->prepare("SELECT c.id, c.nombre, c.documento_identidad,
                          COUNT(f.id) AS facturas,
                          COALESCE(SUM(f.total),0) AS ingresos
                   FROM facturas f
                   JOIN clientes c ON c.id=f.cliente_id
                   WHERE f.estado='paid' AND f.fecha_emision BETWEEN ? AND ?
                   GROUP BY c.id, c.nombre, c.documento_identidad
                   ORDER BY ingresos DESC
                   LIMIT 50");
$q->execute([$startTs, $endTs]);
$clientes = $q->fetchAll(PDO::FETCH_ASSOC);

// Veterinarios asociados a citas facturadas.
$q = $db->prepare("SELECT u.id, u.nombre,
                          COUNT(f.id) AS facturas,
                          COUNT(DISTINCT f.cliente_id) AS clientes,
                          COALESCE(SUM(f.total),0) AS ingresos
                   FROM facturas f
                   JOIN citas c ON c.id=f.cita_id
                   JOIN usuarios u ON u.id=c.veterinario_id
                   WHERE f.estado='paid' AND f.fecha_emision BETWEEN ? AND ?
                   GROUP BY u.id, u.nombre
                   ORDER BY ingresos DESC
                   LIMIT 20");
$q->execute([$startTs, $endTs]);
$veterinarios = $q->fetchAll(PDO::FETCH_ASSOC);
$maxVetIngreso = 0.0;
foreach ($veterinarios as $v) {
    $maxVetIngreso = max($maxVetIngreso, (float)$v['ingresos']);
}

// Facturas para el detalle de ingresos.
$q = $db->prepare("SELECT f.numero_factura, f.fecha_emision, f.total, f.metodo_pago, c.nombre AS cliente
                   FROM facturas f
                   JOIN clientes c ON c.id=f.cliente_id
                   WHERE f.estado='paid' AND f.fecha_emision BETWEEN ? AND ?
                   ORDER BY f.fecha_emision DESC
                   LIMIT 100");
$q->execute([$startTs, $endTs]);
$facturasDetalle = $q->fetchAll(PDO::FETCH_ASSOC);

$meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$labelsServicios = array_map(fn($r) => $r['servicio'], array_slice($servicios, 0, 8));
$datosServicios = array_map(fn($r) => (float)$r['ingresos'], array_slice($servicios, 0, 8));
?>
$page_styles = '
<style>
    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-title {
        color: var(--dark-color);
        font-weight: 700;
        font-size: 1.8rem;
        margin: 0;
    }

    .page-actions {
        display: flex;
        gap: 1rem;
        align-items: center;
    }

    /* Stats Cards */
    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--white);
        padding: 1.5rem;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        text-align: center;
        transition: var(--transition);
        cursor: pointer;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .stat-card.primary {
        border-top: 4px solid var(--primary-color);
    }

    .stat-card.success {
        border-top: 4px solid var(--success-color);
    }

    .stat-card.warning {
        border-top: 4px solid var(--warning-color);
    }

    .stat-card.purple {
        border-top: 4px solid var(--purple-color);
    }

    .stat-card.danger {
        border-top: 4px solid var(--error-color);
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .stat-card.primary .stat-number {
        color: var(--primary-color);
    }

    .stat-card.success .stat-number {
        color: var(--success-color);
    }

    .stat-card.warning .stat-number {
        color: var(--warning-color);
    }

    .stat-card.purple .stat-number {
        color: var(--purple-color);
    }

    .stat-card.danger .stat-number {
        color: var(--error-color);
    }

    .stat-label {
        color: var(--text-muted);
        font-size: 0.9rem;
        font-weight: 500;
    }

    .stat-trend {
        font-size: 0.8rem;
        margin-top: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
    }

    .trend-up {
        color: var(--success-color);
    }

    .trend-down {
        color: var(--error-color);
    }

    /* Filtros Section */
    .filters-section {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .filters-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .filters-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .filters-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .filter-label {
        font-weight: 600;
        color: var(--dark-color);
        font-size: 0.9rem;
    }

    .filter-select, .filter-input {
        padding: 0.6rem;
        border: 1px solid var(--border-light);
        border-radius: 6px;
        background: var(--white);
        font-size: 0.9rem;
    }

    .filter-input:focus {
        outline: none;
        border-color: var(--primary-color);
    }

    /* Gráficos y Reportes */
    .reports-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 1024px) {
        .reports-container {
            grid-template-columns: 1fr;
        }
    }

    .report-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        overflow: hidden;
    }

    .report-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .report-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .report-actions {
        display: flex;
        gap: 0.5rem;
    }

    .report-body {
        padding: 1.5rem;
    }

    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }

    /* Tablas de Reportes */
    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }

    .report-table th {
        background: var(--primary-light);
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        color: var(--primary-color);
        border-bottom: 1px solid var(--border-light);
    }

    .report-table td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-light);
        vertical-align: middle;
    }

    .report-table tr:hover {
        background: var(--bg-light);
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: var(--border-light);
        border-radius: 4px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 4px;
    }

    .progress-primary {
        background: var(--primary-color);
    }

    .progress-success {
        background: var(--success-color);
    }

    .progress-warning {
        background: var(--warning-color);
    }

    .progress-danger {
        background: var(--error-color);
    }

    /* Resumen Financiero */
    .financial-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .summary-card {
        background: var(--white);
        padding: 1.5rem;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
    }

    .summary-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .summary-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
        font-size: 1rem;
    }

    .summary-value {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .summary-change {
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .change-positive {
        color: var(--success-color);
    }

    .change-negative {
        color: var(--error-color);
    }

    /* Tabs */
    .tabs-navigation {
        display: flex;
        border-bottom: 1px solid var(--border-light);
        background: var(--bg-light);
        margin-bottom: 2rem;
        border-radius: 8px 8px 0 0;
        overflow: hidden;
    }

    .tab-btn {
        padding: 1rem 1.5rem;
        background: none;
        border: none;
        font-weight: 500;
        color: var(--text-muted);
        cursor: pointer;
        transition: var(--transition);
        border-bottom: 3px solid transparent;
    }

    .tab-btn.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
        background: var(--white);
    }

    .tab-btn:hover:not(.active) {
        color: var(--dark-color);
        background: var(--primary-light);
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Botones */
    .btn-primary, .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.8rem 1.5rem;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        border: none;
        cursor: pointer;
        font-size: 0.9rem;
    }

    .btn-primary {
        background: var(--primary-color);
        color: var(--white);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: var(--primary-light);
        color: var(--primary-color);
        border: 1px solid var(--primary-color);
    }

    .btn-secondary:hover {
        background: var(--primary-color);
        color: var(--white);
        transform: translateY(-2px);
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .stats-cards {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .filters-content {
            grid-template-columns: repeat(2, 1fr);
        }

        .financial-summary {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .page-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .stats-cards {
            grid-template-columns: 1fr;
        }

        .filters-content {
            grid-template-columns: 1fr;
        }

        .tabs-navigation {
            flex-wrap: wrap;
        }

        .tab-btn {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
    }
</style>
';

include 'includes/header.php';
?>

<main class="main-content" id="mainContent">
    <div class="page-header">
        <div>
            <h1 class="page-title">Reportes Financieros</h1>
            <p style="color: var(--text-muted); margin-top: .5rem;">Datos reales del <?=htmlspecialchars($start)?> al <?=htmlspecialchars($end)?></p>
        </div>
        <div class="page-actions">
            <a class="btn-secondary" href="export_financiero.php?start_date=<?=urlencode($start)?>&end_date=<?=urlencode($end)?>">
                <i class='bx bx-download'></i> Exportar CSV
            </a>
            <a class="btn-primary" target="_blank" rel="noopener" href="reporte_financiero.php?start_date=<?=urlencode($start)?>&end_date=<?=urlencode($end)?>">
                <i class='bx bx-file'></i> Reporte / PDF
            </a>
        </div>
    </div>

    <div class="filters-section">
        <div class="filters-header"><h3 class="filters-title">Parámetros del Reporte</h3></div>
        <div class="filters-content">
            <div class="filter-group">
                <label class="filter-label" for="periodFilter">Período</label>
                <select class="filter-select" id="periodFilter">
                    <option value="month" <?=$periodo==='month'?'selected':''?>>Este Mes</option>
                    <option value="last-month" <?=$periodo==='last-month'?'selected':''?>>Mes Anterior</option>
                    <option value="quarter" <?=$periodo==='quarter'?'selected':''?>>Este Trimestre</option>
                    <option value="year" <?=$periodo==='year'?'selected':''?>>Este Año</option>
                    <option value="custom" <?=$periodo==='custom'?'selected':''?>>Personalizado</option>
                </select>
            </div>
            <div class="filter-group" id="customDateRange" style="<?=$periodo==='custom'?'display:flex':'display:none'?>; flex-direction:column;">
                <label class="filter-label">Rango de Fechas</label>
                <div style="display:flex;gap:.5rem;">
                    <input type="date" class="filter-input" id="startDate" value="<?=htmlspecialchars($start)?>">
                    <input type="date" class="filter-input" id="endDate" value="<?=htmlspecialchars($end)?>">
                </div>
            </div>
            <div class="filter-group">
                <label class="filter-label" for="compareFilter">Comparar con</label>
                <select class="filter-select" id="compareFilter">
                    <option value="none" <?=$comparar==='none'?'selected':''?>>No comparar</option>
                    <option value="previous-period" <?=$comparar==='previous-period'?'selected':''?>>Período Anterior</option>
                    <option value="last-year" <?=$comparar==='last-year'?'selected':''?>>Mismo Período Año Anterior</option>
                </select>
            </div>
            <div class="filter-group" style="justify-content:end;">
                <button type="button" class="btn-primary" id="applyFilters"><i class='bx bx-filter'></i> Aplicar</button>
            </div>
        </div>
    </div>

    <div class="tabs-navigation">
        <button class="tab-btn active" data-tab="overview">Resumen General</button>
        <button class="tab-btn" data-tab="income">Ingresos</button>
        <button class="tab-btn" data-tab="services">Servicios</button>
        <button class="tab-btn" data-tab="clients">Clientes</button>
        <button class="tab-btn" data-tab="veterinarians">Veterinarios</button>
    </div>

    <div class="tab-content active" id="overviewTab">
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-number">$<?=number_format((float)($resumen['ingresos']??0),2)?></div>
                <div class="stat-label">Ingresos Cobrados</div>
                <?php if($variacion !== null): ?>
                <div class="stat-trend <?=$variacion>=0?'trend-up':'trend-down'?>"><i class='bx <?=$variacion>=0?'bx-up-arrow-alt':'bx-down-arrow-alt'?>'></i><?=number_format(abs($variacion),1)?>% vs comparación</div>
                <?php endif; ?>
            </div>
            <div class="stat-card success"><div class="stat-number"><?=number_format((int)($resumen['pagadas']??0))?></div><div class="stat-label">Facturas Pagadas</div></div>
            <div class="stat-card warning"><div class="stat-number"><?=number_format((int)($resumen['pendientes']??0))?></div><div class="stat-label">Facturas Pendientes/Parciales</div></div>
            <div class="stat-card purple"><div class="stat-number">$<?=number_format((float)($resumen['saldo_pendiente']??0),2)?></div><div class="stat-label">Saldo Pendiente</div></div>
        </div>

        <div class="financial-summary">
            <div class="summary-card"><div class="summary-header"><h3 class="summary-title">Servicios cobrados</h3></div><div class="summary-value">$<?=number_format((float)$porTipo['servicios'],2)?></div></div>
            <div class="summary-card"><div class="summary-header"><h3 class="summary-title">Productos cobrados</h3></div><div class="summary-value">$<?=number_format((float)$porTipo['productos'],2)?></div></div>
            <div class="summary-card"><div class="summary-header"><h3 class="summary-title">Ticket Promedio</h3></div><div class="summary-value">$<?=number_format((float)($resumen['ticket_promedio']??0),2)?></div></div>
            <div class="summary-card"><div class="summary-header"><h3 class="summary-title">Clientes Activos</h3></div><div class="summary-value"><?=number_format((int)($resumen['clientes_activos']??0))?></div></div>
        </div>

        <div class="reports-container">
            <div class="report-card"><div class="report-header"><h3 class="report-title">Ingresos Mensuales <?=$anioGrafico?></h3><div class="report-actions"><button class="btn-secondary btn-sm" onclick="downloadChart('incomeChart')"><i class='bx bx-download'></i></button></div></div><div class="report-body"><div class="chart-container"><canvas id="incomeChart"></canvas></div></div></div>
            <div class="report-card"><div class="report-header"><h3 class="report-title">Ingresos por Servicio</h3><div class="report-actions"><button class="btn-secondary btn-sm" onclick="downloadChart('servicesChart')"><i class='bx bx-download'></i></button></div></div><div class="report-body"><div class="chart-container"><canvas id="servicesChart"></canvas></div></div></div>
            <div class="report-card"><div class="report-header"><h3 class="report-title">Crecimiento Mensual</h3><div class="report-actions"><button class="btn-secondary btn-sm" onclick="downloadChart('growthChart')"><i class='bx bx-download'></i></button></div></div><div class="report-body"><div class="chart-container"><canvas id="growthChart"></canvas></div></div></div>
            <div class="report-card"><div class="report-header"><h3 class="report-title">Top Veterinarios por Ingresos</h3></div><div class="report-body"><table class="report-table"><thead><tr><th>Veterinario</th><th>Ingresos</th><th>Clientes</th><th>Participación</th></tr></thead><tbody>
                <?php if(!$veterinarios): ?><tr><td colspan="4">Sin facturas vinculadas a veterinarios en este período.</td></tr><?php endif; ?>
                <?php foreach(array_slice($veterinarios,0,6) as $v): $pct=$maxVetIngreso>0?((float)$v['ingresos']/$maxVetIngreso*100):0; ?><tr><td><?=htmlspecialchars($v['nombre'])?></td><td>$<?=number_format((float)$v['ingresos'],2)?></td><td><?=number_format((int)$v['clientes'])?></td><td><div class="progress-bar"><div class="progress-fill progress-primary" style="width:<?=max(0,min(100,$pct))?>%"></div></div></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        </div>
    </div>

    <div class="tab-content" id="incomeTab" style="display:none;">
        <div class="report-card"><div class="report-header"><h3 class="report-title">Facturas cobradas</h3></div><div class="report-body" style="overflow:auto;"><table class="report-table"><thead><tr><th>Factura</th><th>Fecha</th><th>Cliente</th><th>Método</th><th>Total</th></tr></thead><tbody>
        <?php if(!$facturasDetalle): ?><tr><td colspan="5">No hay facturas pagadas en el período.</td></tr><?php endif; ?>
        <?php foreach($facturasDetalle as $f): ?><tr><td><?=htmlspecialchars($f['numero_factura'])?></td><td><?=htmlspecialchars(date('d/m/Y',strtotime($f['fecha_emision'])))?></td><td><?=htmlspecialchars($f['cliente'])?></td><td><?=htmlspecialchars($f['metodo_pago']?:'Sin definir')?></td><td>$<?=number_format((float)$f['total'],2)?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </div>

    <div class="tab-content" id="servicesTab" style="display:none;">
        <div class="report-card"><div class="report-header"><h3 class="report-title">Servicios facturados</h3></div><div class="report-body" style="overflow:auto;"><table class="report-table"><thead><tr><th>Servicio</th><th>Operaciones</th><th>Ingresos</th></tr></thead><tbody>
        <?php if(!$servicios): ?><tr><td colspan="3">No hay servicios cobrados en el período.</td></tr><?php endif; ?>
        <?php foreach($servicios as $s): ?><tr><td><?=htmlspecialchars($s['servicio'])?></td><td><?=number_format((int)$s['operaciones'])?></td><td>$<?=number_format((float)$s['ingresos'],2)?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </div>

    <div class="tab-content" id="clientsTab" style="display:none;">
        <div class="report-card"><div class="report-header"><h3 class="report-title">Clientes por facturación</h3></div><div class="report-body" style="overflow:auto;"><table class="report-table"><thead><tr><th>Cliente</th><th>Documento</th><th>Facturas</th><th>Ingresos</th></tr></thead><tbody>
        <?php if(!$clientes): ?><tr><td colspan="4">No hay clientes con facturas pagadas en el período.</td></tr><?php endif; ?>
        <?php foreach($clientes as $c): ?><tr><td><?=htmlspecialchars($c['nombre'])?></td><td><?=htmlspecialchars($c['documento_identidad']??'')?></td><td><?=number_format((int)$c['facturas'])?></td><td>$<?=number_format((float)$c['ingresos'],2)?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </div>

    <div class="tab-content" id="veterinariansTab" style="display:none;">
        <div class="report-card"><div class="report-header"><h3 class="report-title">Veterinarios por facturación vinculada</h3></div><div class="report-body" style="overflow:auto;"><table class="report-table"><thead><tr><th>Veterinario</th><th>Facturas</th><th>Clientes</th><th>Ingresos</th></tr></thead><tbody>
        <?php if(!$veterinarios): ?><tr><td colspan="4">No hay facturas vinculadas a veterinarios en el período.</td></tr><?php endif; ?>
        <?php foreach($veterinarios as $v): ?><tr><td><?=htmlspecialchars($v['nombre'])?></td><td><?=number_format((int)$v['facturas'])?></td><td><?=number_format((int)$v['clientes'])?></td><td>$<?=number_format((float)$v['ingresos'],2)?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
<script src="assets/vendor/chart.umd.min.js"></script>
<script>
const monthLabels = <?=json_encode($meses, JSON_UNESCAPED_UNICODE)?>;
const monthlyIncome = <?=json_encode(array_values($ingresosMes), JSON_NUMERIC_CHECK)?>;
const monthlyGrowth = <?=json_encode(array_values($crecimientoMes), JSON_NUMERIC_CHECK)?>;
const serviceLabels = <?=json_encode($labelsServicios, JSON_UNESCAPED_UNICODE)?>;
const serviceData = <?=json_encode($datosServicios, JSON_NUMERIC_CHECK)?>;
let incomeChart, servicesChart, growthChart;

document.addEventListener('DOMContentLoaded', () => {
    const common = {responsive:true, maintainAspectRatio:false};
    incomeChart = new Chart(document.getElementById('incomeChart'), {type:'bar', data:{labels:monthLabels,datasets:[{label:'Ingresos <?=$anioGrafico?>',data:monthlyIncome}]}, options:{...common,scales:{y:{beginAtZero:true,ticks:{callback:v=>'$'+Number(v).toLocaleString()}}}}});
    servicesChart = new Chart(document.getElementById('servicesChart'), {type:'doughnut',data:{labels:serviceLabels.length?serviceLabels:['Sin datos'],datasets:[{data:serviceData.length?serviceData:[1]}]},options:{...common,plugins:{legend:{position:'bottom'}}}});
    growthChart = new Chart(document.getElementById('growthChart'), {type:'line',data:{labels:monthLabels,datasets:[{label:'Variación mensual (%)',data:monthlyGrowth,tension:.3}]},options:{...common,scales:{y:{ticks:{callback:v=>Number(v).toFixed(1)+'%'}}}}});

    document.querySelectorAll('.tab-btn').forEach(btn => btn.addEventListener('click', () => switchTab(btn)));
    document.getElementById('periodFilter').addEventListener('change', e => {
        document.getElementById('customDateRange').style.display = e.target.value === 'custom' ? 'flex' : 'none';
    });
    document.getElementById('applyFilters').addEventListener('click', applyFilters);
});

function switchTab(btn) {
    document.querySelectorAll('.tab-btn').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(x=>{x.classList.remove('active');x.style.display='none';});
    btn.classList.add('active');
    const el=document.getElementById(btn.dataset.tab+'Tab');
    if(el){el.classList.add('active');el.style.display='block';}
}

function applyFilters() {
    const period=document.getElementById('periodFilter').value;
    const compare=document.getElementById('compareFilter').value;
    const params=new URLSearchParams({period,compare});
    if(period==='custom'){
        const start=document.getElementById('startDate').value;
        const end=document.getElementById('endDate').value;
        if(!start || !end || start>end){alert('Seleccione un rango de fechas válido.');return;}
        params.set('start',start); params.set('end',end);
    }
    window.location.href='reportesventas.php?'+params.toString();
}

function downloadChart(chartId) {
    const charts={incomeChart,servicesChart,growthChart};
    const chart=charts[chartId];
    if(!chart) return;
    const a=document.createElement('a');
    a.download=chartId+'.png';
    a.href=chart.toBase64Image();
    a.click();
}
</script>
