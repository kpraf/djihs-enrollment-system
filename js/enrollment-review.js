// =====================================================
// Enrollment Review Handler
// File: js/enrollment-review.js
// =====================================================

class EnrollmentReviewHandler {
    constructor() {
        this.currentUser = null;
        this.enrollments = [];
        this.filteredEnrollments = [];
        this.selectedEnrollment = null;
        this.init();
    }

    init() {
        // Get current user
        this.currentUser = this.getCurrentUser();
        
        if (!this.currentUser) {
            alert('You must be logged in to access this page');
            window.location.href = '../login.html';
            return;
        }

        console.log('Review handler initialized for user:', this.currentUser);

        this.bindEventListeners();
        this.loadPendingEnrollments();
    }

    getCurrentUser() {
        const userDataString = localStorage.getItem('user');
        if (!userDataString) return null;
        
        try {
            return JSON.parse(userDataString);
        } catch (error) {
            console.error('Error parsing user data:', error);
            return null;
        }
    }

    bindEventListeners() {
        // Refresh button
        document.getElementById('btnRefresh')?.addEventListener('click', () => {
            this.loadPendingEnrollments();
        });

        // Approve All button
        document.getElementById('btnApproveAll')?.addEventListener('click', () => {
            this.approveAllEnrollments();
        });

        // Filters
        document.getElementById('filterGrade')?.addEventListener('change', () => {
            this.applyFilters();
        });

        document.getElementById('filterStrand')?.addEventListener('change', () => {
            this.applyFilters();
        });

        // Modal close buttons
        document.getElementById('closeModal')?.addEventListener('click', () => {
            this.closeModal();
        });

        document.getElementById('btnCloseModal')?.addEventListener('click', () => {
            this.closeModal();
        });

        // Approve/Reject buttons
        document.getElementById('btnApprove')?.addEventListener('click', () => {
            this.approveEnrollment();
        });

        document.getElementById('btnReject')?.addEventListener('click', () => {
            this.rejectEnrollment();
        });
    }

