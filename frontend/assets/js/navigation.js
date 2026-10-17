/**
 * MedWaste Watch — Navigation & Frame Controller
 * Dynamically injects unified sidebar and topbar into pages and binds mobile drawer interactions.
 */
class Navigation {
    static async ensureComponents() {
        const required = [
            { name: 'SidebarComponent', src: '/assets/js/components/sidebar.js' },
            { name: 'NavbarComponent', src: '/assets/js/components/navbar.js' },
            { name: 'FooterComponent', src: '/assets/js/components/footer.js' }
        ];

        const toLoad = required.filter(c => typeof window[c.name] === 'undefined');
        if (toLoad.length > 0) {
            await Promise.all(toLoad.map(c => new Promise(resolve => {
                const s = document.createElement('script');
                s.src = c.src;
                s.onload = resolve;
                s.onerror = resolve;
                document.head.appendChild(s);
            })));
        }
    }

    static async init(activePageSlug = '') {
        const user = await Auth.requireAuth();
        if (!user) return;

        await this.ensureComponents();

        this.renderSidebar(user, activePageSlug);
        this.renderTopbar(user, activePageSlug);
        this.renderFooter();
        this.bindEvents();
        this.updateNotificationBadge();
    }

    static renderFooter() {
        if (window.FooterComponent) {
            FooterComponent.render();
            return;
        }

        let container = document.getElementById('footer-container');
        if (!container) {
            const main = document.querySelector('.app-main');
            if (!main) return;
            container = document.createElement('div');
            container.id = 'footer-container';
            main.appendChild(container);
        }

        const year = new Date().getFullYear();
        container.innerHTML = `
            <footer class="app-footer">
                <div class="footer-content">
                    <div class="footer-top">
                        <div class="footer-left">
                            <span class="footer-brand">MedWaste Watch</span>
                            <span class="footer-dot">•</span>
                            <span class="footer-sub">Hospital Medicine Availability &amp; Waste Mitigation</span>
                            <span class="footer-dot">•</span>
                            <span class="footer-version">v2.4 Enterprise</span>
                            <span class="footer-badge">
                                <span class="pulse-dot"></span>
                                <span>All Systems Operational</span>
                            </span>
                        </div>
                        <div class="footer-links">
                            <a href="/dashboard.html">Dashboard</a>
                            <a href="/medicines.html">Medicines</a>
                            <a href="/batches.html">Batches</a>
                            <a href="/expiry.html">FEFO Priority</a>
                            <a href="/reports.html">Reports</a>
                            <a href="/audit-logs.html">Audit Trail</a>
                        </div>
                    </div>
                    <div class="footer-bottom">
                        <div class="footer-legal">
                            <span>&copy; ${year} St. Jude Metropolitan Hospital &amp; Medical Center. Confidential Health Record System.</span>
                        </div>
                        <div class="footer-compliance-tag">
                            <span>NABH &amp; WHO GMP Biomedical Compliance Standard</span>
                        </div>
                    </div>
                </div>
            </footer>
        `;
    }

