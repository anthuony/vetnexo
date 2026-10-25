<?php
require_once __DIR__ . '/config/auth.php';
require_staff();

$page_title = "Flujo de Caja - VetNexo";

// Estilos específicos para esta página
$page_styles = '
<style>
    :root {
        --sidebar-width: 280px;
        --header-height: 70px;
        --primary-dark: #2c5aa0;
        --primary-light: #f0f7ff;
        --success-color: #10b981;
        --warning-color: #f59e0b;
        --error-color: #ef4444;
        --info-color: #3b82f6;
        --purple-color: #8b5cf6;
        --pink-color: #ec4899;
        --bg-light: #f8fafc;
        --border-light: #e2e8f0;
        --text-muted: #64748b;
        --white: #ffffff;
        --primary-color: #3b82f6;
        --dark-color: #1e293b;
        --transition: all 0.3s ease;
    }

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

    /* Filtros de Período */
    .period-filters {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .filters-content {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
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
        min-width: 150px;
    }

    .filter-input:focus {
        outline: none;
        border-color: var(--primary-color);
    }

    .quick-periods {
        display: flex;
        gap: 0.5rem;
        margin-left: auto;
    }

    .period-btn {
        padding: 0.5rem 1rem;
        border: 1px solid var(--border-light);
        background: var(--white);
        border-radius: 6px;
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.8rem;
        font-weight: 500;
    }

    .period-btn.active {
        background: var(--primary-color);
        color: var(--white);
        border-color: var(--primary-color);
    }

    .period-btn:hover:not(.active) {
        background: var(--primary-light);
    }

    /* Resumen Ejecutivo */
    .executive-summary {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
    }

    .summary-card {
        text-align: center;
        padding: 1.5rem;
        border-radius: 8px;
        background: var(--bg-light);
    }

    .summary-value {
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .summary-value.positive {
        color: var(--success-color);
    }

    .summary-value.negative {
        color: var(--error-color);
    }

    .summary-label {
        font-size: 0.9rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    .summary-change {
        font-size: 0.8rem;
        margin-top: 0.3rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
    }

    .change-positive {
        color: var(--success-color);
    }

    .change-negative {
        color: var(--error-color);
    }

    /* Flujo de Caja Principal */
    .cash-flow-statement {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        margin-bottom: 2rem;
        overflow: hidden;
    }

    .statement-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        background: var(--primary-light);
    }

    .statement-title {
        font-weight: 600;
        color: var(--primary-color);
        margin: 0;
        text-align: center;
    }

    .statement-period {
        text-align: center;
        color: var(--text-muted);
        margin: 0.5rem 0 0 0;
        font-size: 0.9rem;
    }

    .statement-body {
        padding: 0;
    }

    .statement-section {
        border-bottom: 1px solid var(--border-light);
    }

    .statement-section:last-child {
        border-bottom: none;
    }

    .section-header {
        padding: 1rem 1.5rem;
        background: var(--bg-light);
        border-bottom: 1px solid var(--border-light);
    }

    .section-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
        font-size: 1rem;
    }

    .statement-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border-light);
    }

    .statement-row:last-child {
        border-bottom: none;
    }

    .statement-row.subtotal {
        background: var(--bg-light);
        font-weight: 600;
    }

    .statement-row.total {
        background: var(--primary-light);
        font-weight: 700;
        font-size: 1.1rem;
    }

    .account-name {
        flex: 1;
        color: var(--dark-color);
    }

    .account-amount {
        font-weight: 600;
        min-width: 120px;
        text-align: right;
    }

    .account-percentage {
        min-width: 80px;
        text-align: right;
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    .amount-positive {
        color: var(--success-color);
    }

    .amount-negative {
        color: var(--error-color);
    }

    /* Análisis de Liquidez */
    .liquidity-analysis {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .analysis-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .analysis-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .liquidity-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }

    .liquidity-card {
        background: var(--bg-light);
        padding: 1.5rem;
        border-radius: 8px;
        text-align: center;
    }

    .liquidity-value {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .liquidity-label {
        font-size: 0.9rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }

    .liquidity-comparison {
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
    }

    /* Gráficos de Análisis */
    .analysis-charts {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 1024px) {
        .analysis-charts {
            grid-template-columns: 1fr;
        }
    }

    .chart-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        overflow: hidden;
    }

    .chart-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
    }

    .chart-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .chart-body {
        padding: 1.5rem;
    }

    .chart-container {
        position: relative;
        height: 250px;
        width: 100%;
    }

    /* Tendencias Históricas */
    .historical-trends {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .trends-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .trends-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .trend-chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .page-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .filters-content {
            flex-direction: column;
            align-items: flex-start;
        }

        .quick-periods {
            margin-left: 0;
            width: 100%;
            justify-content: space-between;
        }

        .period-btn {
            flex: 1;
            text-align: center;
        }

        .statement-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .account-amount, .account-percentage {
            text-align: left;
            min-width: auto;
        }
    }
