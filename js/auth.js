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
    // Check if user is logged in
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const userDataString = localStorage.getItem('user');
    
    if (!isLoggedIn || isLoggedIn !== 'true' || !userDataString) {
        // Not logged in - redirect to login page
        window.location.href = '../login.html';
        return null;
    }
    
    try {
        // Parse user data
        const userData = JSON.parse(userDataString);
        
        // If specific role is required, check if user has it
        if (requiredRole && userData.Role !== requiredRole) {
            // Wrong role - redirect to their correct dashboard or login
            alert('Access denied. You do not have permission to view this page.');
            redirectToDashboard(userData.Role);
            return null;
        }
        
        return userData;
    } catch (error) {
        console.error('Error parsing user data:', error);
        // Invalid data - clear and redirect to login
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
 * Clears all auth data and redirects to login
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
 * Updates the user info section with logged-in user's details
 */
function initUserDisplay() {
    const user = getCurrentUser();
    if (!user) return;
    
    // Update user name displays
    const userNameElements = document.querySelectorAll('.user-name');
    userNameElements.forEach(el => {
        el.textContent = `${user.FirstName} ${user.LastName}`;
    });
    
    // Update user role displays
    const userRoleElements = document.querySelectorAll('.user-role');
    userRoleElements.forEach(el => {
        el.textContent = user.Role.replace('_', ' ');
    });
    
    // Update user initials
    const userInitialsElements = document.querySelectorAll('.user-initials');
    userInitialsElements.forEach(el => {
        const initials = `${user.FirstName.charAt(0)}${user.LastName.charAt(0)}`;
        el.textContent = initials;
    });
    
    // Update username displays
    const usernameElements = document.querySelectorAll('.username');
    usernameElements.forEach(el => {
        el.textContent = user.Username;
    });
}

/**
 * Setup logout buttons
 * Automatically finds and configures logout buttons on the page
 */
function setupLogoutButtons() {
    const logoutButtons = document.querySelectorAll('[data-logout], .logout-btn, button[onclick*="logout"]');
    
    logoutButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Optional: Show confirmation dialog
            if (confirm('Are you sure you want to logout?')) {
                logout();
            }
        });
    });
}

/**
 * Initialize authentication on page load
 */
function initAuth(requiredRole = null) {
    // Check authentication
    const user = checkAuth(requiredRole);
    
    if (user) {
        // User is authenticated
        initUserDisplay();
        setupLogoutButtons();
        
        // Log for debugging (remove in production)
        console.log('Authenticated user:', user);
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initAuth());
} else {
    initAuth();
}