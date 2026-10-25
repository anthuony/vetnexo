<?php
require_once __DIR__ . '/config/auth.php';
require_staff();

$page_title = "Métricas Clave - VetNexo";

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

    /* KPI Cards */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .kpi-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        transition: var(--transition);
    }

    .kpi-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .kpi-header {
        display: flex;
        justify-content: between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }

    .kpi-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
        font-size: 1rem;
    }

    .kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .kpi-value {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .kpi-change {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
        font-weight: 500;
    }

    .kpi-target {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-light);
        font-size: 0.85rem;
        color: var(--text-muted);
    }

    .progress-bar {
        height: 6px;
        background: var(--border-light);
        border-radius: 3px;
        margin-top: 0.5rem;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.5s ease;
    }

    /* Métricas por Categoría */
    .metrics-category {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        margin-bottom: 2rem;
        overflow: hidden;
    }

    .category-header {
        padding: 1.5rem;
        background: var(--primary-light);
        border-bottom: 1px solid var(--border-light);
    }

    .category-title {
        font-weight: 600;
        color: var(--primary-color);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 0;
    }

    .metric-item {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        border-right: 1px solid var(--border-light);
    }

    .metric-item:nth-child(odd) {
        background: var(--bg-light);
    }

    .metric-item:last-child {
        border-bottom: none;
    }

    .metric-name {
        font-weight: 500;
        color: var(--dark-color);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .metric-value {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .metric-comparison {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.8rem;
    }

    /* Gráficos de Métricas */
    .metrics-charts {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 1024px) {
        .metrics-charts {
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
        height: 300px;
        width: 100%;
    }

    /* Scorecards de Comparación */
    .comparison-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .scorecard {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
    }

    .scorecard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .scorecard-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .scorecard-value {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 1rem;
        text-align: center;
    }

    .scorecard-comparison {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 1rem;
        border-top: 1px solid var(--border-light);
    }

    .comparison-item {
        text-align: center;
    }

    .comparison-label {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-bottom: 0.3rem;
    }

    .comparison-value {
        font-weight: 600;
        font-size: 1.1rem;
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

        .kpi-grid {
            grid-template-columns: 1fr;
        }

        .metrics-grid {
            grid-template-columns: 1fr;
        }

        .metrics-charts {
            grid-template-columns: 1fr;
        }

        .comparison-cards {
            grid-template-columns: 1fr;
        }
    }

    /* Colores para estados */
    .positive {
        color: var(--success-color);
    }

    .negative {
        color: var(--error-color);
    }

    .neutral {
        color: var(--warning-color);
    }

    .change-positive {
        color: var(--success-color);
    }

    .change-negative {
        color: var(--error-color);
    }

    .change-neutral {
        color: var(--warning-color);
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
            <h1 class="page-title">Métricas Clave</h1>
            <p style="color: var(--text-muted); margin-top: 0.5rem;">Indicadores de desempeño y rentabilidad de la veterinaria</p>
        </div>
        <div class="page-actions">
            <button class="btn-secondary" onclick="exportMetrics()">
                <i class='bx bx-download'></i>
                Exportar Reporte
            </button>
            <button class="btn-primary" onclick="configureAlerts()">
                <i class='bx bx-bell'></i>
                Configurar Alertas
            </button>
        </div>
    </div>

    <!-- Filtros de Período -->
    <div class="period-filters">
        <div class="filters-content">
            <div class="filter-group">
                <label class="filter-label">Período</label>
                <select class="filter-select" id="periodFilter" onchange="updateMetrics()">
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
                <select class="filter-select" id="compareFilter" onchange="updateMetrics()">
                    <option value="none">No comparar</option>
                    <option value="previous-period">Período Anterior</option>
                    <option value="last-year">Mismo Período Año Anterior</option>
                    <option value="target">Objetivos</option>
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

    <!-- KPIs Principales -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-header">
                <h3 class="kpi-title">ROI (Retorno sobre Inversión)</h3>
                <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success-color);">
                    <i class='bx bx-trending-up'></i>
                </div>
            </div>
            <div class="kpi-value positive">28.5%</div>
            <div class="kpi-change change-positive">
                <i class='bx bx-up-arrow-alt'></i>
                +3.2% vs período anterior
            </div>
            <div class="kpi-target">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                    <span>Progreso vs objetivo (25%)</span>
                    <span>114%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="background: var(--success-color); width: 114%;"></div>
                </div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-header">
                <h3 class="kpi-title">Margen Neto</h3>
                <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success-color);">
                    <i class='bx bx-dollar-circle'></i>
                </div>
            </div>
            <div class="kpi-value positive">37.9%</div>
            <div class="kpi-change change-positive">
                <i class='bx bx-up-arrow-alt'></i>
                +2.1% vs período anterior
            </div>
            <div class="kpi-target">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                    <span>Progreso vs objetivo (35%)</span>
                    <span>108%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="background: var(--success-color); width: 108%;"></div>
                </div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-header">
                <h3 class="kpi-title">Ticket Promedio</h3>
                <div class="kpi-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info-color);">
                    <i class='bx bx-cart'></i>
                </div>
            </div>
            <div class="kpi-value">$141.42</div>
            <div class="kpi-change change-positive">
                <i class='bx bx-up-arrow-alt'></i>
                +3.5% vs período anterior
            </div>
            <div class="kpi-target">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                    <span>Progreso vs objetivo ($135)</span>
                    <span>105%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="background: var(--info-color); width: 105%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Métricas de Rentabilidad -->
    <div class="metrics-category">
        <div class="category-header">
            <h3 class="category-title">
                <i class='bx bx-line-chart'></i>
                Métricas de Rentabilidad
            </h3>
        </div>
        <div class="metrics-grid">
            <div class="metric-item">
                <div class="metric-name">Margen Bruto</div>
                <div class="metric-value positive">84.7%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +1.2% vs período anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Margen Operativo</div>
                <div class="metric-value positive">37.5%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +2.8% vs período anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">ROA (Return on Assets)</div>
                <div class="metric-value positive">22.3%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +4.1% vs período anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">ROE (Return on Equity)</div>
                <div class="metric-value positive">31.8%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +5.2% vs período anterior
                </div>
            </div>
        </div>
    </div>

    <!-- Métricas de Eficiencia -->
    <div class="metrics-category">
        <div class="category-header">
            <h3 class="category-title">
                <i class='bx bx-tachometer'></i>
                Métricas de Eficiencia
            </h3>
        </div>
        <div class="metrics-grid">
            <div class="metric-item">
                <div class="metric-name">Rotación de Inventario</div>
                <div class="metric-value">8.5x</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +0.7x vs período anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Días de Inventario</div>
                <div class="metric-value">42.9 días</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-down-arrow-alt'></i>
                    -3.2 días vs período anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Ciclo de Conversión de Efectivo</div>
                <div class="metric-value">18.3 días</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-down-arrow-alt'></i>
                    -2.1 días vs período anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Ingresos por Empleado</div>
                <div class="metric-value">$9,164.10</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +$452.30 vs período anterior
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos de Métricas -->
    <div class="metrics-charts">
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">Evolución de Márgenes</h3>
            </div>
            <div class="chart-body">
                <div class="chart-container">
                    <canvas id="marginsEvolutionChart"></canvas>
                </div>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">Comparativa de KPIs vs Objetivos</h3>
            </div>
            <div class="chart-body">
                <div class="chart-container">
                    <canvas id="kpiTargetsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Scorecards de Comparación -->
    <div class="comparison-cards">
        <div class="scorecard">
            <div class="scorecard-header">
                <h3 class="scorecard-title">Rentabilidad vs Competencia</h3>
                <span class="badge" style="background: var(--success-color); color: white; padding: 0.3rem 0.6rem; border-radius: 12px; font-size: 0.7rem;">+12.3%</span>
            </div>
            <div class="scorecard-value positive">37.9%</div>
            <div class="scorecard-comparison">
                <div class="comparison-item">
                    <div class="comparison-label">Tu Veterinaria</div>
                    <div class="comparison-value positive">37.9%</div>
                </div>
                <div class="comparison-item">
                    <div class="comparison-label">Promedio Local</div>
                    <div class="comparison-value">25.6%</div>
                </div>
                <div class="comparison-item">
                    <div class="comparison-label">Top 10% Nacional</div>
                    <div class="comparison-value">42.1%</div>
                </div>
            </div>
        </div>

        <div class="scorecard">
            <div class="scorecard-header">
                <h3 class="scorecard-title">Eficiencia Operativa</h3>
                <span class="badge" style="background: var(--success-color); color: white; padding: 0.3rem 0.6rem; border-radius: 12px; font-size: 0.7rem;">+8.7%</span>
            </div>
            <div class="scorecard-value">$9,164</div>
            <div class="scorecard-comparison">
                <div class="comparison-item">
                    <div class="comparison-label">Ingreso/Empleado</div>
                    <div class="comparison-value">$9,164</div>
                </div>
                <div class="comparison-item">
                    <div class="comparison-label">Promedio Sector</div>
                    <div class="comparison-value">$8,430</div>
                </div>
                <div class="comparison-item">
                    <div class="comparison-label">Meta Anual</div>
                    <div class="comparison-value">$9,500</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Métricas de Crecimiento -->
    <div class="metrics-category">
        <div class="category-header">
            <h3 class="category-title">
                <i class='bx bx-growth'></i>
                Métricas de Crecimiento
            </h3>
        </div>
        <div class="metrics-grid">
            <div class="metric-item">
                <div class="metric-name">Crecimiento de Ingresos (YoY)</div>
                <div class="metric-value positive">18.7%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +2.3% vs trimestre anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Crecimiento de Clientes</div>
                <div class="metric-value positive">12.4%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +1.8% vs trimestre anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Tasa de Retención</div>
                <div class="metric-value positive">84.2%</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +3.1% vs trimestre anterior
                </div>
            </div>
            <div class="metric-item">
                <div class="metric-name">Valor Vida del Cliente</div>
                <div class="metric-value">$1,842</div>
                <div class="metric-comparison change-positive">
                    <i class='bx bx-up-arrow-alt'></i>
                    +$127 vs trimestre anterior
                </div>
            </div>
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
    
    // Actualizar las métricas según el período seleccionado
    updateMetrics();
}

function updateMetrics() {
    // Aquí iría la lógica para actualizar las métricas
    console.log('Actualizando métricas...');
}

function exportMetrics() {
    // Lógica para exportar el reporte
    alert('Exportando reporte de métricas...');
}

function configureAlerts() {
    // Lógica para configurar alertas
    alert('Abriendo configuración de alertas...');
}

function initializeCharts() {
    // Gráfico de Evolución de Márgenes
    const marginsCtx = document.getElementById('marginsEvolutionChart').getContext('2d');
    const marginsChart = new Chart(marginsCtx, {
        type: 'line',
        data: {
            labels: ['Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic', 'Ene'],
            datasets: [
                {
                    label: 'Margen Bruto',
                    data: [82.1, 83.5, 83.2, 84.0, 83.8, 84.2, 84.7],
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Margen Operativo',
                    data: [34.2, 35.8, 36.1, 36.5, 36.8, 37.2, 37.5],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Margen Neto',
                    data: [33.5, 34.8, 35.2, 35.8, 36.5, 37.1, 37.9],
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    tension: 0.4,
                    fill: true
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
                            return `${context.dataset.label}: ${context.raw}%`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    min: 30,
                    max: 90,
                    grid: {
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return value + '%';
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

    // Gráfico de KPIs vs Objetivos
    const kpiCtx = document.getElementById('kpiTargetsChart').getContext('2d');
    const kpiChart = new Chart(kpiCtx, {
        type: 'bar',
        data: {
            labels: ['ROI', 'Margen Neto', 'Ticket Promedio', 'Rotación Inventario'],
            datasets: [
                {
                    label: 'Actual',
                    data: [28.5, 37.9, 141.42, 8.5],
                    backgroundColor: '#3b82f6',
                    borderWidth: 0
                },
                {
                    label: 'Objetivo',
                    data: [25, 35, 135, 9],
                    backgroundColor: '#94a3b8',
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
                            if (value < 50) {
                                return value + '%';
                            } else if (value < 100) {
                                return '$' + value;
                            } else {
                                return value + 'x';
                            }
                        }
                    }
                }
            }
        }
    });
}
</script>