</style>
';

include 'includes/header.php';
?>

<!-- Main Content -->
<main class="main-content" id="mainContent">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Flujo de Caja</h1>
            <p style="color: var(--text-muted); margin-top: 0.5rem;">Análisis detallado de entradas y salidas de efectivo de la veterinaria</p>
        </div>
        <div class="page-actions">
            <button class="btn-secondary" onclick="exportCashFlow()">
                <i class='bx bx-download'></i>
                Exportar PDF
            </button>
            <button class="btn-primary" onclick="printStatement()">
                <i class='bx bx-printer'></i>
                Imprimir
            </button>
        </div>
    </div>

    <!-- Filtros de Período -->
    <div class="period-filters">
        <div class="filters-content">
            <div class="filter-group">
                <label class="filter-label">Período</label>
                <select class="filter-select" id="periodFilter" onchange="updateStatement()">
                    <option value="month">Enero 2024</option>
                    <option value="last-month">Diciembre 2023</option>
                    <option value="quarter">Primer Trimestre 2024</option>
                    <option value="year">Año 2023</option>
                    <option value="custom">Personalizado</option>
                </select>
            </div>
            <div class="filter-group" id="customDateRange" style="display: none;">
                <label class="filter-label">Rango de Fechas</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="date" class="filter-input" id="startDate">
                    <input type="date" class="filter-input" id="endDate">
                </div>
            </div>
            <div class="filter-group">
                <label class="filter-label">Comparar con</label>
                <select class="filter-select" id="compareFilter" onchange="updateStatement()">
                    <option value="none">No comparar</option>
                    <option value="previous-period">Período Anterior</option>
                    <option value="last-year">Mismo Período Año Anterior</option>
                </select>
            </div>
            <div class="quick-periods">
                <button class="period-btn active" onclick="setPeriod('month')">Este Mes</button>
                <button class="period-btn" onclick="setPeriod('quarter')">Trimestre</button>
                <button class="period-btn" onclick="setPeriod('year')">Año</button>
                <button class="period-btn" onclick="setPeriod('ytd')">YTD</button>
            </div>
        </div>
    </div>

    <!-- Resumen Ejecutivo -->
    <div class="executive-summary">
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-value positive">$42,580.75</div>
                <div class="summary-label">Flujo Neto del Período</div>
                <div class="summary-change change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    18.3% vs período anterior
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-value positive">$45,820.50</div>
                <div class="summary-label">Entradas de Efectivo</div>
                <div class="summary-change change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    12.5% vs período anterior
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-value negative">$23,450.75</div>
                <div class="summary-label">Salidas de Efectivo</div>
                <div class="summary-change change-negative">
                    <i class='bx bx-down-arrow-alt'></i>
                    8.2% vs período anterior
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-value">$68,150.25</div>
                <div class="summary-label">Saldo Final de Caja</div>
                <div class="summary-change change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    6.7% vs período anterior
                </div>
            </div>
        </div>
    </div>

    <!-- Flujo de Caja Principal -->
    <div class="cash-flow-statement">
        <div class="statement-header">
            <h2 class="statement-title">ESTADO DE FLUJO DE EFECTIVO</h2>
            <p class="statement-period">Del 1 de Enero al 31 de Enero 2024</p>
        </div>
        <div class="statement-body">
            <!-- Actividades Operativas -->
            <div class="statement-section">
                <div class="section-header">
                    <h3 class="section-title">FLUJOS DE ACTIVIDADES OPERATIVAS</h3>
                </div>
                <div class="statement-row">
                    <div class="account-name">Cobros de clientes por servicios veterinarios</div>
                    <div class="account-amount amount-positive">$32,450.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Cobros por venta de productos y medicamentos</div>
                    <div class="account-amount amount-positive">$8,240.50</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pagos a proveedores de medicamentos</div>
                    <div class="account-amount amount-negative">$4,120.25</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pagos a proveedores de productos</div>
                    <div class="account-amount amount-negative">$2,890.50</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pago de nómina al personal</div>
                    <div class="account-amount amount-negative">$12,450.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pago de servicios públicos</div>
                    <div class="account-amount amount-negative">$850.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pago de alquiler</div>
                    <div class="account-amount amount-negative">$2,350.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pago de gastos administrativos</div>
                    <div class="account-amount amount-negative">$1,890.25</div>
                </div>
                <div class="statement-row subtotal">
                    <div class="account-name">FLUJO NETO DE ACTIVIDADES OPERATIVAS</div>
                    <div class="account-amount amount-positive">$16,079.50</div>
                </div>
            </div>

            <!-- Actividades de Inversión -->
            <div class="statement-section">
                <div class="section-header">
                    <h3 class="section-title">FLUJOS DE ACTIVIDADES DE INVERSIÓN</h3>
                </div>
                <div class="statement-row">
                    <div class="account-name">Compra de equipo médico nuevo</div>
                    <div class="account-amount amount-negative">$8,500.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Compra de mobiliario para consultorios</div>
                    <div class="account-amount amount-negative">$2,350.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Venta de equipo antiguo</div>
                    <div class="account-amount amount-positive">$1,200.00</div>
                </div>
                <div class="statement-row subtotal">
                    <div class="account-name">FLUJO NETO DE ACTIVIDADES DE INVERSIÓN</div>
                    <div class="account-amount amount-negative">$9,650.00</div>
                </div>
            </div>

            <!-- Actividades de Financiamiento -->
            <div class="statement-section">
                <div class="section-header">
                    <h3 class="section-title">FLUJOS DE ACTIVIDADES DE FINANCIAMIENTO</h3>
                </div>
                <div class="statement-row">
                    <div class="account-name">Préstamo bancario recibido</div>
                    <div class="account-amount amount-positive">$15,000.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Pago de intereses de préstamos</div>
                    <div class="account-amount amount-negative">$450.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Aportes de capital de socios</div>
                    <div class="account-amount amount-positive">$5,000.00</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Distribución de utilidades</div>
                    <div class="account-amount amount-negative">$3,500.00</div>
                </div>
                <div class="statement-row subtotal">
                    <div class="account-name">FLUJO NETO DE ACTIVIDADES DE FINANCIAMIENTO</div>
                    <div class="account-amount amount-positive">$16,050.00</div>
                </div>
            </div>

            <!-- Resumen Final -->
            <div class="statement-section">
                <div class="statement-row">
                    <div class="account-name">Aumento/Disminución neta del efectivo</div>
                    <div class="account-amount amount-positive">$22,479.50</div>
                </div>
                <div class="statement-row">
                    <div class="account-name">Efectivo al inicio del período</div>
                    <div class="account-amount">$45,670.75</div>
                </div>
                <div class="statement-row total" style="background: var(--success-color); color: white;">
                    <div class="account-name">EFECTIVO AL FINAL DEL PERÍODO</div>
                    <div class="account-amount">$68,150.25</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Análisis de Liquidez -->
    <div class="liquidity-analysis">
        <div class="analysis-header">
            <h3 class="analysis-title">Análisis de Liquidez</h3>
        </div>
        <div class="liquidity-cards">
            <div class="liquidity-card">
                <div class="liquidity-value" style="color: var(--success-color);">2.8</div>
                <div class="liquidity-label">Razón Corriente</div>
                <div class="liquidity-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +0.3 vs período anterior
                </div>
            </div>
            <div class="liquidity-card">
                <div class="liquidity-value" style="color: var(--success-color);">1.5</div>
                <div class="liquidity-label">Prueba Ácida</div>
                <div class="liquidity-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +0.2 vs período anterior
                </div>
            </div>
            <div class="liquidity-card">
                <div class="liquidity-value" style="color: var(--success-color);">35.2</div>
                <div class="liquidity-label">Días de Efectivo Disponible</div>
                <div class="liquidity-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +4.5 días vs período anterior
                </div>
            </div>
            <div class="liquidity-card">
                <div class="liquidity-value" style="color: var(--warning-color);">0.42</div>
                <div class="liquidity-label">Flujo Operativo / Ventas</div>
                <div class="liquidity-comparison change-negative">
                    <i class='bx bx-down-arrow-alt'></i>
                    -0.03 vs período anterior
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos de Análisis -->
    <div class="analysis-charts">
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">Composición del Flujo de Caja</h3>
            </div>
            <div class="chart-body">
                <div class="chart-container">
                    <canvas id="cashFlowCompositionChart"></canvas>
                </div>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">Entradas vs Salidas por Categoría</h3>
            </div>
            <div class="chart-body">
                <div class="chart-container">
                    <canvas id="cashInOutChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Tendencias Históricas -->
    <div class="historical-trends">
        <div class="trends-header">
            <h3 class="trends-title">Tendencias de Flujo de Caja</h3>
            <select class="filter-select" style="min-width: 120px;">
                <option>Últimos 6 meses</option>
                <option>Último año</option>
                <option>Últimos 2 años</option>
            </select>
        </div>
        <div class="trend-chart-container">
            <canvas id="cashFlowTrendChart"></canvas>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>

