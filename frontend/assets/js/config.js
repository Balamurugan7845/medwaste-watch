/**
 * MedWaste Watch — Frontend Configuration
 */
const AppConfig = {
    API_BASE: ['localhost', '127.0.0.1'].includes(window.location.hostname)
        ? '/api'
        : 'https://medwaste-watch.onrender.com/api',
    APP_NAME: 'MedWaste Watch',
    HOSPITAL_NAME: 'St. Jude Metropolitan Hospital',
    DEFAULT_PAGE_SIZE: 15,
    TOAST_DURATION_MS: 2000
};

window.AppConfig = AppConfig;
