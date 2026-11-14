// js/user-account-manager.js
class UserAccountManager {
    constructor(currentUserRole) {
        this.currentUserRole = currentUserRole;
        this.allUsers        = [];
        this.hasAdminAccount = false;
    }

    getInitials(firstName, lastName) {
        return (firstName?.charAt(0) || '') + (lastName?.charAt(0) || '');
    }

    // -------------------------------------------------------------------------
    // Load stats
    // -------------------------------------------------------------------------
    async loadStats() {
        try {
            const response = await fetch('../backend/api/users.php?action=stats');
            const result   = await response.json();
            if (result.success) {
                const s = result.data;
                document.getElementById('totalAccounts').textContent    = s.total    || 0;
                document.getElementById('activeAccounts').textContent   = s.active   || 0;
                document.getElementById('inactiveAccounts').textContent = s.inactive || 0;
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }

    // -------------------------------------------------------------------------
    // Load users
    // -------------------------------------------------------------------------
    async loadUsers() {
        try {
            const response = await fetch('../backend/api/users.php?action=all');
            const result   = await response.json();
            if (result.success) {
                this.allUsers        = result.data;
                this.hasAdminAccount = this.allUsers.some(u => u.Role === 'Admin');
                this.displayUsers(this.allUsers);
            }
        } catch (error) {
            console.error('Error loading users:', error);
        }
    }

    // -------------------------------------------------------------------------
    // Display users in table
    // -------------------------------------------------------------------------
    displayUsers(users) {
        const tbody = document.getElementById('userTableBody');
        if (!tbody) return;

        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No user accounts found</td></tr>';
            return;
        }

        const roleColors = {
            'Admin':           'bg-red-100 text-red-800',
            'ICT_Coordinator': 'bg-blue-100 text-blue-800',
            'Registrar':       'bg-purple-100 text-purple-800',
            'Adviser':         'bg-green-100 text-green-800',
            'Key_Teacher':     'bg-yellow-100 text-yellow-800',
            'Subject_Teacher': 'bg-gray-100 text-gray-800'
        };

        tbody.innerHTML = users.map(user => `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold">
                            ${this.getInitials(user.FirstName, user.LastName)}
                        </div>
                        <div class="text-sm font-medium">${user.FirstName} ${user.LastName}</div>
                    </div>
                </td>
                <td class="px-6 py-4 text-sm font-mono">${user.Username}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${roleColors[user.Role] || 'bg-gray-100 text-gray-800'}">
                        ${user.Role.replace(/_/g, ' ')}
                    </span>
                </td>
                <td class="px-6 py-4">
                    ${user.IsActive == 1
                        ? '<span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800">Active</span>'
                        : '<span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-800">Inactive</span>'
                    }
                </td>
                <td class="px-6 py-4 text-right">
                    <button onclick="userManager.toggleStatus(${user.UserID})"
                            class="p-2 text-gray-600 hover:text-primary"
                            title="${user.IsActive == 1 ? 'Deactivate' : 'Activate'}">
                        <span class="material-symbols-outlined">${user.IsActive == 1 ? 'toggle_on' : 'toggle_off'}</span>
                    </button>
                    <button onclick="userManager.resetPassword(${user.UserID}, '${user.Username}')"
                            class="p-2 text-gray-600 hover:text-primary" title="Reset Password">
                        <span class="material-symbols-outlined">lock_reset</span>
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // -------------------------------------------------------------------------
    // Filter
    // -------------------------------------------------------------------------
    filterUsers() {
        const search = document.getElementById('searchInput')?.value.toLowerCase() || '';
        const role   = document.getElementById('roleFilter')?.value  || '';
        const status = document.getElementById('statusFilter')?.value || '';

        const filtered = this.allUsers.filter(user => {
            const matchSearch = user.FirstName.toLowerCase().includes(search) ||
                                user.LastName.toLowerCase().includes(search)  ||
                                user.Username.toLowerCase().includes(search)  ||
                                user.Role.toLowerCase().includes(search);
            const matchRole   = !role   || user.Role === role;
            const matchStatus = status === '' || user.IsActive == status;
            return matchSearch && matchRole && matchStatus;
        });

        this.displayUsers(filtered);
    }

    // -------------------------------------------------------------------------
    // Create modal
    // -------------------------------------------------------------------------
    openCreateModal() {
        document.getElementById('createModal')?.classList.remove('hidden');
        document.getElementById('createUserForm')?.reset();

        const adminOption = document.getElementById('role')?.querySelector('option[value="Admin"]');
        if (adminOption) {
            adminOption.disabled     = this.hasAdminAccount;
            adminOption.textContent  = this.hasAdminAccount ? 'Admin (Already exists)' : 'Admin';
        }
    }

    closeCreateModal() {
        document.getElementById('createModal')?.classList.add('hidden');
    }

    // -------------------------------------------------------------------------
    // Create user
    // -------------------------------------------------------------------------
    async createUser(formData) {
        if (formData.Password.length < 6) {
            alert('Password must be at least 6 characters long');
            return false;
        }

        try {
            const response = await fetch('../backend/api/users-with-credentials.php?action=create-with-return', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });

            const result = await response.json();

            if (result.success) {
                this.showCredentialsModal(result.credentials);
                this.closeCreateModal();
                await this.loadStats();
                await this.loadUsers();
                return true;
            } else {
                alert('Error: ' + result.message);
                return false;
            }
        } catch (error) {
            console.error('Error creating user:', error);
            alert('Failed to create user account');
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Credentials modal (shown once after account creation)
    // -------------------------------------------------------------------------
    showCredentialsModal(credentials) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Account Created Successfully</h3>
                    <button onclick="this.closest('.fixed').remove()" class="text-gray-500 hover:text-gray-700">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="bg-red-50 border-l-4 border-red-400 p-4">
                        <div class="flex">
                            <span class="material-symbols-outlined text-red-400 mr-2">warning</span>
                            <div>
                                <p class="text-sm text-red-700 font-semibold">CRITICAL: Save These Credentials NOW!</p>
                                <p class="text-xs text-red-600 mt-1">${credentials.warning}</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="border border-gray-200 rounded p-3">
                            <label class="text-xs text-gray-500 uppercase">Username</label>
                            <div class="flex items-center justify-between">
                                <code class="text-sm font-mono font-bold">${credentials.username}</code>
                                <button onclick="navigator.clipboard.writeText('${credentials.username}'); this.innerHTML='<span class=\\'material-symbols-outlined text-sm\\'>check</span>'"
                                        class="text-primary hover:text-primary-dark">
                                    <span class="material-symbols-outlined text-sm">content_copy</span>
                                </button>
                            </div>
                        </div>
                        <div class="border border-gray-200 rounded p-3 bg-yellow-50">
                            <label class="text-xs text-gray-500 uppercase">Temporary Password</label>
                            <div class="flex items-center justify-between">
                                <code class="text-sm font-mono font-bold">${credentials.password}</code>
                                <button onclick="navigator.clipboard.writeText('${credentials.password}'); this.innerHTML='<span class=\\'material-symbols-outlined text-sm\\'>check</span>'"
                                        class="text-primary hover:text-primary-dark">
                                    <span class="material-symbols-outlined text-sm">content_copy</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded p-3">
                        <p class="text-xs text-blue-700">
                            <strong>Note:</strong> The user can change this temporary password using the
                            "Change Password" link on the login page.
                        </p>
                    </div>
                    <button onclick="this.closest('.fixed').remove()"
                            class="w-full bg-primary text-white py-2 rounded hover:bg-primary/90">
                        I Have Saved These Credentials
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // -------------------------------------------------------------------------
    // Toggle active status
    // -------------------------------------------------------------------------
    async toggleStatus(userId) {
        if (!confirm('Are you sure you want to change this user\'s status?')) return;

        try {
            const response = await fetch('../backend/api/users.php?action=toggle-status', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ UserID: userId })
            });

            const result = await response.json();
            if (result.success) {
                await this.loadStats();
                await this.loadUsers();
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error toggling status:', error);
            alert('Failed to update user status');
        }
    }

    // -------------------------------------------------------------------------
    // Reset password
    // -------------------------------------------------------------------------
    async resetPassword(userId, username) {
        const newPassword = prompt(
            `Enter new password for ${username}:\n\nNote: Save this password and give it to the user — you won't be able to retrieve it later.`
        );
        if (!newPassword) return;
        if (newPassword.length < 6) { alert('Password must be at least 6 characters long'); return; }

        try {
            const response = await fetch('../backend/api/users.php?action=reset-password', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ UserID: userId, NewPassword: newPassword })
            });