<script src="assets/vendor/chart.umd.min.js"></script>
<script>
// TODO EL JAVASCRIPT ESPECÍFICO DE ESTA PÁGINA VA AQUÍ

// Funcionalidad del sidebar
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    
    sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        mainContent.classList.toggle('expanded');
    });

    // Funcionalidad de submenús
    const menuToggles = document.querySelectorAll('.menu-link[data-toggle]');
    menuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const submenuId = this.getAttribute('data-toggle');
            const submenu = document.getElementById(submenuId);
            const menuToggle = this.querySelector('.menu-toggle');
            
            submenu.classList.toggle('show');
            menuToggle.classList.toggle('rotated');
        });
    });

    // Funcionalidad de filtros de período
    const periodFilter = document.getElementById('periodFilter');
    const customDateRange = document.getElementById('customDateRange');
    
    periodFilter.addEventListener('change', function() {
        if (this.value === 'custom') {
            customDateRange.style.display = 'flex';
        } else {
            customDateRange.style.display = 'none';
        }
    });

    // Inicializar gráficos
    initializeCharts();
});

function setPeriod(period) {
    const periodBtns = document.querySelectorAll('.period-btn');
    periodBtns.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    // Actualizar el flujo de caja según el período seleccionado
    updateStatement();
}

function updateStatement() {
    // Aquí iría la lógica para actualizar el flujo de caja
    console.log('Actualizando flujo de caja...');
}