    async loadPendingEnrollments() {
        try {
            this.showLoading(true);

            const response = await fetch('../backend/api/enrollment.php?action=pending', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            console.log('Loaded enrollments:', result);

            if (result.success) {
                this.enrollments = result.data || [];
                this.filteredEnrollments = [...this.enrollments];
                this.updateStats();
                this.renderTable();
            } else {
                throw new Error(result.message || 'Failed to load enrollments');
            }

        } catch (error) {
            console.error('Error loading enrollments:', error);
            alert('Error loading enrollments: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    updateStats() {
        const pending = this.enrollments.filter(e => e.Status === 'Pending').length;
        
        const pendingCountEl = document.getElementById('pendingCount');
        const approvedCountEl = document.getElementById('approvedCount');
        const totalCountEl = document.getElementById('totalCount');
        
        if (pendingCountEl) pendingCountEl.textContent = pending;
        if (approvedCountEl) approvedCountEl.textContent = '0'; // TODO: Get from API
        if (totalCountEl) totalCountEl.textContent = '0'; // TODO: Get from API

        const badge = document.getElementById('pendingBadge');
        if (badge) {
            if (pending > 0) {
                badge.textContent = pending;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
    }

    applyFilters() {
        const gradeFilter = document.getElementById('filterGrade').value;
        const strandFilter = document.getElementById('filterStrand').value;

        this.filteredEnrollments = this.enrollments.filter(enrollment => {
            let matches = true;

            if (gradeFilter && enrollment.GradeLevelName !== gradeFilter) {
                matches = false;
            }

            if (strandFilter && !enrollment.StrandName?.includes(strandFilter)) {
                matches = false;
            }

            return matches;
        });

        this.renderTable();
    }

    renderTable() {
        const tbody = document.getElementById('enrollmentsBody');
        const loadingState = document.getElementById('loadingState');
        const emptyState = document.getElementById('emptyState');
        const table = document.getElementById('enrollmentsTable');

        loadingState.classList.add('hidden');

        if (this.filteredEnrollments.length === 0) {
            emptyState.classList.remove('hidden');
            table.classList.add('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        table.classList.remove('hidden');

        tbody.innerHTML = this.filteredEnrollments.map(enrollment => `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center text-primary font-semibold mr-3">
                            ${this.getInitials(enrollment.StudentName)}
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900 dark:text-white">${enrollment.StudentName}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">${enrollment.AcademicYear}</div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900 dark:text-white">${enrollment.LRN || 'N/A'}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900 dark:text-white">${enrollment.GradeLevelName}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900 dark:text-white">${enrollment.StrandName || '-'}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${this.getLearnerTypeBadgeClass(enrollment.LearnerType)}">
                        ${this.formatLearnerType(enrollment.LearnerType)}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900 dark:text-white">${this.formatDate(enrollment.EnrollmentDate)}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <button onclick="enrollmentReview.viewDetails(${enrollment.EnrollmentID})" 
                            class="text-primary hover:text-primary/80 font-medium flex items-center gap-1">
                        <span class="material-icons-outlined text-[18px]">visibility</span>
                        View
                    </button>
                </td>
            </tr>
        `).join('');
    }

    getInitials(name) {
        const parts = name.split(',').map(p => p.trim());
        if (parts.length >= 2) {
            return parts[0].charAt(0) + parts[1].charAt(0);
        }
        return name.split(' ').map(n => n.charAt(0)).join('').substring(0, 2);
    }

    getLearnerTypeBadgeClass(type) {
        if (type.includes('Regular')) {
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        } else {
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
        }
    }

    formatLearnerType(type) {
        return type.replace(/_/g, ' ');
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric' 
        });
    }

    async viewDetails(enrollmentID) {
        try {
            console.log('Loading details for enrollment:', enrollmentID);

            const response = await fetch(`../backend/api/enrollment.php?action=details&id=${enrollmentID}`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            console.log('Enrollment details:', result);

            if (result.success && result.data) {
                this.selectedEnrollment = result.data;
                this.showDetailsModal(result.data);
            } else {
                throw new Error(result.message || 'Failed to load enrollment details');
            }

        } catch (error) {
            console.error('Error loading details:', error);
            alert('Error loading enrollment details: ' + error.message);
        }
    }

    showDetailsModal(data) {
        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('modalContent');

        const fullAddress = [
            data.HouseNumber,
            data.SitioStreet,
            data.Barangay,
            data.Municipality,
            data.Province
        ].filter(Boolean).join(', ');

        content.innerHTML = `
            <div class="space-y-6">
                <!-- Student Information -->
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">person</span>
                        Student Information
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Full Name</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.FullName}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">LRN</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.LRN || 'Not provided'}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Birthdate</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${this.formatDate(data.DateOfBirth)}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Age</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.Age} years old</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Gender</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.Gender}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Religion</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.Religion || 'Not specified'}</p>
                        </div>
                    </div>
                </div>

                <!-- Enrollment Information -->
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">school</span>
                        Enrollment Information
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">School Year</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.AcademicYear}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Grade Level</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.GradeLevelName}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Strand/Track</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.StrandName || 'N/A'}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Learner Type</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${this.formatLearnerType(data.LearnerType)}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Enrollment Type</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.EnrollmentType}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                ${data.Status}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">home</span>
                        Address
                    </h4>
                    <p class="text-sm text-gray-900 dark:text-white">${fullAddress}</p>
                </div>

                <!-- Parent/Guardian Information -->
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">family_restroom</span>
                        Parent/Guardian Information
                    </h4>
                    <div class="space-y-3">
                        ${data.FatherFirstName ? `
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Father's Name</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    ${[data.FatherLastName, data.FatherFirstName, data.FatherMiddleName].filter(Boolean).join(', ')}
                                </p>
                            </div>
                        ` : ''}
                        ${data.MotherFirstName ? `
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Mother's Name</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    ${[data.MotherLastName, data.MotherFirstName, data.MotherMiddleName].filter(Boolean).join(', ')}
                                </p>
                            </div>
                        ` : ''}
                        ${data.GuardianFirstName ? `
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Legal Guardian</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    ${[data.GuardianLastName, data.GuardianFirstName, data.GuardianMiddleName].filter(Boolean).join(', ')}
                                </p>
                            </div>
                        ` : ''}
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Contact Number</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">${data.ContactNumber}</p>
                        </div>
                    </div>
                </div>

                <!-- Special Conditions -->
                ${data.IsIPCommunity || data.IsPWD ? `
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4 border border-yellow-200 dark:border-yellow-800">
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                            <span class="material-icons-outlined text-warning">info</span>
                            Special Considerations
                        </h4>
                        ${data.IsIPCommunity ? `
                            <p class="text-sm text-gray-900 dark:text-white mb-2">
                                <strong>IP Community Member:</strong> ${data.IPCommunitySpecify || 'Yes'}
                            </p>
                        ` : ''}
                        ${data.IsPWD ? `
                            <p class="text-sm text-gray-900 dark:text-white">
                                <strong>Person with Disability:</strong> ${data.PWDSpecify || 'Yes'}
                            </p>
                        ` : ''}
                    </div>
                ` : ''}
            </div>

            ${data.Weight || data.Height ? `
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">fitness_center</span>
                        Physical Information
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        ${data.Weight ? `
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Weight</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    ${data.Weight} kg
                                </p>
                            </div>
                        ` : ''}
                        ${data.Height ? `
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Height</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    ${data.Height} m
                                </p>
                            </div>
                        ` : ''}
                    </div>
                </div>
            ` : ''}

            ${data.Is4PsBeneficiary ? `
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                    <p class="text-sm text-gray-900 dark:text-white">
                        <strong>4Ps Beneficiary:</strong> Yes
                    </p>
                </div>
            ` : ''}
            
        `;
        

        modal.classList.remove('hidden');
    }

    closeModal() {
        document.getElementById('detailsModal').classList.add('hidden');
        this.selectedEnrollment = null;
    }

    async approveEnrollment() {
        if (!this.selectedEnrollment) {
            alert('No enrollment selected');
            return;
        }

        if (!confirm(`Approve enrollment for ${this.selectedEnrollment.FullName}?`)) {
            return;
        }

        try {
            // For now, just update status to Confirmed
            // In future, this will create section assignment
            const response = await fetch('../backend/api/enrollment.php?action=approve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    enrollmentID: this.selectedEnrollment.EnrollmentID,
                    reviewerID: this.currentUser.UserID
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('✓ Enrollment approved successfully!');
                this.closeModal();
                this.loadPendingEnrollments();
            } else {
                throw new Error(result.message || 'Failed to approve enrollment');
            }

        } catch (error) {
            console.error('Error approving enrollment:', error);
            alert('Error: ' + error.message);
        }
    }

    async approveAllEnrollments() {
        const pendingEnrollments = this.filteredEnrollments.filter(e => e.Status === 'Pending');
        
        if (pendingEnrollments.length === 0) {
            alert('No pending enrollments to approve');
            return;
        }

        const message = `Are you sure you want to approve all ${pendingEnrollments.length} pending enrollment${pendingEnrollments.length > 1 ? 's' : ''}?\n\nThis action cannot be undone.`;
        
        if (!confirm(message)) {
            return;
        }

        try {
            // Show loading state
            const approveAllBtn = document.getElementById('btnApproveAll');
            const originalText = approveAllBtn.innerHTML;
            approveAllBtn.disabled = true;
            approveAllBtn.innerHTML = '<span class="material-icons-outlined text-[18px] animate-spin">sync</span> Processing...';

            let successCount = 0;
            let failCount = 0;

            // Process each enrollment
            for (const enrollment of pendingEnrollments) {
                try {
                    const response = await fetch('../backend/api/enrollment.php?action=approve', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            enrollmentID: enrollment.EnrollmentID,
                            reviewerID: this.currentUser.UserID
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        successCount++;
                    } else {
                        failCount++;
                        console.error(`Failed to approve enrollment ${enrollment.EnrollmentID}:`, result.message);
                    }

                } catch (error) {
                    failCount++;
                    console.error(`Error approving enrollment ${enrollment.EnrollmentID}:`, error);
                }
            }

            // Show results
            let resultMessage = `✓ Approved ${successCount} enrollment${successCount !== 1 ? 's' : ''} successfully!`;
            if (failCount > 0) {
                resultMessage += `\n⚠ Failed to approve ${failCount} enrollment${failCount !== 1 ? 's' : ''}.`;
            }

            alert(resultMessage);

            // Restore button and reload
            approveAllBtn.disabled = false;
            approveAllBtn.innerHTML = originalText;
            this.loadPendingEnrollments();

        } catch (error) {
            console.error('Error in approve all:', error);
            alert('Error processing approvals: ' + error.message);
            
            // Restore button
            const approveAllBtn = document.getElementById('btnApproveAll');
            approveAllBtn.disabled = false;
            approveAllBtn.innerHTML = '<span class="material-icons-outlined text-[18px]">done_all</span> Approve All';
        }
    }

    async rejectEnrollment() {
        if (!this.selectedEnrollment) {
            alert('No enrollment selected');
            return;
        }

        const reason = prompt(`Reject enrollment for ${this.selectedEnrollment.FullName}?\n\nPlease provide a reason:`);
        
        if (!reason) {
            return;
        }

        try {
            const response = await fetch('../backend/api/enrollment.php?action=reject', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    enrollmentID: this.selectedEnrollment.EnrollmentID,
                    reviewerID: this.currentUser.UserID,
                    reason: reason
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Enrollment rejected');
                this.closeModal();
                this.loadPendingEnrollments();
            } else {
                throw new Error(result.message || 'Failed to reject enrollment');
            }

        } catch (error) {
            console.error('Error rejecting enrollment:', error);
            alert('Error: ' + error.message);
        }
    }

    showLoading(show) {
        const loadingState = document.getElementById('loadingState');
        if (show) {
            loadingState.classList.remove('hidden');
        } else {
            loadingState.classList.add('hidden');
        }
    }
}

// Initialize and expose globally
let enrollmentReview;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        enrollmentReview = new EnrollmentReviewHandler();
    });
} else {
    enrollmentReview = new EnrollmentReviewHandler();
}