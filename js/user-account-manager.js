class UserAccountManager {
    constructor(currentUserRole) {
        this.currentUserRole = currentUserRole;
        this.allUsers = [];
        this.employeesWithoutAccount = [];
        this.hasAdminAccount = false;
    }

    // Helper function for initials
    getInitials(firstName, lastName) {
        return (firstName?.charAt(0) || '') + (lastName?.charAt(0) || '');
    }

    async loadStats() {
        try {
            const response = await fetch('../backend/api/users.php?action=stats');
            const result = await response.json();
            
            if (result.success) {
                const stats = result.data;
                document.getElementById('totalAccounts').textContent = stats.total || 0;
                document.getElementById('activeAccounts').textContent = stats.active || 0;
                document.getElementById('inactiveAccounts').textContent = stats.inactive || 0;
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
        
        // Load employees without account for stats
        try {
            const response = await fetch('../backend/api/users.php?action=employees-without-account');
            const result = await response.json();
            if (result.success) {
                document.getElementById('withoutAccount').textContent = result.count || 0;
            }
        } catch (error) {
            console.error('Error loading employee count:', error);
        }
    }

    async loadUsers() {
        try {
            const response = await fetch('../backend/api/users.php?action=all');
            const result = await response.json();
            
            if (result.success) {
                this.allUsers = result.data;
                
                // Check if there's an Admin account
                this.hasAdminAccount = this.allUsers.some(user => user.Role === 'Admin');
                
                this.displayUsers(this.allUsers);
            }
        } catch (error) {
            console.error('Error loading users:', error);
        }
    }

    async loadEmployeesWithoutAccount() {
        try {
            const response = await fetch('../backend/api/users.php?action=employees-without-account');
            const result = await response.json();
            
            if (result.success) {
                this.employeesWithoutAccount = result.data;
                this.populateEmployeeSelect();
            }
        } catch (error) {
            console.error('Error loading employees:', error);
        }
    }

    populateEmployeeSelect() {
        const select = document.getElementById('employeeSelect');
        if (!select) return;
        
        select.innerHTML = '<option value="">No Employee Link</option>';
        
        this.employeesWithoutAccount.forEach(emp => {
            const option = document.createElement('option');
            option.value = emp.EmployeeID;
            option.textContent = `${emp.FullName} - ${emp.Position}`;
            select.appendChild(option);
        });
    }

    displayUsers(users) {
        const tbody = document.getElementById('userTableBody');
        if (!tbody) return;
        
        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No user accounts found</td></tr>';
            return;
        }
        
        tbody.innerHTML = users.map(user => {
            const roleColors = {
                'Admin': 'bg-red-100 text-red-800',
                'ICT_Coordinator': 'bg-blue-100 text-blue-800',
                'Registrar': 'bg-purple-100 text-purple-800',
                'Adviser': 'bg-green-100 text-green-800',
                'Key_Teacher': 'bg-yellow-100 text-yellow-800',
                'Subject_Teacher': 'bg-gray-100 text-gray-800'
            };
            
            return `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold">
                                ${this.getInitials(user.FirstName, user.LastName)}
                            </div>
                            <div>
                                <div class="text-sm font-medium">${user.FirstName} ${user.LastName}</div>
                                ${user.Email ? `<div class="text-xs text-gray-500">${user.Email}</div>` : ''}
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm">${user.Username}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full ${roleColors[user.Role] || 'bg-gray-100 text-gray-800'}">
                            ${user.Role.replace('_', ' ')}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        ${user.EmployeeID ? `
                            <div class="text-xs">
                                <div class="font-medium">${user.Position || 'N/A'}</div>
                                <div class="text-gray-500">${user.Department || 'N/A'}</div>
                            </div>
                        ` : '<span class="text-gray-400">No link</span>'}
                    </td>
                    <td class="px-6 py-4">
                        ${user.IsActive == 1 ? 
                            '<span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800">Active</span>' :
                            '<span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-800">Inactive</span>'
                        }
                    </td>
                    <td class="px-6 py-4 text-right">
                        <button onclick="userManager.toggleStatus(${user.UserID})" class="p-2 text-gray-600 hover:text-primary" title="${user.IsActive == 1 ? 'Deactivate' : 'Activate'}">
                            <span class="material-symbols-outlined">${user.IsActive == 1 ? 'toggle_on' : 'toggle_off'}</span>
                        </button>
                        <button onclick="userManager.resetPassword(${user.UserID}, '${user.Username}')" class="p-2 text-gray-600 hover:text-primary" title="Reset Password">
                            <span class="material-symbols-outlined">lock_reset</span>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    filterUsers() {
        const search = document.getElementById('searchInput')?.value.toLowerCase() || '';
        const role = document.getElementById('roleFilter')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        
        const filtered = this.allUsers.filter(user => {
            const matchSearch = user.FirstName.toLowerCase().includes(search) ||
                              user.LastName.toLowerCase().includes(search) ||
                              user.Username.toLowerCase().includes(search) ||
                              user.Role.toLowerCase().includes(search);
            const matchRole = !role || user.Role === role;
            const matchStatus = status === '' || user.IsActive == status;
            
            return matchSearch && matchRole && matchStatus;
        });
        
        this.displayUsers(filtered);
    }

    openCreateModal() {
        document.getElementById('createModal')?.classList.remove('hidden');
        document.getElementById('createUserForm')?.reset();
        
        // Disable Admin option if an Admin account already exists
        const roleSelect = document.getElementById('role');
        const adminOption = roleSelect?.querySelector('option[value="Admin"]');
        
        if (adminOption) {
            if (this.hasAdminAccount) {
                adminOption.disabled = true;
                adminOption.textContent = 'Admin (Already exists - Principal only)';
            } else {
                adminOption.disabled = false;
                adminOption.textContent = 'Admin';
            }
        }
    }

    closeCreateModal() {
        document.getElementById('createModal')?.classList.add('hidden');
    }

    async createUser(formData) {
        if (formData.Password.length < 6) {
            alert('Password must be at least 6 characters long');
            return false;
        }
        
        try {
            const response = await fetch('../backend/api/users.php?action=create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(formData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('User account created successfully!');
                this.closeCreateModal();
                await this.loadStats();
                await this.loadUsers();
                await this.loadEmployeesWithoutAccount();
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

    async toggleStatus(userId) {
        if (!confirm('Are you sure you want to change this user\'s status?')) return;
        
        try {
            const response = await fetch('../backend/api/users.php?action=toggle-status', {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
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

    async resetPassword(userId, username) {
        const newPassword = prompt(`Enter new password for ${username}:`);
        if (!newPassword) return;
        
        if (newPassword.length < 6) {
            alert('Password must be at least 6 characters long');
            return;
        }
        
        try {
            const response = await fetch('../backend/api/users.php?action=reset-password', {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ UserID: userId, NewPassword: newPassword })
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('Password reset successfully!');
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error resetting password:', error);
            alert('Failed to reset password');
        }
    }

    downloadAccountList() {
        if (this.allUsers.length === 0) {
            alert('No accounts to download');
            return;
        }

        // Create printable HTML content
        const printContent = `
            <div style="padding: 40px; font-family: Arial, sans-serif;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="margin: 0; color: #085019;">Don Jose Integrated High School</h1>
                    <h2 style="margin: 10px 0 0 0; font-weight: normal;">User Account List</h2>
                    <p style="margin: 5px 0; color: #666;">Generated on ${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                </div>
                
                <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                    <thead>
                        <tr style="background-color: #085019; color: white;">
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">#</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Name</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Username</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Role</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Position</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${this.allUsers.map((user, index) => `
                            <tr style="background-color: ${index % 2 === 0 ? '#f9f9f9' : 'white'};">
                                <td style="border: 1px solid #ddd; padding: 10px;">${index + 1}</td>
                                <td style="border: 1px solid #ddd; padding: 10px;">${user.FirstName} ${user.LastName}</td>
                                <td style="border: 1px solid #ddd; padding: 10px; font-family: monospace;">${user.Username}</td>
                                <td style="border: 1px solid #ddd; padding: 10px;">${user.Role.replace('_', ' ')}</td>
                                <td style="border: 1px solid #ddd; padding: 10px;">${user.Position || 'N/A'}</td>
                                <td style="border: 1px solid #ddd; padding: 10px;">
                                    <span style="color: ${user.IsActive == 1 ? '#059669' : '#dc2626'}; font-weight: bold;">
                                        ${user.IsActive == 1 ? 'Active' : 'Inactive'}
                                    </span>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                
                <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd;">
                    <p style="margin: 5px 0;"><strong>Total Accounts:</strong> ${this.allUsers.length}</p>
                    <p style="margin: 5px 0;"><strong>Active:</strong> ${this.allUsers.filter(u => u.IsActive == 1).length}</p>
                    <p style="margin: 5px 0;"><strong>Inactive:</strong> ${this.allUsers.filter(u => u.IsActive == 0).length}</p>
                </div>
                
                <div style="margin-top: 30px; padding: 15px; background-color: #fef3c7; border-left: 4px solid #f59e0b;">
                    <p style="margin: 0; color: #92400e;"><strong>⚠️ CONFIDENTIAL:</strong> This document contains sensitive login credentials. Please handle with care and store securely.</p>
                </div>
            </div>
        `;

        // Set content and print
        const printableDiv = document.getElementById('printableContent');
        if (printableDiv) {
            printableDiv.innerHTML = printContent;
            printableDiv.style.display = 'block';
            
            window.print();
            
            // Hide after printing
            setTimeout(() => {
                printableDiv.style.display = 'none';
            }, 100);
        }
    }

    // Initialize event listeners
    initializeEventListeners() {
        // Search and filter listeners
        document.getElementById('searchInput')?.addEventListener('input', () => this.filterUsers());
        document.getElementById('roleFilter')?.addEventListener('change', () => this.filterUsers());
        document.getElementById('statusFilter')?.addEventListener('change', () => this.filterUsers());

        // Employee select auto-fill
        document.getElementById('employeeSelect')?.addEventListener('change', (e) => {
            const employeeId = e.target.value;
            if (employeeId) {
                const employee = this.employeesWithoutAccount.find(emp => emp.EmployeeID == employeeId);
                if (employee) {
                    const firstNameInput = document.getElementById('firstName');
                    const lastNameInput = document.getElementById('lastName');
                    if (firstNameInput) firstNameInput.value = employee.FirstName;
                    if (lastNameInput) lastNameInput.value = employee.LastName;
                }
            }
        });

        // Create user form submission
        document.getElementById('createUserForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = {
                EmployeeID: document.getElementById('employeeSelect')?.value || null,
                Username: document.getElementById('username')?.value,
                Password: document.getElementById('password')?.value,
                FirstName: document.getElementById('firstName')?.value,
                LastName: document.getElementById('lastName')?.value,
                Role: document.getElementById('role')?.value,
                IsActive: 1
            };
            
            await this.createUser(formData);
        });
    }

    // Get available roles based on current user role
    getAvailableRoles() {
        if (this.currentUserRole === 'ICT_Coordinator') {
            return ['Admin', 'Adviser', 'Key_Teacher', 'ICT_Coordinator', 'Registrar', 'Subject_Teacher'];
        } else if (this.currentUserRole === 'Registrar') {
            // Registrar has limited role creation permissions
            return ['Adviser', 'Subject_Teacher'];
        }
        return [];
    }
}