function exportCashFlow() {
    // Lógica para exportar a PDF
    alert('Exportando flujo de caja a PDF...');
}

function printStatement() {
    // Lógica para imprimir
    window.print();
}

function initializeCharts() {
    // Gráfico de Composición del Flujo de Caja
    const compositionCtx = document.getElementById('cashFlowCompositionChart').getContext('2d');
    const compositionChart = new Chart(compositionCtx, {
        type: 'doughnut',
        data: {
            labels: ['Actividades Operativas', 'Actividades de Inversión', 'Actividades de Financiamiento'],
            datasets: [{
                data: [16079.5, -9650, 16050],
                backgroundColor: [
                    '#10b981',
                    '#ef4444',
                    '#3b82f6'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            return `${label}: $${value.toLocaleString()}`;
                        }
                    }
                }
            }
        }
    });

    // Gráfico de Entradas vs Salidas
    const inOutCtx = document.getElementById('cashInOutChart').getContext('2d');
    const inOutChart = new Chart(inOutCtx, {
        type: 'bar',
        data: {
            labels: ['Servicios', 'Productos', 'Inversiones', 'Financiamiento', 'Gastos Operativos', 'Gastos Capital'],
            datasets: [
                {
                    label: 'Entradas',
                    data: [32450, 8240.5, 1200, 20000, 0, 0],
                    backgroundColor: '#10b981',
                    borderWidth: 0
                },
                {
                    label: 'Salidas',
                    data: [0, 7010.75, 10850, 3950, 21640, 0],
                    backgroundColor: '#ef4444',
                    borderWidth: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // Gráfico de Tendencias de Flujo de Caja
    const trendCtx = document.getElementById('cashFlowTrendChart').getContext('2d');
    const trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: ['Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic', 'Ene'],
            datasets: [
                {
                    label: 'Flujo Operativo',
                    data: [12500, 13200, 14100, 14800, 15200, 15800, 16079.5],
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Flujo de Inversión',
                    data: [-5200, -4800, -6200, -7500, -8200, -9100, -9650],
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Flujo de Financiamiento',
                    data: [8000, 7500, 9200, 10500, 11200, 12800, 16050],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Flujo Neto',
                    data: [15300, 15900, 17100, 17800, 18200, 19500, 22479.5],
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return `${context.dataset.label}: $${context.raw.toLocaleString()}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grid: {
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
}
</script>