            const result = await response.json();
            if (result.success) {
                alert(`Password reset successfully!\n\nNew Password: ${newPassword}\n\n⚠️ IMPORTANT: Save this password and give it to ${username}.\nThe user can change it using "Change Password" on the login page.`);
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error resetting password:', error);
            alert('Failed to reset password');
        }
    }

    // -------------------------------------------------------------------------
    // Download / print account list
    // -------------------------------------------------------------------------
    downloadAccountList() {
        if (this.allUsers.length === 0) { alert('No accounts to download'); return; }

        const proceed = confirm(
            'IMPORTANT NOTICE:\n\n' +
            'Passwords are encrypted and cannot be retrieved from the database.\n\n' +
            'The exported list will include usernames only.\n' +
            'Users must use the "Change Password" feature to set their own password.\n\n' +
            'Continue with export?'
        );
        if (!proceed) return;

        const printContent = `
            <div style="padding:40px;font-family:Arial,sans-serif;">
                <div style="text-align:center;margin-bottom:30px;">
                    <h1 style="margin:0;color:#085019;">Don Jose Integrated High School</h1>
                    <h2 style="margin:10px 0 0 0;font-weight:normal;">User Account List</h2>
                    <p style="margin:5px 0;color:#666;">Generated on ${new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</p>
                </div>
                <div style="margin:20px 0;padding:15px;background:#fef3c7;border-left:4px solid #f59e0b;">
                    <p style="margin:0;color:#92400e;"><strong>PASSWORD INFORMATION:</strong> Passwords are encrypted and cannot be retrieved. Users can set their own password using the "Change Password" feature on the login page.</p>
                </div>
                <table style="width:100%;border-collapse:collapse;margin-top:20px;">
                    <thead>
                        <tr style="background:#085019;color:white;">
                            <th style="border:1px solid #ddd;padding:12px;text-align:left;">#</th>
                            <th style="border:1px solid #ddd;padding:12px;text-align:left;">Name</th>
                            <th style="border:1px solid #ddd;padding:12px;text-align:left;">Username</th>
                            <th style="border:1px solid #ddd;padding:12px;text-align:left;">Role</th>
                            <th style="border:1px solid #ddd;padding:12px;text-align:left;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${this.allUsers.map((user, i) => `
                            <tr style="background:${i % 2 === 0 ? '#f9f9f9' : 'white'};">
                                <td style="border:1px solid #ddd;padding:10px;">${i + 1}</td>
                                <td style="border:1px solid #ddd;padding:10px;">${user.FirstName} ${user.LastName}</td>
                                <td style="border:1px solid #ddd;padding:10px;font-family:monospace;font-weight:bold;">${user.Username}</td>
                                <td style="border:1px solid #ddd;padding:10px;">${user.Role.replace(/_/g, ' ')}</td>
                                <td style="border:1px solid #ddd;padding:10px;">
                                    <span style="color:${user.IsActive == 1 ? '#059669' : '#dc2626'};font-weight:bold;">
                                        ${user.IsActive == 1 ? 'Active' : 'Inactive'}
                                    </span>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                <div style="margin-top:40px;padding-top:20px;border-top:2px solid #ddd;">
                    <p style="margin:5px 0;"><strong>Total:</strong> ${this.allUsers.length}</p>
                    <p style="margin:5px 0;"><strong>Active:</strong> ${this.allUsers.filter(u => u.IsActive == 1).length}</p>
                    <p style="margin:5px 0;"><strong>Inactive:</strong> ${this.allUsers.filter(u => u.IsActive == 0).length}</p>
                </div>
                <div style="margin-top:30px;padding:15px;background:#eff6ff;border-left:4px solid #3b82f6;">
                    <p style="margin:0 0 10px 0;color:#1e40af;font-weight:bold;">Instructions for Users:</p>
                    <ol style="margin:0;padding-left:20px;color:#1e40af;">
                        <li>Go to the login page</li>
                        <li>Click "Change Password" at the bottom</li>
                        <li>Enter your username and the temporary password given to you</li>
                        <li>Create a new secure password (minimum 6 characters)</li>
                        <li>Login with your username and new password</li>
                    </ol>
                </div>
                <div style="margin-top:20px;padding:15px;background:#fef3c7;border-left:4px solid #f59e0b;">
                    <p style="margin:0;color:#92400e;"><strong>CONFIDENTIAL:</strong> Handle with care and store securely.</p>
                </div>
            </div>`;

        const printableDiv = document.getElementById('printableContent');
        if (printableDiv) {
            printableDiv.innerHTML      = printContent;
            printableDiv.style.display  = 'block';
            window.print();
            setTimeout(() => { printableDiv.style.display = 'none'; }, 100);
        }
    }

    // -------------------------------------------------------------------------
    // Event listeners
    // -------------------------------------------------------------------------
    initializeEventListeners() {
        document.getElementById('searchInput')?.addEventListener('input',  () => this.filterUsers());
        document.getElementById('roleFilter')?.addEventListener('change',  () => this.filterUsers());
        document.getElementById('statusFilter')?.addEventListener('change', () => this.filterUsers());

        document.getElementById('createUserForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = {
                Username:  document.getElementById('username')?.value,
                Password:  document.getElementById('password')?.value,
                FirstName: document.getElementById('firstName')?.value,
                LastName:  document.getElementById('lastName')?.value,
                Role:      document.getElementById('role')?.value,
                IsActive:  1
            };
            await this.createUser(formData);
        });
    }
}