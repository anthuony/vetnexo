        </main> <!-- Cierre de main-content -->
    </div> <!-- Cierre de dashboard-container -->

    <!-- Footer Principal -->
    <footer class="footer">
        <p>© 2026 VetNexo | Desarrollado con ❤️ por <strong>VetNexo Product Team</strong></p>
    </footer>

    <style>
        .footer {
            background: var(--white);
            border-top: 1px solid var(--border-light);
            padding: 1.5rem 2rem;
            text-align: center;
            color: var(--text-muted);
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .footer p {
            margin: 0;
            font-size: 0.9rem;
        }

        .footer strong {
            color: var(--primary-color);
        }

        /* Ajustes responsive para el footer */
        @media (max-width: 900px) {
            .footer {
                margin-left: 0;
                padding: 1rem;
            }
        }
    </style>

    <script>
        // =============================================
        // JAVASCRIPT UNIFICADO - TODO EN UN SOLO LUGAR
        // =============================================

        // 1. TOGGLE SIDEBAR PRINCIPAL
        (function setupSidebarDrawer(){
            const toggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('legacySidebarOverlay');
            const mainContent = document.getElementById('mainContent');
            const footer = document.querySelector('.footer');
            if (!toggle || !sidebar) return;

            function sync(open) {
                sidebar.classList.toggle('active', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (overlay) {
                    overlay.classList.toggle('active', open && window.innerWidth <= 900);
                    overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
                }
                document.body.style.overflow = (open && window.innerWidth <= 900) ? 'hidden' : '';
                if (mainContent && window.innerWidth > 900) mainContent.classList.toggle('expanded', open);
                if (footer && window.innerWidth > 900) footer.style.marginLeft = open ? '0' : 'var(--sidebar-width)';
            }

            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                sync(!sidebar.classList.contains('active'));
            });
            if (overlay) overlay.addEventListener('click', function(){ sync(false); });
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape') sync(false); });
            window.addEventListener('resize', function(){
                if (window.innerWidth > 900 && overlay) overlay.classList.remove('active');
                if (window.innerWidth > 900) document.body.style.overflow = '';
            }, { passive: true });
        })();

        // 2. SUBMENU TOGGLE - ESTA ES LA PARTE CRÍTICA
        function setupSubmenus() {
            console.log('Configurando submenús...');
            
            // Primero: abrir automáticamente el menú padre si un hijo está activo
            document.querySelectorAll('.submenu .menu-link.active').forEach(activeItem => {
                const parentMenu = activeItem.closest('.menu-item');
                if (parentMenu) {
                    const parentLink = parentMenu.querySelector('.menu-link[data-toggle]');
                    const submenuId = parentLink?.getAttribute('data-toggle');
                    const submenu = document.getElementById(submenuId);
                    const toggle = parentLink?.querySelector('.menu-toggle');
                    
                    if (parentLink && submenu && toggle) {
                        parentLink.classList.add('active');
                        submenu.classList.add('show');
                        submenu.style.display = 'block';
                        toggle.classList.add('rotated');
                        console.log('Submenú abierto automáticamente:', submenuId);
                    }
                }
            });

            // Segundo: configurar clicks en los enlaces del menú
            document.querySelectorAll('.menu-link[data-toggle]').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const submenuId = this.getAttribute('data-toggle');
                    const submenu = document.getElementById(submenuId);
                    const toggle = this.querySelector('.menu-toggle');
                    
                    console.log('Click en menú:', submenuId);
                    
                    if (!submenu || !toggle) {
                        console.error('Elementos no encontrados para:', submenuId);
                        return;
                    }

                    // Determinar si vamos a abrir o cerrar
                    const isOpening = !submenu.classList.contains('show');
                    
                    // Cerrar otros submenús abiertos
                    if (isOpening) {
                        document.querySelectorAll('.submenu.show').forEach(otherSubmenu => {
                            if (otherSubmenu.id !== submenuId) {
                                otherSubmenu.classList.remove('show');
                                otherSubmenu.style.display = 'none';
                                const otherToggle = otherSubmenu.closest('.menu-item')?.querySelector('.menu-toggle');
                                if (otherToggle) otherToggle.classList.remove('rotated');
                            }
                        });
                    }
                    
                    // Toggle del submenú actual
                    if (isOpening) {
                        submenu.classList.add('show');
                        submenu.style.display = 'block';
                        toggle.classList.add('rotated');
                    } else {
                        submenu.classList.remove('show');
                        submenu.style.display = 'none';
                        toggle.classList.remove('rotated');
                    }
                    
                    console.log('Submenú', isOpening ? 'abierto' : 'cerrado', ':', submenuId);
                });
            });

            console.log('Submenús configurados correctamente');
        }

        // 3. CIERRE DE SEGURIDAD EN MÓVIL
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 900) {
                const sidebar = document.getElementById('sidebar');
                const toggle = document.getElementById('sidebarToggle');
                const overlay = document.getElementById('legacySidebarOverlay');
                if (sidebar && toggle && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    toggle.setAttribute('aria-expanded', 'false');
                    if (overlay) overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // 4. SISTEMA DE NOTIFICACIONES
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <span>${message}</span>
                <button onclick="this.parentElement.remove()">×</button>
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 90px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success-color)' : 
                           type === 'error' ? 'var(--error-color)' : 
                           type === 'warning' ? 'var(--warning-color)' : 'var(--primary-color)'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 1rem;
                animation: slideIn 0.3s ease;
                max-width: 400px;
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }

        // 5. NAVEGACIÓN ENTRE MÓDULOS
        function navigateTo(module) {
            console.log(`Navegando al módulo: ${module}`);
            
            const routes = {
                'pacientes': 'listademascotas.php',
                'nuevo-paciente': 'registrarmascota.php',
                'citas': 'calendario.php',
                'nueva-cita': 'agendarcita.php',
                'facturacion': 'nuevafactura.php',
                'nueva-factura': 'nuevafactura.php',
                'inventario-bajo': 'stock.php',
                'dashboard': 'dashboard.php'
            };
            
            if (routes[module]) {
                window.location.href = routes[module];
            } else {
                showNotification(`Módulo "${module}" no disponible aún`, 'warning');
            }
        }

        // 6. INICIALIZACIÓN CUANDO EL DOM ESTÉ LISTO
        document.addEventListener('DOMContentLoaded', function() {
            console.log('VetNexo - Sistema inicializado');
            
            // Configurar submenús (¡IMPORTANTE!)
            setupSubmenus();
            
            // Configurar búsquedas
            document.querySelectorAll('.search-input').forEach(input => {
                input.addEventListener('input', function(e) {
                    console.log('Buscando:', e.target.value);
                });
            });

            console.log('Todos los componentes inicializados');
        });

        // 7. ESTILOS PARA ANIMACIONES
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            .submenu {
                transition: all 0.3s ease;
                overflow: hidden;
            }
            
            .submenu:not(.show) {
                display: none;
            }
            
            .menu-toggle {
                transition: transform 0.3s ease;
            }
            
            .notification button {
                background: none;
                border: none;
                color: white;
                font-size: 1.2rem;
                cursor: pointer;
                padding: 0;
                width: 20px;
                height: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
            }
            
            .notification button:hover {
                background: rgba(255,255,255,0.2);
            }
        `;
        document.head.appendChild(style);

    </script>
</body>
</html>