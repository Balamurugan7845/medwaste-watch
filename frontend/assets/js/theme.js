/**
 * MedWaste Watch — Unified Theme Management Service
 * Provides instant theme detection, persistence, system synchronization, and event dispatch.
 */
class ThemeManager {
    static STORAGE_KEY = 'medwaste_theme';

    static init() {
        // Read saved theme from localStorage (defaults to 'system')
        const currentTheme = this.getPreference();
        this.applyTheme(currentTheme, false);

        // Listen for system changes if preference is 'system'
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (this.getPreference() === 'system') {
                    this.applyTheme('system', false);
                }
            });
        }
    }

    static getPreference() {
        return localStorage.getItem(this.STORAGE_KEY) || 'system';
    }

    static getResolvedTheme() {
        const pref = this.getPreference();
        if (pref === 'system') {
            return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        return pref;
    }

    static setTheme(theme) {
        if (!['light', 'dark', 'system'].includes(theme)) return;
        localStorage.setItem(this.STORAGE_KEY, theme);
        this.applyTheme(theme, true);
    }

    static toggle() {
        const current = this.getResolvedTheme();
        const next = current === 'dark' ? 'light' : 'dark';
        this.setTheme(next);
        return next;
    }

    static applyTheme(theme, showToast = false) {
        let resolved = theme;
        if (theme === 'system') {
            resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }

        // Apply attribute to <html> element
        document.documentElement.setAttribute('data-theme', resolved);

        // Update any theme toggles in topbar or settings
        this.updateUI(theme, resolved);

        // Dispatch custom event for charts or components
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme, resolved } }));

        if (showToast && window.App && typeof App.toast === 'function') {
            const label = theme === 'system' ? 'System Theme' : (theme === 'dark' ? 'Dark Theme' : 'Light Theme');
            App.toast('info', `Switched to ${label}`, 2000);
        }
    }

    static updateUI(pref, resolved) {
        // Update toggle icons
        const lightIcons = document.querySelectorAll('.theme-icon-light');
        const darkIcons = document.querySelectorAll('.theme-icon-dark');

        lightIcons.forEach(el => el.style.display = resolved === 'dark' ? 'none' : 'block');
        darkIcons.forEach(el => el.style.display = resolved === 'dark' ? 'block' : 'none');

        // Update text labels if present
        const themeLabels = document.querySelectorAll('.theme-label-text');
        themeLabels.forEach(el => {
            el.textContent = resolved === 'dark' ? 'Dark Mode' : 'Light Mode';
        });

        // Update settings page radio cards if present
        document.querySelectorAll('.theme-option-card').forEach(card => {
            const val = card.getAttribute('data-theme-val');
            if (val === pref) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });
    }
}

// Immediate execution to prevent flash of unstyled theme
ThemeManager.init();
window.Theme = ThemeManager;
