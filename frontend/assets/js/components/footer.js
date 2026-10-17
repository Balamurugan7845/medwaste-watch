/**
 * MedWaste Watch — Modular Footer Component
 * File: /assets/js/components/footer.js
 * Renders the standardized clinical footer with status pulse, version, and compliance notices.
 */
class FooterComponent {
    static render() {
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
}

window.FooterComponent = FooterComponent;
