// =====================================================
// Enhanced Student Management Handler
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
        this.strands = [];
        this.init();
    }

    init() {
        this.currentUser = this.getCurrentUser();
        
        if (!this.currentUser) {
            alert('You must be logged in to access this page');
            window.location.href = '../login.html';
            return;
        }

        this.bindEventListeners();
        this.loadStrands();
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
        // Search input - debounced
        const searchInput = document.getElementById('search');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.applyFilters();
                }, 300);
            });
        }

        // Filter dropdowns
        const filters = ['grade-level', 'section', 'enrollment-status', 'academic-year', 'strand'];
        filters.forEach(filterId => {
            const filter = document.getElementById(filterId);
            if (filter) filter.addEventListener('change', () => this.applyFilters());
        });

        // Buttons
        const applyBtn = document.querySelector('button[data-action="apply-filters"]');
        const clearBtn = document.querySelector('button[data-action="clear-filters"]');
        const addBtn = document.querySelector('button[data-action="add-student"]');

        if (applyBtn) applyBtn.addEventListener('click', () => this.applyFilters());
        if (clearBtn) clearBtn.addEventListener('click', () => this.clearFilters());
        if (addBtn) addBtn.addEventListener('click', () => this.addNewStudent());

        // Select all checkbox
        const selectAllCheckbox = document.querySelector('thead input[type="checkbox"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.handleSelectAll(e.target.checked);
            });
        }

        // Grade level change to show/hide strand filter
        const gradeFilter = document.getElementById('grade-level');
        if (gradeFilter) {
            gradeFilter.addEventListener('change', () => this.toggleStrandFilter());
        }
    }

    toggleStrandFilter() {
        const gradeFilter = document.getElementById('grade-level');
        const strandFilter = document.getElementById('strand');
        const strandContainer = strandFilter?.closest('.flex.flex-col');
        
        if (!gradeFilter || !strandContainer) return;

        const selectedGrade = gradeFilter.value;
        
        // Show strand filter only for grades 11 and 12
        if (selectedGrade === '11' || selectedGrade === '12') {
            strandContainer.classList.remove('hidden');
        } else {
            strandContainer.classList.add('hidden');
            if (strandFilter) strandFilter.selectedIndex = 0;
        }
    }

    async loadStrands() {
        try {
            const response = await fetch('../backend/api/students.php?action=get_strands');
            const result = await response.json();

            if (result.success) {
                this.strands = result.strands || [];
                this.populateStrandFilter();
            }
        } catch (error) {
            console.error('Error loading strands:', error);
        }
    }

    populateStrandFilter() {
        const strandFilter = document.getElementById('strand');
        if (!strandFilter) return;

        strandFilter.innerHTML = '<option value="">All Strands</option>';
        this.strands.forEach(strand => {
            strandFilter.innerHTML += `<option value="${strand.StrandName}">${strand.StrandCode} - ${strand.StrandName}</option>`;
        });
    }

    async loadStudents() {
        try {
            this.showLoading(true);

            const response = await fetch('../backend/api/students.php?action=list');
            const result = await response.json();

            if (result.success) {
                this.students = result.data || [];
                this.filteredStudents = [...this.students];
                this.populateFilterOptions();
                this.renderTable();
                this.updatePagination();
            } else {
                throw new Error(result.message || 'Failed to load students');
            }

        } catch (error) {
            console.error('Error loading students:', error);
            this.loadSampleData();
        } finally {
            this.showLoading(false);
        }
    }

    loadSampleData() {
        this.students = [
            {
                StudentID: 1,
                LRN: '123456789012',
                FullName: 'Dela Cruz, Juan Santos',
                GradeLevel: 'Grade 10',
                Section: 'A',
                Status: 'Active',
                EnrollmentStatus: 'Confirmed',
                AcademicYear: '2025-2026'
            }
        ];
        this.filteredStudents = [...this.students];
        this.populateFilterOptions();
        this.renderTable();
        this.updatePagination();
    }

    populateFilterOptions() {
        const grades = new Set();
        const sections = new Set();
        const statuses = new Set();
        const academicYears = new Set();

        this.students.forEach(student => {
            const gradeMatch = student.GradeLevel?.match(/\d+/);
            if (gradeMatch) grades.add(gradeMatch[0]);
            if (student.Section) sections.add(student.Section);
            if (student.EnrollmentStatus || student.Status) {
                statuses.add(student.EnrollmentStatus || student.Status);
            }
            if (student.AcademicYear) academicYears.add(student.AcademicYear);
        });

        // Populate Grade Level
        const gradeFilter = document.getElementById('grade-level');
        if (gradeFilter) {
            const currentValue = gradeFilter.value;
            gradeFilter.innerHTML = '<option value="">All Grades</option>';
            Array.from(grades).sort((a, b) => Number(a) - Number(b)).forEach(grade => {
                gradeFilter.innerHTML += `<option value="${grade}">${grade}</option>`;
            });
            if (currentValue) gradeFilter.value = currentValue;
        }

        // Populate Section
        const sectionFilter = document.getElementById('section');
        if (sectionFilter) {
            const currentValue = sectionFilter.value;
            sectionFilter.innerHTML = '<option value="">All Sections</option>';
            Array.from(sections).sort().forEach(section => {
                sectionFilter.innerHTML += `<option value="${section}">${section}</option>`;
            });
            if (currentValue) sectionFilter.value = currentValue;
        }

        // Populate Status
        const statusFilter = document.getElementById('enrollment-status');
        if (statusFilter) {
            const currentValue = statusFilter.value;
            statusFilter.innerHTML = '<option value="">All Statuses</option>';
            
            const statusMap = {
                'Confirmed': 'Enrolled',
                'Active': 'Enrolled',
                'Pending': 'Pending',
                'For_Review': 'For Review',
                'Cancelled': 'Cancelled',
                'Withdrawn': 'Withdrawn'
            };

            const displayStatuses = new Set();
            statuses.forEach(status => {
                displayStatuses.add(statusMap[status] || status);
            });

            Array.from(displayStatuses).sort().forEach(status => {
                statusFilter.innerHTML += `<option value="${status}">${status}</option>`;
            });
            
            if (currentValue) statusFilter.value = currentValue;
        }

        // Populate Academic Year
        const yearFilter = document.getElementById('academic-year');
        if (yearFilter) {
            const currentValue = yearFilter.value;
            yearFilter.innerHTML = '<option value="">All Years</option>';
            Array.from(academicYears).sort().reverse().forEach(year => {
                yearFilter.innerHTML += `<option value="${year}">${year}</option>`;
            });
            if (currentValue) yearFilter.value = currentValue;
        }
    }

    applyFilters() {
        const searchTerm = document.getElementById('search')?.value.toLowerCase().trim() || '';
        const gradeFilter = document.getElementById('grade-level')?.value;
        const sectionFilter = document.getElementById('section')?.value;
        const statusFilter = document.getElementById('enrollment-status')?.value;
        const yearFilter = document.getElementById('academic-year')?.value;
        const strandFilter = document.getElementById('strand')?.value;
        
        let results = [...this.students];

        // Search filter
        if (searchTerm) {
            results = results.filter(student => {
                const fullName = student.FullName?.toLowerCase() || '';
                const lrn = student.LRN?.toLowerCase() || '';
                const studentId = student.StudentID?.toString() || '';
                
                return fullName.includes(searchTerm) || 
                       lrn.includes(searchTerm) || 
                       studentId.includes(searchTerm);
            });
        }

        // Grade Level filter
        if (gradeFilter) {
            results = results.filter(student => {
                const gradeMatch = student.GradeLevel?.match(/\d+/);
                return gradeMatch && gradeMatch[0] === gradeFilter;
            });
        }

        // Section filter
        if (sectionFilter) {
            results = results.filter(student => student.Section === sectionFilter);
        }

        // Status filter
        if (statusFilter) {
            results = results.filter(student => {
                const studentStatus = student.EnrollmentStatus || student.Status;
                return this.normalizeStatus(studentStatus) === statusFilter;
            });
        }

        // Academic Year filter
        if (yearFilter) {
            results = results.filter(student => student.AcademicYear === yearFilter);
        }

        // Strand filter - FIXED: now using StrandName instead of StrandID
        if (strandFilter) {
            results = results.filter(student => student.StrandName === strandFilter);
        }

        this.filteredStudents = results;
        this.currentPage = 1;
        this.renderTable();
        this.updatePagination();
    }

    normalizeStatus(status) {
        const statusMap = {
            'Confirmed': 'Enrolled',
            'Active': 'Enrolled',
            'Pending': 'Pending',
            'For_Review': 'For Review',
            'Cancelled': 'Cancelled',
            'Withdrawn': 'Withdrawn'
        };
        return statusMap[status] || status;
    }

    clearFilters() {
        const inputs = ['search', 'grade-level', 'section', 'enrollment-status', 'academic-year', 'strand'];
        inputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (element.tagName === 'INPUT') {
                    element.value = '';
                } else {
                    element.selectedIndex = 0;
                }
            }
        });
        
        // Hide strand filter
        const strandContainer = document.getElementById('strand')?.closest('.flex.flex-col');
        if (strandContainer) strandContainer.classList.add('hidden');
        
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
                    <td colspan="8" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
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
                    ${student.StrandName ? student.StrandName + ' - ' : ''}${student.Section || '-'}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${student.AcademicYear || '-'}
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
                this.updateSelectAllCheckbox();
            });
        });

        this.updateSelectAllCheckbox();
    }

    updateSelectAllCheckbox() {
        const selectAllCheckbox = document.querySelector('thead input[type="checkbox"]');
        if (!selectAllCheckbox) return;

        const start = (this.currentPage - 1) * this.itemsPerPage;
        const end = start + this.itemsPerPage;
        const pageStudents = this.filteredStudents.slice(start, end);

        const allSelected = pageStudents.length > 0 && 
            pageStudents.every(s => this.selectedStudents.has(s.StudentID));
        
        const someSelected = pageStudents.some(s => this.selectedStudents.has(s.StudentID));

        selectAllCheckbox.checked = allSelected;
        selectAllCheckbox.indeterminate = someSelected && !allSelected;
    }

    formatName(fullName) {
        if (!fullName) return 'N/A';
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
        const normalizedStatus = this.normalizeStatus(status);
        
        const statusMap = {
            'Enrolled': { class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', text: 'Enrolled' },
            'Pending': { class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300', text: 'Pending' },
            'For Review': { class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300', text: 'For Review' },
            'Withdrawn': { class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', text: 'Withdrawn' },
            'Cancelled': { class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', text: 'Cancelled' }
        };

        const config = statusMap[normalizedStatus] || statusMap['Pending'];
        return `<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.class}">${config.text}</span>`;
    }

    updatePagination() {
        const totalPages = Math.ceil(this.filteredStudents.length / this.itemsPerPage);
        const start = (this.currentPage - 1) * this.itemsPerPage + 1;
        const end = Math.min(start + this.itemsPerPage - 1, this.filteredStudents.length);

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

    async viewStudent(studentId) {
        try {
            const response = await fetch(`../backend/api/students.php?action=details&id=${studentId}`);
            const result = await response.json();

            if (result.success) {
                this.showStudentDetailsModal(result.data);
            } else {
                alert('Failed to load student details');
            }
        } catch (error) {
            console.error('Error loading student details:', error);
            alert('Error loading student details');
        }
    }

    showStudentDetailsModal(student) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">Student Details</h2>
                    <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">LRN</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.LRN || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Full Name</label>
                            <p class="text-base text-gray-900 dark:text-white">${this.formatName(student.FullName || `${student.LastName}, ${student.FirstName}`)}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Date of Birth</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.DateOfBirth || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Age</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.Age || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Gender</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.Gender || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Contact Number</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.ContactNumber || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Grade Level</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.GradeLevelName || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Section</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.SectionName || 'Not Assigned'}</p>
                        </div>
                        ${student.StrandName ? `
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Strand</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.StrandCode} - ${student.StrandName}</p>
                        </div>` : ''}
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Academic Year</label>
                            <p class="text-base text-gray-900 dark:text-white">${student.AcademicYear || 'N/A'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</label>
                            <p class="text-base">${this.getStatusBadge(student.EnrollmentStatus)}</p>
                        </div>
                    </div>
                    <div class="flex gap-3 justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button onclick="studentManagement.editStudent(${student.StudentID}); this.closest('.fixed').remove();" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-green-700">
                            Edit Student
                        </button>
                        <button onclick="this.closest('.fixed').remove()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    editStudent(studentId) {
        alert(`Edit functionality for student ${studentId} - This would open an edit form`);
    }

    showMoreOptions(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">Student Actions</h2>
                </div>
                <div class="p-6 space-y-2">
                    <button onclick="studentManagement.viewStudent(${studentId}); this.closest('.fixed').remove();" class="w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">visibility</span>
                        <span class="text-gray-900 dark:text-white">View Full Details</span>
                    </button>
                    <button onclick="studentManagement.editStudent(${studentId}); this.closest('.fixed').remove();" class="w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">edit</span>
                        <span class="text-gray-900 dark:text-white">Edit Information</span>
                    </button>
                    <button onclick="studentManagement.addRemarks(${studentId}); this.closest('.fixed').remove();" class="w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">comment</span>
                        <span class="text-gray-900 dark:text-white">Add Remarks</span>
                    </button>
                    <button onclick="studentManagement.cancelStudent(${studentId}); this.closest('.fixed').remove();" class="w-full text-left px-4 py-3 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg flex items-center gap-3 text-red-600 dark:text-red-400">
                        <span class="material-symbols-outlined">cancel</span>
                        <span>Cancel Enrollment</span>
                    </button>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <button onclick="this.closest('.fixed').remove()" class="w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Close
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    addRemarks(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">Add Remarks</h2>
                </div>
                <div class="p-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Remarks</label>
                    <textarea id="remarks-input" rows="4" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter remarks for this student..."></textarea>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button onclick="this.closest('.fixed').remove()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Cancel
                    </button>
                    <button onclick="studentManagement.saveRemarks(${studentId}, document.getElementById('remarks-input').value); this.closest('.fixed').remove();" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-green-700">
                        Save Remarks
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    async saveRemarks(studentId, remarks) {
        if (!remarks.trim()) {
            alert('Please enter remarks');
            return;
        }

        alert(`Remarks saved for student ${studentId}: ${remarks}\n\nThis would be saved to the database.`);
    }

    cancelStudent(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-red-600 dark:text-red-400">Cancel Enrollment</h2>
                </div>
                <div class="p-6">
                    <p class="text-gray-700 dark:text-gray-300 mb-4">Are you sure you want to cancel this student's enrollment? This action cannot be undone.</p>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Cancellation</label>
                    <textarea id="cancel-reason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter reason..."></textarea>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button onclick="this.closest('.fixed').remove()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        No, Keep Enrollment
                    </button>
                    <button onclick="studentManagement.confirmCancel(${studentId}, document.getElementById('cancel-reason').value); this.closest('.fixed').remove();" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        Yes, Cancel Enrollment
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    async confirmCancel(studentId, reason) {
        if (!reason.trim()) {
            alert('Please provide a reason for cancellation');
            return;
        }

        alert(`Enrollment cancelled for student ${studentId}\nReason: ${reason}\n\nThis would update the database and reload the student list.`);
        // In production: call API to update status, then reload
        this.loadStudents();
    }

    addNewStudent() {
        window.location.href = 'enrollment-management.html';
    }

    showLoading(show) {
        // Implement loading indicator if needed
        console.log('Loading:', show);
    }
}

// Initialize
let studentManagement;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        studentManagement = new StudentManagementHandler();
    });
} else {
    studentManagement = new StudentManagementHandler();
}