    static renderSidebar(user, activeSlug) {
        if (window.SidebarComponent) {
            SidebarComponent.render(user, activeSlug);
            return;
        }
        const container = document.getElementById('sidebar-container');
        if (!container) return;

        const role = user.role_slug;
        const isSuperAdmin = role === 'super_admin';
        const isPharmacist = isSuperAdmin || role === 'pharmacist';
        const isDoctor = isSuperAdmin || role === 'doctor';
        const isAuditor = isSuperAdmin || role === 'auditor';

        container.innerHTML = `
            <aside class="app-sidebar" id="app-sidebar">
                <div class="sidebar-header">
                    <div class="sidebar-logo-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
                    </div>
                    <div class="sidebar-logo-text">
                        <div class="sidebar-brand-name">MedWaste Watch</div>
                        <div class="sidebar-tagline">Clinical Inventory SaaS</div>
                    </div>
                </div>

                <nav class="sidebar-nav">
                    <div class="nav-section-title">Overview</div>
                    <a href="/dashboard.html" class="nav-link ${activeSlug === 'dashboard' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                        <span>Dashboard</span>
                    </a>

                    <div class="nav-section-title">Inventory & Stock</div>
                    <a href="/medicines.html" class="nav-link ${activeSlug === 'medicines' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 14 1.5-1.5a2.828 2.828 0 1 0-4-4L15 10"/><path d="m14 15 1.5-1.5a2.828 2.828 0 1 0-4-4L10 11"/><path d="m10 19 1.5-1.5a2.828 2.828 0 1 0-4-4L6 15"/><path d="m5 19 1.5-1.5a2.828 2.828 0 1 0-4-4L1 15"/></svg>
                        <span>Medicines Catalog</span>
                    </a>
                    <a href="/batches.html" class="nav-link ${activeSlug === 'batches' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        <span>Batch Management</span>
                    </a>
                    <a href="/expiry.html" class="nav-link ${activeSlug === 'expiry' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Expiry Priority</span>
                    </a>
                    <a href="/low-stock.html" class="nav-link ${activeSlug === 'low-stock' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/></svg>
                        <span>Low-Stock Alerts</span>
                    </a>

                    <div class="nav-section-title">Clinical Operations</div>
                    <a href="/reservations.html" class="nav-link ${activeSlug === 'reservations' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M15 2H9a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1Z"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
                        <span>Stock Reservations</span>
                    </a>
                    <a href="/alternatives.html" class="nav-link ${activeSlug === 'alternatives' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5"/><path d="M4 20 21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                        <span>Alternative Lookup</span>
                    </a>
                    <a href="/transfers.html" class="nav-link ${activeSlug === 'transfers' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>
                        <span>Location Transfers</span>
                    </a>
                    <a href="/disposal.html" class="nav-link ${activeSlug === 'disposal' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        <span>Waste & Disposal</span>
                    </a>

                    <div class="nav-section-title">Intelligence & Reports</div>
                    <a href="/reports.html" class="nav-link ${activeSlug === 'reports' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                        <span>Reports & CSV</span>
                    </a>
                    <a href="/notifications.html" class="nav-link ${activeSlug === 'notifications' ? 'active' : ''}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <span>Alerts Center</span>
                        <span class="nav-badge badge-critical unread-notif-count" style="display:none;">0</span>
                    </a>

                    ${isSuperAdmin || isAuditor ? `
                        <div class="nav-section-title">Administration</div>
                        ${isSuperAdmin ? `
                            <a href="/users.html" class="nav-link ${activeSlug === 'users' ? 'active' : ''}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span>Staff & Users</span>
                            </a>
                            <a href="/departments.html" class="nav-link ${activeSlug === 'departments' ? 'active' : ''}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>
                                <span>Departments</span>
                            </a>
                            <a href="/settings.html" class="nav-link ${activeSlug === 'settings' ? 'active' : ''}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                                <span>Hospital Settings</span>
                            </a>
                        ` : ''}
                        <a href="/audit-logs.html" class="nav-link ${activeSlug === 'audit-logs' ? 'active' : ''}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            <span>Audit Trail</span>
                        </a>
                    ` : ''}
                </nav>

                <div class="sidebar-footer">
                    <div class="user-profile-badge">
                        <div class="user-avatar">${user.full_name ? user.full_name.charAt(0) : 'U'}</div>
                        <div style="overflow:hidden;">
                            <div class="user-meta-name">${App.escapeHtml(user.full_name)}</div>
                            <div class="user-meta-role">${App.escapeHtml(user.role_name)}</div>
                        </div>
                    </div>
                    <button class="topbar-btn" id="logout-sidebar-btn" title="Sign Out">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                    </button>
                </div>
            </aside>
            <div class="sidebar-overlay" id="sidebar-overlay"></div>
        `;

        if (window.Theme) {
            Theme.updateUI(Theme.getPreference(), Theme.getResolvedTheme());
        }
    }

