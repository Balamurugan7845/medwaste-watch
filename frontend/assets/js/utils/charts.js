/**
 * MedWaste Watch — Lightweight Vanilla SVG Chart Engine
 * No heavy external JS libraries needed.
 */
class VisualCharts {
    /**
     * Render an interactive SVG Donut Chart
     */
    static renderDonutChart(containerId, slices, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const size = options.size || 220;
        const strokeWidth = options.strokeWidth || 32;
        const radius = (size - strokeWidth) / 2;
        const circumference = 2 * Math.PI * radius;
        const center = size / 2;

        const total = slices.reduce((acc, s) => acc + (s.value || 0), 0);

        if (total === 0) {
            container.innerHTML = `<div class="empty-state" style="padding:1rem;"><p class="text-muted text-sm">No distribution data available</p></div>`;
            return;
        }

        let accumulatedAngle = 0;
        let svgPaths = '';

        slices.forEach(slice => {
            const val = slice.value || 0;
            if (val <= 0) return;

            const percentage = val / total;
            const strokeDasharray = `${percentage * circumference} ${circumference}`;
            const strokeDashoffset = -accumulatedAngle * circumference;

            svgPaths += `
                <circle
                    cx="${center}"
                    cy="${center}"
                    r="${radius}"
                    fill="transparent"
                    stroke="${slice.color}"
                    stroke-width="${strokeWidth}"
                    stroke-dasharray="${strokeDasharray}"
                    stroke-dashoffset="${strokeDashoffset}"
                    stroke-linecap="round"
                    style="transition: stroke-dashoffset 0.6s ease, stroke-dasharray 0.6s ease;"
                >
                    <title>${slice.label}: ${val} (${Math.round(percentage * 100)}%)</title>
                </circle>
            `;

            accumulatedAngle += percentage;
        });

        container.innerHTML = `
            <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" style="transform: rotate(-90deg); display:block; margin:0 auto;">
                ${svgPaths}
            </svg>
            <div style="position:absolute; text-align:center; pointer-events:none;">
                <div style="font-size:1.5rem; font-weight:700; color:var(--text-primary); line-height:1;">${total}</div>
                <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">Batches</div>
            </div>
        `;
    }

    /**
     * Render horizontal progress bar list for Categories
     */
    static renderCategoryBars(containerId, categories) {
        const container = document.getElementById(containerId);
        if (!container) return;

        if (!categories || categories.length === 0) {
            container.innerHTML = `<p class="text-muted text-sm">No category inventory records.</p>`;
            return;
        }

        const maxUnits = Math.max(...categories.map(c => parseInt(c.total_units) || 0), 1);

        let html = '<div class="category-stat-list">';
        categories.slice(0, 5).forEach(cat => {
            const units = parseInt(cat.total_units) || 0;
            const pct = Math.min(100, Math.round((units / maxUnits) * 100));

            html += `
                <div class="cat-stat-item">
                    <div class="cat-stat-header">
                        <span class="cat-stat-name">${App.escapeHtml(cat.category_name)}</span>
                        <span class="cat-stat-val">${units} units</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width: ${pct}%"></div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }
}

window.VisualCharts = VisualCharts;
