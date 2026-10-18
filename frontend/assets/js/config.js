/**
 * MedWaste Watch — Frontend Configuration
 */
const AppConfig = {
    // The PHP router replaces this value from backend/.env (APP_URL) at runtime.
    // Keep the same-origin fallback for static hosts and local development.
   API_BASE: window.__MEDWASTE_ENV__?.API_BASE || 'https://medwaste-watch.onrender.com/api',
    APP_NAME: 'MedWaste Watch',
    HOSPITAL_NAME: 'St. Jude Metropolitan Hospital',
    DEFAULT_PAGE_SIZE: 15,
    TOAST_DURATION_MS: 2000
};

window.AppConfig = AppConfig;