    static renderTopbar(user, activeSlug) {
        if (window.NavbarComponent) {
            NavbarComponent.render(user, activeSlug);
            return;
        }
        const container = document.getElementById('topbar-container');
        if (!container) return;

        const pageTitles = {
            dashboard: 'Hospital Executive Dashboard',
            medicines: 'Medicines Inventory Catalog',
            'medicine-details': 'Medicine Details & Batch Analytics',
            batches: 'Batch Consignments & Storage',
            expiry: 'Expiry Priority Management',
            'low-stock': 'Stock Shortage & Reorder Replenishment',
            alternatives: 'Clinical Alternative Medicines',
            reservations: 'Department Medicine Reservations',
            transfers: 'Storage Location Transfers',
            disposal: 'Biomedical Waste & Approved Disposal',
            reports: 'Inventory Intelligence & Compliance Reports',
            notifications: 'System & Expiry Notifications',
            users: 'Hospital User Administration',
            departments: 'Departments & Clinical Units',
            settings: 'System & Policy Configuration',
            'audit-logs': 'Immutable Compliance Audit Logs'
        };

        const title = pageTitles[activeSlug] || 'MedWaste Watch';

        container.innerHTML = `
            <header class="app-topbar">
                <div class="topbar-left">
                    <button class="menu-toggle-btn" id="mobile-menu-btn" aria-label="Toggle navigation menu">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                    </button>
                    <div class="topbar-breadcrumbs">
                        <span class="breadcrumb-brand">MedWaste Watch</span>
                        <span class="breadcrumb-separator">/</span>
                        <span class="active">${title}</span>
                    </div>
                </div>

                <div class="topbar-right">
                    <span class="badge badge-info topbar-dept-badge" style="font-size:0.75rem;">
                        ${App.escapeHtml(user.department_name || 'Central')}
                    </span>

                    <button class="topbar-btn theme-toggle-btn" id="topbar-theme-toggle" title="Switch Theme (Light / Dark)" aria-label="Toggle theme">
                        <svg class="theme-icon-light" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <svg class="theme-icon-dark" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>

                    <a href="/notifications.html" class="topbar-btn" title="View Notifications">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <span class="topbar-notif-indicator unread-dot" style="display:none;"></span>
                    </a>

                    <button class="btn btn-secondary btn-sm topbar-logout-btn" id="logout-topbar-btn" title="Logout">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                        <span class="topbar-logout-text">Logout</span>
                    </button>
                </div>
            </header>
        `;

        if (window.Theme) {
            Theme.updateUI(Theme.getPreference(), Theme.getResolvedTheme());
        }
    }

    static bindEvents() {
        if (window.SidebarComponent && SidebarComponent.bindEvents) {
            SidebarComponent.bindEvents();
        }
        if (window.NavbarComponent && NavbarComponent.bindEvents) {
            NavbarComponent.bindEvents();
        }

        const menuBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('app-sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        const closeBtn = document.getElementById('sidebar-close-btn');

        const closeDrawer = () => {
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
        };

        if (menuBtn && sidebar && overlay && !menuBtn._hasBoundDrawer) {
            menuBtn._hasBoundDrawer = true;
            menuBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
            });

            overlay.addEventListener('click', (e) => {
                e.preventDefault();
                closeDrawer();
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    closeDrawer();
                }
            });
        }

        if (closeBtn && !closeBtn._hasBoundClick) {
            closeBtn._hasBoundClick = true;
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                closeDrawer();
            });
        }

        const themeBtn = document.getElementById('topbar-theme-toggle');
        if (themeBtn && window.Theme && !themeBtn._hasBoundClick) {
            themeBtn._hasBoundClick = true;
            themeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                Theme.toggle();
            });
        }

        const logoutTop = document.getElementById('logout-topbar-btn');
        const logoutSide = document.getElementById('logout-sidebar-btn');

        if (logoutTop && !logoutTop._hasBoundClick) {
            logoutTop._hasBoundClick = true;
            logoutTop.addEventListener('click', () => Auth.logout());
        }
        if (logoutSide && !logoutSide._hasBoundClick) {
            logoutSide._hasBoundClick = true;
            logoutSide.addEventListener('click', () => Auth.logout());
        }
    }

    static async updateNotificationBadge() {
        try {
            const res = await API.get('/notifications');
            if (res && res.data) {
                const unread = res.data.unread_count || 0;
                document.querySelectorAll('.unread-notif-count').forEach(el => {
                    el.textContent = unread;
                    el.style.display = unread > 0 ? 'inline-flex' : 'none';
                });
                document.querySelectorAll('.unread-dot').forEach(el => {
                    el.style.display = unread > 0 ? 'block' : 'none';
                });
            }
        } catch (e) {
            // Silently ignore background badge poll error
        }
    }
}

window.Navigation = Navigation;
