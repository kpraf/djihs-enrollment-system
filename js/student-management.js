// =====================================================
// Enhanced Student Management Handler
// File: js/student-management.js
// Updated: 2026-02-08 - Added Dropped, Transferred In/Out statuses
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
        this.gradeLevels = [];
        this.sections = [];
        this.currentEditingStudent = null;
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
        this.loadGradeLevels();
        this.loadStrands();
        this.loadStudents();
        this.displayUserInfo();
        // Initialize new handlers
        this.documentHandler = new DocumentSubmissionHandler();
        this.revisionHandler = new RevisionRequestHandler();
    }

    displayUserInfo() {
        const userData = this.currentUser;
        const userNameEl = document.querySelector('.user-name');
        const userInitialsEl = document.querySelector('.user-initials');
        
        if (userData.FirstName && userData.LastName && userNameEl && userInitialsEl) {
            userNameEl.textContent = `${userData.FirstName} ${userData.LastName}`;
            userInitialsEl.textContent = `${userData.FirstName[0]}${userData.LastName[0]}`.toUpperCase();
        }
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

    async loadGradeLevels() {
        try {
            const response = await fetch('../backend/api/student-update.php?action=get_grade_levels');
            const result = await response.json();

            if (result.success) {
                this.gradeLevels = result.gradeLevels || [];
            } else {
                console.error('Failed to load grade levels:', result.message);
            }
        } catch (error) {
            console.error('Error loading grade levels:', error);
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
            alert('Error loading students. Please refresh the page.');
        } finally {
            this.showLoading(false);
        }
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
                gradeFilter.innerHTML += `<option value="${grade}">Grade ${grade}</option>`;
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

        // Populate Status - Updated with new statuses
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
                'Dropped': 'Dropped',
                'Transferred_In': 'Transferred In',
                'Transferred_Out': 'Transferred Out',
                'Graduated': 'Graduated'
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

        // Strand filter
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
            'Dropped': 'Dropped',
            'Transferred_In': 'Transferred In',
            'Transferred_Out': 'Transferred Out',
            'Graduated': 'Graduated'
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
                            class="btn-view text-gray-400 hover:text-primary dark:hover:text-primary transition-colors"
                            data-student-id="${student.StudentID}"
                            title="View Details">
                            <span class="material-symbols-outlined" style="font-size:20px;">visibility</span>
                        </button>
                        <button 
                            class="btn-edit text-gray-400 hover:text-primary dark:hover:text-primary transition-colors"
                            data-student-id="${student.StudentID}"
                            title="Edit">
                            <span class="material-symbols-outlined" style="font-size:20px;">edit</span>
                        </button>
                        <button 
                            class="btn-more text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                            data-student-id="${student.StudentID}"
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

        // Bind action button events
        tbody.querySelectorAll('.btn-view').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studentId = parseInt(e.currentTarget.dataset.studentId);
                this.viewStudent(studentId);
            });
        });

        tbody.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studentId = parseInt(e.currentTarget.dataset.studentId);
                this.editStudent(studentId);
            });
        });

        tbody.querySelectorAll('.btn-more').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studentId = parseInt(e.currentTarget.dataset.studentId);
                this.showMoreOptions(studentId);
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
        return match ? `Grade ${match[0]}` : gradeLevel;
    }

    getStatusBadge(status) {
        const normalizedStatus = this.normalizeStatus(status);
        
        const statusMap = {
            'Enrolled': { 
                class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', 
                text: 'Enrolled' 
            },
            'Pending': { 
                class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300', 
                text: 'Pending' 
            },
            'For Review': { 
                class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300', 
                text: 'For Review' 
            },
            'Cancelled': { 
                class: 'bg-gray-100 text-gray-800 dark:bg-gray-900/40 dark:text-gray-300', 
                text: 'Cancelled' 
            },
            'Dropped': { 
                class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', 
                text: 'Dropout' 
            },
            'Transferred In': { 
                class: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300', 
                text: 'Transferred In' 
            },
            'Transferred Out': { 
                class: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300', 
                text: 'Transferred Out' 
            },
            'Graduated': { 
                class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300', 
                text: 'Graduated' 
            }
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
                this.showStudentDetailsModal(result.data, false);
            } else {
                alert('Failed to load student details');
            }
        } catch (error) {
            console.error('Error loading student details:', error);
            alert('Error loading student details');
        }
    }

    async editStudent(studentId) {
        // Advisers can edit (changes go for approval)
        // Registrar/ICT can view but should approve changes, not edit directly
        const userRole = this.currentUser.Role;
        const canSubmitEdit = userRole === 'Adviser';
        if (!canSubmitEdit) {
            alert('Only Advisers can submit student information edits. Registrars and ICT Coordinators approve these changes.');
            return;
        }
        try {
            const response = await fetch(`../backend/api/students.php?action=details&id=${studentId}`);
            const result = await response.json();

            if (result.success) {
                this.showStudentDetailsModal(result.data, true);
            } else {
                alert('Failed to load student details');
            }
        } catch (error) {
            console.error('Error loading student details:', error);
            alert('Error loading student details');
        }
    }

    showStudentDetailsModal(student, editMode = false) {
        this.currentEditingStudent = student;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('studentDetailsModal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'studentDetailsModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
        
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-6xl w-full max-h-[90vh] overflow-y-auto">
                <!-- Header -->
                <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-between z-10">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                        ${editMode ? 'Edit Student Information' : 'Student Details'}
                    </h3>
                    <button onclick="document.getElementById('studentDetailsModal').remove()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <span class="material-icons-outlined">close</span>
                    </button>
                </div>

                <!-- Content -->
                <div class="p-6">
                    ${this.renderStudentForm(student, editMode)}
                </div>

                <!-- Footer -->
                <div class="sticky bottom-0 bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    ${editMode ? `
                        <button id="btn-cancel-edit" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                            Cancel
                        </button>
                        <button id="btn-save-student" class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                            <span class="material-icons-outlined text-[18px]">save</span>
                            Save Changes
                        </button>
                    ` : `
                        <button id="btn-edit-student" data-student-id="${student.StudentID}" class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                            <span class="material-icons-outlined text-[18px]">edit</span>
                            Edit Student
                        </button>
                        <button onclick="document.getElementById('studentDetailsModal').remove()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                            Close
                        </button>
                    `}
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);

        // Bind modal button events
        if (editMode) {
            const cancelBtn = document.getElementById('btn-cancel-edit');
            const saveBtn = document.getElementById('btn-save-student');
            
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => this.cancelEdit());
            }
            if (saveBtn) {
                saveBtn.addEventListener('click', () => this.saveStudent());
            }

            // Bind grade and strand change handlers
            const gradeSelect = document.getElementById('edit-grade');
            const strandSelect = document.getElementById('edit-strand');
            
            if (gradeSelect) {
                gradeSelect.addEventListener('change', () => this.handleGradeChange());
            }
            if (strandSelect) {
                strandSelect.addEventListener('change', () => this.handleStrandChange());
            }

            // Determine GradeLevelID from GradeLevelName
            let gradeLevelId = null;
            if (student.GradeLevelName) {
                const gradeMatch = student.GradeLevelName.match(/\d+/);
                if (gradeMatch) {
                    const gradeNumber = parseInt(gradeMatch[0]);
                    const gradeLevel = this.gradeLevels.find(g => g.GradeLevelNumber === gradeNumber);
                    if (gradeLevel) {
                        gradeLevelId = gradeLevel.GradeLevelID;
                    }
                }
            }

            // Load sections for current grade/strand
            if (gradeLevelId) {
                this.loadSectionsForGrade(gradeLevelId, student.StrandID);
            }
        } else {
            const editBtn = document.getElementById('btn-edit-student');
            if (editBtn) {
                editBtn.addEventListener('click', (e) => {
                    const studentId = parseInt(e.currentTarget.dataset.studentId);
                    this.editStudent(studentId);
                });
            }
        }
    }

    renderStudentForm(student, editMode) {
        const isEditable = editMode ? '' : 'disabled';
        const inputClass = editMode 
            ? 'form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white' 
            : 'form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-100 dark:text-gray-400 cursor-not-allowed';

        // Determine grade level ID from name
        let selectedGradeId = '';
        if (student.GradeLevelName) {
            const gradeMatch = student.GradeLevelName.match(/\d+/);
            if (gradeMatch) {
                const gradeNumber = parseInt(gradeMatch[0]);
                const gradeLevel = this.gradeLevels.find(g => g.GradeLevelNumber === gradeNumber);
                if (gradeLevel) {
                    selectedGradeId = gradeLevel.GradeLevelID;
                }
            }
        }

        // Check if grade level is 11 or 12 for strand visibility
        const showStrand = selectedGradeId >= 5; // GradeLevelID 5 = Grade 11, 6 = Grade 12

        return `
            <div class="space-y-8">
                <!-- Personal Information -->
                <div>
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">person</span>
                        Personal Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">LRN</label>
                            <input type="text" id="edit-lrn" value="${student.LRN || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                            <input type="text" id="edit-lastname" value="${student.LastName || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                            <input type="text" id="edit-firstname" value="${student.FirstName || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                            <input type="text" id="edit-middlename" value="${student.MiddleName || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Extension Name</label>
                            <input type="text" id="edit-extension" value="${student.ExtensionName || ''}" ${isEditable} placeholder="Jr., Sr., III, etc." class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
                            <input type="date" id="edit-dob" value="${student.DateOfBirth || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age</label>
                            <input type="number" id="edit-age" value="${student.Age || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
                            <select id="edit-gender" ${isEditable} class="${inputClass}">
                                <option value="Male" ${student.Gender === 'Male' ? 'selected' : ''}>Male</option>
                                <option value="Female" ${student.Gender === 'Female' ? 'selected' : ''}>Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Religion</label>
                            <input type="text" id="edit-religion" value="${student.Religion || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact Number</label>
                            <input type="tel" id="edit-contact" value="${student.ContactNumber || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div class="flex items-center gap-3">
                            <input type="checkbox" id="edit-ip" ${student.IsIPCommunity ? 'checked' : ''} ${isEditable} class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                            <label for="edit-ip" class="text-sm font-medium text-gray-700 dark:text-gray-300">Member of Indigenous Peoples Community</label>
                        </div>
                        <div>
                            <input type="text" id="edit-ip-specify" value="${student.IPCommunitySpecify || ''}" ${isEditable} placeholder="Specify IP Community" class="${inputClass}" />
                        </div>
                        <div class="flex items-center gap-3">
                            <input type="checkbox" id="edit-pwd" ${student.IsPWD ? 'checked' : ''} ${isEditable} class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                            <label for="edit-pwd" class="text-sm font-medium text-gray-700 dark:text-gray-300">Person with Disability (PWD)</label>
                        </div>
                        <div>
                            <input type="text" id="edit-pwd-specify" value="${student.PWDSpecify || ''}" ${isEditable} placeholder="Specify Disability" class="${inputClass}" />
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div>
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">home</span>
                        Address Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">House Number</label>
                            <input type="text" id="edit-house" value="${student.HouseNumber || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sitio/Street</label>
                            <input type="text" id="edit-street" value="${student.SitioStreet || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Barangay</label>
                            <input type="text" id="edit-barangay" value="${student.Barangay || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Municipality</label>
                            <input type="text" id="edit-municipality" value="${student.Municipality || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Province</label>
                            <input type="text" id="edit-province" value="${student.Province || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Zip Code</label>
                            <input type="text" id="edit-zipcode" value="${student.ZipCode || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                    </div>
                </div>

                <!-- Parent/Guardian Information -->
                <div>
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">family_restroom</span>
                        Parent/Guardian Information
                    </h4>
                    <div class="grid grid-cols-1 gap-6">
                        <!-- Father -->
                        <div>
                            <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-2">Father's Information</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                                    <input type="text" id="edit-father-last" value="${student.FatherLastName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                                    <input type="text" id="edit-father-first" value="${student.FatherFirstName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                                    <input type="text" id="edit-father-middle" value="${student.FatherMiddleName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                            </div>
                        </div>
                        
                        <!-- Mother -->
                        <div>
                            <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-2">Mother's Information</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                                    <input type="text" id="edit-mother-last" value="${student.MotherLastName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                                    <input type="text" id="edit-mother-first" value="${student.MotherFirstName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                                    <input type="text" id="edit-mother-middle" value="${student.MotherMiddleName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                            </div>
                        </div>
                        
                        <!-- Guardian -->
                        <div>
                            <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-2">Guardian's Information (if applicable)</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                                    <input type="text" id="edit-guardian-last" value="${student.GuardianLastName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                                    <input type="text" id="edit-guardian-first" value="${student.GuardianFirstName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                                    <input type="text" id="edit-guardian-middle" value="${student.GuardianMiddleName || ''}" ${isEditable} class="${inputClass}" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enrollment Information -->
                <div>
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-primary">school</span>
                        Enrollment Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Grade Level</label>
                            <select id="edit-grade" ${isEditable} class="${inputClass}">
                                <option value="">Select Grade Level</option>
                                ${this.gradeLevels.map(gl => `
                                    <option value="${gl.GradeLevelID}" ${selectedGradeId == gl.GradeLevelID ? 'selected' : ''}>
                                        ${gl.GradeLevelName}
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                        <div id="strand-container" class="${showStrand ? '' : 'hidden'}">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Strand</label>
                            <select id="edit-strand" ${isEditable} class="${inputClass}">
                                <option value="">Select Strand</option>
                                ${this.strands.map(s => `
                                    <option value="${s.StrandID}" ${student.StrandID == s.StrandID ? 'selected' : ''}>
                                        ${s.StrandCode} - ${s.StrandName}
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Section</label>
                            <select id="edit-section" ${isEditable} class="${inputClass}">
                                <option value="">Select Section</option>
                                <!-- Sections will be loaded dynamically -->
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Academic Year</label>
                            <input type="text" id="edit-academic-year" value="${student.AcademicYear || ''}" ${isEditable} class="${inputClass}" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Enrollment Status</label>
                            <select id="edit-status" ${isEditable} class="${inputClass}">
                                <option value="Active" ${student.EnrollmentStatus === 'Active' ? 'selected' : ''}>Active</option>
                                <option value="Cancelled" ${student.EnrollmentStatus === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                                <option value="Dropped" ${student.EnrollmentStatus === 'Dropped' ? 'selected' : ''}>Dropped</option>
                                <option value="Transferred_In" ${student.EnrollmentStatus === 'Transferred_In' ? 'selected' : ''}>Transferred In</option>
                                <option value="Transferred_Out" ${student.EnrollmentStatus === 'Transferred_Out' ? 'selected' : ''}>Transferred Out</option>
                                <option value="Graduated" ${student.EnrollmentStatus === 'Graduated' ? 'selected' : ''}>Graduated</option>
                            </select>
                        </div>
                    </div>
                </div>

                ${editMode ? '' : `
                    <!-- Additional Information (View Only) -->
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                            <span class="material-icons-outlined text-primary">info</span>
                            Additional Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Transfer Status</label>
                                <p class="text-base text-gray-900 dark:text-white">
                                    ${student.EnrollmentStatus === 'Transferred_In' ? 'Transferred In' : 
                                      student.EnrollmentStatus === 'Transferred_Out' ? 'Transferred Out' : 
                                      student.IsTransferee ? 'Yes (Legacy)' : 'No'}
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Created At</label>
                                <p class="text-base text-gray-900 dark:text-white">${student.CreatedAt || 'N/A'}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Updated</label>
                                <p class="text-base text-gray-900 dark:text-white">${student.UpdatedAt || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                `}
            </div>
        `;
    }

    handleGradeChange() {
        const gradeSelect = document.getElementById('edit-grade');
        const strandContainer = document.getElementById('strand-container');
        const sectionSelect = document.getElementById('edit-section');
        const strandSelect = document.getElementById('edit-strand');
        
        if (!gradeSelect) return;
        
        const selectedGrade = parseInt(gradeSelect.value);
        
        // Show/hide strand based on grade level (5 = Grade 11, 6 = Grade 12)
        if (selectedGrade >= 5) {
            strandContainer.classList.remove('hidden');
        } else {
            strandContainer.classList.add('hidden');
            strandSelect.value = '';
        }
        
        // Load sections for selected grade
        this.loadSectionsForGrade(selectedGrade, strandSelect.value);
    }

    handleStrandChange() {
        const gradeSelect = document.getElementById('edit-grade');
        const strandSelect = document.getElementById('edit-strand');
        
        if (!gradeSelect) return;
        
        const selectedGrade = parseInt(gradeSelect.value);
        const selectedStrand = strandSelect.value;
        
        this.loadSectionsForGrade(selectedGrade, selectedStrand);
    }

    async loadSectionsForGrade(gradeLevel, strandId = null) {
        const sectionSelect = document.getElementById('edit-section');
        if (!sectionSelect) return;
        
        const currentSection = this.currentEditingStudent?.SectionID;
        
        try {
            let url = `../backend/api/student-update.php?action=get_sections&grade_level=${gradeLevel}`;
            if (strandId) {
                url += `&strand_id=${strandId}`;
            }
            
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                sectionSelect.innerHTML = '<option value="">Select Section</option>';
                result.sections.forEach(section => {
                    sectionSelect.innerHTML += `
                        <option value="${section.SectionID}" ${currentSection == section.SectionID ? 'selected' : ''}>
                            ${section.SectionName}
                        </option>
                    `;
                });
            }
        } catch (error) {
            console.error('Error loading sections:', error);
        }
    }

    async saveStudent() {
        try {
            // Gather form data
            const formData = {
                StudentID: this.currentEditingStudent.StudentID,
                LRN: document.getElementById('edit-lrn')?.value,
                LastName: document.getElementById('edit-lastname')?.value,
                FirstName: document.getElementById('edit-firstname')?.value,
                MiddleName: document.getElementById('edit-middlename')?.value,
                ExtensionName: document.getElementById('edit-extension')?.value,
                DateOfBirth: document.getElementById('edit-dob')?.value,
                Age: document.getElementById('edit-age')?.value,
                Gender: document.getElementById('edit-gender')?.value,
                Religion: document.getElementById('edit-religion')?.value,
                ContactNumber: document.getElementById('edit-contact')?.value,
                IsIPCommunity: document.getElementById('edit-ip')?.checked ? 1 : 0,
                IPCommunitySpecify: document.getElementById('edit-ip-specify')?.value,
                IsPWD: document.getElementById('edit-pwd')?.checked ? 1 : 0,
                PWDSpecify: document.getElementById('edit-pwd-specify')?.value,
                HouseNumber: document.getElementById('edit-house')?.value,
                SitioStreet: document.getElementById('edit-street')?.value,
                Barangay: document.getElementById('edit-barangay')?.value,
                Municipality: document.getElementById('edit-municipality')?.value,
                Province: document.getElementById('edit-province')?.value,
                ZipCode: document.getElementById('edit-zipcode')?.value,
                FatherLastName: document.getElementById('edit-father-last')?.value,
                FatherFirstName: document.getElementById('edit-father-first')?.value,
                FatherMiddleName: document.getElementById('edit-father-middle')?.value,
                MotherLastName: document.getElementById('edit-mother-last')?.value,
                MotherFirstName: document.getElementById('edit-mother-first')?.value,
                MotherMiddleName: document.getElementById('edit-mother-middle')?.value,
                GuardianLastName: document.getElementById('edit-guardian-last')?.value,
                GuardianFirstName: document.getElementById('edit-guardian-first')?.value,
                GuardianMiddleName: document.getElementById('edit-guardian-middle')?.value,
                GradeLevelID: document.getElementById('edit-grade')?.value,
                StrandID: document.getElementById('edit-strand')?.value || null,
                SectionID: document.getElementById('edit-section')?.value || null,
                AcademicYear: document.getElementById('edit-academic-year')?.value,
                EnrollmentStatus: document.getElementById('edit-status')?.value || null,
                UpdatedBy: this.currentUser.UserID
            };

            // Validate required fields
            if (!formData.LRN || !formData.LastName || !formData.FirstName) {
                alert('Please fill in all required fields (LRN, Last Name, First Name)');
                return;
            }

            // Check user role - Advisers submit revision requests
                const userRole = this.currentUser.Role;

                let endpoint, successMessage;
                if (userRole === 'Adviser') {
                    endpoint = '../backend/api/revision-requests.php?action=create_bulk_update';
                    successMessage = 'Changes submitted for approval. A registrar will review your updates.';
                } else {
                    endpoint = '../backend/api/student-update.php?action=update';
                    successMessage = 'Student information updated successfully!';
                }

                const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const result = await response.json();

            if (result.success) {
                alert(successMessage);
                document.getElementById('studentDetailsModal').remove();
                this.loadStudents(); // Reload the student list
            } else {
                alert('Failed to update student: ' + (result.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error saving student:', error);
            alert('Error saving student information');
        }
    }

    cancelEdit() {
        if (confirm('Are you sure you want to cancel? Any unsaved changes will be lost.')) {
            document.getElementById('studentDetailsModal').remove();
        }
    }

    showMoreOptions(studentId) {
        const student = this.students.find(s => s.StudentID === studentId);
        const currentStatus = student?.EnrollmentStatus || student?.Status;

        const userRole = this.currentUser.Role;
        // Role-based permissions:
        // - Advisers: Can EDIT (submits for approval), REQUEST REVISIONS, and MANAGE DOCUMENTS
        // - Registrar/ICT: Can APPROVE revisions, MANAGE DOCUMENTS, and change status
        const isRegistrar = userRole === 'Registrar';
        const isAdviser = userRole === 'Adviser';
        const isICTCoordinator = userRole === 'ICT_Coordinator';
        const isApprover = isRegistrar || isICTCoordinator; // Can approve changes
        const canSubmitEdits = isAdviser; // Can edit for approval
        const canManageDocuments = isAdviser || isRegistrar || isICTCoordinator; // ALL can manage documents
        
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">Student Actions</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Role: ${userRole}</p>
                </div>
                <div class="p-6 space-y-2">
                    <button class="btn-modal-view w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">visibility</span>
                        <span class="text-gray-900 dark:text-white">View Full Details</span>
                    </button>
                    
                    <!-- Advisers can EDIT (submits for approval) -->
                    ${canSubmitEdits ? `
                        <button class="btn-modal-edit w-full text-left px-4 py-3 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg flex items-center gap-3 text-blue-600 dark:text-blue-400">
                            <span class="material-symbols-outlined">edit</span>
                            <div class="flex-1">
                                <span class="block">Edit Information</span>
                                <span class="text-xs opacity-75">Submit changes for approval</span>
                            </div>
                        </button>
                    ` : ''}
                    
                    <!-- Advisers can REQUEST specific revisions -->
                    ${canSubmitEdits ? `
                        <button class="btn-modal-revision w-full text-left px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg flex items-center gap-3 text-purple-600 dark:text-purple-400">
                            <span class="material-symbols-outlined">request_quote</span>
                            <div class="flex-1">
                                <span class="block">Request Specific Revision</span>
                                <span class="text-xs opacity-75">For corrections requiring justification</span>
                            </div>
                        </button>
                    ` : ''}
                    
                    <!-- Approvers (Registrar/ICT) can VIEW PENDING REVISIONS -->
                    ${isApprover ? `
                        <button class="btn-modal-review-revisions w-full text-left px-4 py-3 hover:bg-orange-50 dark:hover:bg-orange-900/20 rounded-lg flex items-center gap-3 text-orange-600 dark:text-orange-400">
                            <span class="material-symbols-outlined">fact_check</span>
                            <div class="flex-1">
                                <span class="block">Review Pending Revisions</span>
                                <span class="text-xs opacity-75">Approve or reject changes</span>
                            </div>
                        </button>
                    ` : ''}

                    <!-- EVERYONE (Adviser, Registrar, ICT) can manage documents -->
                    ${canManageDocuments ? `
                        <button class="btn-modal-documents w-full text-left px-4 py-3 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg flex items-center gap-3 text-indigo-600 dark:text-indigo-400">
                            <span class="material-symbols-outlined">description</span>
                            <div class="flex-1">
                                <span class="block">Document Checklist</span>
                                <span class="text-xs opacity-75">Manage student documents</span>
                            </div>
                        </button>
                    ` : ''}

                    <!-- Everyone can add remarks -->
                    <button class="btn-modal-remarks w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">comment</span>
                        <span class="text-gray-900 dark:text-white">Add Remarks</span>
                    </button>
                    
                    <!-- Only Registrar and ICT can manage status changes directly -->
                    ${isApprover ? `
                        <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
                        
                        ${currentStatus !== 'Cancelled' ? `
                            <button class="btn-modal-cancel w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-900/20 rounded-lg flex items-center gap-3 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined">cancel</span>
                                <span>Cancel Enrollment</span>
                            </button>
                        ` : ''}
                        
                        ${currentStatus !== 'Dropped' ? `
                            <button class="btn-modal-dropout w-full text-left px-4 py-3 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg flex items-center gap-3 text-red-600 dark:text-red-400">
                                <span class="material-symbols-outlined">person_off</span>
                                <span>Mark as Dropout</span>
                            </button>
                        ` : ''}
                        
                        ${currentStatus !== 'Transferred_Out' ? `
                            <button class="btn-modal-transfer-out w-full text-left px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg flex items-center gap-3 text-purple-600 dark:text-purple-400">
                                <span class="material-symbols-outlined">logout</span>
                                <span>Transfer Out</span>
                            </button>
                        ` : ''}
                    ` : ''}
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <button class="btn-modal-close w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Close
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Bind modal buttons
        modal.querySelector('.btn-modal-view')?.addEventListener('click', () => {
            this.viewStudent(studentId);
            modal.remove();
        });
        
        modal.querySelector('.btn-modal-edit')?.addEventListener('click', () => {
            this.editStudent(studentId);
            modal.remove();
        });
        
        modal.querySelector('.btn-modal-remarks')?.addEventListener('click', () => {
            this.addRemarks(studentId);
            modal.remove();
        });
        
        modal.querySelector('.btn-modal-cancel')?.addEventListener('click', () => {
            this.cancelEnrollment(studentId);
            modal.remove();
        });
        
        modal.querySelector('.btn-modal-dropout')?.addEventListener('click', () => {
            this.markAsDropout(studentId);
            modal.remove();
        });
        
        modal.querySelector('.btn-modal-transfer-out')?.addEventListener('click', () => {
            this.transferOut(studentId);
            modal.remove();
        });
        
        modal.querySelector('.btn-modal-close')?.addEventListener('click', () => {
            modal.remove();
        });

        // Bind document checklist (for Advisers, Registrars, and ICT Coordinators)
        modal.querySelector('.btn-modal-documents')?.addEventListener('click', () => {
            this.documentHandler.showDocumentChecklist(
                studentId, 
                student.FullName,
                student.EnrollmentID
            );
            modal.remove();
        });

        // Bind revision request (for Advisers)
        modal.querySelector('.btn-modal-revision')?.addEventListener('click', () => {
            this.revisionHandler.showRevisionRequestForm(student);
            modal.remove();
        });

        // Bind review revisions (for Registrar and ICT Coordinator approvers)
        modal.querySelector('.btn-modal-review-revisions')?.addEventListener('click', () => {
            this.showPendingRevisionsForStudent(studentId);
            modal.remove();
        });
    }

    /**
     * Show pending revision requests for a specific student (for approvers)
     */

    async showPendingRevisionsForStudent(studentId) {
        try {
            const response = await fetch(`../backend/api/revision-requests.php?action=get_by_student&student_id=${studentId}`);
            const result = await response.json();
            if (!result.success) {
                alert('Error loading revision requests: ' + result.message);
                return;
            }
            const requests = result.data || [];
            if (requests.length === 0) {
                alert('No pending revision requests found for this student.');
                return;
            }
            this.showRevisionListModal(requests, studentId);
        } catch (error) {
            console.error('Error loading revisions:', error);
            alert('Error loading revision requests');
        }
    }

    /**
     * Display list of revision requests
     */
    showRevisionListModal(requests, studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
        
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-between z-10">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Pending Revision Requests</h3>
                    <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <span class="material-icons-outlined">close</span>
                    </button>
                </div>
                
                <div class="p-6 space-y-4">
                    ${requests.map(req => {
                        const priorityColors = {
                            'Urgent': 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                            'High': 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
                            'Normal': 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300'
                        };
                        
                        return `
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors cursor-pointer" data-request-id="${req.RequestID}">
                                <div class="flex items-start justify-between mb-3">
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <h4 class="font-semibold text-gray-900 dark:text-white">Request #${req.RequestID}</h4>
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium ${priorityColors[req.Priority] || priorityColors['Normal']}">
                                                ${req.Priority}
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            ${req.RequestType.replace(/_/g, ' ')} • ${new Date(req.CreatedAt).toLocaleDateString()}
                                        </p>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300">
                                        Pending
                                    </span>
                                </div>
                                <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">
                                    <strong>Requested by:</strong> ${req.RequestedByName} (${req.RequesterRole})
                                </p>
                                <button class="btn-review-request text-sm text-primary hover:text-green-700 dark:text-green-400 dark:hover:text-green-300 font-medium" data-request-id="${req.RequestID}">
                                    Review Request →
                                </button>
                            </div>
                        `;
                    }).join('')}
                </div>
                
                <div class="sticky bottom-0 bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <button onclick="this.closest('.fixed').remove()" class="w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Close
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        // Bind review buttons
        modal.querySelectorAll('.btn-review-request').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const requestId = e.currentTarget.dataset.requestId;
                modal.remove();
                this.revisionHandler.showApprovalInterface(requestId);
            });
        });
        // Also allow clicking the whole card
        modal.querySelectorAll('[data-request-id]').forEach(card => {
            if (!card.classList.contains('btn-review-request')) {
                card.addEventListener('click', (e) => {
                    const requestId = e.currentTarget.dataset.requestId;
                    modal.remove();
                    this.revisionHandler.showApprovalInterface(requestId);
                });
            }
        });
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
                    <button class="btn-remarks-cancel px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Cancel
                    </button>
                    <button class="btn-remarks-save px-4 py-2 bg-primary text-white rounded-lg hover:bg-green-700">
                        Save Remarks
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.btn-remarks-cancel').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-remarks-save').addEventListener('click', () => {
            const remarks = document.getElementById('remarks-input').value;
            this.saveRemarks(studentId, remarks);
            modal.remove();
        });
    }

    async saveRemarks(studentId, remarks) {
        if (!remarks.trim()) {
            alert('Please enter remarks');
            return;
        }

        try {
            const response = await fetch('../backend/api/student-update.php?action=add_remarks', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    StudentID: studentId,
                    Remarks: remarks,
                    UserID: this.currentUser.UserID
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Remarks saved successfully!');
            } else {
                alert('Failed to save remarks: ' + (result.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error saving remarks:', error);
            alert('Error saving remarks');
        }
    }

    cancelEnrollment(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-gray-600 dark:text-gray-400">Cancel Enrollment</h2>
                </div>
                <div class="p-6">
                    <p class="text-gray-700 dark:text-gray-300 mb-4">Cancel this enrollment? This is typically used for administrative cancellations (e.g., never attended, enrolled in error).</p>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Cancellation</label>
                    <textarea id="cancel-reason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter reason..."></textarea>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button class="btn-cancel-no px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        No, Keep Enrollment
                    </button>
                    <button class="btn-cancel-yes px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        Yes, Cancel Enrollment
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.btn-cancel-no').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-cancel-yes').addEventListener('click', () => {
            const reason = document.getElementById('cancel-reason').value;
            this.confirmStatusChange(studentId, 'Cancelled', reason);
            modal.remove();
        });
    }

    markAsDropout(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        
        // Check if user can approve directly
        const userRole = this.currentUser.Role;
        const canApproveDirectly = ['Registrar', 'ICT_Coordinator'].includes(userRole);
        
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-red-600 dark:text-red-400">Mark as Dropout</h2>
                    ${!canApproveDirectly ? `
                        <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-2">
                            ⚠️ This request requires approval from Registrar or ICT Coordinator
                        </p>
                    ` : ''}
                </div>
                <div class="p-6">
                    <p class="text-gray-700 dark:text-gray-300 mb-4">
                        ${canApproveDirectly 
                            ? 'Mark this student as a dropout? This indicates the student left school after attending.' 
                            : 'Request to mark this student as a dropout? Your request will be sent for approval.'}
                    </p>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Reason for Dropout <span class="text-red-600">*</span>
                    </label>
                    <textarea id="dropout-reason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter detailed reason..."></textarea>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button class="btn-dropout-no px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Cancel
                    </button>
                    <button class="btn-dropout-yes px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        ${canApproveDirectly ? 'Confirm Dropout' : 'Submit Request'}
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.btn-dropout-no').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-dropout-yes').addEventListener('click', () => {
            const reason = document.getElementById('dropout-reason').value;
            this.confirmStatusChange(studentId, 'Dropped', reason);
            modal.remove();
        });
    }

    transferOut(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        
        // Check if user can approve directly
        const userRole = this.currentUser.Role;
        const canApproveDirectly = ['Registrar', 'ICT_Coordinator'].includes(userRole);
        
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-purple-600 dark:text-purple-400">Transfer Out Student</h2>
                    ${!canApproveDirectly ? `
                        <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-2">
                            ⚠️ This request requires approval from Registrar or ICT Coordinator
                        </p>
                    ` : ''}
                </div>
                <div class="p-6">
                    <p class="text-gray-700 dark:text-gray-300 mb-4">
                        ${canApproveDirectly 
                            ? 'Transfer this student to another school?' 
                            : 'Request to transfer this student out? Your request will be sent for approval.'}
                    </p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Transfer To (School Name) <span class="text-red-600">*</span>
                            </label>
                            <input type="text" id="transfer-school" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter destination school..."/>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Reason for Transfer <span class="text-red-600">*</span>
                            </label>
                            <textarea id="transfer-reason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter reason..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button class="btn-transfer-no px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                        Cancel
                    </button>
                    <button class="btn-transfer-yes px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                        ${canApproveDirectly ? 'Confirm Transfer' : 'Submit Request'}
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.btn-transfer-no').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-transfer-yes').addEventListener('click', () => {
            const school = document.getElementById('transfer-school').value;
            const reason = document.getElementById('transfer-reason').value;
            
            if (!school.trim()) {
                alert('Please enter the destination school name');
                return;
            }
            
            this.confirmStatusChange(studentId, 'Transferred_Out', reason, school);
            modal.remove();
        });
    }

    async confirmStatusChange(studentId, newStatus, reason, additionalInfo = null) {
        if (!reason.trim()) {
            alert('Please provide a reason');
            return;
        }

        try {
            const response = await fetch('../backend/api/student-update.php?action=change_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    StudentID: studentId,
                    NewStatus: newStatus,
                    Reason: reason,
                    UserID: this.currentUser.UserID,
                    AdditionalInfo: additionalInfo
                })
            });

            const result = await response.json();

            if (result.success) {
                if (result.requiresApproval) {
                    alert(`Status change request submitted for approval!\n\nRequest ID: ${result.requestId}\n\nA Registrar or ICT Coordinator will review your request.`);
                } else {
                    const statusLabels = {
                        'Cancelled': 'cancelled',
                        'Dropped': 'marked as dropout',
                        'Transferred_Out': 'transferred out'
                    };
                    alert(`Student ${statusLabels[newStatus]} successfully!`);
                }
                this.loadStudents();
            } else {
                alert('Failed to update status: ' + (result.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error changing status:', error);
            alert('Error changing student status');
        }
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

// Make it globally accessible
window.studentManagement = studentManagement;