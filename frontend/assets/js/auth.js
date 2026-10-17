/**
 * MedWaste Watch — Authentication & Authorization Manager
 */
class Auth {
    static currentUser = null;
    static permissions = [];

    static async check() {
        try {
            const res = await API.get('/auth/me');
            if (res && res.data && res.data.user) {
                this.currentUser = res.data.user;
                this.permissions = res.data.permissions || [];
                if (res.data.csrf_token) {
                    API.setCsrfToken(res.data.csrf_token);
                }
                return this.currentUser;
            }
        } catch (e) {
            this.currentUser = null;
            this.permissions = [];
        }
        return null;
    }

    static async requireAuth() {
        const user = await this.check();
        if (!user) {
            window.location.href = '/login.html';
            return null;
        }
        return user;
    }

    static async login(identifier, password) {
        const res = await API.post('/auth/login', {
            email: identifier,
            username: identifier,
            password
        });
        if (res.success && res.data) {
            this.currentUser = res.data.user;
            this.permissions = res.data.permissions || [];
            if (res.data.csrf_token) {
                API.setCsrfToken(res.data.csrf_token);
            }
            return res.data;
        }
        throw new Error(res.message || 'Login failed');
    }

    static async logout() {
        try {
            await API.post('/auth/logout');
        } catch (e) {
            // Ignore error on logout
        }
        this.currentUser = null;
        this.permissions = [];
        window.location.href = '/login.html';
    }

    static getUser() {
        return this.currentUser;
    }

    static hasRole(...roles) {
        if (!this.currentUser) return false;
        if (this.currentUser.role_slug === 'super_admin' || this.currentUser.role_slug === 'admin') return true;
        return roles.includes(this.currentUser.role_slug);
    }

    static hasPermission(permissionSlug) {
        if (!this.currentUser) return false;
        if (this.currentUser.role_slug === 'super_admin' || this.currentUser.role_slug === 'admin') return true;
        return this.permissions.includes(permissionSlug);
    }
}

window.Auth = Auth;
