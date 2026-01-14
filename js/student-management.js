// =====================================================
// Student Management Handler
// File: js/student-management.js
// =====================================================

class StudentManagementHandler {
    constructor() {
        this.currentUser = null;
        this.students = [];
        this.filteredStudents = [];
        this.currentPage = 1;
        this.itemsPerPage = 10;
        this.selectedStudents = new Set();
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

        console.log('Student Management initialized for user:', this.currentUser);

        this.bindEventListeners();
        this.loadStudents();
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
        // Search input
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.handleSearch(e.target.value);
            });
        }

        // Filter dropdowns
        const gradeFilter = document.getElementById('grade-level');
        const sectionFilter = document.getElementById('section');
        const statusFilter = document.getElementById('enrollment-status');

        if (gradeFilter) gradeFilter.addEventListener('change', () => this.applyFilters());
        if (sectionFilter) sectionFilter.addEventListener('change', () => this.applyFilters());
        if (statusFilter) statusFilter.addEventListener('change', () => this.applyFilters());

        // Find buttons by their text content
        const buttons = document.querySelectorAll('button');
        buttons.forEach(btn => {
            const text = btn.textContent.trim();
            if (text === 'Apply Filters') {
                btn.addEventListener('click', () => this.applyFilters());
            } else if (text === 'Clear All') {
                btn.addEventListener('click', () => this.clearFilters());
            } else if (text.includes('Add New Student')) {
                btn.addEventListener('click', () => this.addNewStudent());
            }
        });

        // Select all checkbox
        const selectAllCheckbox = document.querySelector('thead input[type="checkbox"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.handleSelectAll(e.target.checked);
            });
        }

        // Pagination buttons - will be bound in updatePagination
    }

    async loadStudents() {
        try {
            this.showLoading(true);

            const response = await fetch('../backend/api/students.php?action=list', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            console.log('Loaded students:', result);

            if (result.success) {
                this.students = result.data || [];
                this.filteredStudents = [...this.students];
                this.renderTable();
                this.updatePagination();
            } else {
                throw new Error(result.message || 'Failed to load students');
            }

        } catch (error) {
            console.error('Error loading students:', error);
            // Show sample data if backend fails
            this.loadSampleData();
        } finally {
            this.showLoading(false);
        }
    }

    loadSampleData() {
        // Fallback sample data if API fails
        this.students = [
            {
                StudentID: 1,
                LRN: '123456789012',
                FullName: 'Dela Cruz, Juan Santos',
                FirstName: 'Juan',
                LastName: 'Dela Cruz',
                GradeLevel: 'Grade 10',
                Section: 'A',
                Status: 'Active',
                EnrollmentStatus: 'Enrolled'
            },
            {
                StudentID: 2,
                LRN: '123456789013',
                FullName: 'Garcia, Maria Reyes',
                FirstName: 'Maria',
                LastName: 'Garcia',
                GradeLevel: 'Grade 11',
                Section: 'B',
                Status: 'Active',
                EnrollmentStatus: 'Enrolled'
            },
            {
                StudentID: 3,
                LRN: '123456789014',
                FullName: 'Santos, Pedro Lopez',
                FirstName: 'Pedro',
                LastName: 'Santos',
                GradeLevel: 'Grade 9',
                Section: 'C',
                Status: 'Active',
                EnrollmentStatus: 'Pending'
            },
            {
                StudentID: 4,
                LRN: '123456789015',
                FullName: 'Reyes, Ana Maria',
                FirstName: 'Ana',
                LastName: 'Reyes',
                GradeLevel: 'Grade 12',
                Section: 'A',
                Status: 'Active',
                EnrollmentStatus: 'Enrolled'
            }
        ];
        this.filteredStudents = [...this.students];
        this.renderTable();
        this.updatePagination();
        console.log('Loaded sample data');
    }

    handleSearch(searchTerm) {
        searchTerm = searchTerm.toLowerCase().trim();
        
        if (!searchTerm) {
            this.filteredStudents = [...this.students];
        } else {
            this.filteredStudents = this.students.filter(student => {
                const fullName = student.FullName.toLowerCase();
                const lrn = student.LRN ? student.LRN.toLowerCase() : '';
                const studentId = student.StudentID.toString();
                
                return fullName.includes(searchTerm) || 
                       lrn.includes(searchTerm) || 
                       studentId.includes(searchTerm);
            });
        }
        
        this.currentPage = 1;
        this.renderTable();
        this.updatePagination();
    }

    applyFilters() {
        const gradeFilter = document.getElementById('grade-level')?.value;
        const sectionFilter = document.getElementById('section')?.value;
        const statusFilter = document.getElementById('enrollment-status')?.value;

        this.filteredStudents = this.students.filter(student => {
            let matches = true;

            if (gradeFilter && gradeFilter !== 'All Grades') {
                const gradeNum = student.GradeLevel?.match(/\d+/)?.[0];
                if (gradeNum !== gradeFilter) matches = false;
            }

            if (sectionFilter && sectionFilter !== 'All Sections') {
                if (student.Section !== sectionFilter) matches = false;
            }

            if (statusFilter && statusFilter !== 'All Statuses') {
                const status = student.EnrollmentStatus || student.Status;
                if (status !== statusFilter) matches = false;
            }

            return matches;
        });

        this.currentPage = 1;
        this.renderTable();
        this.updatePagination();
    }

    clearFilters() {
        const searchInput = document.getElementById('search');
        const gradeFilter = document.getElementById('grade-level');
        const sectionFilter = document.getElementById('section');
        const statusFilter = document.getElementById('enrollment-status');

        if (searchInput) searchInput.value = '';
        if (gradeFilter) gradeFilter.selectedIndex = 0;
        if (sectionFilter) sectionFilter.selectedIndex = 0;
        if (statusFilter) statusFilter.selectedIndex = 0;
        
        this.filteredStudents = [...this.students];
        this.currentPage = 1;
        this.renderTable();
        this.updatePagination();
    }

    renderTable() {
        const tbody = document.querySelector('tbody');
        if (!tbody) return;

        const start = (this.currentPage - 1) * this.itemsPerPage;
        const end = start + this.itemsPerPage;
        const pageStudents = this.filteredStudents.slice(start, end);

        if (pageStudents.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        <div class="flex flex-col items-center gap-3">
                            <span class="material-symbols-outlined text-6xl text-gray-300">inbox</span>
                            <p class="text-lg font-medium">No students found</p>
                            <p class="text-sm">Try adjusting your search or filters</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = pageStudents.map(student => `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                    <input 
                        class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700" 
                        type="checkbox"
                        data-student-id="${student.StudentID}"
                        ${this.selectedStudents.has(student.StudentID) ? 'checked' : ''}
                    />
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${student.LRN || 'N/A'}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white">
                    ${this.formatName(student.FullName)}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${this.extractGradeNumber(student.GradeLevel)}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${student.Section || '-'}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm">
                    ${this.getStatusBadge(student.EnrollmentStatus || student.Status)}
                </td>
                <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                    <div class="flex items-center justify-end gap-2">
                        <button 
                            class="text-gray-400 hover:text-primary dark:hover:text-primary transition-colors"
                            onclick="studentManagement.viewStudent(${student.StudentID})"
                            title="View Details">
                            <span class="material-symbols-outlined" style="font-size:20px;">visibility</span>
                        </button>
                        <button 
                            class="text-gray-400 hover:text-primary dark:hover:text-primary transition-colors"
                            onclick="studentManagement.editStudent(${student.StudentID})"
                            title="Edit">
                            <span class="material-symbols-outlined" style="font-size:20px;">edit</span>
                        </button>
                        <button 
                            class="text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                            onclick="studentManagement.showMoreOptions(${student.StudentID})"
                            title="More Options">
                            <span class="material-symbols-outlined" style="font-size:20px;">more_vert</span>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        // Bind checkbox events
        tbody.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const studentId = parseInt(e.target.dataset.studentId);
                if (e.target.checked) {
                    this.selectedStudents.add(studentId);
                } else {
                    this.selectedStudents.delete(studentId);
                }
            });
        });
    }

    formatName(fullName) {
        if (!fullName) return 'N/A';
        // Input: "Dela Cruz, Juan Santos"
        // Output: "Juan Santos Dela Cruz"
        const parts = fullName.split(',').map(p => p.trim());
        if (parts.length === 2) {
            return `${parts[1]} ${parts[0]}`;
        }
        return fullName;
    }

    extractGradeNumber(gradeLevel) {
        if (!gradeLevel) return 'N/A';
        const match = gradeLevel.match(/\d+/);
        return match ? match[0] : gradeLevel;
    }

    getStatusBadge(status) {
        const statusMap = {
            'Enrolled': { class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', text: 'Enrolled' },
            'Active': { class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', text: 'Enrolled' },
            'Confirmed': { class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', text: 'Enrolled' },
            'Pending': { class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300', text: 'Pending' },
            'Withdrawn': { class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', text: 'Withdrawn' },
            'Cancelled': { class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', text: 'Cancelled' }
        };

        const config = statusMap[status] || statusMap['Pending'];
        return `<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.class}">${config.text}</span>`;
    }

    updatePagination() {
        const totalPages = Math.ceil(this.filteredStudents.length / this.itemsPerPage);
        const start = (this.currentPage - 1) * this.itemsPerPage + 1;
        const end = Math.min(start + this.itemsPerPage - 1, this.filteredStudents.length);

        // Update pagination text
        const paginationText = document.querySelector('nav[aria-label="Pagination"] p');
        if (paginationText) {
            paginationText.innerHTML = `
                Showing
                <span class="font-medium">${this.filteredStudents.length > 0 ? start : 0}</span>
                to
                <span class="font-medium">${end}</span>
                of
                <span class="font-medium">${this.filteredStudents.length}</span>
                results
            `;
        }

        // Find and update prev/next buttons
        const paginationLinks = document.querySelectorAll('nav[aria-label="Pagination"] a');
        paginationLinks.forEach(link => {
            const text = link.textContent.trim();
            
            if (text === 'Previous') {
                link.onclick = (e) => {
                    e.preventDefault();
                    if (this.currentPage > 1) {
                        this.currentPage--;
                        this.renderTable();
                        this.updatePagination();
                    }
                };
                
                // Disable/enable button
                if (this.currentPage === 1) {
                    link.classList.add('opacity-50', 'cursor-not-allowed');
                    link.style.pointerEvents = 'none';
                } else {
                    link.classList.remove('opacity-50', 'cursor-not-allowed');
                    link.style.pointerEvents = 'auto';
                }
                
            } else if (text === 'Next') {
                link.onclick = (e) => {
                    e.preventDefault();
                    if (this.currentPage < totalPages) {
                        this.currentPage++;
                        this.renderTable();
                        this.updatePagination();
                    }
                };
                
                // Disable/enable button
                if (this.currentPage === totalPages || totalPages === 0) {
                    link.classList.add('opacity-50', 'cursor-not-allowed');
                    link.style.pointerEvents = 'none';
                } else {
                    link.classList.remove('opacity-50', 'cursor-not-allowed');
                    link.style.pointerEvents = 'auto';
                }
            }
        });
    }

    handleSelectAll(checked) {
        const start = (this.currentPage - 1) * this.itemsPerPage;
        const end = start + this.itemsPerPage;
        const pageStudents = this.filteredStudents.slice(start, end);

        pageStudents.forEach(student => {
            if (checked) {
                this.selectedStudents.add(student.StudentID);
            } else {
                this.selectedStudents.delete(student.StudentID);
            }
        });

        this.renderTable();
    }

    viewStudent(studentId) {
        console.log('View student:', studentId);
        alert(`View student details for ID: ${studentId}\n\nThis will open a modal with complete student information.`);
    }

    editStudent(studentId) {
        console.log('Edit student:', studentId);
        alert(`Edit student ID: ${studentId}\n\nThis will open an edit form.`);
    }

    showMoreOptions(studentId) {
        console.log('More options for student:', studentId);
        const options = [
            'View Full Details',
            'Edit Information',
            'View Grades',
            'Print Student Card',
            'Transfer Section',
            'Mark as Withdrawn'
        ];
        alert(`More options for student ID: ${studentId}\n\nOptions:\n${options.join('\n')}`);
    }

    addNewStudent() {
        console.log('Add new student');
        window.location.href = 'enrollment-management.html';
    }

    showLoading(show) {
        console.log('Loading:', show);
    }
}

// Initialize and expose globally
let studentManagement;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        studentManagement = new StudentManagementHandler();
    });
} else {
    studentManagement = new StudentManagementHandler();
}