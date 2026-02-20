// ============================================
// FILE: js/auth.js
// Purpose: Protect dashboard pages from unauthorized access
// ============================================

/**
 * Authentication Guard
 * Call this function at the top of every dashboard page
 * It will redirect to login if user is not authenticated
 */
function checkAuth(requiredRole = null) {
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const userDataString = localStorage.getItem('user');
    
    if (!isLoggedIn || isLoggedIn !== 'true' || !userDataString) {
        window.location.href = '../login.html';
        return null;
    }
    
    try {
        const userData = JSON.parse(userDataString);
        
        if (requiredRole && userData.Role !== requiredRole) {
            alert('Access denied. You do not have permission to view this page.');
            redirectToDashboard(userData.Role);
            return null;
        }
        
        return userData;
    } catch (error) {
        console.error('Error parsing user data:', error);
        clearAuth();
        window.location.href = '../login.html';
        return null;
    }
}

/**
 * Get current user data
 * Returns null if not logged in
 */
function getCurrentUser() {
    const userDataString = localStorage.getItem('user');
    if (!userDataString) return null;
    
    try {
        return JSON.parse(userDataString);
    } catch (error) {
        console.error('Error parsing user data:', error);
        return null;
    }
}

/**
 * Logout user
 */
function logout() {
    clearAuth();
    window.location.href = '../login.html';
}

/**
 * Clear authentication data
 */
function clearAuth() {
    localStorage.removeItem('isLoggedIn');
    localStorage.removeItem('user');
}

/**
 * Redirect to correct dashboard based on role
 */
function redirectToDashboard(role) {
    const dashboardMap = {
        'Admin': '../admin/dashboard.html',
        'Adviser': '../adviser/dashboard.html',
        'Key_Teacher': '../key-teacher/dashboard.html',
        'ICT_Coordinator': '../ict-coordinator/dashboard.html',
        'Registrar': '../registrar/dashboard.html',
        'Subject_Teacher': '../subject-teacher/dashboard.html'
    };
    
    const dashboard = dashboardMap[role];
    if (dashboard) {
        window.location.href = dashboard;
    } else {
        window.location.href = '../login.html';
    }
}

/**
 * Initialize user display on dashboard
 * Supports both class-based and ID-based elements in the sidebar
 */
function initUserDisplay() {
    const user = getCurrentUser();
    if (!user) return;

    const fullName = `${user.FirstName} ${user.LastName}`;
    const initials = `${user.FirstName.charAt(0)}${user.LastName.charAt(0)}`.toUpperCase();
    const role = user.Role.replace(/_/g, ' ');

    // --- Class-based selectors (reusable across pages) ---
    document.querySelectorAll('.user-name').forEach(el => el.textContent = fullName);
    document.querySelectorAll('.user-role').forEach(el => el.textContent = role);
    document.querySelectorAll('.user-initials').forEach(el => el.textContent = initials);
    document.querySelectorAll('.username').forEach(el => el.textContent = user.Username);

    // --- ID-based selectors (sidebar fallback for existing pages) ---
    const userNameEl = document.getElementById('userName');
    const userInitialsEl = document.getElementById('userInitials');
    const userRoleEl = document.getElementById('userRole');

    if (userNameEl) userNameEl.textContent = fullName;
    if (userInitialsEl) userInitialsEl.textContent = initials;
    if (userRoleEl) userRoleEl.textContent = role;
}

/**
 * Setup logout buttons
 */
function setupLogoutButtons() {
    const logoutButtons = document.querySelectorAll('.logout-btn');
    const modal = document.getElementById('logoutModal');
    const confirmBtn = document.getElementById('confirmLogout');
    const cancelBtn = document.getElementById('cancelLogout');

    if (!modal) return;

    logoutButtons.forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            modal.classList.remove('hidden');
        });
    });

    confirmBtn.addEventListener('click', logout);

    cancelBtn.addEventListener('click', () => {
        modal.classList.add('hidden');
    });

    modal.addEventListener('click', e => {
        if (e.target === modal) modal.classList.add('hidden');
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') modal.classList.add('hidden');
    });
}

/**
 * Load logout modal HTML into the page
 */
async function loadLogoutModal() {
    if (document.getElementById('logoutModal')) return;

    const res = await fetch('../partials/logout-modal.html');
    const html = await res.text();
    document.body.insertAdjacentHTML('beforeend', html);
}

/**
 * Initialize authentication on page load
 */
async function initAuth(requiredRole = null) {
    const user = checkAuth(requiredRole);

    if (user) {
        await loadLogoutModal();
        initUserDisplay();
        setupLogoutButtons();
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initAuth());
} else {
    initAuth();
}