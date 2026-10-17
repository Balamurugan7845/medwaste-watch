/**
 * MedWaste Watch — Modular Navbar (Topbar) Component
 * File: /assets/js/components/navbar.js
 * Renders the sticky top navigation header with breadcrumbs, theme switch, alerts, and user controls.
 */
class NavbarComponent {
    static pageTitles = {
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

    static render(user, activeSlug = '') {
        const container = document.getElementById('topbar-container');
        if (!container) return;

        const title = this.pageTitles[activeSlug] || 'MedWaste Watch';
        const deptName = user ? (user.department_name || 'Central Pharmacy') : 'Central Pharmacy';

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
                        ${App.escapeHtml(deptName)}
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
        const themeBtn = document.getElementById('topbar-theme-toggle');
        if (themeBtn && !themeBtn._hasBoundClick) {
            themeBtn._hasBoundClick = true;
            themeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (window.Theme) {
                    Theme.toggle();
                }
            });
        }

        const logoutTop = document.getElementById('logout-topbar-btn');
        if (logoutTop && !logoutTop._hasBoundClick) {
            logoutTop._hasBoundClick = true;
            logoutTop.addEventListener('click', (e) => {
                e.preventDefault();
                if (window.Auth) {
                    Auth.logout();
                }
            });
        }
    }
}

window.NavbarComponent = NavbarComponent;
