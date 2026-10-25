<?php
require_once __DIR__ . '/config/auth.php';
require_staff();

$page_title = "Servicios - VetNexo";

// Estilos específicos para esta página
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

    /* Botones */
    .btn-primary, .btn-secondary, .btn-success, .btn-warning, .btn-danger {
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

    .btn-success {
        background: var(--success-color);
        color: var(--white);
    }

    .btn-warning {
        background: var(--warning-color);
        color: var(--white);
    }

    .btn-danger {
        background: var(--error-color);
        color: var(--white);
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
    }

    /* Filtros y Búsqueda */
    .filters-section {
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

    .search-box {
        position: relative;
        margin-left: auto;
    }

    .search-input {
        padding: 0.6rem 2.5rem 0.6rem 1rem;
        border: 1px solid var(--border-light);
        border-radius: 6px;
        background: var(--white);
        font-size: 0.9rem;
        min-width: 250px;
    }

    .search-icon {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
    }

    /* Estadísticas */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        padding: 1.5rem;
        text-align: center;
        transition: var(--transition);
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 0.9rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    /* Grid de Servicios */
    .services-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .service-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid var(--border-light);
        overflow: hidden;
        transition: var(--transition);
    }

    .service-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .service-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        background: var(--primary-light);
    }

    .service-info {
        flex: 1;
    }

    .service-title {
        font-weight: 600;
        color: var(--primary-color);
        margin: 0 0 0.5rem 0;
        font-size: 1.1rem;
    }

    .service-category {
        display: inline-block;
        padding: 0.3rem 0.8rem;
        background: var(--white);
        color: var(--primary-color);
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .service-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        background: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: var(--primary-color);
    }

    .service-body {
        padding: 1.5rem;
    }

    .service-description {
        color: var(--text-muted);
        margin-bottom: 1rem;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .service-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }

    .detail-label {
        font-size: 0.8rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    .detail-value {
        font-weight: 600;
        color: var(--dark-color);
        font-size: 0.9rem;
    }

    .service-price {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--success-color);
        text-align: center;
        margin-bottom: 1rem;
    }

    .service-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border-light);
        background: var(--bg-light);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .service-status {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .status-badge {
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .status-active {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success-color);
    }

    .status-inactive {
        background: rgba(239, 68, 68, 0.1);
        color: var(--error-color);
    }

    .service-actions {
        display: flex;
        gap: 0.5rem;
    }

    .action-btn {
        padding: 0.4rem 0.8rem;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .edit-btn {
        background: rgba(59, 130, 246, 0.1);
        color: var(--info-color);
    }

    .edit-btn:hover {
        background: var(--info-color);
        color: var(--white);
    }

    .delete-btn {
        background: rgba(239, 68, 68, 0.1);
        color: var(--error-color);
    }

    .delete-btn:hover {
        background: var(--error-color);
        color: var(--white);
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1001;
        align-items: center;
        justify-content: center;
    }

    .modal.show {
        display: flex;
    }

    .modal-content {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-title {
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--text-muted);
        padding: 0.2rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        padding: 1.5rem;
        border-top: 1px solid var(--border-light);
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
    }

    /* Formularios */
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--dark-color);
        font-size: 0.9rem;
    }

    .form-control {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border-light);
        border-radius: 6px;
        font-size: 0.9rem;
        transition: var(--transition);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .form-select {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border-light);
        border-radius: 6px;
        font-size: 0.9rem;
        background: var(--white);
        cursor: pointer;
    }

    .form-check {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-check-input {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }

    .form-check-label {
        font-size: 0.9rem;
        color: var(--dark-color);
        cursor: pointer;
    }

    /* Categorías de Servicios */
    .categories-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
    }

    .category-tab {
        padding: 0.8rem 1.5rem;
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: 8px;
        cursor: pointer;
        transition: var(--transition);
        font-weight: 500;
        color: var(--text-muted);
    }

    .category-tab.active {
        background: var(--primary-color);
        color: var(--white);
        border-color: var(--primary-color);
    }

    .category-tab:hover:not(.active) {
        background: var(--primary-light);
        color: var(--primary-color);
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

        .search-box {
            margin-left: 0;
            width: 100%;
        }

        .search-input {
            min-width: auto;
            width: 100%;
        }

        .services-grid {
            grid-template-columns: 1fr;
        }

        .service-details {
            grid-template-columns: 1fr;
        }

        .service-footer {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
        }

        .service-actions {
            width: 100%;
            justify-content: space-between;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .categories-tabs {
            justify-content: center;
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
            <h1 class="page-title">Servicios</h1>
            <p style="color: var(--text-muted); margin-top: 0.5rem;">Gestiona los servicios y precios de la veterinaria</p>
        </div>
        <div class="page-actions">
            <button class="btn-secondary" onclick="exportServices()">
                <i class="bx bx-download"></i>
                Exportar Lista
            </button>
            <button class="btn-primary" onclick="openServiceModal()">
                <i class="bx bx-plus"></i>
                Nuevo Servicio
            </button>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value">24</div>
            <div class="stat-label">Servicios Activos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">8</div>
            <div class="stat-label">Categorías</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">€45,820</div>
            <div class="stat-label">Ingresos Mensuales</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">324</div>
            <div class="stat-label">Servicios Este Mes</div>
        </div>
    </div>

    <!-- Filtros y Búsqueda -->
    <div class="filters-section">
        <div class="filters-content">
            <div class="filter-group">
                <label class="filter-label">Categoría</label>
                <select class="filter-select" id="categoryFilter" onchange="filterServices()">
                    <option value="all">Todas las categorías</option>
                    <option value="consultation">Consultas</option>
                    <option value="vaccination">Vacunación</option>
                    <option value="surgery">Cirugía</option>
                    <option value="emergency">Urgencias</option>
                    <option value="grooming">Estética</option>
                    <option value="laboratory">Laboratorio</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Estado</label>
                <select class="filter-select" id="statusFilter" onchange="filterServices()">
                    <option value="all">Todos los estados</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </select>
            </div>
            <div class="search-box">
                <input type="text" class="search-input" placeholder="Buscar servicio..." id="searchInput" onkeyup="searchServices()">
                <i class="bx bx-search search-icon"></i>
            </div>
        </div>
    </div>

    <!-- Categorías -->
    <div class="categories-tabs">
        <div class="category-tab active" data-category="all">Todos</div>
        <div class="category-tab" data-category="consultation">Consultas</div>
        <div class="category-tab" data-category="vaccination">Vacunación</div>
        <div class="category-tab" data-category="surgery">Cirugía</div>
        <div class="category-tab" data-category="emergency">Urgencias</div>
        <div class="category-tab" data-category="grooming">Estética</div>
        <div class="category-tab" data-category="laboratory">Laboratorio</div>
    </div>

    <!-- Grid de Servicios -->
    <div class="services-grid">
        <!-- Consulta General -->
        <div class="service-card">
            <div class="service-header">
                <div class="service-info">
                    <h3 class="service-title">Consulta General</h3>
                    <span class="service-category">Consultas</span>
                </div>
                <div class="service-icon">
                    <i class="bx bx-plus-medical"></i>
                </div>
            </div>
            <div class="service-body">
                <p class="service-description">
                    Consulta veterinaria completa que incluye examen físico, diagnóstico y recomendaciones de tratamiento.
                </p>
                <div class="service-details">
                    <div class="detail-item">
                        <span class="detail-label">Duración</span>
                        <span class="detail-value">30 minutos</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Veterinario</span>
                        <span class="detail-value">Cualquiera</span>
                    </div>
                </div>
                <div class="service-price">€35.00</div>
            </div>
            <div class="service-footer">
                <div class="service-status">
                    <span class="status-badge status-active">Activo</span>
                </div>
                <div class="service-actions">
                    <button class="action-btn edit-btn" onclick="editService(1)">
                        <i class="bx bx-edit"></i>
                        Editar
                    </button>
                    <button class="action-btn delete-btn" onclick="deleteService(1)">
                        <i class="bx bx-trash"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Vacunación Anual -->
        <div class="service-card">
            <div class="service-header">
                <div class="service-info">
                    <h3 class="service-title">Vacunación Anual</h3>
                    <span class="service-category">Vacunación</span>
                </div>
                <div class="service-icon">
                    <i class="bx bx-shield-plus"></i>
                </div>
            </div>
            <div class="service-body">
                <p class="service-description">
                    Vacunación completa anual que protege contra las principales enfermedades caninas y felinas.
                </p>
                <div class="service-details">
                    <div class="detail-item">
                        <span class="detail-label">Duración</span>
                        <span class="detail-value">20 minutos</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Especie</span>
                        <span class="detail-value">Perros/Gatos</span>
                    </div>
                </div>
                <div class="service-price">€45.00</div>
            </div>
            <div class="service-footer">
                <div class="service-status">
                    <span class="status-badge status-active">Activo</span>
                </div>
                <div class="service-actions">
                    <button class="action-btn edit-btn" onclick="editService(2)">
                        <i class="bx bx-edit"></i>
                        Editar
                    </button>
                    <button class="action-btn delete-btn" onclick="deleteService(2)">
                        <i class="bx bx-trash"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Cirugía de Esterilización -->
        <div class="service-card">
            <div class="service-header">
                <div class="service-info">
                    <h3 class="service-title">Esterilización</h3>
                    <span class="service-category">Cirugía</span>
                </div>
                <div class="service-icon">
                    <i class="bx bx-cut"></i>
                </div>
            </div>
            <div class="service-body">
                <p class="service-description">
                    Procedimiento quirúrgico para esterilización de mascotas. Incluye anestesia y medicamentos post-operatorios.
                </p>
                <div class="service-details">
                    <div class="detail-item">
                        <span class="detail-label">Duración</span>
                        <span class="detail-value">2-3 horas</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Hospitalización</span>
                        <span class="detail-value">1 día</span>
                    </div>
                </div>
                <div class="service-price">€180.00</div>
            </div>
            <div class="service-footer">
                <div class="service-status">
                    <span class="status-badge status-active">Activo</span>
                </div>
                <div class="service-actions">
                    <button class="action-btn edit-btn" onclick="editService(3)">
                        <i class="bx bx-edit"></i>
                        Editar
                    </button>
                    <button class="action-btn delete-btn" onclick="deleteService(3)">
                        <i class="bx bx-trash"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Urgencia 24h -->
        <div class="service-card">
            <div class="service-header">
                <div class="service-info">
                    <h3 class="service-title">Consulta de Urgencia</h3>
                    <span class="service-category">Urgencias</span>
                </div>
                <div class="service-icon">
                    <i class="bx bx-first-aid"></i>
                </div>
            </div>
            <div class="service-body">
                <p class="service-description">
                    Atención veterinaria de urgencia disponible las 24 horas para casos críticos y emergencias.
                </p>
                <div class="service-details">
                    <div class="detail-item">
                        <span class="detail-label">Disponibilidad</span>
                        <span class="detail-value">24/7</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Tiempo Espera</span>
                        <span class="detail-value">Máx. 15 min</span>
                    </div>
                </div>
                <div class="service-price">€75.00</div>
            </div>
            <div class="service-footer">
                <div class="service-status">
                    <span class="status-badge status-active">Activo</span>
                </div>
                <div class="service-actions">
                    <button class="action-btn edit-btn" onclick="editService(4)">
                        <i class="bx bx-edit"></i>
                        Editar
                    </button>
                    <button class="action-btn delete-btn" onclick="deleteService(4)">
                        <i class="bx bx-trash"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Baño y Estética -->
        <div class="service-card">
            <div class="service-header">
                <div class="service-info">
                    <h3 class="service-title">Baño Completo</h3>
                    <span class="service-category">Estética</span>
                </div>
                <div class="service-icon">
                    <i class="bx bx-shower"></i>
                </div>
            </div>
            <div class="service-body">
                <p class="service-description">
                    Servicio completo de baño que incluye shampoo especializado, secado y cepillado profesional.
                </p>
                <div class="service-details">
                    <div class="detail-item">
                        <span class="detail-label">Duración</span>
                        <span class="detail-value">1-2 horas</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Tamaño</span>
                        <span class="detail-value">Todos</span>
                    </div>
                </div>
                <div class="service-price">€25.00</div>
            </div>
            <div class="service-footer">
                <div class="service-status">
                    <span class="status-badge status-active">Activo</span>
                </div>
                <div class="service-actions">
                    <button class="action-btn edit-btn" onclick="editService(5)">
                        <i class="bx bx-edit"></i>
                        Editar
                    </button>
                    <button class="action-btn delete-btn" onclick="deleteService(5)">
                        <i class="bx bx-trash"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Análisis de Sangre -->
        <div class="service-card">
            <div class="service-header">
                <div class="service-info">
                    <h3 class="service-title">Análisis de Sangre</h3>
                    <span class="service-category">Laboratorio</span>
                </div>
                <div class="service-icon">
                    <i class="bx bx-test-tube"></i>
                </div>
            </div>
            <div class="service-body">
                <p class="service-description">
                    Análisis completo de sangre que incluye hemograma, bioquímica y pruebas de función hepática y renal.
                </p>
                <div class="service-details">
                    <div class="detail-item">
                        <span class="detail-label">Tiempo Resultado</span>
                        <span class="detail-value">24-48 horas</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Muestra</span>
                        <span class="detail-value">Sangre</span>
                    </div>
                </div>
                <div class="service-price">€65.00</div>
            </div>
            <div class="service-footer">
                <div class="service-status">
                    <span class="status-badge status-active">Activo</span>
                </div>
                <div class="service-actions">
                    <button class="action-btn edit-btn" onclick="editService(6)">
                        <i class="bx bx-edit"></i>
                        Editar
                    </button>
                    <button class="action-btn delete-btn" onclick="deleteService(6)">
                        <i class="bx bx-trash"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- MODALES -->
<div class="modal" id="serviceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Nuevo Servicio</h3>
            <button class="modal-close" onclick="closeServiceModal()">
                <i class="bx bx-x"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="serviceForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nombre del Servicio *</label>
                        <input type="text" class="form-control" id="serviceName" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Categoría *</label>
                        <select class="form-select" id="serviceCategory" required>
                            <option value="">Seleccionar categoría...</option>
                            <option value="consultation">Consultas</option>
                            <option value="vaccination">Vacunación</option>
                            <option value="surgery">Cirugía</option>
                            <option value="emergency">Urgencias</option>
                            <option value="grooming">Estética</option>
                            <option value="laboratory">Laboratorio</option>
                            <option value="other">Otros</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Precio (€) *</label>
                        <input type="number" class="form-control" id="servicePrice" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Duración</label>
                        <input type="text" class="form-control" id="serviceDuration" placeholder="Ej: 30 minutos">
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control form-textarea" id="serviceDescription" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Veterinario Asignado</label>
                        <select class="form-select" id="serviceVeterinarian">
                            <option value="">Cualquier veterinario</option>
                            <option value="1">Dr. Carlos Rodríguez</option>
                            <option value="2">Dra. Sofía Mendoza</option>
                            <option value="3">Dr. Miguel Ángel</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Especie</label>
                        <select class="form-select" id="serviceSpecies">
                            <option value="all">Todas las especies</option>
                            <option value="dog">Perros</option>
                            <option value="cat">Gatos</option>
                            <option value="other">Otras mascotas</option>
                        </select>
                    </div>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="serviceActive" checked>
                    <label class="form-check-label">Servicio activo</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="serviceFeatured">
                    <label class="form-check-label">Servicio destacado</label>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeServiceModal()">Cancelar</button>
            <button class="btn-primary" onclick="saveService()">Guardar Servicio</button>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// TODO EL JAVASCRIPT ESPECÍFICO DE ESTA PÁGINA VA AQUÍ

// Funciones de gestión de servicios
function openServiceModal() {
    document.getElementById('modalTitle').textContent = 'Nuevo Servicio';
    document.getElementById('serviceForm').reset();
    document.getElementById('serviceModal').classList.add('show');
}

function closeServiceModal() {
    document.getElementById('serviceModal').classList.remove('show');
}

function editService(serviceId) {
    document.getElementById('modalTitle').textContent = 'Editar Servicio';
    // Aquí cargaríamos los datos del servicio desde una API
    document.getElementById('serviceModal').classList.add('show');
}

function saveService() {
    const form = document.getElementById('serviceForm');
    if (form.checkValidity()) {
        alert('Servicio guardado correctamente');
        closeServiceModal();
    } else {
        form.reportValidity();
    }
}

function deleteService(serviceId) {
    if (confirm('¿Estás seguro de que quieres eliminar este servicio?')) {
        alert('Servicio eliminado correctamente');
    }
}

function filterServices() {
    const categoryFilter = document.getElementById('categoryFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    console.log('Filtrando servicios...', { categoryFilter, statusFilter });
}

function filterServicesByCategory(category) {
    console.log('Filtrando por categoría:', category);
}

function searchServices() {
    const searchTerm = document.getElementById('searchInput').value;
    console.log('Buscando servicios...', searchTerm);
}

function exportServices() {
    alert('Exportando lista de servicios...');
}

// Inicialización cuando el DOM está listo
document.addEventListener('DOMContentLoaded', function() {
    // Funcionalidad de pestañas de categorías
    const categoryTabs = document.querySelectorAll('.category-tab');
    categoryTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remover clase active de todas las pestañas
            categoryTabs.forEach(t => t.classList.remove('active'));
            // Agregar clase active a la pestaña clickeada
            this.classList.add('active');
            
            const category = this.getAttribute('data-category');
            filterServicesByCategory(category);
        });
    });

    // Cerrar modal al hacer clic fuera
    document.getElementById('serviceModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeServiceModal();
        }
    });
});
</script>